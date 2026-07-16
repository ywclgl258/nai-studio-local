//! /api/admin/fetch_all_images -- 抓 NAI 历史作品 / 抓 Danbooru 标签示例图
//!
//! 跟 NAI Studio PHP 项目 admin/fetch_all_images.php 等价 (Danbooru 标签示例图抓取版)
//!   GET  /api/admin/fetch_all_images?action=stats -> 覆盖率统计
//!   GET  /api/admin/fetch_all_images             -> 状态
//!   POST /api/admin/fetch_all_images             -> 启动 (body: {limit})
//!   DELETE /api/admin/fetch_all_images           -> 停止
//!
//! 行为:
//!   - 查 tags 表中 example_image_url 为空 的 N 条 (按 post_count DESC)
//!   - 对每条 tag 拉 1 个随机 Danbooru post, 下载 preview_file_url
//!   - 存到 storage/tag-previews/{hash}/{safe_name}.jpg
//!   - 更新 tags.example_image_url

use std::path::PathBuf;
use std::sync::Arc;
use std::sync::atomic::{AtomicBool, Ordering};
use std::time::{Duration, Instant};

use axum::extract::{Query, State};
use axum::http::StatusCode;
use axum::response::{IntoResponse, Response};
use axum::Json;
use reqwest::Client;
use rusqlite::params;
use serde_json::{Value, json};
use std::collections::HashMap;
use tokio::time::sleep;

use super::{get_or_create, is_cancelled, update};
use crate::api::SharedState;
use crate::error::AppResult;

const NAME: &str = "fetch_all_images";
const DANBOORU_BASE: &str = "https://danbooru.donmai.us";

/// GET 状态 (默认)
pub async fn status(State(_state): State<SharedState>) -> AppResult<Json<Value>> {
    let j = get_or_create(NAME);
    Ok(Json(j.to_json()))
}

/// GET ?action=stats -> 覆盖率
pub async fn stats(State(state): State<SharedState>) -> AppResult<Json<Value>> {
    let conn = state.db.lock();
    let total: i64 = conn.query_row("SELECT COUNT(*) FROM tags", [], |r| r.get(0)).unwrap_or(0);
    let have: i64 = conn.query_row(
        "SELECT COUNT(*) FROM tags WHERE example_image_url IS NOT NULL AND example_image_url <> ''",
        [], |r| r.get(0)
    ).unwrap_or(0);
    let missing = total - have;
    let coverage = if total > 0 { round2(have as f64 * 100.0 / total as f64) } else { 0.0 };
    Ok(Json(json!({
        "ok": true,
        "total": total,
        "have": have,
        "missing": missing,
        "coverage": coverage,
    })))
}

fn round2(x: f64) -> f64 { (x * 10.0).round() / 10.0 }

/// 兼容 GET?action=stats 的复合入口
pub async fn status_or_stats(
    State(state): State<SharedState>,
    Query(params): Query<HashMap<String, String>>,
) -> Response {
    let action = params.get("action").map(|s| s.as_str()).unwrap_or("");
    if action == "stats" {
        match stats(State(state)).await {
            Ok(j) => j.into_response(),
            Err(e) => (StatusCode::INTERNAL_SERVER_ERROR, Json(json!({"ok": false, "error": e.to_string()}))).into_response(),
        }
    } else {
        match status(State(state)).await {
            Ok(j) => j.into_response(),
            Err(e) => (StatusCode::INTERNAL_SERVER_ERROR, Json(json!({"ok": false, "error": e.to_string()}))).into_response(),
        }
    }
}

