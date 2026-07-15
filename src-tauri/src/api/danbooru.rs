//! /api/danbooru -- Danbooru tag 搜索 + 翻译
//!
//! 跟 NAI Studio PHP 项目 danbooru.php 等价
//!   GET ?action=tag&q=foo&limit=N        -> 搜索 tag
//!   GET ?action=post&q=foo&limit=N       -> 搜索 post(示例图)
//!   GET ?action=translate&q=foo          -> 单 tag 翻译
//!
//! 翻译链: 内置字典 -> MyMemory API -> 缓存 30 天

use std::collections::HashMap;
use std::time::Duration;

use axum::Json;
use axum::extract::{Query, State};
use chrono::Utc;
use reqwest::Client;
use rusqlite::types::Value as SqlValue;
use serde_json::{Value, json};

use crate::api::SharedState;
use crate::error::AppResult;

const DANBOORU_BASE: &str = "https://danbooru.donmai.us";
const MYMEMORY_URL: &str = "https://api.mymemory.translated.net/get";

/// 内置字典(命中秒回)
fn builtin_dict(name: &str) -> Option<&'static str> {
    super::decompose::builtin_dict_pub(name)
}

pub async fn handle(
    State(state): State<SharedState>,
    Query(params): Query<HashMap<String, String>>,
) -> AppResult<Json<Value>> {
    let action = params.get("action").map(|s| s.as_str()).unwrap_or("tag");
    let q = params.get("q").map(|s| s.as_str()).unwrap_or("").trim().to_string();
    let limit: usize = params.get("limit").and_then(|s| s.parse().ok()).unwrap_or(50).clamp(1, 100);

    match action {
        "tag"       => tag_search(&state, &q, limit).await,
        "post"      => post_search(&state, &q, limit).await,
        "translate" => translate(&state, &q).await,
        _ => Ok(Json(json!({"ok": false, "error": format!("unknown action: {}", action)}))),
    }
}

