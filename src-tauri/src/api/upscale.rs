//! Real-ESRGAN 无损放大
//!
//! POST /api/upscale
//!   body: { generation_id, scale, save_to_gallery? }
//!   scale: 2 | 4 | 8
//!
//! 8x 实现：先跑 4x Real-ESRGAN，再 GD LANCZOS 二次采样到 8x

use std::path::PathBuf;
use std::process::Command;

use axum::Json;
use axum::extract::State;
use base64::Engine;
use rusqlite::types::Value as SqlValue;
use serde_json::{Value, json};

use crate::api::SharedState;
use crate::error::{AppError, AppResult};

/// 工具根目录（%APPDATA%/nai-studio-desktop/storage/tools/realesrgan/）
fn tools_base(state: &SharedState) -> PathBuf {
    state.paths.tools.join("realesrgan")
}
fn binary_path(state: &SharedState) -> PathBuf {
    #[cfg(windows)]
    { tools_base(state).join("realesrgan-ncnn-vulkan.exe") }
    #[cfg(not(windows))]
    { tools_base(state).join("realesrgan-ncnn-vulkan") }
}
fn model_bin(state: &SharedState) -> PathBuf {
    tools_base(state).join("models").join("realesrgan-x4plus-anime.bin")
}
fn model_param(state: &SharedState) -> PathBuf {
    tools_base(state).join("models").join("realesrgan-x4plus-anime.param")
}

/// Real-ESRGAN 模型 + binary 是否就绪
pub fn is_ready(state: &SharedState) -> bool {
    binary_path(state).is_file()
        && model_bin(state).is_file()
        && model_param(state).is_file()
        && std::fs::metadata(model_bin(state)).map(|m| m.len() > 100_000).unwrap_or(false)
}

/// POST /api/upscale
/// body: { generation_id: int, scale: 2|4|8, save_to_gallery: bool }
pub async fn handle(
    State(state): State<SharedState>,
    Json(body): Json<Value>,
) -> AppResult<Json<Value>> {
    let generation_id = body.get("generation_id").and_then(|v| v.as_i64())
        .ok_or_else(|| AppError::BadRequest("generation_id required".into()))?;
    let scale = body.get("scale").and_then(|v| v.as_i64()).unwrap_or(4);
    if !matches!(scale, 2 | 4 | 8) {
        return Err(AppError::BadRequest(format!("unsupported scale {} (allowed: 2/4/8)", scale)));
    }
    let save_to_gallery = body.get("save_to_gallery").and_then(|v| v.as_bool()).unwrap_or(false);

    if !is_ready(&state) {
        return Err(AppError::UpscalerNotReady(
            "Real-ESRGAN not installed. Open Settings -> Real-ESRGAN section to download.".into()
        ));
    }

    // 拿原图
    let conn = state.db.lock();
    let row_res: rusqlite::Result<(i64, String, String, i64, i64, i64, Option<String>, Option<String>, i64)> = conn.query_row(
        "SELECT id, prompt, model, width, height, seed, negative_prompt, image_path, image_size_bytes FROM generations WHERE id = ?",
        [generation_id],
        |r| Ok((r.get(0)?, r.get(1)?, r.get(2)?, r.get(3)?, r.get(4)?, r.get(5)?, r.get(6)?, r.get(7)?, r.get(8)?))
    );
    let row_opt = match row_res {
        Ok(r) => Some(r),
        Err(rusqlite::Error::QueryReturnedNoRows) => None,
        Err(e) => return Err(AppError::Db(e)),
    };
    drop(conn);
    let _ = SqlValue::Null;  // suppress unused import warning

    let (_id, prompt, model, w0, h0, _seed, neg, image_path, _size_before) = match row_opt {
        Some(r) => r,
        None => return Err(AppError::NotFound(format!("generation #{}", generation_id))),
    };
    let image_path = image_path.ok_or_else(|| AppError::NotFound("source image_path missing".into()))?;
    let abs_src = storage_path(&state, &image_path);
    if !abs_src.is_file() {
        return Err(AppError::NotFound(format!("source image missing: {}", abs_src.display())));
    }

    // 跑 Real-ESRGAN (s = min(scale, 4))
    let real_scale = scale.min(4);
    let tmp_step1 = std::env::temp_dir().join(format!("upscale_step1_{}.png", uuid::Uuid::new_v4()));
    let bin = binary_path(&state);
    let bin_str = bin.to_string_lossy().to_string();
    let src_str = abs_src.to_string_lossy().to_string();
    let tmp_str = tmp_step1.to_string_lossy().to_string();
    // 关键坑：不要传 -j 2 / -g 0（v0.2.5.0 已知 bug）
    // 安全命令行：-i -o -n -s -f 五个
    let mut cmd = Command::new(&bin);
    cmd.arg("-i").arg(&abs_src)
       .arg("-o").arg(&tmp_step1)
       .arg("-n").arg("realesrgan-x4plus-anime")
       .arg("-s").arg(real_scale.to_string())
       .arg("-f").arg("png");

    let start = std::time::Instant::now();
    let output = cmd.output().map_err(|e| AppError::Internal(format!("spawn realesrgan: {}", e)))?;
    if !output.status.success() {
        let stderr = String::from_utf8_lossy(&output.stderr).to_string();
        let stdout = String::from_utf8_lossy(&output.stdout).to_string();
        let tail = format!("{}{}", stdout, stderr);
        let last3 = tail.lines().rev().take(3).collect::<Vec<_>>().join(" | ");
        return Err(AppError::Internal(format!("Real-ESRGAN exit {} · {}", output.status.code().unwrap_or(-1), last3)));
    }
    if !tmp_step1.is_file() {
        return Err(AppError::Internal("Real-ESRGAN: output file missing".into()));
    }

    // scale=8: 再 LANCZOS 2x 二次采样
    let final_out = if scale == 8 {
        let tmp_step2 = std::env::temp_dir().join(format!("upscale_step2_{}.png", uuid::Uuid::new_v4()));
        lanczos_2x(&tmp_step1, &tmp_step2)?;
        let _ = std::fs::remove_file(&tmp_step1);
        tmp_step2
    } else {
        tmp_step1
    };

    // 拿到实际尺寸
    let (w1, h1) = image_dimensions(&final_out);
    let size_after = std::fs::metadata(&final_out).map(|m| m.len() as i64).unwrap_or(0);
    let ms = start.elapsed().as_millis() as i64;

    // 准备输出路径
    let out_dir = state.paths.upscales.clone();
    std::fs::create_dir_all(&out_dir).ok();
    let ts = chrono::Local::now().format("%Y%m%d-%H%M%S").to_string();
    let out_filename = format!("{}_{}x_{}x{}_{}.png", generation_id, scale, w1, h1, &ts);
    let out_abs = out_dir.join(&out_filename);
    std::fs::copy(&final_out, &out_abs).map_err(|e| AppError::Io(e.to_string()))?;
    let _ = std::fs::remove_file(&final_out);
    let out_rel_url = format!("/storage/upscales/{}", out_filename);

    let mut result = json!({
        "id": Value::Null,
        "output_url": out_rel_url,
        "output_filename": out_filename,
        "width_after": w1,
        "height_after": h1,
        "size_bytes": size_after,
        "duration_ms": ms,
        "scale": scale,
        "model": "realesrgan-x4plus-anime",
        "parent_id": generation_id,
        "saved_to_gallery": false,
    });

    if save_to_gallery {
        // 写一条新 generations（operation='upscale', parent_id 指向原图）
        let prompt_display = if !prompt.is_empty() {
            format!("[Upscaled {}x from #{}] {}", scale, generation_id, &prompt[..prompt.len().min(200)])
        } else {
            format!("[Upscaled {}x from #{}]", scale, generation_id)
        };
        let meta = json!({
            "upscale_from": generation_id,
            "upscale_scale": scale,
            "upscale_model": "realesrgan-x4plus-anime",
            "duration_ms": ms,
            "w_before": w0,
            "h_before": h0,
        });
        let conn = state.db.lock();
        let prompt_str = prompt_display;
        let meta_str = serde_json::to_string(&meta).unwrap_or_else(|_| "{}".to_string());
        let neg_str = neg.unwrap_or_default();
        let out_rel_db = format!("/storage/upscales/{}", out_filename);
        conn.execute(
            "INSERT INTO generations (parent_id, operation, prompt, negative_prompt, model, sampler, steps, scale, seed, width, height, cfg_rescale, noise_schedule, uc_preset, quality_toggle, image_path, thumbnail_path, image_width, image_height, image_size_bytes, meta_json) VALUES (?, 'upscale', ?, ?, ?, ?, 0, 0, 0, ?, ?, 0, 'n/a', 0, 0, ?, ?, ?, ?, ?, ?)",
            rusqlite::params![
                generation_id,
                prompt_str,
                neg_str,
                model,
                "n/a",
                w1,
                h1,
                out_rel_db,
                out_rel_db,
                w1,
                h1,
                size_after,
                meta_str,
            ]
        )?;
        let new_id = conn.last_insert_rowid();
        result["id"] = json!(new_id);
        result["saved_to_gallery"] = json!(true);
    }

    Ok(Json(result))
}