pub async fn start(
    State(state): State<SharedState>,
    Json(body): Json<Value>,
) -> AppResult<Json<Value>> {
    let j = get_or_create(NAME);
    if j.status == "running" {
        return Ok(Json(json!({"ok": false, "error": "已经在运行"})));
    }
    let limit: i64 = body.get("limit").and_then(|v| v.as_i64()).unwrap_or(500).clamp(1, 50000);

    let _ = j.cancel.store(false, Ordering::Relaxed);
    update(NAME, |j| {
        j.status = "running".to_string();
        j.started_at = Some(Instant::now());
        j.finished_at = None;
        j.done = 0;
        j.total = limit;
        j.added = 0;
        j.translated = 0;
        j.images = 0;
        j.skipped = 0;
        j.errors = 0;
        j.current_tag = String::new();
        j.message = format!("启动: limit={}", limit);
    });
    let cancel = j.cancel.clone();
    tokio::spawn(async move {
        run_worker(state, limit, cancel).await;
    });
    Ok(Json(json!({"ok": true, "message": "已启动"})))
}

pub async fn stop(State(_state): State<SharedState>) -> AppResult<Json<Value>> {
    let j = get_or_create(NAME);
    j.cancel.store(true, Ordering::Relaxed);
    update(NAME, |j| {
        j.status = "stopped".to_string();
        j.message = "已请求停止".to_string();
        j.finished_at = Some(Instant::now());
    });
    Ok(Json(json!({"ok": true, "message": "已停止"})))
}

async fn run_worker(
    state: SharedState,
    limit: i64,
    cancel: Arc<AtomicBool>,
) {
    // 1. 找待抓 tag
    let targets: Vec<(i64, String)> = {
        let conn = state.db.lock();
        let mut stmt = match conn.prepare(
            "SELECT id, name FROM tags
             WHERE example_image_url IS NULL OR example_image_url = ''
             ORDER BY post_count DESC LIMIT ?1"
        ) {
            Ok(s) => s,
            Err(e) => {
                update(NAME, |j| { j.status = "error".into(); j.message = format!("DB: {}", e); j.finished_at = Some(Instant::now()); });
                return;
            }
        };
        stmt.query_map(params![limit], |r| Ok((r.get::<_, i64>(0)?, r.get::<_, String>(1)?)))
            .ok()
            .map(|rs| rs.filter_map(|r| r.ok()).collect())
            .unwrap_or_default()
    };
    let total = targets.len() as i64;
    update(NAME, |j| { j.total = total; j.message = format!("共 {} 个待抓", total); });

    if total == 0 {
        update(NAME, |j| { j.status = "done".into(); j.message = "没有待抓的 tag".into(); j.finished_at = Some(Instant::now()); });
        return;
    }

    let client = match build_client(&state) {
        Ok(c) => c,
        Err(e) => {
            update(NAME, |j| { j.status = "error".into(); j.message = format!("reqwest: {}", e); j.finished_at = Some(Instant::now()); });
            return;
        }
    };

    let start_time = Instant::now();
    let mut ok = 0i64;
    let mut fail = 0i64;
    let mut no_posts = 0i64;
    let mut skip = 0i64;

    for (idx, (id, name)) in targets.iter().enumerate() {
        if cancel.load(Ordering::Relaxed) || is_cancelled(NAME) {
            update(NAME, |j| { j.status = "stopped".into(); j.message = format!("已停止 ({}/{})", idx, total); j.finished_at = Some(Instant::now()); });
            return;
        }
        let i = idx as i64 + 1;
        update(NAME, |j| {
            j.done = i;
            j.current_tag = name.clone();
            j.message = format!("{}/{} {}", i, total, name);
        });

        let result = fetch_one(&state, &client, id, name).await;
        match result {
            FetchResult::Ok => {
                ok += 1;
                update(NAME, |j| { j.added += 1; j.images += 1; });
            }
            FetchResult::Skip => {
                skip += 1;
                update(NAME, |j| { j.skipped += 1; });
            }
            FetchResult::NoPosts => {
                no_posts += 1;
                update(NAME, |j| { j.skipped += 1; });
            }
            FetchResult::Fail(reason) => {
                fail += 1;
                update(NAME, |j| { j.errors += 1; j.last_error = reason; });
            }
        }
        // 限速 (~40/s, 对 Danbooru 友好)
        sleep(Duration::from_millis(25)).await;
    }

    let elapsed = start_time.elapsed().as_secs();
    let final_status = if cancel.load(Ordering::Relaxed) || is_cancelled(NAME) { "stopped" } else { "done" };
    update(NAME, |j| {
        j.status = final_status.to_string();
        j.message = format!("完成: ok={} skip={} noPosts={} fail={} 用时 {}s",
            ok, skip, no_posts, fail, elapsed);
        j.finished_at = Some(Instant::now());
    });
    let _ = cancel;
}

