//! /api/tag_image -- 图片打 tag
//!
//! 跟 NAI Studio PHP 项目 tag_image.php 等价
//!   GET ?path=/storage/...&method=...
//!     method: 'wd' (WD Tagger), 'danbooru' (镜像), 'decompose' (内置)
//!   Returns { tags: [{name, score}], method, ms }
//!
//! Phase 3.3: 基础框架 + 'decompose' 走内置拆分
//! Phase 4: WD Tagger 本地 ONNX 模型 + Danbooru 镜像化

use std::collections::HashMap;
use std::path::PathBuf;

use axum::Json;
use axum::extract::{Query, State};
use axum::http::StatusCode;
use axum::response::{IntoResponse, Response};
use serde_json::{Value, json};
use walkdir::WalkDir;

use crate::api::SharedState;
use crate::error::{AppError, AppResult};

pub async fn handle(
    State(state): State<SharedState>,
    Query(params): Query<HashMap<String, String>>,
) -> AppResult<Response> {
    let path = params.get("path").map(|s| s.as_str()).unwrap_or("");
    let method = params.get("method").map(|s| s.as_str()).unwrap_or("decompose");
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

    match method {
        "decompose" => {
            // 基础:走 import_meta 提取 prompt,再 decompose 拆分
            let info = super::import_meta::extract_png_text_chunks(&abs).unwrap_or_default();
            let prompt = info.iter()
                .find(|(k, _)| k == "prompt" || k == "Description" || k == "UserComment")
                .map(|(_, v)| v.clone())
                .unwrap_or_default();
            let tags = decompose_prompt(&prompt);
            Ok((StatusCode::OK, Json(json!({
                "ok": true,
                "method": method,
                "tags": tags,
                "prompt_source": if prompt.is_empty() { "none" } else { "png_text" },
            }))).into_response())
        }
        "wd" => Ok((StatusCode::OK, Json(json!({
            "ok": false,
            "method": method,
            "error": "WD Tagger 暂未实现 (Phase 4)",
            "note": "需要 ONNX Runtime + 模型 (~1.5GB)，Phase 4 实装",
        }))).into_response()),
        "danbooru" => {
            // 简化:扫 storage 找同名 Danbooru post 缓存
            let stem = abs.file_stem().and_then(|s| s.to_str()).unwrap_or("");
            let _ = WalkDir::new(&state.paths.cache);
            Ok((StatusCode::OK, Json(json!({
                "ok": false,
                "method": method,
                "stem": stem,
                "error": "Danbooru 镜像化暂未实现 (Phase 4)",
            }))).into_response())
        }
        _ => Ok((StatusCode::BAD_REQUEST, Json(json!({"ok": false, "error": format!("unknown method: {}", method)}))).into_response()),
    }
}

fn decompose_prompt(prompt: &str) -> Vec<Value> {
    use crate::api::decompose::builtin_dict_pub;
    if prompt.is_empty() { return vec![]; }
    prompt
        .split(|c: char| c == ',' || c == '\n' || c == ';')
        .map(|s| s.trim())
        .filter(|s| !s.is_empty())
        .map(|s| {
            // 去权重 {}
            let s = s.replace('{', "").replace('}', "");
            let s = if let Some(idx) = s.find("::") { s[idx+2..].to_string() } else { s.clone() };
            let name = if let Some(idx) = s.find(':') {
                if s.starts_with("artist:") { s.clone() } else { s[idx+1..].to_string() }
            } else { s.clone() };
            let cn = builtin_dict_pub(&name.to_lowercase()).map(|s| s.to_string());
            json!({"name": name, "cn": cn, "source": if cn.is_some() { "builtin" } else { "raw" }})
        })
        .collect()
}

#[allow(dead_code)]
fn _resolve_path(_state: &SharedState) -> Result<(), AppError> { Ok(()) }
