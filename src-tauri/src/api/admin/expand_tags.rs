//! /api/admin/expand-tags -- 批量扩充本地标签库
//!
//! 跟 NAI Studio PHP 项目 admin/expand-tags.php 等价
//!   GET  /api/admin/expand-tags        -> 状态
//!   POST /api/admin/expand-tags        -> 启动 (body: {min_posts, max_pages, with_images})
//!   DELETE /api/admin/expand-tags      -> 停止
//!
//! 行为:
//!   - 拉 Danbooru tags (按 post_count 排序,默认 min_posts=100 过滤低频)
//!   - 翻译链: 内置字典 (秒回) -> DB 已有 cn_name -> MyMemory 公开 API (限速)
//!   - 预下载每个 tag 的 top 1 示例图到 storage/tag-previews/{hash}/

use std::collections::HashMap;
use std::path::PathBuf;
use std::sync::Arc;
use std::sync::atomic::{AtomicBool, Ordering};
use std::time::Duration;

use axum::Json;
use axum::extract::State;
use chrono::Utc;
use reqwest::Client;
use rusqlite::params;
use serde_json::{Value, json};
use tokio::time::sleep;

use super::{JobState, get_or_create, is_cancelled, update};
use crate::api::SharedState;
use crate::error::AppResult;

const NAME: &str = "expand_tags";
const DANBOORU_BASE: &str = "https://danbooru.donmai.us";
const MYMEMORY_URL: &str = "https://api.mymemory.translated.net/get";

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
    let min_posts: i64 = body.get("min_posts").and_then(|v| v.as_i64()).unwrap_or(100).max(1);
    let max_pages: i64 = body.get("max_pages").and_then(|v| v.as_i64()).unwrap_or(20).clamp(1, 200);
    let with_images: bool = body.get("with_images").and_then(|v| v.as_bool()).unwrap_or(true);

    let _ = j.cancel.store(false, Ordering::Relaxed);
    update(NAME, |j| {
        j.status = "running".to_string();
        j.started_at = Some(std::time::Instant::now());
        j.finished_at = None;
        j.done = 0;
        j.total = max_pages * 1000;
        j.message = format!("启动：min_posts={} max_pages={} with_images={}", min_posts, max_pages, with_images);
    });
    let cancel = j.cancel.clone();
    tokio::spawn(async move {
        run_worker(state, min_posts, max_pages, with_images, cancel).await;
    });
    Ok(Json(json!({"ok": true, "message": "已启动"})))
}

pub async fn stop(State(_state): State<SharedState>) -> AppResult<Json<Value>> {
    let j = get_or_create(NAME);
    j.cancel.store(true, Ordering::Relaxed);
    update(NAME, |j| {
        j.status = "stopped".to_string();
        j.message = "已请求停止".to_string();
        j.finished_at = Some(std::time::Instant::now());
    });
    Ok(Json(json!({"ok": true, "message": "已停止"})))
}

