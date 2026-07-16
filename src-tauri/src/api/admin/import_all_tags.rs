//! /api/admin/import-all-tags -- 后台导入 Danbooru 全部标签
//!
//! 跟 NAI Studio PHP 项目 admin/import-all-tags.php 等价
//!   GET  /api/admin/import-all-tags   -> 状态
//!   POST /api/admin/import-all-tags   -> 启动 (body: {min_posts, max_pages})
//!   DELETE /api/admin/import-all-tags -> 停止
//!
//! 行为:
//!   - 拉全量 Danbooru tag (默认 500 页 × 1000 = 50 万 tag 上限)
//!   - 翻译: 内置字典 (~500+) 秒回; miss 留英文 (MyMemory 免费额度翻译 30 万 tag 不现实)
//!   - 示例图: 不下 (拉图占 90% 时间,用户可在 expand-tags 里专门下)

use std::collections::HashMap;
use std::path::PathBuf;
use std::sync::Arc;
use std::sync::atomic::{AtomicBool, Ordering};
use std::time::{Duration, Instant};

use axum::Json;
use axum::extract::State;
use reqwest::Client;
use rusqlite::params;
use serde_json::{Value, json};
use tokio::time::sleep;

use super::{get_or_create, is_cancelled, tick, update};
use crate::api::SharedState;
use crate::error::AppResult;

const NAME: &str = "import_all_tags";
const DANBOORU_BASE: &str = "https://danbooru.donmai.us";

pub async fn status(State(_state): State<SharedState>) -> AppResult<Json<Value>> {
    let j = get_or_create(NAME);
    Ok(Json(j.to_json()))
}

