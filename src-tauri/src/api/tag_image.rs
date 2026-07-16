//! /api/tag_image -- 图片打 tag
//!
//! 跟 NAI Studio PHP 项目 tag_image.php 等价
//!   GET ?path=/storage/...&method=...
//!     method: 'decompose' (内置) | 'danbooru' (从 Danbooru 拉关联 post + 拆 tag)
//!                            | 'wd' (WD Tagger, 暂未实装 - 模型 ~1.5GB)
//!   Returns { tags: [{name, score, cn}], method, ms, source }
//!
//! Phase 4 升级:
//!   - decompose: 走 import_meta 提取 prompt + 拆分 (已有)
//!   - danbooru:  从 PNG 文件名/路径中识别 tag stem, 拉 Danbooru post 列表
//!                聚合 tag_string 频次, 返 top N tag (含翻译)
//!   - wd:        WD Tagger ONNX 接入 (Phase 5 单独实装, 模型太大, 走 subprocess)

use std::collections::{HashMap, HashSet};
use std::path::PathBuf;
use std::time::Duration;

use axum::extract::{Query, State};
use axum::http::StatusCode;
use axum::response::{IntoResponse, Response};
use axum::Json;
use reqwest::Client;
use serde_json::{Value, json};

use crate::api::SharedState;
use crate::error::AppResult;

const DANBOORU_BASE: &str = "https://danbooru.donmai.us";

pub async fn handle(
    State(state): State<SharedState>,
    Query(params): Query<HashMap<String, String>>,
) -> AppResult<Response> {
    let path = params.get("path").map(|s| s.as_str()).unwrap_or("");
    let method = params.get("method").map(|s| s.as_str()).unwrap_or("decompose");
    let limit: usize = params.get("limit").and_then(|s| s.parse().ok()).unwrap_or(20).clamp(1, 100);
    if path.is_empty() {
        return Ok((StatusCode::BAD_REQUEST, Json(json!({"ok": false, "error": "path required"}))).into_response());
    }

    let abs = if let Some(rel) = path.strip_prefix("/storage/") {
        state.paths.storage.join(rel)
    } else {
        PathBuf::from(path)
    };
    if !abs.is_file() {
        return Ok((StatusCode::NOT_FOUND, Json(json!({"ok": false, "error": format!("file not found: {}", path)}))).into_response());
    }

    let start = std::time::Instant::now();
    match method {
        "decompose" => Ok(decompose(&state, &abs, start).into_response()),
        "danbooru" => Ok(danbooru_mirror(&state, &abs, limit, start).await.into_response()),
        "wd" => Ok((StatusCode::OK, Json(json!({
            "ok": false,
            "method": method,
            "error": "WD Tagger 暂未实装",
            "note": "Phase 5 单独装 - WD Tagger ONNX 模型 ~1.5GB, 走 subprocess 类似 Real-ESRGAN",
            "alternatives": ["decompose", "danbooru"],
        }))).into_response()),
        _ => Ok((StatusCode::BAD_REQUEST, Json(json!({"ok": false, "error": format!("unknown method: {}", method)}))).into_response()),
    }
}

/// 内置拆分: 从 PNG 提 prompt, 按逗号拆, 命中内置字典返中文
fn decompose(state: &SharedState, abs: &PathBuf, start: std::time::Instant) -> (StatusCode, Json<Value>) {
    let info = super::import_meta::extract_png_text_chunks(abs).unwrap_or_default();
    let prompt = info.iter()
        .find(|(k, _)| k == "prompt" || k == "Description" || k == "UserComment")
        .map(|(_, v)| v.clone())
        .unwrap_or_default();
    let tags = decompose_prompt(&prompt);
    // 写回 DB 缓存 (用 danbooru_tag_cache 表,跟 danbooru 一致)
    write_tags_to_cache(state, &tags);
    let ms = start.elapsed().as_millis() as i64;
    (StatusCode::OK, Json(json!({
        "ok": true,
        "method": "decompose",
        "tags": tags,
        "prompt_source": if prompt.is_empty() { "none" } else { "png_text" },
        "ms": ms,
    })))
}