/// 相对 /storage/ 的 path → 绝对路径（用 Path 组件重组成 OS 路径，避免 / \ 混合）
fn storage_path(state: &SharedState, rel_or_url: &str) -> PathBuf {
    let stripped = rel_or_url.trim_start_matches("/storage/");
    // 把 / 全部转成当前 OS 的 separator
    let normalized = stripped.replace('/', std::path::MAIN_SEPARATOR_STR);
    let mut out = state.paths.storage.clone();
    for part in normalized.split(std::path::MAIN_SEPARATOR).filter(|s| !s.is_empty()) {
        out.push(part);
    }
    out
}

/// GD LANCZOS 2x 二次采样（image crate）
fn lanczos_2x(in_path: &PathBuf, out_path: &PathBuf) -> AppResult<()> {
    let img = image::open(in_path).map_err(|e| AppError::Io(format!("open {}: {}", in_path.display(), e)))?;
    let w = img.width();
    let h = img.height();
    let new_w = w * 2;
    let new_h = h * 2;
    let resized = img.resize_exact(new_w, new_h, image::imageops::FilterType::Lanczos3);
    resized.save(out_path).map_err(|e| AppError::Io(format!("save {}: {}", out_path.display(), e)))?;
    Ok(())
}

/// 简单 PNG/JPG 尺寸解析
fn image_dimensions(path: &PathBuf) -> (i64, i64) {
    let bytes = match std::fs::read(path) {
        Ok(b) => b,
        Err(_) => return (0, 0),
    };
    if bytes.len() >= 24 && bytes[..8] == [0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A] {
        let w = u32::from_be_bytes([bytes[16], bytes[17], bytes[18], bytes[19]]) as i64;
        let h = u32::from_be_bytes([bytes[20], bytes[21], bytes[22], bytes[23]]) as i64;
        return (w, h);
    }
    (0, 0)
}
