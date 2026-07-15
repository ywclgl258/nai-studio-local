//! /api/upload -- multipart 文件上传
//!
//! 跟 NAI Studio PHP 项目 upload.php 等价
//!   POST multipart/form-data, field "file" (image/*)
//!   Returns {path, url, info: {width, height, format, size}}

use std::path::Path;

use axum::Json;
use axum::extract::{Multipart, State};
use chrono::Utc;
use serde_json::{Value, json};
use sha2::{Digest, Sha256};
use uuid::Uuid;

use crate::api::SharedState;
use crate::error::{AppError, AppResult};

const MAX_SIZE: u64 = 20 * 1024 * 1024; // 20MB
const ALLOWED_EXT: &[&str] = &["png", "jpg", "jpeg", "webp"];

pub async fn handle(
    State(state): State<SharedState>,
    mut multipart: Multipart,
) -> AppResult<Json<Value>> {
    let mut file_bytes: Option<Vec<u8>> = None;
    let mut file_name: Option<String> = None;
    let mut mime: Option<String> = None;

    while let Some(field) = multipart.next_field().await.map_err(|e| AppError::BadRequest(format!("multipart: {}", e)))? {
        let name = field.name().unwrap_or("").to_string();
        if name != "file" { continue; }
        if let Some(fname) = field.file_name() {
            file_name = Some(fname.to_string());
        }
        if let Some(m) = field.content_type() {
            mime = Some(m.to_string());
        }
        let data = field.bytes().await.map_err(|e| AppError::BadRequest(format!("read file: {}", e)))?;
        if data.len() as u64 > MAX_SIZE {
            return Err(AppError::BadRequest(format!("File too large (max {}MB)", MAX_SIZE / 1024 / 1024)));
        }
        file_bytes = Some(data.to_vec());
    }

    let bytes = file_bytes.ok_or_else(|| AppError::BadRequest("No file uploaded".into()))?;
    let fname = file_name.ok_or_else(|| AppError::BadRequest("No filename".into()))?;

    // 取扩展名
    let ext = Path::new(&fname)
        .extension()
        .and_then(|s| s.to_str())
        .map(|s| s.to_lowercase())
        .ok_or_else(|| AppError::BadRequest("No extension".into()))?;
    if !ALLOWED_EXT.contains(&ext.as_str()) {
        return Err(AppError::BadRequest(format!("Unsupported file type: .{}", ext)));
    }

    // 计算 hash,做分桶
    let mut hasher = Sha256::new();
    hasher.update(&bytes);
    let hash = hasher.finalize();
    let hash_hex = hex::encode(&hash[..16]); // 32 字符够
    let hash_dir = &hash_hex[..2];

    let safe_base = Path::new(&fname).file_stem()
        .and_then(|s| s.to_str())
        .map(|s| s.chars().filter(|c| c.is_ascii_alphanumeric() || *c == '_' || *c == '-').collect::<String>())
        .unwrap_or_else(|| "upload".to_string());
    let timestamp = Utc::now().timestamp();
    let unique = Uuid::new_v4().to_string().split('-').next().unwrap_or("x").to_string();
    let stored_name = format!("{}_{}_{}.{}", timestamp, unique, safe_base, ext);

    let dir = state.paths.uploads.join(hash_dir);
    if !dir.is_dir() {
        std::fs::create_dir_all(&dir).map_err(|e| AppError::Io(format!("mkdir: {}", e)))?;
    }
    let abs_path = dir.join(&stored_name);
    std::fs::write(&abs_path, &bytes).map_err(|e| AppError::Io(format!("write: {}", e)))?;

    // 读图片信息(用 image crate)
    let info = read_image_info(&abs_path);

    // 拼 URL 路径(前端通过 /api/file/... 访问)
    let rel = format!("/storage/uploads/{}/{}", hash_dir, stored_name);

    log::info!("[upload] file={} ({} bytes) -> {}", fname, bytes.len(), abs_path.display());

    Ok(Json(json!({
        "ok": true,
        "path": rel,
        "url": rel,
        "info": info,
        "size": bytes.len(),
        "mime": mime,
        "hash": format!("sha256:{}", hex::encode(hash)),
    })))
}

/// 用 image crate 读图片 metadata
fn read_image_info(path: &Path) -> Value {
    match image::image_dimensions(path) {
        Ok((w, h)) => {
            let ext = path.extension().and_then(|s| s.to_str()).unwrap_or("").to_lowercase();
            let format = match ext.as_str() {
                "png"  => "png",
                "jpg" | "jpeg" => "jpeg",
                "webp" => "webp",
                _      => "unknown",
            };
            json!({
                "width": w,
                "height": h,
                "format": format,
            })
        }
        Err(_) => json!({"width": 0, "height": 0, "format": "unknown"}),
    }
}
