//! NAI API 客户端
//!
//! 跟 NAI Studio PHP 项目的 NaiApi 等价：
//!   - 调 https://image.novelai.net/ai/generate-image
//!   - V4/V3 自动适配 payload 结构
//!   - 5xx 自动重试 2 次（每次 sleep 2s）
//!   - 429 按 Retry-After 重试（最多 3 次）
//!   - 多 key 轮换（401/402/429 立即换 key，5xx 当前 key 重试再换）
//!   - HTTP 代理支持（从 settings 读）

use std::sync::Arc;
use std::time::Duration;

use parking_lot::Mutex;
use reqwest::Client;
use reqwest::Proxy;
use serde::{Deserialize, Serialize};
use serde_json::{Map, Value, json};

use crate::error::{AppError, AppResult};
use crate::state::AppState;

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct NaiKeyInfo {
    pub id: i64,
    pub label: Option<String>,
    pub fingerprint: String,
    pub enabled: bool,
    pub sort_order: i64,
}

/// 加密的 key 条目（含密钥 + 元数据）
struct ActiveKey {
    id: i64,
    encrypted: String,
    fingerprint: String,
    fail_count: i64,
    last_error: Option<String>,
}

/// API 响应：{ok, status, data: Vec<base64 PNG>, error, headers, ms}
#[derive(Debug)]
pub struct NaiResponse {
    pub status: u16,
    pub data: Option<Vec<String>>,  // base64 列表
    pub error: Option<String>,
    pub headers: std::collections::HashMap<String, String>,
    pub ms: u64,
}

/// 1) 拿到当前所有 enabled keys（按 sort_order）
fn list_keys(state: &AppState) -> AppResult<Vec<ActiveKey>> {
    let conn = state.db.lock();
    let mut stmt = conn.prepare(
        "SELECT id, api_key_encrypted, api_key_fingerprint, fail_count, last_error_msg
         FROM nai_api_keys WHERE enabled = 1 ORDER BY sort_order ASC, id ASC"
    )?;
    let rows = stmt.query_map([], |r| {
        Ok(ActiveKey {
            id: r.get(0)?,
            encrypted: r.get(1)?,
            fingerprint: r.get(2)?,
            fail_count: r.get(3)?,
            last_error: r.get(4)?,
        })
    })?.collect::<Result<Vec<_>, _>>()?;
    Ok(rows)
}

/// 2) 标记 key 失败 / 成功
fn record_key_result(state: &AppState, key_id: i64, ok: bool, error_msg: Option<String>) {
    let conn = state.db.lock();
    let _ = if ok {
        conn.execute(
            "UPDATE nai_api_keys SET fail_count = 0, last_error_msg = NULL, last_used_at = CURRENT_TIMESTAMP WHERE id = ?",
            [key_id]
        )
    } else {
        conn.execute(
            "UPDATE nai_api_keys SET fail_count = fail_count + 1, last_error_msg = ?, last_error_at = CURRENT_TIMESTAMP WHERE id = ?",
            rusqlite::params![error_msg.unwrap_or_default(), key_id]
        )
    };
}

/// 3) 从 settings 读 proxy URL
fn read_proxy(state: &AppState) -> Option<String> {
    let conn = state.db.lock();
    let res: rusqlite::Result<(i64, Option<String>)> = conn.query_row(
        "SELECT proxy_enabled, proxy_url FROM settings WHERE id = 1",
        [],
        |r| Ok((r.get(0)?, r.get(1)?))
    );
    if let Ok((enabled, url)) = res {
        if enabled == 1 {
            return url.filter(|s| !s.is_empty());
        }
    }
    None
}

/// 4) 构造 reqwest client（含 proxy + UA）
fn build_client(proxy_url: Option<&str>) -> AppResult<Client> {
    let mut b = Client::builder()
        .user_agent("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36")
        .timeout(Duration::from_secs(300))
        .connect_timeout(Duration::from_secs(30))
        .danger_accept_invalid_certs(false);
    if let Some(p) = proxy_url {
        // 接受 http://127.0.0.1:PORT (Clash/V2Ray) 或 socks5://
        if p.starts_with("http://") || p.starts_with("socks5://") {
            b = b.proxy(Proxy::all(p).map_err(|e| AppError::Config(format!("bad proxy: {}", e)))?);
        }
    }
    b.build().map_err(|e| AppError::Config(format!("reqwest: {}", e)))
}

