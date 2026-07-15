//! /api/proxy -- 代理配置 + 测试
//!
//! 从 settings 表读 proxy_enabled + proxy_url，测试时尝试 NAI 连接

use std::time::Duration;

use axum::Json;
use axum::extract::State;
use reqwest::Proxy;
use serde_json::{Value, json};

use crate::api::SharedState;
use crate::error::{AppError, AppResult};

pub async fn status(State(state): State<SharedState>) -> AppResult<Json<Value>> {
    let conn = state.db.lock();
    let row: Option<(i64, Option<String>, Option<String>, Option<String>)> = conn.query_row(
        "SELECT proxy_enabled, proxy_url, proxy_test_status, proxy_tested_at FROM settings WHERE id = 1",
        [],
        |r| Ok((r.get(0)?, r.get(1)?, r.get(2)?, r.get(3)?))
    ).ok();
    let (enabled, url, test_status, tested_at) = row.unwrap_or((0, None, None, None));
    Ok(Json(json!({
        "ok": true,
        "enabled": enabled == 1,
        "url": url,
        "test_status": test_status,
        "tested_at": tested_at,
    })))
}

pub async fn test(State(state): State<SharedState>) -> AppResult<Json<Value>> {
    // 在 block 内取数据，conn 离开 block 就 drop
    let (enabled, url) = {
        let conn = state.db.lock();
        let row: Option<(i64, Option<String>)> = conn.query_row(
            "SELECT proxy_enabled, proxy_url FROM settings WHERE id = 1",
            [],
            |r| Ok((r.get(0)?, r.get(1)?))
        ).ok();
        row.unwrap_or((0, None))
    };

    if enabled != 1 {
        return Ok(Json(json!({"ok": false, "error": "代理未启用"})));
    }
    let url = match url {
        Some(u) if !u.is_empty() => u,
        _ => return Ok(Json(json!({"ok": false, "error": "代理 URL 为空"}))),
    };

    // 拿一个 NAI key
    let api_key = {
        let conn = state.db.lock();
        let key_enc: Option<String> = conn.query_row(
            "SELECT api_key_encrypted FROM nai_api_keys WHERE enabled = 1 ORDER BY sort_order ASC LIMIT 1",
            [],
            |r| r.get(0)
        ).ok().flatten();
        key_enc.and_then(|e| crate::encryption::decrypt(&e).ok())
    };
    let api_key = match api_key {
        Some(k) => k,
        None => return Ok(Json(json!({"ok": false, "error": "未设置 API Key，无法测试代理"}))),
    };

    // 用代理测 NAI
    let client = reqwest::Client::builder()
        .timeout(Duration::from_secs(15))
        .proxy(Proxy::all(&url).map_err(|e| AppError::Config(format!("bad proxy: {}", e)))?)
        .build()
        .map_err(|e| AppError::Config(format!("reqwest: {}", e)))?;

    let start = std::time::Instant::now();
    let resp = client.get("https://api.novelai.net/user/subscription")
        .header("Authorization", format!("Bearer {}", api_key))
        .header("Accept", "application/json")
        .send().await;
    let ms = start.elapsed().as_millis() as i64;

    match resp {
        Ok(r) if r.status().is_success() => {
            let _ = {
                let conn = state.db.lock();
                conn.execute(
                    "UPDATE settings SET proxy_test_status = ?, proxy_tested_at = CURRENT_TIMESTAMP WHERE id = 1",
                    rusqlite::params![format!("ok:{}", r.status().as_u16())]
                )
            };
            Ok(Json(json!({
                "ok": true,
                "message": format!("✓ 代理可用，连上 NAI 成功 ({}ms)", ms),
                "ms": ms,
                "status": r.status().as_u16(),
            })))
        }
        Ok(r) => {
            let _ = {
                let conn = state.db.lock();
                conn.execute(
                    "UPDATE settings SET proxy_test_status = ?, proxy_tested_at = CURRENT_TIMESTAMP WHERE id = 1",
                    rusqlite::params![format!("fail:{}", r.status().as_u16())]
                )
            };
            Ok(Json(json!({
                "ok": false,
                "error": format!("代理连上了，但 NAI 返 HTTP {}", r.status().as_u16()),
                "status": r.status().as_u16(),
            })))
        }
        Err(e) => {
            let _ = {
                let conn = state.db.lock();
                conn.execute(
                    "UPDATE settings SET proxy_test_status = ?, proxy_tested_at = CURRENT_TIMESTAMP WHERE id = 1",
                    rusqlite::params![format!("fail:0")]
                )
            };
            Ok(Json(json!({
                "ok": false,
                "error": format!("代理测试失败：{}（errno={}）", e, e),
            })))
        }
    }
}
