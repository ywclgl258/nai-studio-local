//! /api/cleanup -- One-click storage cleanup
//!
//! 跟 NAI Studio PHP 项目 cleanup.php 等价
//!   POST body: { level: 'all' | 'cache' | 'logs' | 'orphans' | 'rows',
//!               keep_favorites, log_retention_days, dry_run }
//!
//! Returns { ok, level, dry_run, counts: { cache, logs, orphans, files, rows, bytes_freed } }
//!
//! level 含义:
//!   - cache:   清空 storage/cache/
//!   - logs:    删 N 天前的 logs/*.log
//!   - orphans: 扫 images/thumbs, 删 DB 没引用的孤儿文件
//!   - rows:    删 generations 表里 is_favorite=0 的行, 同时物理删图/缩略图
//!   - all:     cache + logs + orphans + rows 一起跑

use std::collections::HashSet;
use std::path::{Path, PathBuf};
use std::time::{Duration, SystemTime, UNIX_EPOCH};

use axum::Json;
use axum::extract::State;
use serde_json::{Value, json};
use walkdir::WalkDir;

use crate::api::SharedState;
use crate::error::AppResult;

pub async fn handle(
    State(state): State<SharedState>,
    Json(body): Json<Value>,
) -> AppResult<Json<Value>> {
    let level = body.get("level").and_then(|v| v.as_str()).unwrap_or("all");
    let keep_favorites = body.get("keep_favorites").and_then(|v| v.as_bool()).unwrap_or(true);
    let dry_run = body.get("dry_run").and_then(|v| v.as_bool()).unwrap_or(false);
    let log_retention_days: i64 = body.get("log_retention_days").and_then(|v| v.as_i64()).unwrap_or(7).max(1);

    let mut counts = json!({
        "cache": 0, "logs": 0, "orphans": 0, "files": 0, "rows": 0,
        "bytes_freed": 0, "rows_kept": 0, "rows_deleted": 0,
    });

    if level == "all" || level == "cache" {
        let (n, bytes) = if dry_run {
            dir_size(&state.paths.cache)
        } else {
            let (deleted_count, bytes) = clean_dir_with_bytes(&state.paths.cache);
            (deleted_count, bytes)
        };
        counts["cache"] = json!(n);
        counts["files"] = json!(counts["files"].as_i64().unwrap_or(0) + n);
        counts["bytes_freed"] = json!(counts["bytes_freed"].as_i64().unwrap_or(0) + bytes);
    }
    if level == "all" || level == "logs" {
        let (n, bytes) = clean_logs(&state.paths.logs, log_retention_days, dry_run);
        counts["logs"] = json!(n);
        counts["files"] = json!(counts["files"].as_i64().unwrap_or(0) + n);
        counts["bytes_freed"] = json!(counts["bytes_freed"].as_i64().unwrap_or(0) + bytes);
    }
    if level == "all" || level == "orphans" {
        let (n, bytes) = clean_orphans(&state, dry_run)?;
        counts["orphans"] = json!(n);
        counts["files"] = json!(counts["files"].as_i64().unwrap_or(0) + n);
        counts["bytes_freed"] = json!(counts["bytes_freed"].as_i64().unwrap_or(0) + bytes);
    }
    if level == "all" || level == "rows" {
        let (deleted, kept, bytes) = clean_rows(&state, keep_favorites, dry_run)?;
        counts["rows"] = json!(deleted);
        counts["rows_deleted"] = json!(deleted);
        counts["rows_kept"] = json!(kept);
        counts["files"] = json!(counts["files"].as_i64().unwrap_or(0) + deleted);
        counts["bytes_freed"] = json!(counts["bytes_freed"].as_i64().unwrap_or(0) + bytes);
    }

    Ok(Json(json!({
        "ok": true,
        "level": level,
        "dry_run": dry_run,
        "keep_favorites": keep_favorites,
        "counts": counts,
    })))
}

/// 清空目录内容,返 (文件数, 字节数)
fn clean_dir_with_bytes(dir: &Path) -> (i64, i64) {
    if !dir.is_dir() { return (0, 0); }
    let mut n = 0i64;
    let mut bytes = 0i64;
    for entry in WalkDir::new(dir).min_depth(1).into_iter().filter_map(|e| e.ok()) {
        let p = entry.path();
        if p.is_file() {
            let size = p.metadata().map(|m| m.len() as i64).unwrap_or(0);
            if std::fs::remove_file(p).is_ok() {
                n += 1;
                bytes += size;
            }
        } else if p.is_dir() {
            let _ = std::fs::remove_dir(p);
        }
    }
    (n, bytes)
}

fn dir_size(dir: &Path) -> (i64, i64) {
    if !dir.is_dir() { return (0, 0); }
    let mut n = 0i64;
    let mut bytes = 0i64;
    for entry in WalkDir::new(dir).min_depth(1).into_iter().filter_map(|e| e.ok()) {
        let p = entry.path();
        if p.is_file() {
            n += 1;
            bytes += p.metadata().map(|m| m.len() as i64).unwrap_or(0);
        }
    }
    (n, bytes)
}

