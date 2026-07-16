//! /api/ai_analyze -- AI 图像分析 (支持 text-only 和 vision)
//!
//! 跟 NAI Studio PHP 项目 ai_analyze.php 等价
//!   POST {image_path: '...', prompt?: '...', mode?: 'describe'|'prompt'|'style'|'tags'}
//!   Returns { ok, analysis, tokens_used, ms, mode, model }
//!
//! Mode:
//!   - describe: 详细描述图 (默认)
//!   - prompt:   生成 NAI prompt (English, comma-separated tags)
//!   - style:    风格分析
//!   - tags:     拆分成 Danbooru tag 分类

use std::path::Path;

use axum::Json;
use axum::extract::State;
use serde_json::{Value, json};

use crate::api::SharedState;
use crate::api::ai_client::{self, AiConfig, ChatOptions, Message};
use crate::error::AppResult;

const DEFAULT_DESCRIBE: &str = "请用中文详细描述这张图片的内容、风格、构图、人物特征、配色、光照等关键信息(150字内)。";
const PROMPT_MODE: &str = "请为这张图生成 NovelAI 用的 prompt 标签 (英文, 逗号分隔)。\
按 Danbooru 风格: 1girl/solo/外观/服装/姿势/视角/背景/质量标签, 30 个以内。\
只要 prompt 本身,不要其他解释。";
const STYLE_MODE: &str = "请用中文分析这张图片的艺术风格 (画师风格 / 渲染 / 配色 / 光照 / 氛围), 100 字内。";
const TAGS_MODE: &str = "请把图中的关键元素拆分成 Danbooru 标签分类, 严格按 JSON 输出:\
{\"general\":[],\"artist\":[],\"character\":[],\"copyright\":[],\"meta\":[]}\
每个数组 5-15 个英文 tag (snake_case)。";

pub async fn handle(
    State(state): State<SharedState>,
    Json(body): Json<Value>,
) -> AppResult<Json<Value>> {
    let image_path = body.get("image_path").and_then(|v| v.as_str());
    let mode = body.get("mode").and_then(|v| v.as_str()).unwrap_or("describe");
    let user_prompt = body.get("prompt").and_then(|v| v.as_str());

    if image_path.is_none() {
        return Ok(Json(json!({"ok": false, "error": "image_path required"})));
    }
    let image_path = image_path.unwrap();

    // 检查文件
    let abs = if let Some(rel) = image_path.strip_prefix("/storage/") {
        state.paths.storage.join(rel)
    } else {
        std::path::PathBuf::from(image_path)
    };
    if !abs.is_file() {
        return Ok(Json(json!({"ok": false, "error": format!("file not found: {}", image_path)})));
    }

    // 选 prompt
    let prompt_text = user_prompt.map(|s| s.to_string()).unwrap_or_else(|| match mode {
        "prompt" => PROMPT_MODE.to_string(),
        "style"  => STYLE_MODE.to_string(),
        "tags"   => TAGS_MODE.to_string(),
        _        => DEFAULT_DESCRIBE.to_string(),
    });

    // 读 AI config
    let cfg = match AiConfig::load(&state) {
        Ok(c) => c,
        Err(e) => return Ok(Json(json!({"ok": false, "error": format!("AI config: {}", e)}))),
    };

    // 调 AI (vision)
    let data_uri = match ai_client::read_image_as_data_uri(abs.to_str().unwrap_or(""), 1024) {
        Ok(u) => u,
        Err(e) => return Ok(Json(json!({"ok": false, "error": format!("read image: {}", e)}))),
    };
    let messages = vec![Message::Vision { text: prompt_text, image: data_uri }];
    let mut opts = ChatOptions::default()
        .with_max_tokens(if mode == "describe" { 600 } else { 800 })
        .with_temperature(0.4);
    if mode == "tags" { opts = opts.with_json_mode(); }

    let resp = match ai_client::chat(&state, &cfg, &messages, opts).await {
        Ok(r) => r,
        Err(e) => return Ok(Json(json!({"ok": false, "error": e.to_string()}))),
    };

    let mut out = json!({
        "ok": true,
        "analysis": resp.content,
        "tokens_used": resp.tokens_used,
        "ms": resp.ms,
        "model": resp.model,
        "mode": mode,
        "provider": cfg.provider,
    });
    if mode == "tags" {
        // 尝试 parse JSON
        if let Ok(parsed) = serde_json::from_str::<Value>(&resp.content) {
            out["tags_json"] = parsed;
        }
    }
    // suppress unused
    let _ = Path::new(image_path);
    Ok(Json(out))
}
