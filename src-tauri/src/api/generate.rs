//! POST /api/generate
//!
//! 接收 NAI 生图请求，构造 payload，调 NaiApi，存图到画廊。

use std::sync::Arc;

use axum::Json;
use axum::extract::State;
use rusqlite::types::Value as SqlValue;
use serde_json::{Map, Value, json};

use crate::api::SharedState;
use crate::error::AppResult;

pub async fn generate(
    State(state): State<SharedState>,
    Json(body): Json<Value>,
) -> AppResult<Json<Value>> {
    let prompt = body.get("prompt").and_then(|v| v.as_str()).unwrap_or("").to_string();
    let neg = body.get("negative_prompt").and_then(|v| v.as_str()).unwrap_or("").to_string();
    let model = body.get("model").and_then(|v| v.as_str()).unwrap_or("nai-diffusion-4-5-curated").to_string();
    let sampler = body.get("sampler").and_then(|v| v.as_str()).unwrap_or("k_euler_ancestral").to_string();
    let steps = body.get("steps").and_then(|v| v.as_i64()).unwrap_or(28) as i64;
    let scale = body.get("scale").and_then(|v| v.as_f64()).unwrap_or(5.0) as f64;
    let seed = body.get("seed").and_then(|v| v.as_i64()).unwrap_or_else(|| rand::random::<i64>().abs() % 4294967296);
    let size = body.get("size").and_then(|v| v.as_str()).unwrap_or("832x1216").to_string();
    let (w, h) = parse_size(&size)?;
    let cfg_rescale = body.get("cfg_rescale").and_then(|v| v.as_f64()).unwrap_or(0.0) as f64;
    let noise = body.get("noise_schedule").and_then(|v| v.as_str()).unwrap_or("karras").to_string();
    let uc_preset = body.get("uc_preset").and_then(|v| v.as_i64()).unwrap_or(0) as i64;
    let quality = body.get("quality_toggle").and_then(|v| v.as_bool()).unwrap_or(true);
    let n_samples = body.get("n_samples").and_then(|v| v.as_i64()).unwrap_or(1).clamp(1, 4) as i64;
    let operation = body.get("operation").and_then(|v| v.as_str()).unwrap_or("generate");
    let batch_id = body.get("batch_id").and_then(|v| v.as_str()).map(|s| s.to_string());
    let strength = body.get("strength").and_then(|v| v.as_f64());
    let noise_val = body.get("noise").and_then(|v| v.as_f64());
    let base_image = body.get("base_image").and_then(|v| v.as_str()).map(|s| s.to_string());
    let mask = body.get("mask").and_then(|v| v.as_str()).map(|s| s.to_string());
    let characters = body.get("characters").cloned();
    let vibe_refs = body.get("vibe_refs").cloned();
    let precise_refs = body.get("precise_refs").cloned();

    // 构造 NAI payload
    let is_v4 = model.starts_with("nai-diffusion-4");
    let mut params = json!({
        "params_version": 3,
        "width": w, "height": h,
        "scale": scale, "sampler": sampler, "steps": steps,
        "n_samples": n_samples,
        "ucPreset": uc_preset,
        "qualityToggle": quality,
        "cfg_rescale": cfg_rescale,
        "noise_schedule": noise,
        "seed": seed,
    });

    if is_v4 {
        let v4_extra = json!({
            "autoSmea": false,
            "dynamic_thresholding": false,
            "controlnet_strength": 1,
            "legacy": false,
            "legacy_v3_extend": false,
            "use_coords": false,
            "legacy_uc": false,
            "normalize_reference_strength_multiple": true,
            "deliberate_euler_ancestral_bug": false,
            "prefer_brownian": true,
            "image_format": "png",
            "characterPrompts": [],
            "v4_prompt": {
                "caption": {
                    "base_caption": prompt,
                    "char_captions": [],
                },
                "use_coords": false,
                "use_order": true,
            },
            "v4_negative_prompt": {
                "caption": {
                    "base_caption": neg,
                    "char_captions": [],
                },
                "legacy_uc": false,
            },
        });
        merge_json(&mut params, &v4_extra);
    } else {
        let v3_extra = json!({
            "sm": cfg_rescale > 0.0,
            "sm_dyn": false,
            "dynamic_threshold": cfg_rescale,
            "controlnet_model": "none",
            "add_original_image": true,
            "legacy": false,
            "reference_image_multiple": [],
            "reference_information_extracted_multiple": [],
            "reference_strength_multiple": [],
        });
        merge_json(&mut params, &v3_extra);
        if !neg.is_empty() {
            params.as_object_mut().unwrap().insert("negative_prompt".to_string(), json!(neg));
        }
    }

    // Img2Img / Inpaint
    if let Some(b64) = &base_image {
        let clean = strip_data_url(b64);
        params.as_object_mut().unwrap().insert("image".to_string(), json!(clean));
        if let Some(s) = strength {
            params.as_object_mut().unwrap().insert("strength".to_string(), json!(s));
        }
        if let Some(n) = noise_val {
            params.as_object_mut().unwrap().insert("noise".to_string(), json!(n));
        }
        if let Some(m) = &mask {
            let clean = strip_data_url(m);
            params.as_object_mut().unwrap().insert("mask".to_string(), json!(clean));
        }
    }

    // Characters / Vibe / Precise refs（简单把外部 base64 读出来再放进去）
    if let Some(chars) = &characters {
        if let Some(arr) = chars.as_array() {
            let nai_chars: Vec<Value> = arr.iter().map(|c| json!({
                "prompt": c.get("prompt").and_then(|v| v.as_str()).unwrap_or(""),
                "uc": c.get("negative").and_then(|v| v.as_str()).unwrap_or(""),
                "center": c.get("position").cloned().unwrap_or(json!({"x": 0.5, "y": 0.5})),
                "enabled": true,
            })).collect();
            params.as_object_mut().unwrap().insert("characters".to_string(), json!(nai_chars));
        }
    }
    if let Some(refs) = &vibe_refs {
        if let Some(arr) = refs.as_array() {
            for vr in arr {
                if let Some(p) = vr.get("path").and_then(|v| v.as_str()) {
                    if let Ok(b) = read_user_image_b64(state.paths.storage.join(strip_storage_prefix(p))) {
                        push_to(params.as_object_mut().unwrap(), "reference_image_multiple", json!(b));
                        push_to(params.as_object_mut().unwrap(), "reference_information_extracted_multiple",
                                json!(vr.get("info_extracted").cloned().unwrap_or(Value::Null)));
                        push_to(params.as_object_mut().unwrap(), "reference_strength_multiple",
                                json!(vr.get("strength").and_then(|v| v.as_f64()).unwrap_or(0.6)));
                    }
                }
            }
        }
    }
    if let Some(refs) = &precise_refs {
        if let Some(arr) = refs.as_array() {
            for pr in arr {
                if let Some(p) = pr.get("path").and_then(|v| v.as_str()) {
                    if let Ok(b) = read_user_image_b64(state.paths.storage.join(strip_storage_prefix(p))) {
                        push_to(params.as_object_mut().unwrap(), "reference_image_multiple", json!(b));
                        push_to(params.as_object_mut().unwrap(), "reference_information_extracted_multiple",
                                json!(pr.get("info_extracted").cloned().unwrap_or(Value::Null)));
                        push_to(params.as_object_mut().unwrap(), "reference_strength_multiple",
                                json!(pr.get("strength").and_then(|v| v.as_f64()).unwrap_or(0.6)));
                    }
                }
            }
        }
    }

    let payload = json!({
        "input": prompt,
        "model": model,
        "action": "generate",
        "parameters": params,
    });

    // 调 NaiApi
    let nai_resp = crate::nai_api::generate(Arc::clone(&state), payload).await?;
    if !nai_resp.status.eq(&200) {
        return Err(crate::error::AppError::Upstream(nai_resp.error.unwrap_or_else(|| "NAI failed".into())));
    }
    let images = nai_resp.data.unwrap_or_default();
    if images.is_empty() {
        return Err(crate::error::AppError::Upstream("no images returned".into()));
    }

    // 保存到画廊
    let mut items = Vec::new();
    for (idx, b64) in images.iter().enumerate() {
        let this_seed = (seed + idx as i64) & 0xFFFFFFFF;
        let row_id = insert_generation(&state, &common_params(&body, &prompt, &neg, &model, &sampler, steps, scale, this_seed, w, h, cfg_rescale, &noise, uc_preset, quality, operation, batch_id.as_deref(), strength, noise_val, characters.as_ref(), vibe_refs.as_ref(), precise_refs.as_ref()))?;
        let row_id_str = row_id.to_string();

        // 写文件
        let subdir = if row_id_str.len() >= 2 { &row_id_str[..2] } else { "00" };
        let dir = state.paths.images.join(subdir);
        std::fs::create_dir_all(&dir).ok();
        let id_md5 = format!("{:x}", md5_short(row_id));
        let id_md5_short = if id_md5.len() >= 8 { &id_md5[..8] } else { &id_md5 };
        let filename = format!("{}_{}.png", row_id_str, id_md5_short);
        let abs = dir.join(&filename);
        if let Ok(bytes) = base64_decode(b64) {
            if std::fs::write(&abs, &bytes).is_ok() {
                let rel = format!("/storage/images/{}/{}", subdir, filename);
                let (img_w, img_h) = image_dimensions(&bytes);
                // 缩略图
                let thumb_rel = make_thumbnail(&state, &abs, &rel, img_w, img_h);
                let conn = state.db.lock();
                conn.execute(
                    "UPDATE generations SET image_path = ?, thumbnail_path = ?, image_width = ?, image_height = ?, image_size_bytes = ? WHERE id = ?",
                    rusqlite::params![rel, thumb_rel, img_w, img_h, bytes.len() as i64, row_id]
                )?;
                items.push(json!({"id": row_id, "image_path": rel, "width": img_w, "height": img_h, "ms": nai_resp.ms}));
            }
        }
    }

    Ok(Json(json!({
        "ok": true,
        "items": items,
        "ms": nai_resp.ms,
    })))
}

