//! /api/cleanup -- One-click storage cleanup
//!
//! 跟 NAI Studio PHP 项目 cleanup.php 等价
//!   POST body: { level: 'all' | 'cache' | 'logs' | 'orphans', keep_favorites, log_retention_days }
//!
//! Returns { ok, counts: { cache, logs, orphans, files } }
//!
//! Phase 3.2: cache + logs + orphans 实现
//! Phase 4: 'all' / 'rows' level (DB rows 清理, 复杂, 暂 stub)

use std::collections::HashSet;
use std::path::Path;
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
    let _keep_favorites = body.get("keep_favorites").and_then(|v| v.as_bool()).unwrap_or(true);
    let log_retention_days: i64 = body.get("log_retention_days").and_then(|v| v.as_i64()).unwrap_or(7).max(1);

    let mut counts = json!({"cache": 0, "logs": 0, "orphans": 0, "files": 0, "rows": 0});

    if level == "all" || level == "cache" {
        let n = clean_dir_contents(&state.paths.cache);
        counts["cache"] = json!(n);
        counts["files"] = json!(counts["files"].as_i64().unwrap_or(0) + n);
    }
    if level == "all" || level == "logs" {
        let n = clean_logs(&state.paths.logs, log_retention_days);
        counts["logs"] = json!(n);
        counts["files"] = json!(counts["files"].as_i64().unwrap_or(0) + n);
    }
    if level == "all" || level == "orphans" {
        let (n, _orphans) = clean_orphans(&state)?;
        counts["orphans"] = json!(n);
        counts["files"] = json!(counts["files"].as_i64().unwrap_or(0) + n);
    }
    if level == "all" || level == "rows" {
        // Phase 4: delete generations rows (保留 favorite), 物理删 image/thumb
        counts["rows"] = json!(-1);  // 标记"暂未实现"
    }

    Ok(Json(json!({"ok": true, "level": level, "counts": counts})))
}

/// 清空目录内容(保留目录本身)
fn clean_dir_contents(dir: &Path) -> i64 {
    if !dir.is_dir() { return 0; }
    let mut n = 0i64;
    for entry in WalkDir::new(dir).min_depth(1).into_iter().filter_map(|e| e.ok()) {
        let p = entry.path();
        if p.is_file() {
            if std::fs::remove_file(p).is_ok() { n += 1; }
        } else if p.is_dir() {
            // 尝试删空目录(失败也没关系,可能不是空)
            let _ = std::fs::remove_dir(p);
        }
    }
    n
}

/// 删 N 天前的日志文件
fn clean_logs(log_dir: &Path, retention_days: i64) -> i64 {
    if !log_dir.is_dir() { return 0; }
    let cutoff = SystemTime::now() - Duration::from_secs(retention_days as u64 * 86400);
    let mut n = 0i64;
    for entry in WalkDir::new(log_dir).min_depth(1).into_iter().filter_map(|e| e.ok()) {
        let p = entry.path();
        if p.is_file() {
            if let Ok(meta) = p.metadata() {
                if let Ok(mtime) = meta.modified() {
                    if mtime < cutoff {
                        if std::fs::remove_file(p).is_ok() { n += 1; }
                    }
                }
            }
        }
    }
    n
}

/// 扫 images/thumbs,删 DB 没引用的孤儿
fn clean_orphans(state: &SharedState) -> AppResult<(i64, Vec<String>)> {
    // 1. 读 DB 引用
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

    // 2. 扫目录,收集 DB 没引用的文件(归一化路径比较)
    let mut orphans: Vec<String> = Vec::new();
    for dir in [&state.paths.images, &state.paths.thumbs] {
        if !dir.is_dir() { continue; }
        for entry in WalkDir::new(dir).min_depth(1).into_iter().filter_map(|e| e.ok()) {
            let p = entry.path();
            if !p.is_file() { continue; }
            // 把 / 换成 \ (Windows),并把绝对路径归一化
            let rel = p.strip_prefix(&state.paths.storage).unwrap_or(p);
            let rel_str = rel.to_string_lossy().replace('\\', "/");
            let rel_with_slash = format!("/{}", rel_str.trim_start_matches('/'));
            if !referenced.contains(&rel_with_slash) {
                orphans.push(p.to_string_lossy().to_string());
            }
        }
    }

    let n = orphans.len() as i64;
    for f in &orphans {
        let _ = std::fs::remove_file(f);
    }
    Ok((n, orphans))
}

// 防止 SystemTime warning
#[allow(dead_code)]
fn _now() -> i64 {
    SystemTime::now().duration_since(UNIX_EPOCH).unwrap_or_default().as_secs() as i64
}
