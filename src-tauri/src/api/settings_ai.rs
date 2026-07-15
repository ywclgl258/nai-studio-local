//! /api/settings_ai -- AI provider 配置 + 测试
//!
//! 跟 NAI Studio PHP 项目 settings_ai.php 等价
//!   - GET  /api/settings_ai  -> 返 presets + 当前 config
//!   - POST /api/settings_ai  -> 保存 config
//!   - GET  /api/settings_ai?action=test  -> 测连通

use std::time::Duration;

use axum::Json;
use axum::extract::State;
use rusqlite::types::Value as SqlValue;
use serde_json::{Map, Value, json};

use crate::api::SharedState;
use crate::error::{AppError, AppResult};

/// 预置 provider 配置
const PRESETS: &[(&str, &str, &str)] = &[
    ("deepseek",  "DeepSeek",      "https://api.deepseek.com/v1/chat/completions"),
    ("openai",    "OpenAI",        "https://api.openai.com/v1/chat/completions"),
    ("siliconflow","SiliconFlow",  "https://api.siliconflow.cn/v1/chat/completions"),
    ("ollama",    "Ollama (本地)", "http://127.0.0.1:11434/v1/chat/completions"),
];

pub async fn get(
    State(state): State<SharedState>,
) -> AppResult<Json<Value>> {
    let conn = state.db.lock();
    let row: Option<(String, Option<String>, Option<String>, Option<String>, Option<String>)> = conn.query_row(
        "SELECT ai_provider, ai_base_url, ai_api_key, ai_model, ai_reasoning_effort FROM settings WHERE id = 1",
        [],
        |r| Ok((r.get(0)?, r.get(1)?, r.get(2)?, r.get(3)?, r.get(4)?))
    ).ok();
    let (provider, base_url, api_key, model, effort) = row.unwrap_or_else(|| ("deepseek".to_string(), None, None, None, None));

    let config = json!({
        "provider": provider,
        "base_url": base_url,
        "api_key": api_key,  // 已经是密文；前端自己解密 or 显示脱敏
        "model": model,
        "reasoning_effort": effort,
        "enabled": !api_key.as_deref().unwrap_or("").is_empty(),
    });

    let presets: Vec<Value> = PRESETS.iter().map(|(k, n, u)| json!({
        "key": k,
        "name": n,
        "default_base_url": u,
    })).collect();

    Ok(Json(json!({"ok": true, "config": config, "presets": presets})))
}

pub async fn update(
    State(state): State<SharedState>,
    Json(body): Json<Value>,
) -> AppResult<Json<Value>> {
    let obj = body.as_object().ok_or_else(|| AppError::BadRequest("body must be object".into()))?;
    let mut set_clauses: Vec<String> = Vec::new();
    let mut params: Vec<SqlValue> = Vec::new();

    // ai_api_key 加密存
    if let Some(v) = obj.get("api_key") {
        let key_str = v.as_str().unwrap_or("");
        if key_str.is_empty() {
            set_clauses.push("ai_api_key = ?".to_string());
            params.push(SqlValue::Null);
        } else {
            let enc = crate::encryption::encrypt(key_str)?;
            set_clauses.push("ai_api_key = ?".to_string());
            params.push(SqlValue::Text(enc));
        }
    }

    let allowed = ["ai_provider", "ai_base_url", "ai_model", "ai_reasoning_effort"];
    for field in allowed.iter() {
        if let Some(v) = obj.get(*field) {
            set_clauses.push(format!("{} = ?", field));
            params.push(match v {
                Value::Null => SqlValue::Null,
                Value::String(s) => SqlValue::Text(s.clone()),
                _ => SqlValue::Text(v.to_string()),
            });
        }
    }

    if set_clauses.is_empty() {
        return Ok(Json(json!({"ok": true, "note": "nothing to update"})));
    }

    let sql = format!("UPDATE settings SET {} WHERE id = 1", set_clauses.join(", "));
    let conn = state.db.lock();
    let param_refs: Vec<&dyn rusqlite::ToSql> = params.iter().map(|p| p as &dyn rusqlite::ToSql).collect();
    conn.execute(&sql, param_refs.as_slice())?;

    // 重新读 config 返回（避免嵌套 await State<SharedState>）
    let cfg = load_config(&state);
    Ok(Json(json!({"ok": true, "config": cfg})))
}

