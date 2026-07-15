//! /api/import_meta -- 导入图片 metadata
//!
//! 跟 NAI Studio PHP 项目 import_meta.php 等价
//!   POST {path: '/storage/...'} 或 {base64: '...'}
//!   Returns { prompt, negative, seed, steps, scale, sampler, model, width, height }
//!
//! Phase 3.3: 基础版 — 读 PNG tEXt chunks (NAI/SD-style) + EXIF (jpeg)
//! Phase 4: save_as_generation 创建 DB record

use std::io::Read;
use std::path::PathBuf;

use axum::Json;
use axum::extract::State;
use serde_json::{Value, json};

use crate::api::SharedState;
use crate::error::{AppError, AppResult};

pub async fn handle(
    State(state): State<SharedState>,
    Json(body): Json<Value>,
) -> AppResult<Json<Value>> {
    let path = body.get("path").and_then(|v| v.as_str());
    let base64 = body.get("base64").and_then(|v| v.as_str());

    let abs = if let Some(p) = path {
        resolve_path(&state, p)?
    } else if let Some(b64) = base64 {
        // 解 base64 写临时文件
        let clean = if let Some(idx) = b64.find(",") { &b64[idx+1..] } else { b64 };
        let bytes = base64_decode(clean)
            .map_err(|e| AppError::BadRequest(format!("base64: {}", e)))?;
        let tmp_dir = state.paths.uploads.join("_import");
        std::fs::create_dir_all(&tmp_dir).map_err(|e| AppError::Io(format!("mkdir: {}", e)))?;
        let tmp = tmp_dir.join(format!("import_{}.png", chrono::Utc::now().timestamp_millis()));
        std::fs::write(&tmp, &bytes).map_err(|e| AppError::Io(format!("write: {}", e)))?;
        tmp
    } else {
        return Err(AppError::BadRequest("path or base64 required".into()));
    };

    if !abs.is_file() {
        return Err(AppError::NotFound(format!("File not found: {}", abs.display())));
    }

    let meta = extract_png_text_chunks(&abs).unwrap_or_default();
    let info = parse_nai_metadata(&meta);
    let (w, h) = image::image_dimensions(&abs).unwrap_or((0, 0));

    Ok(Json(json!({
        "ok": true,
        "path": abs.to_string_lossy(),
        "info": {
            "prompt": info.prompt,
            "negative": info.negative,
            "seed": info.seed,
            "steps": info.steps,
            "scale": info.scale,
            "sampler": info.sampler,
            "model": info.model,
            "width": w,
            "height": h,
        },
        "raw_text_chunks": meta,
    })))
}

fn resolve_path(state: &SharedState, p: &str) -> AppResult<PathBuf> {
    // 接受 "/storage/..." 或绝对路径
    if let Some(rel) = p.strip_prefix("/storage/") {
        Ok(state.paths.storage.join(rel))
    } else if PathBuf::from(p).is_absolute() {
        Ok(PathBuf::from(p))
    } else {
        Err(AppError::BadRequest(format!("Cannot resolve path: {}", p)))
    }
}

/// 从 PNG 读 tEXt/zTXt/iTXt chunks
pub fn extract_png_text_chunks(path: &PathBuf) -> Option<Vec<(String, String)>> {
    let mut file = std::fs::File::open(path).ok()?;
    let mut header = [0u8; 8];
    file.read_exact(&mut header).ok()?;
    if &header != b"\x89PNG\r\n\x1a\n" { return None; }

    let mut chunks: Vec<(String, String)> = Vec::new();
    let mut buf = Vec::new();
    loop {
        // length (4) + type (4)
        let mut len = [0u8; 4];
        if file.read_exact(&mut len).is_err() { break; }
        let length = u32::from_be_bytes(len) as usize;
        let mut kind = [0u8; 4];
        if file.read_exact(&mut kind).is_err() { break; }
        let kind_str = String::from_utf8_lossy(&kind).to_string();

        buf.resize(length, 0);
        if file.read_exact(&mut buf).is_err() { break; }
        // skip CRC (4)
        let mut crc = [0u8; 4];
        if file.read_exact(&mut crc).is_err() { break; }

        if kind_str == "tEXt" {
            // 1 null-separated: keyword\0text
            if let Some(idx) = buf.iter().position(|&b| b == 0) {
                let key = String::from_utf8_lossy(&buf[..idx]).to_string();
                let val = String::from_utf8_lossy(&buf[idx+1..]).to_string();
                chunks.push((key, val));
            }
        } else if kind_str == "iTXt" {
            // keyword\0compression_flag(1)compression_method(1)language\0translated_keyword\0text
            if let Some(idx) = buf.iter().position(|&b| b == 0) {
                let key = String::from_utf8_lossy(&buf[..idx]).to_string();
                let val = String::from_utf8_lossy(&buf[idx+3..]).to_string();
                chunks.push((key, val));
            }
        }
        if kind_str == "IEND" { break; }
    }
    Some(chunks)
}

#[derive(Default, Debug)]
struct NaiMeta {
    prompt: Option<String>,
    negative: Option<String>,
    seed: Option<i64>,
    steps: Option<i64>,
    scale: Option<f64>,
    sampler: Option<String>,
    model: Option<String>,
}

fn parse_nai_metadata(chunks: &[(String, String)]) -> NaiMeta {
    let mut info = NaiMeta::default();
    for (k, v) in chunks {
        match k.as_str() {
            "prompt" | "Description" | "UserComment" => {
                let p = strip_nai_negative(v);
                if p.neg.is_some() {
                    if info.prompt.is_none() { info.prompt = Some(p.pos); }
                    if info.negative.is_none() { info.negative = p.neg; }
                } else if info.prompt.is_none() {
                    info.prompt = Some(v.clone());
                }
            }
            "Negative prompt" => { info.negative = Some(v.clone()); }
            "seed" | "Seed" => { info.seed = v.parse().ok(); }
            "steps" | "Steps" => { info.steps = v.parse().ok(); }
            "scale" | "CFG scale" | "Guidance" => { info.scale = v.parse().ok(); }
            "sampler" | "Sampler" => { info.sampler = Some(v.clone()); }
            "model" | "Model" | "Software" => {
                if info.model.is_none() { info.model = Some(v.clone()); }
            }
            _ => {}
        }
    }
    info
}

/// NAI 风格 "Prompt: ... \nNegative prompt: ..."
struct PosNeg { pos: String, neg: Option<String> }
fn strip_nai_negative(s: &str) -> PosNeg {
    if let Some(idx) = s.find("Negative prompt:") {
        let pos = s[..idx].trim().to_string();
        let neg = s[idx + "Negative prompt:".len()..].trim().to_string();
        return PosNeg { pos, neg: if neg.is_empty() { None } else { Some(neg) } };
    }
    PosNeg { pos: s.trim().to_string(), neg: None }
}

fn base64_decode(s: &str) -> Result<Vec<u8>, String> {
    use base64::engine::general_purpose::STANDARD;
    use base64::Engine;
    STANDARD.decode(s).map_err(|e| format!("base64 decode failed: {}", e))
}
