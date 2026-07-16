//! 共享 AI 客户端
//!
//! 集中处理:
//!   - 从 settings 表读 AI config (provider / base_url / api_key / model)
//!   - 解密 api_key (AES-GCM, 与 PHP 端兼容)
//!   - 调 Chat Completions API
//!   - 支持 text-only 和 vision 两种模式
//!
//! 任何需要调 LLM/vision 的地方都应该用这个,不要复制 settings_ai.rs 的样板代码

use std::time::Duration;

use reqwest::Client;
use serde_json::{Value, json};

use crate::api::SharedState;
use crate::error::{AppError, AppResult};

/// AI provider 预设 (跟 settings_ai.rs 保持一致)
const PRESETS: &[(&str, &str)] = &[
    ("deepseek",    "https://api.deepseek.com/v1/chat/completions"),
    ("openai",      "https://api.openai.com/v1/chat/completions"),
    ("siliconflow", "https://api.siliconflow.cn/v1/chat/completions"),
    ("ollama",      "http://127.0.0.1:11434/v1/chat/completions"),
];

/// AI 配置 (从 settings 表读出来,已解密)
#[derive(Debug, Clone)]
pub struct AiConfig {
    pub provider: String,
    pub base_url: String,
    pub api_key: String,
    pub model: String,
}

impl AiConfig {
    /// 从 settings 读 AI 配置,已解密
    ///   读取在 block 内,conn 离开 block 已 drop,可以放心 .await
    pub fn load(state: &SharedState) -> AppResult<Self> {
        let (provider, base_url, api_key_enc, model) = {
            let conn = state.db.lock();
            let row: Option<(String, Option<String>, Option<String>, Option<String>)> = conn.query_row(
                "SELECT ai_provider, ai_base_url, ai_api_key, ai_model FROM settings WHERE id = 1",
                [],
                |r| Ok((r.get(0)?, r.get(1)?, r.get(2)?, r.get(3)?))
            ).ok();
            match row {
                Some(r) => r,
                None => return Err(AppError::Config("AI 未配置".into())),
            }
        };

        let api_key = match api_key_enc.and_then(|e| if e.is_empty() { None } else { Some(e) }) {
            Some(e) => crate::encryption::decrypt(&e).unwrap_or(e),
            None => return Err(AppError::Config("AI API key 未设置".into())),
        };

        let base_url = base_url.unwrap_or_else(|| {
            PRESETS.iter().find(|(k, _)| *k == provider).map(|(_, u)| u.to_string()).unwrap_or_default()
        });
        if base_url.is_empty() {
            return Err(AppError::Config(format!("provider {} 缺 base_url", provider)));
        }

        let model = model.unwrap_or_else(|| match provider.as_str() {
            "deepseek"    => "deepseek-chat",
            "openai"      => "gpt-4o-mini",
            "siliconflow" => "Qwen/Qwen2.5-7B-Instruct",
            "ollama"      => "llama3.2",
            _             => "gpt-4o-mini",
        }.to_string());

        Ok(AiConfig { provider, base_url, api_key, model })
    }

    /// 构造 client (含代理)
    pub fn build_client(&self, state: &SharedState) -> AppResult<Client> {
        let mut builder = Client::builder()
            .timeout(Duration::from_secs(180))  // AI 慢,给足
            .user_agent("nai-studio-desktop/2.0");
        if let Some(proxy) = state.proxy_url() {
            builder = builder.proxy(reqwest::Proxy::all(&proxy)
                .map_err(|e| AppError::Config(format!("proxy {}: {}", proxy, e)))?);
        }
        builder.build().map_err(|e| AppError::Config(format!("reqwest: {}", e)))
    }
}

/// Chat message (text or vision)
#[derive(Debug, Clone)]
pub enum Message {
    /// 纯文本
    Text(String),
    /// Vision: 文本 + 图像 (data URI 或 https URL)
    Vision { text: String, image: String },
}

impl Message {
    /// 转 OpenAI 兼容格式
    fn to_json(&self) -> Value {
        match self {
            Message::Text(t) => json!({"role": "user", "content": t}),
            Message::Vision { text, image } => json!({
                "role": "user",
                "content": [
                    {"type": "text", "text": text},
                    {"type": "image_url", "image_url": {"url": image}},
                ]
            }),
        }
    }
}

/// Chat completion 选项
#[derive(Debug, Clone, Default)]
pub struct ChatOptions {
    pub max_tokens: Option<u32>,
    pub temperature: Option<f32>,
    pub json_mode: bool,           // 强制 JSON 模式
}