async fn run_worker(
    state: SharedState,
    min_posts: i64,
    max_pages: i64,
    with_images: bool,
    cancel: Arc<AtomicBool>,
) {
    // 1. 已处理列表 (跨进程跳过) — 写到 cache 文件
    let done_path = state.paths.cache.join("expand-done.txt");
    let _ = std::fs::create_dir_all(&state.paths.cache);
    let mut done: HashMap<String, ()> = HashMap::new();
    if let Ok(s) = std::fs::read_to_string(&done_path) {
        for line in s.lines() {
            done.insert(line.trim().to_string(), ());
        }
    }
    let mut done_file = std::fs::OpenOptions::new()
        .create(true).append(true).open(&done_path).ok();

    // 2. 分类映射 (Danbooru category index 0/1/3/4/5 -> DB tag_categories.id)
    let cat_map: HashMap<String, i64> = {
        let conn = state.db.lock();
        let mut stmt = match conn.prepare("SELECT slug, id FROM tag_categories") {
            Ok(s) => s,
            Err(e) => {
                update(NAME, |j| { j.status = "error".into(); j.message = format!("DB: {}", e); j.finished_at = Some(std::time::Instant::now()); });
                return;
            }
        };
        let rows = stmt.query_map([], |r| Ok((r.get::<_, String>(0)?, r.get::<_, i64>(1)?))).ok();
        let map: HashMap<String, i64> = rows.map(|rs| rs.filter_map(|r| r.ok()).collect()).unwrap_or_default();
        map
    };
    let default_cat = cat_map.get("general").copied().unwrap_or(1);

    // 3. HTTP client (含代理)
    let client = match state.proxy_url() {
        Some(p) => {
            let proxy = reqwest::Proxy::all(&p).ok();
            match proxy {
                Some(pr) => reqwest::Client::builder()
                    .timeout(Duration::from_secs(30))
                    .user_agent("nai-studio-desktop/2.0 (expand-tags)")
                    .proxy(pr)
                    .build(),
                None => reqwest::Client::builder()
                    .timeout(Duration::from_secs(30))
                    .user_agent("nai-studio-desktop/2.0 (expand-tags)")
                    .build(),
            }
        }
        None => reqwest::Client::builder()
            .timeout(Duration::from_secs(30))
            .user_agent("nai-studio-desktop/2.0 (expand-tags)")
            .build(),
    };
    let client = match client {
        Ok(c) => c,
        Err(e) => {
            update(NAME, |j| { j.status = "error".into(); j.message = format!("reqwest: {}", e); j.finished_at = Some(std::time::Instant::now()); });
            return;
        }
    };

    let mut page = 1i64;

    'pages: while page <= max_pages {
        if cancel.load(Ordering::Relaxed) || is_cancelled(NAME) { break; }

        update(NAME, |j| { j.message = format!("拉第 {}/{} 页...", page, max_pages); j.current_page = page; j.pages_total = max_pages; });

        // 拉这一页
        let url = format!(
            "{}/tags.json?limit=1000&page={}&search[order]=count",
            DANBOORU_BASE, page
        );
        let resp = match client.get(&url).send().await {
            Ok(r) => r,
            Err(e) => {
                update(NAME, |j| { j.errors += 1; j.last_error = format!("page {} net: {}", page, e); j.message = format!("第 {} 页网络错误: {}", page, e); });
                sleep(Duration::from_secs(2)).await;
                page += 1;
                continue;
            }
        };
        if !resp.status().is_success() {
            update(NAME, |j| { j.errors += 1; j.last_error = format!("page {} HTTP {}", page, resp.status().as_u16()); j.message = format!("第 {} 页 HTTP {}", page, resp.status().as_u16()); });
            sleep(Duration::from_secs(2)).await;
            page += 1;
            continue;
        }
        let tags: Vec<Value> = resp.json().await.unwrap_or_default();
        if tags.is_empty() {
            update(NAME, |j| { j.message = format!("第 {} 页空数据, 结束", page); });
            break;
        }

        for t in &tags {
            if cancel.load(Ordering::Relaxed) || is_cancelled(NAME) { break 'pages; }

            let name = t.get("name").and_then(|v| v.as_str()).unwrap_or("").to_string();
            if name.is_empty() || name.len() > 128 { update(NAME, |j| { j.skipped += 1; }); continue; }
            let post_count = t.get("post_count").and_then(|v| v.as_i64()).unwrap_or(0);
            if post_count < min_posts { update(NAME, |j| { j.skipped += 1; }); continue; }
            if done.contains_key(&name) { update(NAME, |j| { j.skipped += 1; }); continue; }

            update(NAME, |j| {
                j.done += 1;
                j.current_tag = name.clone();
                j.message = format!("处理 {} ({} post)", name, post_count);
            });

            // 翻译: 内置字典 -> DB 已有 -> MyMemory
            let cn_name: Option<String> = if let Some(cn) = super::super::decompose::builtin_dict_pub(&name) {
                Some(cn.to_string())
            } else {
                let from_db = {
                    let conn = state.db.lock();
                    conn.query_row(
                        "SELECT cn_name FROM tags WHERE name = ?1",
                        params![name],
                        |r| r.get::<_, Option<String>>(0)
                    ).ok().flatten()
                };
                match from_db {
                    Some(cn) if !cn.is_empty() => Some(cn),
                    _ => {
                        sleep(Duration::from_millis(120)).await;  // 限速
                        if let Some(cn) = translate_en_to_zh(&client, &name).await {
                            update(NAME, |j| { j.translated += 1; });
                            Some(cn)
                        } else {
                            None
                        }
                    }
                }
            };

            // 分类映射
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

            // 入库
            let now = Utc::now().to_rfc3339();
            let result = {
                let conn = state.db.lock();
                conn.execute(
                    "INSERT INTO tags (name, category_id, cn_name, post_count, is_nsfw, created_at)
                     VALUES (?1, ?2, ?3, ?4, ?5, ?6)
                     ON CONFLICT(name) DO UPDATE SET
                        category_id = excluded.category_id,
                        cn_name = COALESCE(NULLIF(tags.cn_name, ''), excluded.cn_name),
                        post_count = excluded.post_count,
                        is_nsfw = excluded.is_nsfw",
                    params![name, cat_id, cn_name, post_count, is_nsfw, now],
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

            // 预下载示例图
            if with_images && post_count > 0 {
                if let Some(rel_url) = download_example_image(&state, &client, &name).await {
                    let conn = state.db.lock();
                    let _ = conn.execute(
                        "UPDATE tags SET example_image_url = ?1 WHERE name = ?2",
                        params![rel_url, name],
                    );
                    update(NAME, |j| { j.images += 1; });
                }
                sleep(Duration::from_millis(60)).await;  // 限速
            }
        }
        page += 1;
        sleep(Duration::from_millis(200)).await;  // 页间间隔
    }

    let final_status = if cancel.load(Ordering::Relaxed) || is_cancelled(NAME) { "stopped" } else { "done" };
    update(NAME, |j| {
        j.status = final_status.to_string();
        j.message = format!("完成: 新增 {} 翻译 {} 图 {} 跳过 {} 错 {}",
            j.added, j.translated, j.images, j.skipped, j.errors);
        j.finished_at = Some(std::time::Instant::now());
    });
    let _ = cancel;
}

/// MyMemory en -> zh-CN
async fn translate_en_to_zh(client: &Client, name: &str) -> Option<String> {
    let url = format!("{}?q={}&langpair=en|zh-CN", MYMEMORY_URL, urlencoded(name));
    let resp = client.get(&url).send().await.ok()?;
    if !resp.status().is_success() { return None; }
    let data: Value = resp.json().await.ok()?;
    let translated = data.get("responseData")
        .and_then(|rd| rd.get("translatedText"))
        .and_then(|t| t.as_str())
        .map(|s| s.trim().to_string())?;
    if translated.is_empty() { return None; }
    if translated.to_lowercase() == name.to_lowercase() { return None; }
    // MyMemory 失败时常返 "PLEASE SELECT TWO DISTINCT LANGUAGES" 等
    if translated.contains("PLEASE SELECT") || translated.contains("WARNING") { return None; }
    Some(translated.chars().take(128).collect())
}

async fn download_example_image(
    state: &SharedState,
    client: &Client,
    tag: &str,
) -> Option<String> {
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
        if meta.len() > 1000 { return Some(rel_url); }
    }

    // 拉 1 个随机 post
    let url = format!("{}/posts.json?tags={}&limit=1&random=true", DANBOORU_BASE, urlencoded(tag));
    let resp = client.get(&url).send().await.ok()?;
    if !resp.status().is_success() { return None; }
    let posts: Vec<Value> = resp.json().await.ok()?;
    let preview_url = posts.first()
        .and_then(|p| p.get("preview_file_url"))
        .and_then(|v| v.as_str())?;
    if preview_url.is_empty() { return None; }

    // 下载
    let img = client.get(preview_url).send().await.ok()?;
    if !img.status().is_success() { return None; }
    let bytes = img.bytes().await.ok()?;
    if bytes.len() < 500 { return None; }
    if std::fs::write(&fname, &bytes).is_err() { return None; }
    Some(rel_url)
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

#[allow(dead_code)]
fn _phantom(_: JobState) {}