/// Danbooru 镜像: 拿文件名 stem 当 tag 查 (PNG 通常 NAI 命名为 nai_<seed>_<size>),
/// 拉 1-3 个 post, 聚合 tag_string 频次, 返 top N
async fn danbooru_mirror(
    state: &SharedState,
    abs: &PathBuf,
    limit: usize,
    start: std::time::Instant,
) -> (StatusCode, Json<Value>) {
    // 1. 优先: 从 PNG metadata 提 prompt, 拆第一个/前 3 个 tag 当查询
    let info = super::import_meta::extract_png_text_chunks(abs).unwrap_or_default();
    let prompt = info.iter()
        .find(|(k, _)| k == "prompt" || k == "Description" || k == "UserComment")
        .map(|(_, v)| v.clone())
        .unwrap_or_default();

    let query_tags: Vec<String> = if !prompt.is_empty() {
        // 拆 prompt, 取前 3 个有意义的 (跳过权重/质量)
        parse_prompt_simple(&prompt).into_iter()
            .filter(|t| !t.is_empty() && !QUALITY_TAGS.contains(&t.as_str()))
            .take(3)
            .collect()
    } else {
        // 2. fallback: 用文件 stem 的前 2 段
        let stem = abs.file_stem().and_then(|s| s.to_str()).unwrap_or("");
        let parts: Vec<&str> = stem.split(|c: char| c == '_' || c == '-').collect();
        parts.into_iter().take(2).map(|s| s.to_string()).collect()
    };

    if query_tags.is_empty() {
        return (StatusCode::OK, Json(json!({
            "ok": false,
            "method": "danbooru",
            "error": "无法从文件提取 tag (无 PNG prompt, 文件名也无语义)",
            "ms": start.elapsed().as_millis() as i64,
        })));
    }

    let q = query_tags.join(" ");
    let client = match state.proxy_url() {
        Some(p) => {
            let proxy = reqwest::Proxy::all(&p).ok();
            match proxy {
                Some(pr) => reqwest::Client::builder()
                    .timeout(Duration::from_secs(20))
                    .user_agent("nai-studio-desktop/2.0 (tag-image)")
                    .proxy(pr)
                    .build(),
                None => reqwest::Client::builder()
                    .timeout(Duration::from_secs(20))
                    .user_agent("nai-studio-desktop/2.0 (tag-image)")
                    .build(),
            }
        }
        None => reqwest::Client::builder()
            .timeout(Duration::from_secs(20))
            .user_agent("nai-studio-desktop/2.0 (tag-image)")
            .build(),
    };
    let client = match client {
        Ok(c) => c,
        Err(e) => return (StatusCode::INTERNAL_SERVER_ERROR, Json(json!({
            "ok": false, "method": "danbooru", "error": format!("reqwest: {}", e),
        }))),
    };

    // 3. 拉 5 个 post
    let url = format!("{}/posts.json?tags={}&limit=5&sf=random", DANBOORU_BASE, urlencoded(&q));
    let resp = match client.get(&url).send().await {
        Ok(r) => r,
        Err(e) => return (StatusCode::OK, Json(json!({
            "ok": false, "method": "danbooru",
            "error": format!("Danbooru 不可达: {}", e),
            "query": q,
        }))),
    };
    if !resp.status().is_success() {
        return (StatusCode::OK, Json(json!({
            "ok": false, "method": "danbooru",
            "error": format!("Danbooru HTTP {}", resp.status().as_u16()),
            "query": q,
        })));
    }
    let posts: Vec<Value> = resp.json().await.unwrap_or_default();
    if posts.is_empty() {
        return (StatusCode::OK, Json(json!({
            "ok": true, "method": "danbooru",
            "tags": [],
            "query": q,
            "message": "Danbooru 0 post",
        })));
    }

    // 4. 聚合 tag_string 频次 (排除 query 自身, 排除超低频)
    let query_set: HashSet<String> = query_tags.iter().map(|s| s.to_lowercase()).collect();
    let mut freq: HashMap<String, i64> = HashMap::new();
    for p in &posts {
        if let Some(ts) = p.get("tag_string").and_then(|v| v.as_str()) {
            for t in ts.split_whitespace() {
                let lower = t.to_lowercase();
                if query_set.contains(&lower) { continue; }
                if t.len() < 2 || t.len() > 64 { continue; }
                *freq.entry(t.to_string()).or_insert(0) += 1;
            }
        }
    }

    // 5. 按频次排, top N
    let mut sorted: Vec<(String, i64)> = freq.into_iter().collect();
    sorted.sort_by(|a, b| b.1.cmp(&a.1).then(a.0.cmp(&b.0)));
    sorted.truncate(limit);
    let top: Vec<Value> = sorted.iter().enumerate().map(|(i, (name, count))| {
        let cn = super::decompose::builtin_dict_pub(&name.to_lowercase()).map(|s| s.to_string());
        json!({
            "name": name,
            "score": count,
            "rank": i + 1,
            "cn": cn,
        })
    }).collect();

    // 6. 写 cache
    write_tags_to_cache(state, &top);

    let ms = start.elapsed().as_millis() as i64;
    (StatusCode::OK, Json(json!({
        "ok": true,
        "method": "danbooru",
        "query": q,
        "source": "danbooru",
        "posts_used": posts.len(),
        "tags": top,
        "ms": ms,
    })))
}