fn load_config(state: &SharedState) -> Value {
    let conn = state.db.lock();
    let row: Option<(String, Option<String>, Option<String>, Option<String>, Option<String>)> = conn.query_row(
        "SELECT ai_provider, ai_base_url, ai_api_key, ai_model, ai_reasoning_effort FROM settings WHERE id = 1",
        [],
        |r| Ok((r.get(0)?, r.get(1)?, r.get(2)?, r.get(3)?, r.get(4)?))
    ).ok();
    let (provider, base_url, api_key, model, effort) = row.unwrap_or_else(|| ("deepseek".to_string(), None, None, None, None));
    json!({
        "provider": provider,
        "base_url": base_url,
        "api_key": api_key,
        "model": model,
        "reasoning_effort": effort,
        "enabled": !api_key.as_deref().unwrap_or("").is_empty(),
    })
}

/// GET /api/settings_ai/test  — 测 LLM 连通
pub async fn test(
    State(state): State<SharedState>,
) -> AppResult<Json<Value>> {
    // 在 block 内取数据，conn 离开 block 就 drop，await 时 future 不再持有 guard -> Send
    let (provider, base_url, api_key_enc, model) = {
        let conn = state.db.lock();
        let row: Option<(String, Option<String>, Option<String>, Option<String>)> = conn.query_row(
            "SELECT ai_provider, ai_base_url, ai_api_key, ai_model FROM settings WHERE id = 1",
            [],
            |r| Ok((r.get(0)?, r.get(1)?, r.get(2)?, r.get(3)?))
        ).ok();
        match row {
            Some(r) => r,
            None => return Ok(Json(json!({"ok": false, "error": "AI not configured"}))),
        }
    };

    let api_key = match api_key_enc.and_then(|e| if e.is_empty() { None } else { Some(e) }) {
        Some(e) => crate::encryption::decrypt(&e).unwrap_or(e),
        None => return Ok(Json(json!({"ok": false, "error": "AI API key not set"}))),
    };
    let url = base_url.unwrap_or_else(|| {
        PRESETS.iter().find(|(k, _, _)| *k == provider).map(|(_, _, u)| u.to_string()).unwrap_or_default()
    });
    let model = model.unwrap_or_else(|| match provider.as_str() {
        "deepseek" => "deepseek-chat",
        "openai" => "gpt-4o-mini",
        "siliconflow" => "Qwen/Qwen2.5-7B-Instruct",
        "ollama" => "llama3.2",
        _ => "gpt-4o-mini",
    }.to_string());

    if url.is_empty() {
        return Ok(Json(json!({"ok": false, "error": "no base_url configured"})));
    }

    // 发最小测试请求
    let client = reqwest::Client::builder()
        .timeout(Duration::from_secs(30))
        .build()
        .map_err(|e| AppError::Config(format!("reqwest: {}", e)))?;
    let body = json!({
        "model": model,
        "messages": [{"role": "user", "content": "ping"}],
        "max_tokens": 5,
    });
    let start = std::time::Instant::now();
    let resp = client.post(&url)
        .header("Authorization", format!("Bearer {}", api_key))
        .header("Content-Type", "application/json")
        .json(&body)
        .send().await
        .map_err(|e| AppError::Upstream(format!("network: {}", e)))?;
    let status = resp.status().as_u16();
    let ms = start.elapsed().as_millis() as i64;
    let text = resp.text().await.unwrap_or_default();
    let text_short: String = text.chars().take(500).collect();

    if status >= 200 && status < 300 {
        let _ = {
            let conn = state.db.lock();
            conn.execute(
                "UPDATE settings SET deepseek_status = ?, deepseek_tested_at = CURRENT_TIMESTAMP WHERE id = 1",
                rusqlite::params![format!("ok:{}", status)]
            )
        };
        Ok(Json(json!({
            "ok": true,
            "message": format!("✓ {} 连通 ({}ms)", provider, ms),
            "status": status,
            "ms": ms,
            "response": text_short,
        })))
    } else {
        let _ = {
            let conn = state.db.lock();
            conn.execute(
                "UPDATE settings SET deepseek_status = ?, deepseek_tested_at = CURRENT_TIMESTAMP WHERE id = 1",
                rusqlite::params![format!("fail:{}", status)]
            )
        };
        Ok(Json(json!({
            "ok": false,
            "error": format!("{} 返 HTTP {}: {}", provider, status, text_short),
            "status": status,
            "ms": ms,
        })))
    }
}
