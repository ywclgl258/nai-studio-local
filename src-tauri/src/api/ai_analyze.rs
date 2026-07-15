//! /api/ai_analyze -- AI 图像分析
//!
//! 跟 NAI Studio PHP 项目 ai_analyze.php 等价
//!   POST {image_path: '...', prompt?: '...'}
//!   Returns { analysis: '...', tokens_used: N }
//!
//! Phase 3.3: 基础 stub — 检查 settings_ai,有 key 则发个简单 prompt
//! Phase 4: 多模态视觉 + 风格分析 + 自动 prompt 生成

use std::time::Duration;

use axum::Json;
use axum::extract::State;
use reqwest::Client;
use serde_json::{Value, json};

use crate::api::SharedState;
use crate::error::AppResult;

const DEFAULT_PROMPT: &str = "请用中文描述这张图片的内容、风格、构图、人物特征等关键信息(100字内)。";

pub async fn handle(
    State(state): State<SharedState>,
    Json(body): Json<Value>,
) -> AppResult<Json<Value>> {
    let image_path = body.get("image_path").and_then(|v| v.as_str());
    let user_prompt = body.get("prompt").and_then(|v| v.as_str()).unwrap_or(DEFAULT_PROMPT);
    if image_path.is_none() {
        return Ok(Json(json!({"ok": false, "error": "image_path required"})));
    }

    // 读 settings_ai
    let (provider, base_url, api_key, model) = {
        let conn = state.db.lock();
        let row: Option<(String, Option<String>, Option<String>, Option<String>)> = conn.query_row(
            "SELECT ai_provider, ai_base_url, ai_api_key, ai_model FROM settings WHERE id = 1",
            [],
            |r| Ok((r.get(0)?, r.get(1)?, r.get(2)?, r.get(3)?))
        ).ok();
        let r = row.unwrap_or_else(|| ("deepseek".to_string(), None, None, None));
        let key = r.2.and_then(|e| if e.is_empty() { None } else { Some(crate::encryption::decrypt(&e).unwrap_or(e)) });
        (r.0, r.1, key, r.3)
    };
    let url = match base_url {
        Some(u) if !u.is_empty() => u,
        _ => match provider.as_str() {
            "deepseek"    => "https://api.deepseek.com/v1/chat/completions".to_string(),
            "openai"      => "https://api.openai.com/v1/chat/completions".to_string(),
            "siliconflow" => "https://api.siliconflow.cn/v1/chat/completions".to_string(),
            "ollama"      => "http://127.0.0.1:11434/v1/chat/completions".to_string(),
            _             => return Ok(Json(json!({"ok": false, "error": format!("unknown provider: {}", provider)}))),
        }
    };
    let model = model.unwrap_or_else(|| match provider.as_str() {
        "deepseek"    => "deepseek-chat",
        "openai"      => "gpt-4o-mini",
        "siliconflow" => "Qwen/Qwen2.5-7B-Instruct",
        "ollama"      => "llama3.2",
        _             => "gpt-4o-mini",
    }.to_string());
    let api_key = match api_key {
        Some(k) => k,
        None => return Ok(Json(json!({"ok": false, "error": "AI API key 未设置，请在设置页配置"}))),
    };

    // Phase 3.3: 简单 text-only 调 LLM
    // Phase 4: 接 vision 模型的 image_url 字段
    let body_json = json!({
        "model": model,
        "messages": [{"role": "user", "content": user_prompt}],
        "max_tokens": 500,
    });
    let client = Client::builder().timeout(Duration::from_secs(60))
        .build()
        .map_err(|e| crate::error::AppError::Upstream(format!("reqwest: {}", e)))?;
    let start = std::time::Instant::now();
    let resp = client.post(&url)
        .header("Authorization", format!("Bearer {}", api_key))
        .header("Content-Type", "application/json")
        .json(&body_json)
        .send().await
        .map_err(|e| crate::error::AppError::Upstream(format!("network: {}", e)))?;
    let ms = start.elapsed().as_millis() as i64;
    let status = resp.status();
    let text = resp.text().await.unwrap_or_default();
    if !status.is_success() {
        return Ok(Json(json!({
            "ok": false,
            "error": format!("{} 返 HTTP {}: {}", provider, status.as_u16(), &text[..text.len().min(300)]),
            "ms": ms,
        })));
    }
    let parsed: Value = serde_json::from_str(&text).unwrap_or_default();
    let content = parsed.get("choices")
        .and_then(|c| c.get(0))
        .and_then(|c| c.get("message"))
        .and_then(|m| m.get("content"))
        .and_then(|c| c.as_str())
        .unwrap_or("")
        .to_string();
    let tokens = parsed.get("usage")
        .and_then(|u| u.get("total_tokens"))
        .and_then(|t| t.as_i64())
        .unwrap_or(0);

    Ok(Json(json!({
        "ok": true,
        "analysis": content,
        "tokens_used": tokens,
        "ms": ms,
        "model": model,
        "provider": provider,
        "note": "Phase 3.3 text-only；Phase 4 接 vision",
    })))
}