/// 写 tag 到 danbooru_tag_cache (translate-on-demand 时命中)
fn write_tags_to_cache(_state: &SharedState, tags: &[Value]) {
    // 简化: 当前主流程是 Danbooru 在线拉,缓存主要靠 danbooru.rs 那条路
    // 这里如果 tag 来自内置拆分,实际已经 DB 里有; 暂只做占位
    let _ = tags;
}

const QUALITY_TAGS: &[&str] = &[
    "masterpiece", "best_quality", "amazing_quality", "highres", "absurdres",
    "great_quality", "good_quality", "normal_quality", "low_quality", "worst_quality",
    "very_aesthetic", "aesthetic",
];

fn parse_prompt_simple(prompt: &str) -> Vec<String> {
    prompt
        .split(|c: char| c == ',' || c == '\n' || c == ';')
        .map(|s| s.trim())
        .filter(|s| !s.is_empty())
        .map(|s| {
            let mut t = s.replace('{', "").replace('}', "").replace('[', "").replace(']', "");
            t = t.replace("::", ":");
            t
        })
        .map(|s| {
            // 提权部分 1.05::tag -> tag
            if let Some(pos) = s.find(':') {
                if s.starts_with("artist:") { s } else { s[pos+1..].to_string() }
            } else { s }
        })
        .filter(|s| !s.is_empty() && s.chars().all(|c| c.is_ascii_alphanumeric() || c == '_' || c == '-'))
        .collect()
}

fn decompose_prompt(prompt: &str) -> Vec<Value> {
    let parsed = parse_prompt_simple(prompt);
    parsed.into_iter().map(|name| {
        let cn = super::decompose::builtin_dict_pub(&name.to_lowercase()).map(|s| s.to_string());
        json!({
            "name": name,
            "cn": cn,
            "source": if cn.is_some() { "builtin" } else { "raw" },
        })
    }).collect()
}

fn urlencoded(s: &str) -> String {
    s.chars().map(|c| {
        if c.is_ascii_alphanumeric() || c == '-' || c == '_' || c == '.' || c == '~' || c == ' ' {
            if c == ' ' { '+'.to_string() } else { c.to_string() }
        } else {
            format!("%{:02X}", c as u8)
        }
    }).collect()
}

#[allow(dead_code)]
fn _client_phantom(_: Client) {}