pub async fn start(
    State(state): State<SharedState>,
    Json(body): Json<Value>,
) -> AppResult<Json<Value>> {
    let j = get_or_create(NAME);
    if j.status == "running" {
        return Ok(Json(json!({"ok": false, "error": "已经在运行"})));
    }
    let min_posts: i64 = body.get("min_posts").and_then(|v| v.as_i64()).unwrap_or(1).max(1);
    let max_pages: i64 = body.get("max_pages").and_then(|v| v.as_i64()).unwrap_or(500).clamp(1, 2000);
    let page_delay_ms: u64 = body.get("page_delay_ms").and_then(|v| v.as_u64()).unwrap_or(600);

    let _ = j.cancel.store(false, Ordering::Relaxed);
    update(NAME, |j| {
        j.status = "running".to_string();
        j.started_at = Some(Instant::now());
        j.finished_at = None;
        j.done = 0;
        j.total = 0;
        j.added = 0;
        j.translated = 0;
        j.images = 0;
        j.skipped = 0;
        j.errors = 0;
        j.current_tag = String::new();
        j.current_page = 0;
        j.pages_total = max_pages;
        j.last_error = String::new();
        j.rate_per_sec = 0.0;
        j.message = format!("启动: min_posts={} max_pages={}", min_posts, max_pages);
    });
    let cancel = j.cancel.clone();
    tokio::spawn(async move {
        run_worker(state, min_posts, max_pages, page_delay_ms, cancel).await;
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
    min_posts: i64,
    max_pages: i64,
    page_delay_ms: u64,
    cancel: Arc<AtomicBool>,
) {
    // 已处理列表 (跨进程跳过)
    let done_path = state.paths.cache.join("import-done.txt");
    let _ = std::fs::create_dir_all(&state.paths.cache);
    let mut done: HashMap<String, ()> = HashMap::new();
    if let Ok(s) = std::fs::read_to_string(&done_path) {
        for line in s.lines() {
            done.insert(line.trim().to_string(), ());
        }
    }
    let mut done_file = std::fs::OpenOptions::new()
        .create(true).append(true).open(&done_path).ok();

    // 分类映射
    let cat_map: HashMap<String, i64> = {
        let conn = state.db.lock();
        let mut stmt = match conn.prepare("SELECT slug, id FROM tag_categories") {
            Ok(s) => s,
            Err(e) => {
                update(NAME, |j| { j.status = "error".into(); j.message = format!("DB: {}", e); j.finished_at = Some(Instant::now()); });
                return;
            }
        };
        let rows = stmt.query_map([], |r| Ok((r.get::<_, String>(0)?, r.get::<_, i64>(1)?))).ok();
        rows.map(|rs| rs.filter_map(|r| r.ok()).collect()).unwrap_or_default()
    };
    let default_cat = cat_map.get("general").copied().unwrap_or(1);

    // HTTP client
    let client = match build_client(&state) {
        Ok(c) => c,
        Err(e) => {
            update(NAME, |j| { j.status = "error".into(); j.message = format!("reqwest: {}", e); j.finished_at = Some(Instant::now()); });
            return;
        }
    };

    let start_time = Instant::now();
    let mut total_processed = 0i64;

    for page in 1..=max_pages {
        if cancel.load(Ordering::Relaxed) || is_cancelled(NAME) { break; }
        update(NAME, |j| { j.current_page = page; j.message = format!("拉第 {}/{} 页", page, max_pages); });

        // 拉这一页
        let url = format!("{}/tags.json?limit=1000&page={}", DANBOORU_BASE, page);
        let resp = match client.get(&url).send().await {
            Ok(r) => r,
            Err(e) => {
                update(NAME, |j| { j.errors += 1; j.last_error = format!("page {} net: {}", page, e); });
                sleep(Duration::from_millis(page_delay_ms)).await;
                continue;
            }
        };
        if !resp.status().is_success() {
            update(NAME, |j| { j.errors += 1; j.last_error = format!("page {} HTTP {}", page, resp.status().as_u16()); });
            sleep(Duration::from_millis(page_delay_ms)).await;
            continue;
        }
        let tags: Vec<Value> = resp.json().await.unwrap_or_default();
        if tags.is_empty() {
            update(NAME, |j| { j.message = format!("第 {} 页空数据, 结束", page); });
            break;
        }

        for t in &tags {
            if cancel.load(Ordering::Relaxed) || is_cancelled(NAME) {
                update(NAME, |j| { j.status = "stopped".into(); j.message = format!("已停止 (已处理 {})", total_processed); j.finished_at = Some(Instant::now()); });
                return;
            }

            let name = t.get("name").and_then(|v| v.as_str()).unwrap_or("").to_string();
            if name.is_empty() || name.len() > 128 { update(NAME, |j| { j.skipped += 1; }); continue; }

            let post_count = t.get("post_count").and_then(|v| v.as_i64()).unwrap_or(0);
            if post_count < min_posts { update(NAME, |j| { j.skipped += 1; }); continue; }
            if done.contains_key(&name) { update(NAME, |j| { j.skipped += 1; }); continue; }

            // 翻译
            let cn_name = super::super::decompose::builtin_dict_pub(&name).map(|s| s.to_string());
            if cn_name.is_some() {
                update(NAME, |j| { j.translated += 1; });
            }

            // 分类
            let cat_idx = t.get("category").and_then(|v| v.as_i64()).unwrap_or(0);
            let cat_slug = match cat_idx {
                1 => "artist",
                3 => "copyright",
                4 => "character",
                5 => "meta",
                _ => "general",
            };
            let cat_id = cat_map.get(cat_slug).copied().unwrap_or(default_cat);
            let is_nsfw = if t.get("is_deprecated").and_then(|v| v.as_bool()).unwrap_or(false) { 1 } else { 0 };

            // upsert
            let result = {
                let conn = state.db.lock();
                conn.execute(
                    "INSERT INTO tags (name, category_id, cn_name, post_count, is_nsfw, created_at)
                     VALUES (?1, ?2, ?3, ?4, ?5, CURRENT_TIMESTAMP)
                     ON CONFLICT(name) DO UPDATE SET
                        category_id = excluded.category_id,
                        cn_name = COALESCE(NULLIF(tags.cn_name, ''), excluded.cn_name),
                        post_count = excluded.post_count,
                        is_nsfw = excluded.is_nsfw",
                    params![name, cat_id, cn_name, post_count, is_nsfw],
                )
            };
            if result.is_err() {
                update(NAME, |j| { j.errors += 1; });
            } else {
                update(NAME, |j| { j.added += 1; });
                if let Some(ref mut f) = done_file {
                    use std::io::Write;
                    let _ = writeln!(f, "{}", name);
                }
                done.insert(name.clone(), ());
            }
            total_processed += 1;
            update(NAME, |j| {
                j.fetched = total_processed;
                j.current_tag = name.clone();
                j.done = total_processed;
                let secs = start_time.elapsed().as_secs_f64();
                j.rate_per_sec = if secs > 0.0 { total_processed as f64 / secs } else { 0.0 };
            });
        }

        sleep(Duration::from_millis(page_delay_ms)).await;
    }

    let final_status = if cancel.load(Ordering::Relaxed) || is_cancelled(NAME) { "stopped" } else { "done" };
    update(NAME, |j| {
        j.status = final_status.to_string();
        j.message = format!("完成: 新增 {} 翻译 {} 跳过 {} 错 {}",
            j.added, j.translated, j.skipped, j.errors);
        j.finished_at = Some(Instant::now());
    });
    let _ = tick(NAME, 0, 0);  // suppress unused
    let _ = cancel;
    let _ = PathBuf::new();  // suppress unused
}

fn build_client(state: &SharedState) -> Result<Client, String> {
    let mut builder = reqwest::Client::builder()
        .timeout(Duration::from_secs(30))
        .user_agent("nai-studio-desktop/2.0 (import-all-tags)");
    if let Some(p) = state.proxy_url() {
        builder = builder.proxy(reqwest::Proxy::all(&p).map_err(|e| format!("proxy: {}", e))?);
    }
    builder.build().map_err(|e| e.to_string())
}