enum FetchResult { Ok, Skip, NoPosts, Fail(String) }

async fn fetch_one(state: &SharedState, client: &Client, id: &i64, tag: &str) -> FetchResult {
    let hash = {
        let mut h: u32 = 5381;
        for b in tag.bytes() { h = h.wrapping_mul(33).wrapping_add(b as u32); }
        format!("{:02x}", h & 0xFF)
    };
    let safe_name: String = tag.chars()
        .map(|c| if c.is_ascii_alphanumeric() || c == '_' { c } else { '_' })
        .collect();
    let subdir: PathBuf = state.paths.tag_previews.join(&hash);
    let _ = std::fs::create_dir_all(&subdir);
    let fname = subdir.join(format!("{}.jpg", safe_name));
    let rel_url = format!("/storage/tag-previews/{}/{}.jpg", hash, safe_name);

    // 本地已有
    if let Ok(meta) = std::fs::metadata(&fname) {
        if meta.len() > 1000 {
            let conn = state.db.lock();
            let _ = conn.execute(
                "UPDATE tags SET example_image_url = ?1, fetched_at = CURRENT_TIMESTAMP WHERE id = ?2",
                params![rel_url, id],
            );
            return FetchResult::Skip;
        }
    }

    // 拉 1 个随机 post
    let url = format!("{}/posts.json?tags={}&limit=1&random=true", DANBOORU_BASE, urlencoded(tag));
    let resp = match client.get(&url).send().await {
        Ok(r) => r,
        Err(e) => return FetchResult::Fail(format!("network: {}", e)),
    };
    if !resp.status().is_success() {
        return FetchResult::Fail(format!("HTTP {}", resp.status().as_u16()));
    }
    let posts: Vec<Value> = resp.json().await.unwrap_or_default();
    if posts.is_empty() {
        return FetchResult::NoPosts;
    }
    let preview_url = match posts[0].get("preview_file_url").and_then(|v| v.as_str()) {
        Some(u) if !u.is_empty() => u,
        _ => return FetchResult::Fail("no preview_file_url".into()),
    };

    // 下载
    let img = match client.get(preview_url).send().await {
        Ok(r) => r,
        Err(e) => return FetchResult::Fail(format!("img network: {}", e)),
    };
    if !img.status().is_success() {
        return FetchResult::Fail(format!("img HTTP {}", img.status().as_u16()));
    }
    let bytes = match img.bytes().await {
        Ok(b) => b,
        Err(e) => return FetchResult::Fail(format!("img read: {}", e)),
    };
    if bytes.len() < 500 {
        return FetchResult::Fail("img too small".into());
    }
    if std::fs::write(&fname, &bytes).is_err() {
        return FetchResult::Fail("write fail".into());
    }
    let conn = state.db.lock();
    let _ = conn.execute(
        "UPDATE tags SET example_image_url = ?1, fetched_at = CURRENT_TIMESTAMP WHERE id = ?2",
        params![rel_url, id],
    );
    FetchResult::Ok
}

fn build_client(state: &SharedState) -> Result<Client, String> {
    let mut builder = reqwest::Client::builder()
        .timeout(Duration::from_secs(30))
        .user_agent("nai-studio-desktop/2.0 (fetch-tag-images)");
    if let Some(p) = state.proxy_url() {
        builder = builder.proxy(reqwest::Proxy::all(&p).map_err(|e| format!("proxy: {}", e))?);
    }
    builder.build().map_err(|e| e.to_string())
}

fn urlencoded(s: &str) -> String {
    s.chars().map(|c| {
        if c.is_ascii_alphanumeric() || c == '-' || c == '_' || c == '.' || c == '~' {
            c.to_string()
        } else {
            format!("%{:02X}", c as u8)
        }
    }).collect()
}