/// 搜索 tag(本地 + 在线 + 翻译)
async fn tag_search(state: &SharedState, q: &str, limit: usize) -> AppResult<Json<Value>> {
    if q.is_empty() {
        return Ok(Json(json!({"ok": true, "rows": [], "source": "empty", "q": q})));
    }
    let lower = q.to_lowercase();

    // 1. 先查本地 cache
    let local_rows = {
        let conn = state.db.lock();
        let like = format!("%{}%", lower);
        let mut stmt = conn.prepare(
            "SELECT name, category, post_count, cn_name, example_image_url
             FROM danbooru_tag_cache
             WHERE name LIKE ?1 OR cn_name LIKE ?1
             ORDER BY post_count DESC LIMIT ?2"
        )?;
        let rows: Vec<Value> = stmt.query_map(rusqlite::params![like, limit as i64], |r| {
            let name: String = r.get(0)?;
            let category: i64 = r.get(1)?;
            let post_count: i64 = r.get(2)?;
            let cn_name: Option<String> = r.get(3)?;
            let example_url: Option<String> = r.get(4)?;
            let cn = builtin_dict(&name).map(|s| s.to_string()).or(cn_name);
            Ok(json!({
                "name": name,
                "category": category,
                "post_count": post_count,
                "cn_name": cn,
                "example_image_url": example_url,
                "source": "local_cache",
            }))
        })?.collect::<Result<Vec<_>, _>>()?;
        rows
    };
    if !local_rows.is_empty() {
        return Ok(Json(json!({
            "ok": true,
            "rows": local_rows,
            "source": "local_cache",
            "q": q,
        })));
    }

    // 2. 在线 Danbooru 搜索
    let client = Client::builder()
        .timeout(Duration::from_secs(20))
        .user_agent("nai-studio-desktop/1.0")
        .build()
        .map_err(|e| crate::error::AppError::Upstream(format!("reqwest: {}", e)))?;

    let url = format!(
        "{}/tags.json?search[name_matches]={}*&limit={}",
        DANBOORU_BASE,
        urlencoded(&lower),
        limit
    );
    let resp = client.get(&url).send().await;
    let data: Vec<Value> = match resp {
        Ok(r) if r.status().is_success() => r.json().await.unwrap_or_default(),
        _ => {
            return Ok(Json(json!({
                "ok": true,
                "rows": local_rows,  // 可能是空
                "source": "danbooru_offline",
                "q": q,
                "warning": "Danbooru 不可达，已返回本地 cache（空）",
            })));
        }
    };

    // 3. 排序 by post_count
    let mut data = data;
    data.sort_by(|a, b| {
        let ap = a.get("post_count").and_then(|v| v.as_i64()).unwrap_or(0);
        let bp = b.get("post_count").and_then(|v| v.as_i64()).unwrap_or(0);
        bp.cmp(&ap)
    });
    data.truncate(limit);

    // 4. 入库 + 翻译
    let mut rows_out: Vec<Value> = Vec::with_capacity(data.len());
    let mut translated_count = 0;
    for t in &data {
        let name = t.get("name").and_then(|v| v.as_str()).unwrap_or("").to_string();
        if name.is_empty() { continue; }
        let category = t.get("category").and_then(|v| v.as_i64()).unwrap_or(0);
        let post_count = t.get("post_count").and_then(|v| v.as_i64()).unwrap_or(0);

        // 写回 cache
        let now = Utc::now().to_rfc3339();
        {
            let conn = state.db.lock();
            let _ = conn.execute(
                "INSERT INTO danbooru_tag_cache (name, category, post_count, fetched_at)
                 VALUES (?1, ?2, ?3, ?4)
                 ON CONFLICT(name) DO UPDATE SET category=excluded.category, post_count=excluded.post_count, fetched_at=excluded.fetched_at",
                rusqlite::params![name, category, post_count, now],
            );
        }

        // 翻译
        let cn = if let Some(b) = builtin_dict(&name) {
            Some(b.to_string())
        } else {
            match translate_en_to_zh(&client, &name).await {
                Some(t) if !t.is_empty() => {
                    let cn = t.chars().take(128).collect::<String>();
                    let _ = {
                        let conn = state.db.lock();
                        conn.execute(
                            "UPDATE danbooru_tag_cache SET cn_name = ?1, translated_at = ?2 WHERE name = ?3",
                            rusqlite::params![cn, now, name]
                        )
                    };
                    translated_count += 1;
                    Some(cn)
                }
                _ => None,
            }
        };

        rows_out.push(json!({
            "name": name,
            "category": category,
            "post_count": post_count,
            "cn_name": cn,
            "example_image_url": Value::Null,
            "source": "danbooru",
        }));
    }

    Ok(Json(json!({
        "ok": true,
        "rows": rows_out,
        "source": "danbooru",
        "q": q,
        "translated": translated_count,
    })))
}

/// 搜索 post(示例图)
async fn post_search(state: &SharedState, q: &str, limit: usize) -> AppResult<Json<Value>> {
    if q.is_empty() {
        return Ok(Json(json!({"ok": false, "error": "q required"})));
    }
    let client = Client::builder()
        .timeout(Duration::from_secs(20))
        .user_agent("nai-studio-desktop/1.0")
        .build()
        .map_err(|e| crate::error::AppError::Upstream(format!("reqwest: {}", e)))?;
    let url = format!(
        "{}/posts.json?tags={}&limit={}&sf=random",
        DANBOORU_BASE,
        urlencoded(q),
        limit
    );
    match client.get(&url).send().await {
        Ok(r) if r.status().is_success() => {
            let data: Vec<Value> = r.json().await.unwrap_or_default();
            let rows: Vec<Value> = data.iter().map(|p| {
                let preview = format!("https://cdn.donmai.us/preview/{}",
                    p.get("preview_file_url").and_then(|v| v.as_str()).unwrap_or(""));
                let sample = format!("https://cdn.donmai.us/sample/{}",
                    p.get("sample_file_url").and_then(|v| v.as_str()).unwrap_or(""));
                json!({
                    "id": p.get("id").and_then(|v| v.as_i64()).unwrap_or(0),
                    "preview_url": preview,
                    "sample_url": sample,
                    "file_url": format!("https://danbooru.donmai.us/data/{}/{}",
                        p.get("directory").and_then(|v| v.as_str()).unwrap_or(""),
                        p.get("image").and_then(|v| v.as_str()).unwrap_or("")),
                    "width": p.get("image_width").and_then(|v| v.as_i64()).unwrap_or(0),
                    "height": p.get("image_height").and_then(|v| v.as_i64()).unwrap_or(0),
                    "tags": p.get("tag_string").and_then(|v| v.as_str()).unwrap_or(""),
                })
            }).collect();
            Ok(Json(json!({"ok": true, "rows": rows, "source": "danbooru", "q": q})))
        }
        _ => {
            // 降级：返回空 + 警告
            let _ = state;  // suppress
            Ok(Json(json!({
                "ok": true,
                "rows": [],
                "source": "offline",
                "q": q,
                "warning": "Danbooru 不可达"
            })))
        }
    }
}