fn parse_size(s: &str) -> AppResult<(i64, i64)> {
    let m: Vec<&str> = s.split('x').collect();
    if m.len() != 2 { return Err(crate::error::AppError::BadRequest(format!("invalid size: {}", s))); }
    let w: i64 = m[0].parse().map_err(|_| crate::error::AppError::BadRequest("bad width".into()))?;
    let h: i64 = m[1].parse().map_err(|_| crate::error::AppError::BadRequest("bad height".into()))?;
    Ok((w, h))
}

fn merge_json(a: &mut Value, b: &Value) {
    if let (Some(am), Some(bm)) = (a.as_object_mut(), b.as_object()) {
        for (k, v) in bm {
            am.insert(k.clone(), v.clone());
        }
    }
}

fn push_to(obj: &mut Map<String, Value>, key: &str, val: Value) {
    obj.entry(key.to_string())
        .or_insert_with(|| Value::Array(Vec::new()))
        .as_array_mut()
        .unwrap()
        .push(val);
}

fn strip_data_url(b64: &str) -> String {
    if let Some(idx) = b64.find("base64,") {
        b64[idx + 7..].to_string()
    } else {
        b64.to_string()
    }
}

fn strip_storage_prefix(p: &str) -> &str {
    p.trim_start_matches("/storage/")
}