impl ChatOptions {
    pub fn with_max_tokens(mut self, n: u32) -> Self { self.max_tokens = Some(n); self }
    pub fn with_temperature(mut self, t: f32) -> Self { self.temperature = Some(t); self }
    pub fn with_json_mode(mut self) -> Self { self.json_mode = true; self }
}

/// Chat completion 响应
#[derive(Debug, Clone)]
pub struct ChatResponse {
    pub content: String,
    pub tokens_used: i64,
    pub ms: i64,
    pub model: String,
}

/// 调一次 Chat Completions (text 或 vision)
///   自动重试 1 次 (网络瞬断)
pub async fn chat(
    state: &SharedState,
    cfg: &AiConfig,
    messages: &[Message],
    opts: ChatOptions,
) -> AppResult<ChatResponse> {
    let client = cfg.build_client(state)?;
    let messages_json: Vec<Value> = messages.iter().map(|m| m.to_json()).collect();

    let mut body = json!({
        "model": cfg.model,
        "messages": messages_json,
    });
    if let Some(n) = opts.max_tokens {
        body["max_tokens"] = json!(n);
    } else {
        body["max_tokens"] = json!(2048);
    }
    if let Some(t) = opts.temperature {
        body["temperature"] = json!(t);
    } else {
        body["temperature"] = json!(0.3);
    }
    if opts.json_mode {
        body["response_format"] = json!({"type": "json_object"});
    }

    let start = std::time::Instant::now();
    let mut last_err = String::new();
    for attempt in 0..2 {
        let req = client.post(&cfg.base_url)
            .header("Authorization", format!("Bearer {}", cfg.api_key))
            .header("Content-Type", "application/json")
            .json(&body);
        let resp = match req.send().await {
            Ok(r) => r,
            Err(e) => {
                last_err = format!("network: {}", e);
                if attempt == 0 { tokio::time::sleep(Duration::from_millis(800)).await; continue; }
                return Err(AppError::Upstream(last_err));
            }
        };
        let status = resp.status();
        let text = resp.text().await.unwrap_or_default();
        if !status.is_success() {
            let snippet = text.chars().take(400).collect::<String>();
            return Err(AppError::Upstream(format!("{} HTTP {}: {}", cfg.provider, status.as_u16(), snippet)));
        }
        let parsed: Value = serde_json::from_str(&text).unwrap_or_default();
        let content = parsed.get("choices")
            .and_then(|c| c.get(0))
            .and_then(|c| c.get("message"))
            .and_then(|m| m.get("content"))
            .and_then(|c| c.as_str())
            .unwrap_or("")
            .to_string();
        if content.is_empty() {
            return Err(AppError::Upstream(format!("{} 返回空 content: {}", cfg.provider, text.chars().take(200).collect::<String>())));
        }
        let tokens = parsed.get("usage")
            .and_then(|u| u.get("total_tokens"))
            .and_then(|t| t.as_i64())
            .unwrap_or(0);
        let ms = start.elapsed().as_millis() as i64;
        return Ok(ChatResponse { content, tokens_used: tokens, ms, model: cfg.model.clone() });
    }
    Err(AppError::Upstream(last_err))
}

/// 加载本地图片并转 data URI (用于 vision)
pub fn read_image_as_data_uri(path: &str, max_dim: u32) -> AppResult<String> {
    use std::path::Path;
    use base64::Engine;

    let p = Path::new(path);
    if !p.is_file() {
        return Err(AppError::NotFound(format!("image not found: {}", path)));
    }
    let img = image::open(p).map_err(|e| AppError::Config(format!("open image: {}", e)))?;
    // 等比缩放,最长边 ≤ max_dim
    let img = if img.width().max(img.height()) > max_dim {
        let ratio = max_dim as f32 / img.width().max(img.height()) as f32;
        let nw = (img.width() as f32 * ratio).round() as u32;
        let nh = (img.height() as f32 * ratio).round() as u32;
        img.resize(nw, nh, image::imageops::FilterType::Lanczos3)
    } else { img };
    let mut buf = Vec::new();
    let mut cursor = std::io::Cursor::new(&mut buf);
    img.write_to(&mut cursor, image::ImageFormat::Jpeg)
        .map_err(|e| AppError::Config(format!("encode jpeg: {}", e)))?;
    let b64 = base64::engine::general_purpose::STANDARD.encode(&buf);
    Ok(format!("data:image/jpeg;base64,{}", b64))
}