/// 5) 发送一次请求（不重试）
async fn send_once(
    client: &Client,
    api_key: &str,
    payload: &Value,
) -> Result<NaiResponse, AppError> {
    let url = "https://image.novelai.net/ai/generate-image";
    let start = std::time::Instant::now();
    let resp = client
        .post(url)
        .header("Authorization", format!("Bearer {}", api_key))
        .header("Content-Type", "application/json")
        .header("Accept", "*/*")
        .json(payload)
        .send()
        .await
        .map_err(|e| AppError::Upstream(format!("network: {}", e)))?;
    let status = resp.status().as_u16();
    let mut headers = std::collections::HashMap::new();
    for (k, v) in resp.headers() {
        if let Ok(vs) = v.to_str() {
            headers.insert(k.as_str().to_lowercase(), vs.to_string());
        }
    }
    let body = resp.bytes().await.map_err(|e| AppError::Upstream(format!("read body: {}", e)))?;
    let ms = start.elapsed().as_millis() as u64;

    // 解析 response：binary/octet-stream（zip）+ image/*（单图）
    let ct = headers.get("content-type").cloned().unwrap_or_default();
    let cd = headers.get("content-disposition").cloned().unwrap_or_default();

    if status >= 200 && status < 300 {
        // 是 zip
        let is_zip = ct.contains("application/zip")
            || (ct.contains("binary/octet-stream") && cd.contains(".zip"))
            || (body.len() >= 4 && &body[..4] == b"PK\x03\x04");
        if is_zip {
            let images = extract_zip_images(&body)?;
            return Ok(NaiResponse { status: 200, data: Some(images), error: None, headers, ms });
        }
        // 单图
        if ct.contains("image/") || body.len() >= 8 {
            let sig = &body[..body.len().min(8)];
            let is_png = sig.starts_with(b"\x89PNG\r\n\x1a\n");
            let is_jpg = sig.starts_with(b"\xFF\xD8\xFF");
            if is_png || is_jpg {
                return Ok(NaiResponse {
                    status: 200,
                    data: Some(vec![base64_encode(&body)]),
                    error: None,
                    headers,
                    ms,
                });
            }
        }
        // 错误文本
        let text = String::from_utf8_lossy(&body[..body.len().min(500)]).to_string();
        Ok(NaiResponse { status, data: None, error: Some(format!("Unexpected response: {}", text)), headers, ms })
    } else {
        let text = String::from_utf8_lossy(&body[..body.len().min(500)]).to_string();
        Ok(NaiResponse { status, data: None, error: Some(format!("HTTP {}: {}", status, text)), headers, ms })
    }
}

/// 提取 ZIP 内的 PNG/JPG/WEBP 文件（base64）
fn extract_zip_images(zip_bytes: &[u8]) -> AppResult<Vec<String>> {
    use std::io::Read;
    let cursor = std::io::Cursor::new(zip_bytes);
    let mut zip = zip::ZipArchive::new(cursor).map_err(|e| AppError::Upstream(format!("zip: {}", e)))?;
    let mut images = Vec::new();
    for i in 0..zip.len() {
        let mut f = zip.by_index(i).map_err(|e| AppError::Upstream(format!("zip entry: {}", e)))?;
        if !f.is_file() { continue; }
        let name = f.name().to_lowercase();
        if !name.ends_with(".png") && !name.ends_with(".jpg") && !name.ends_with(".jpeg") && !name.ends_with(".webp") {
            continue;
        }
        let mut buf = Vec::new();
        f.read_to_end(&mut buf).map_err(|e| AppError::Upstream(format!("read zip entry: {}", e)))?;
        images.push(base64_encode(&buf));
    }
    Ok(images)
}

fn base64_encode(bytes: &[u8]) -> String {
    use base64::Engine;
    base64::engine::general_purpose::STANDARD.encode(bytes)
}

/// 主入口：发 NAI generate 请求，带 5xx/429 重试 + key 轮换
pub async fn generate(
    state: Arc<AppState>,
    payload: Value,
) -> AppResult<NaiResponse> {
    let proxy = read_proxy(&state);
    let client = build_client(proxy.as_deref())?;
    let keys = list_keys(&state)?;

    if keys.is_empty() {
        return Err(AppError::Auth("no NAI API key configured".into()));
    }

    // 轮换：每个 key 内 5xx 重试 2 次 / 429 重试 3 次 / 401/402 立即换
    let mut last_err: Option<String> = None;
    for key in keys.iter() {
        let api_key = crate::encryption::decrypt(&key.encrypted)?;
        let mut attempts = 0;
        loop {
            let r = send_once(&client, &api_key, &payload).await?;
            log::info!("nai.generate: status={} ms={}", r.status, r.ms);

            match r.status {
                200 => {
                    record_key_result(&state, key.id, true, None);
                    return Ok(r);
                }
                401 | 402 | 429 => {
                    // 立即换 key
                    last_err = r.error.clone();
                    record_key_result(&state, key.id, false, Some(format!("HTTP {}", r.status)));
                    log::warn!("nai.key.rotating: key=#{} status={} -> next key", key.id, r.status);
                    break;
                }
                _ => {
                    // 5xx/其他：在当前 key 内重试
                    attempts += 1;
                    if attempts > 2 {
                        // 当前 key 已重试耗尽，标记失败 + 换 key
                        last_err = r.error.clone();
                        record_key_result(&state, key.id, false, Some(format!("HTTP {} after {} retries", r.status, attempts)));
                        log::warn!("nai.key.exhausted: key=#{} retries={} -> next key", key.id, attempts);
                        break;
                    }
                    // sleep 2s 重试
                    last_err = r.error.clone();
                    tokio::time::sleep(Duration::from_secs(2)).await;
                    log::info!("nai.key.retry: key=#{} attempt={}", key.id, attempts + 1);
                }
            }
        }
    }

    Err(AppError::Upstream(last_err.unwrap_or_else(|| "all keys failed".into())))
}

/// 简单 GET（给 /api/anlas 用）
pub async fn get_anlas(state: Arc<AppState>) -> AppResult<Value> {
    let proxy = read_proxy(&state);
    let client = build_client(proxy.as_deref())?;
    let keys = list_keys(&state)?;
    if keys.is_empty() {
        return Err(AppError::Auth("no NAI API key".into()));
    }
    let api_key = crate::encryption::decrypt(&keys[0].encrypted)?;
    let resp = client
        .get("https://api.novelai.net/user/subscription")
        .header("Authorization", format!("Bearer {}", api_key))
        .header("Accept", "application/json")
        .send()
        .await
        .map_err(|e| AppError::Upstream(format!("network: {}", e)))?;
    if !resp.status().is_success() {
        return Err(AppError::Upstream(format!("anlas HTTP {}", resp.status().as_u16())));
    }
    let json: Value = resp.json().await.map_err(|e| AppError::Upstream(format!("anlas json: {}", e)))?;
    Ok(json)
}