/// 删 N 天前的日志文件
fn clean_logs(log_dir: &Path, retention_days: i64, dry_run: bool) -> (i64, i64) {
    if !log_dir.is_dir() { return (0, 0); }
    let cutoff = SystemTime::now() - Duration::from_secs(retention_days as u64 * 86400);
    let mut n = 0i64;
    let mut bytes = 0i64;
    for entry in WalkDir::new(log_dir).min_depth(1).into_iter().filter_map(|e| e.ok()) {
        let p = entry.path();
        if p.is_file() {
            if let Ok(meta) = p.metadata() {
                if let Ok(mtime) = meta.modified() {
                    if mtime < cutoff {
                        let size = meta.len() as i64;
                        if dry_run {
                            n += 1; bytes += size;
                        } else if std::fs::remove_file(p).is_ok() {
                            n += 1; bytes += size;
                        }
                    }
                }
            }
        }
    }
    (n, bytes)
}

/// 扫 images/thumbs,删 DB 没引用的孤儿
fn clean_orphans(state: &SharedState, dry_run: bool) -> AppResult<(i64, i64)> {
    let referenced = {
        let conn = state.db.lock();
        let mut stmt = conn.prepare(
            "SELECT image_path, thumbnail_path FROM generations
             WHERE image_path IS NOT NULL OR thumbnail_path IS NOT NULL"
        )?;
        let mut set: HashSet<String> = HashSet::new();
        let mut rows = stmt.query([])?;
        while let Some(r) = rows.next()? {
            let ip: Option<String> = r.get(0)?;
            let tp: Option<String> = r.get(1)?;
            if let Some(p) = ip { set.insert(p); }
            if let Some(p) = tp { set.insert(p); }
        }
        set
    };

    let mut orphans: Vec<PathBuf> = Vec::new();
    for dir in [&state.paths.images, &state.paths.thumbs] {
        if !dir.is_dir() { continue; }
        for entry in WalkDir::new(dir).min_depth(1).into_iter().filter_map(|e| e.ok()) {
            let p = entry.path();
            if !p.is_file() { continue; }
            let rel = p.strip_prefix(&state.paths.storage).unwrap_or(p);
            let rel_str = rel.to_string_lossy().replace('\\', "/");
            let rel_with_slash = format!("/{}", rel_str.trim_start_matches('/'));
            if !referenced.contains(&rel_with_slash) {
                orphans.push(p.to_path_buf());
            }
        }
    }

    let mut bytes = 0i64;
    for f in &orphans {
        let size = f.metadata().map(|m| m.len() as i64).unwrap_or(0);
        bytes += size;
        if !dry_run { let _ = std::fs::remove_file(f); }
    }
    Ok((orphans.len() as i64, bytes))
}

/// 删 generations 表 is_favorite=0 的行, 同时物理删图/缩略图
///   keep_favorites=true: 默认保留收藏的
///   返 (deleted, kept, bytes_freed)
fn clean_rows(state: &SharedState, keep_favorites: bool, dry_run: bool) -> AppResult<(i64, i64, i64)> {
    // 1. 找要删的行
    let to_delete: Vec<(i64, Option<String>, Option<String>)> = {
        let conn = state.db.lock();
        let sql = if keep_favorites {
            "SELECT id, image_path, thumbnail_path FROM generations WHERE is_favorite = 0"
        } else {
            "SELECT id, image_path, thumbnail_path FROM generations"
        };
        let mut stmt = conn.prepare(sql)?;
        let rows = stmt.query_map([], |r| {
            Ok((r.get::<_, i64>(0)?, r.get::<_, Option<String>>(1)?, r.get::<_, Option<String>>(2)?))
        })?.collect::<Result<Vec<_>, _>>()?;
        rows
    };
    let kept = if keep_favorites {
        let conn = state.db.lock();
        conn.query_row("SELECT COUNT(*) FROM generations WHERE is_favorite = 1", [], |r| r.get(0)).unwrap_or(0)
    } else { 0 };

    // 2. 物理删图 + 缩略图
    let mut bytes = 0i64;
    let mut paths_to_remove: Vec<PathBuf> = Vec::new();
    for (_id, ip, tp) in &to_delete {
        if let Some(p) = ip {
            let abs = resolve_storage_path(&state, p);
            if let Ok(meta) = std::fs::metadata(&abs) {
                bytes += meta.len() as i64;
            }
            paths_to_remove.push(abs);
        }
        if let Some(p) = tp {
            let abs = resolve_storage_path(&state, p);
            if let Ok(meta) = std::fs::metadata(&abs) {
                bytes += meta.len() as i64;
            }
            paths_to_remove.push(abs);
        }
    }
    if !dry_run {
        for p in &paths_to_remove {
            let _ = std::fs::remove_file(p);
        }
    }

    // 3. 删 DB rows
    let deleted = to_delete.len() as i64;
    if !dry_run && deleted > 0 {
        let conn = state.db.lock();
        if keep_favorites {
            conn.execute("DELETE FROM generations WHERE is_favorite = 0", [])?;
        } else {
            conn.execute("DELETE FROM generations", [])?;
        }
    }

    Ok((deleted, kept, bytes))
}

/// 把 /storage/images/xxx.png 解析成 %APPDATA%/nai-studio-desktop/storage/images/xxx.png
fn resolve_storage_path(state: &SharedState, rel: &str) -> PathBuf {
    if let Some(stripped) = rel.strip_prefix("/storage/") {
        state.paths.storage.join(stripped)
    } else {
        PathBuf::from(rel)
    }
}

// 防止 SystemTime warning
#[allow(dead_code)]
fn _now() -> i64 {
    SystemTime::now().duration_since(UNIX_EPOCH).unwrap_or_default().as_secs() as i64
}
