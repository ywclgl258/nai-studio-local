//! GUI 端 HTTP 客户端 — 调后端 Axum server
//!
//! 设计: GUI 是 client, 后端 Axum server 跑在 127.0.0.1:RANDOM_PORT。
//! 所有业务数据通过 HTTP API 拿, GUI 不直接碰 DB / 文件。

use std::time::Duration;

use serde_json::Value;

#[derive(Clone)]
pub struct HttpClient {
    base: String,
    client: reqwest::Client,
}

impl HttpClient {
    pub fn new(port: u16) -> Self {
        let client = reqwest::Client::builder()
            .timeout(Duration::from_secs(60))
            .build()
            .expect("reqwest client");
        Self {
            base: format!("http://127.0.0.1:{}/api", port),
            client,
        }
    }

    pub fn base(&self) -> &str { &self.base }

    pub async fn get(&self, path: &str) -> Result<Value, String> {
        let url = format!("{}{}", self.base, path);
        let resp = self.client.get(&url).send().await
            .map_err(|e| format!("network: {}", e))?;
        let status = resp.status();
        let text = resp.text().await.map_err(|e| format!("read body: {}", e))?;
        if !status.is_success() {
            return Err(format!("HTTP {}: {}", status, &text[..text.len().min(200)]));
        }
        serde_json::from_str(&text).map_err(|e| format!("parse JSON: {} (body: {})", e, &text[..text.len().min(200)]))
    }

    pub async fn get_text(&self, path: &str) -> Result<String, String> {
        let url = format!("{}{}", self.base, path);
        let resp = self.client.get(&url).send().await
            .map_err(|e| format!("network: {}", e))?;
        let status = resp.status();
        let text = resp.text().await.map_err(|e| format!("read body: {}", e))?;
        if !status.is_success() {
            return Err(format!("HTTP {}: {}", status, &text[..text.len().min(200)]));
        }
        Ok(text)
    }

    pub async fn post(&self, path: &str, body: &Value) -> Result<Value, String> {
        let url = format!("{}{}", self.base, path);
        let resp = self.client.post(&url).json(body).send().await
            .map_err(|e| format!("network: {}", e))?;
        let status = resp.status();
        let text = resp.text().await.map_err(|e| format!("read body: {}", e))?;
        if !status.is_success() {
            return Err(format!("HTTP {}: {}", status, &text[..text.len().min(200)]));
        }
        serde_json::from_str(&text).map_err(|e| format!("parse JSON: {} (body: {})", e, &text[..text.len().min(200)]))
    }
}