/// 翻译:内置字典 -> MyMemory -> 30 天缓存
async fn translate(state: &SharedState, q: &str) -> AppResult<Json<Value>> {
    if q.is_empty() {
        return Ok(Json(json!({"ok": false, "error": "q required"})));
    }
    let lower = q.to_lowercase();
    // 内置字典
    if let Some(cn) = builtin_dict(&lower) {
        return Ok(Json(json!({
            "ok": true,
            "q": q,
            "cn": cn,
            "en": "",
            "source": "builtin",
        })));
    }
    // DB 缓存(30 天内有效)
    {
        let conn = state.db.lock();
        let row: Option<(Option<String>, Option<String>)> = conn.query_row(
            "SELECT cn_name, translated_at FROM danbooru_tag_cache WHERE name = ?1",
            [&lower],
            |r| Ok((r.get(0)?, r.get(1)?))
        ).ok();
        if let Some((Some(cn), Some(translated_at))) = row {
            // 简化：translated_at 字符串里有 "T"，能 parse 即可
            if let Ok(dt) = chrono::DateTime::parse_from_rfc3339(&translated_at) {
                let age = (Utc::now() - dt.with_timezone(&Utc)).num_days();
                if age < 30 && !cn.is_empty() {
                    return Ok(Json(json!({
                        "ok": true,
                        "q": q,
                        "cn": cn,
                        "en": "",
                        "source": "cache",
                        "cached": true,
                    })));
                }
            }
        }
    }
    // 在线 MyMemory
    let client = Client::builder()
        .timeout(Duration::from_secs(15))
        .build()
        .map_err(|e| crate::error::AppError::Upstream(format!("reqwest: {}", e)))?;
    let cn = translate_en_to_zh(&client, &lower).await;
    let cn_str = cn.clone().unwrap_or_default();
    if let Some(cn) = cn {
        // 写回缓存
        let now = Utc::now().to_rfc3339();
        let conn = state.db.lock();
        let _ = conn.execute(
            "INSERT INTO danbooru_tag_cache (name, cn_name, translated_at) VALUES (?1, ?2, ?3)
             ON CONFLICT(name) DO UPDATE SET cn_name=excluded.cn_name, translated_at=excluded.translated_at",
            rusqlite::params![lower, cn, now],
        );
    }
    Ok(Json(json!({
        "ok": true,
        "q": q,
        "cn": cn_str,
        "en": "",
        "source": if !cn_str.is_empty() { "mymemory" } else { "fail" },
    })))
}

/// MyMemory en->zh-CN
async fn translate_en_to_zh(client: &Client, name: &str) -> Option<String> {
    let url = format!(
        "{}?q={}&langpair=en|zh-CN",
        MYMEMORY_URL,
        urlencoded(name)
    );
    match client.get(&url).send().await {
        Ok(r) if r.status().is_success() => {
            let data: Value = r.json().await.ok()?;
            let translated = data.get("responseData")
                .and_then(|rd| rd.get("translatedText"))
                .and_then(|t| t.as_str())
                .map(|s| s.trim().to_string())?;
            if translated.is_empty() { return None; }
            // MyMemory 返回的可能是同输入(翻译失败),简单启发
            if translated.to_lowercase() == name.to_lowercase() { return None; }
            Some(translated)
        }
        _ => None,
    }
}

/// 简单 url encode(够用,只处理 ASCII)
fn urlencoded(s: &str) -> String {
    s.chars().map(|c| {
        if c.is_ascii_alphanumeric() || c == '-' || c == '_' || c == '.' || c == '~' {
            c.to_string()
        } else {
            format!("%{:02X}", c as u8)
        }
    }).collect()
}

// 抑制 sqlvalue unused warning
#[allow(dead_code)]
fn _mark_used(_v: SqlValue) {}