fn read_user_image_b64(abs: std::path::PathBuf) -> AppResult<String> {
    use base64::Engine;
    let bytes = std::fs::read(&abs).map_err(|e| crate::error::AppError::Io(e.to_string()))?;
    Ok(base64::engine::general_purpose::STANDARD.encode(&bytes))
}

fn base64_decode(b64: &str) -> AppResult<Vec<u8>> {
    use base64::Engine;
    base64::engine::general_purpose::STANDARD.decode(b64).map_err(|e| crate::error::AppError::Io(format!("base64: {}", e)))
}

fn md5_short(id: i64) -> u64 {
    use std::hash::{Hash, Hasher};
    let mut h = std::collections::hash_map::DefaultHasher::new();
    id.hash(&mut h);
    h.finish()
}

fn image_dimensions(bytes: &[u8]) -> (i64, i64) {
    // 简单 PNG/JPG 头部解析
    if bytes.len() >= 24 && bytes[..8] == [0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A] {
        // PNG: IHDR width/height 在 offset 16-24 big endian
        let w = u32::from_be_bytes([bytes[16], bytes[17], bytes[18], bytes[19]]) as i64;
        let h = u32::from_be_bytes([bytes[20], bytes[21], bytes[22], bytes[23]]) as i64;
        return (w, h);
    }
    (0, 0)
}

fn make_thumbnail(state: &SharedState, abs: &std::path::Path, rel: &str, w: i64, h: i64) -> Option<String> {
    if w <= 320 { return Some(rel.to_string()); }
    let img = image::open(abs).ok()?;
    let new_w = 320u32;
    let new_h = (h as u32 * new_w / w as u32).max(1);
    let thumb = img.thumbnail(new_w, new_h);
    let thumb_rel = rel.replace("/images/", "/thumbs/");
    let thumb_abs_final = state.paths.storage.join("thumbs").join(strip_storage_prefix(&thumb_rel));
    if let Some(parent) = thumb_abs_final.parent() {
        std::fs::create_dir_all(parent).ok();
    }
    thumb.save(&thumb_abs_final).ok()?;
    Some(thumb_rel)
}

#[allow(clippy::too_many_arguments)]
fn common_params<'a>(
    body: &'a Value,
    prompt: &'a str,
    neg: &'a str,
    model: &'a str,
    sampler: &'a str,
    steps: i64,
    scale: f64,
    seed: i64,
    w: i64,
    h: i64,
    cfg_rescale: f64,
    noise: &'a str,
    uc_preset: i64,
    quality: bool,
    operation: &'a str,
    batch_id: Option<&'a str>,
    strength: Option<f64>,
    noise_val: Option<f64>,
    characters: Option<&'a Value>,
    vibe_refs: Option<&'a Value>,
    precise_refs: Option<&'a Value>,
) -> Vec<(&'a str, SqlValue)> {
    let mut v: Vec<(&str, SqlValue)> = vec![
        ("prompt", SqlValue::Text(prompt.to_string())),
        ("negative_prompt", SqlValue::Text(neg.to_string())),
        ("model", SqlValue::Text(model.to_string())),
        ("sampler", SqlValue::Text(sampler.to_string())),
        ("steps", SqlValue::Integer(steps)),
        ("scale", SqlValue::Real(scale)),
        ("seed", SqlValue::Integer(seed)),
        ("width", SqlValue::Integer(w)),
        ("height", SqlValue::Integer(h)),
        ("cfg_rescale", SqlValue::Real(cfg_rescale)),
        ("noise_schedule", SqlValue::Text(noise.to_string())),
        ("uc_preset", SqlValue::Integer(uc_preset)),
        ("quality_toggle", SqlValue::Integer(if quality { 1 } else { 0 })),
        ("operation", SqlValue::Text(operation.to_string())),
    ];
    if let Some(b) = batch_id {
        v.push(("batch_id", SqlValue::Text(b.to_string())));
    }
    if let Some(s) = strength {
        v.push(("strength", SqlValue::Real(s)));
    }
    if let Some(n) = noise_val {
        v.push(("noise", SqlValue::Real(n)));
    }
    if let Some(c) = characters {
        v.push(("characters_json", SqlValue::Text(serde_json::to_string(c).unwrap_or_default())));
    }
    if let Some(v_) = vibe_refs {
        v.push(("vibe_refs_json", SqlValue::Text(serde_json::to_string(v_).unwrap_or_default())));
    }
    if let Some(p) = precise_refs {
        v.push(("precise_refs_json", SqlValue::Text(serde_json::to_string(p).unwrap_or_default())));
    }
    // body 里的 meta_json
    if let Some(m) = body.get("meta_json") {
        v.push(("meta_json", SqlValue::Text(serde_json::to_string(m).unwrap_or_default())));
    }
    v
}

fn insert_generation(state: &SharedState, params: &[(&str, SqlValue)]) -> AppResult<i64> {
    let cols: Vec<&str> = params.iter().map(|(k, _)| *k).collect();
    let placeholders: Vec<String> = cols.iter().map(|_| "?".to_string()).collect();
    let sql = format!("INSERT INTO generations ({}) VALUES ({})",
                      cols.iter().map(|c| format!("\"{}\"", c)).collect::<Vec<_>>().join(", "),
                      placeholders.join(", "));
    let conn = state.db.lock();
    let mut stmt = conn.prepare(&sql)?;
    let vals: Vec<&dyn rusqlite::ToSql> = params.iter().map(|(_, v)| v as &dyn rusqlite::ToSql).collect();
    stmt.execute(vals.as_slice())?;
    Ok(conn.last_insert_rowid())
}
