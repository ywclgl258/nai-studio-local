//! /api/gallery/* -- generation history (Phase 2)
//!
//! 跟 NAI Studio PHP 项目 GalleryManager 等价：
//!   - GET    /api/gallery?page=&per_page=&model=&favorite=&search=  -> list
//!   - GET    /api/gallery?id=N                                     -> single
//!   - GET    /api/gallery/zip?favorite=1                           -> zip download
//!   - POST   /api/gallery  {action:favorite,id,value}              -> toggle fav
//!   - POST   /api/gallery  {action:notes,id,value}                  -> update notes
//!   - POST   /api/gallery/clear  {include_favorites}                -> clear
//!   - DELETE /api/gallery?id=N&hard=1                              -> soft / hard delete

use axum::Json;
use axum::body::Body;
use axum::extract::{Query, State};
use axum::http::header;
use axum::http::StatusCode;
use axum::response::{IntoResponse, Response};
use rusqlite::OptionalExtension;
use serde::Serialize;
use serde_json::{Map, Value, json};
use std::collections::HashMap;
use std::io::Write;

use crate::api::SharedState;
use crate::error::{AppError, AppResult};

#[derive(Debug, Serialize)]
struct GalleryListItem {
    id: i64,
    batch_id: Option<String>,
    operation: String,
    model: String,
    sampler: String,
    steps: i64,
    scale: f64,
    seed: i64,
    width: i64,
    height: i64,
    cfg_rescale: f64,
    noise_schedule: String,
    uc_preset: i64,
    image_path: Option<String>,
    thumbnail_path: Option<String>,
    is_favorite: i64,
    notes: Option<String>,
    created_at: String,
    prompt_preview: Option<String>,
}

fn row_to_item(row: &rusqlite::Row) -> rusqlite::Result<GalleryListItem> {
    Ok(GalleryListItem {
        id: row.get(0)?,
        batch_id: row.get(1)?,
        operation: row.get(2)?,
        model: row.get(3)?,
        sampler: row.get(4)?,
        steps: row.get(5)?,
        scale: row.get(6)?,
        seed: row.get(7)?,
        width: row.get(8)?,
        height: row.get(9)?,
        cfg_rescale: row.get(10)?,
        noise_schedule: row.get(11)?,
        uc_preset: row.get(12)?,
        image_path: row.get(13)?,
        thumbnail_path: row.get(14)?,
        is_favorite: row.get(15)?,
        notes: row.get(16)?,
        created_at: row.get(17)?,
        prompt_preview: row.get(18)?,
    })
}

fn row_to_full_value(row: &rusqlite::Row, cols: &[String]) -> rusqlite::Result<Value> {
    let mut obj = Map::new();
    for (i, name) in cols.iter().enumerate() {
        let v: rusqlite::types::Value = row.get(i)?;
        let jv = match v {
            rusqlite::types::Value::Null => Value::Null,
            rusqlite::types::Value::Integer(i) => json!(i),
            rusqlite::types::Value::Real(f) => json!(f),
            rusqlite::types::Value::Text(s) => {
                if matches!(name.as_str(), "characters_json" | "vibe_refs_json" | "precise_refs_json" | "meta_json") {
                    serde_json::from_str(&s).unwrap_or(Value::Null)
                } else {
                    Value::String(s)
                }
            }
            rusqlite::types::Value::Blob(b) => json!(b),
        };
        obj.insert(name.clone(), jv);
    }
    Ok(Value::Object(obj))
}

/// GET /api/gallery?action=zip -> Response (zip download)
/// 否则 GET /api/gallery?id=N  -> single item
/// 否则 GET /api/gallery  -> list
pub async fn list(
    State(state): State<SharedState>,
    Query(params): Query<HashMap<String, String>>,
) -> AppResult<Response> {
    if params.get("action").map(|s| s.as_str()) == Some("zip") {
        return zip_inner(&state, &params).await;
    }
    if let Some(id_str) = params.get("id") {
        let id: i64 = id_str.parse().map_err(|_| AppError::BadRequest("id must be integer".into()))?;
        let json = get_single(&state, id).await?;
        return Ok(json.into_response());
    }
    let json = list_inner(&state, &params).await?;
    Ok(json.into_response())
}

async fn list_inner(state: &SharedState, params: &HashMap<String, String>) -> AppResult<Json<Value>> {
    let page = params.get("page").and_then(|s| s.parse().ok()).unwrap_or(1i64);
    let per_page = params.get("per_page").and_then(|s| s.parse().ok()).unwrap_or(30i64).clamp(1, 200);
    let only_fav = matches!(params.get("favorite").map(|s| s.as_str()), Some("1") | Some("true"));

    let offset = (page - 1) * per_page;
    let conn = state.db.lock();

    let mut wheres: Vec<String> = vec!["is_deleted = 0".to_string()];
    let mut where_params: Vec<Box<dyn rusqlite::ToSql>> = Vec::new();
    if let Some(m) = params.get("model") {
        wheres.push("model = ?".to_string());
        where_params.push(Box::new(m.clone()));
    }
    if only_fav {
        wheres.push("is_favorite = 1".to_string());
    }
    if let Some(s) = params.get("search") {
        wheres.push("(prompt LIKE ? OR negative_prompt LIKE ?)".to_string());
        where_params.push(Box::new(format!("%{}%", s)));
        where_params.push(Box::new(format!("%{}%", s)));
    }
    if let Some(d) = params.get("from_date") {
        wheres.push("created_at >= ?".to_string());
        where_params.push(Box::new(d.clone()));
    }
    if let Some(d) = params.get("to_date") {
        wheres.push("created_at <= ?".to_string());
        where_params.push(Box::new(d.clone()));
    }
    let where_sql = wheres.join(" AND ");

    let total: i64 = {
        let sql = format!("SELECT COUNT(*) FROM generations WHERE {}", where_sql);
        let refs: Vec<&dyn rusqlite::ToSql> = where_params.iter().map(|p| p.as_ref()).collect();
        conn.query_row(&sql, refs.as_slice(), |r| r.get(0))?
    };

    let sql = format!(
        "SELECT id, batch_id, operation, model, sampler, steps, scale, seed, width, height,
                cfg_rescale, noise_schedule, uc_preset, image_path, thumbnail_path,
                is_favorite, notes, created_at,
                LEFT(prompt, 120) AS prompt_preview
         FROM generations
         WHERE {}
         ORDER BY created_at DESC, id DESC
         LIMIT {} OFFSET {}",
        where_sql, per_page, offset
    );
    let refs: Vec<&dyn rusqlite::ToSql> = where_params.iter().map(|p| p.as_ref()).collect();
    let mut stmt = conn.prepare(&sql)?;
    let rows = stmt.query_map(refs.as_slice(), row_to_item)?
        .collect::<Result<Vec<_>, _>>()?;

    let pages = (total as f64 / per_page as f64).ceil() as i64;
    Ok(Json(json!({
        "ok": true,
        "rows": rows,
        "total": total,
        "page": page,
        "per_page": per_page,
        "pages": pages,
    })))
}

async fn get_single(state: &SharedState, id: i64) -> AppResult<Json<Value>> {
    let conn = state.db.lock();
    let mut stmt = conn.prepare("SELECT * FROM generations WHERE id = ? AND is_deleted = 0")?;
    let col_names: Vec<String> = stmt.column_names().into_iter().map(|c| c.to_string()).collect();
    let row = stmt.query_row([id], |r| row_to_full_value(r, &col_names)).optional()?;
    match row {
        Some(v) => Ok(Json(json!({"ok": true, "item": v}))),
        None => Err(AppError::NotFound(format!("gallery item #{}", id))),
    }
}

pub async fn action(
    State(state): State<SharedState>,
    Json(body): Json<Value>,
) -> AppResult<Json<Value>> {
    let obj = body.as_object().ok_or_else(|| AppError::BadRequest("body must be object".into()))?;
    let action = obj.get("action").and_then(|v| v.as_str()).unwrap_or("");
    let id = obj.get("id").and_then(|v| v.as_i64()).ok_or_else(|| AppError::BadRequest("id required".into()))?;

    let conn = state.db.lock();
    match action {
        "favorite" => {
            let v = if obj.get("value").and_then(|x| x.as_bool()).unwrap_or(false) { 1 } else { 0 };
            conn.execute("UPDATE generations SET is_favorite = ? WHERE id = ?", rusqlite::params![v, id])?;
            Ok(Json(json!({"ok": true, "id": id, "is_favorite": v})))
        }
        "notes" => {
            let notes = obj.get("value").and_then(|v| v.as_str()).unwrap_or("").to_string();
            conn.execute("UPDATE generations SET notes = ? WHERE id = ?", rusqlite::params![notes, id])?;
            Ok(Json(json!({"ok": true, "id": id})))
        }
        "clear_all" => Err(AppError::BadRequest("clear_all handled by /api/gallery/clear".into())),
        _ => Err(AppError::BadRequest(format!("unknown action: {}", action))),
    }
}

pub async fn clear(
    State(state): State<SharedState>,
    Json(body): Json<Value>,
) -> AppResult<Json<Value>> {
    let include_fav = body.get("include_favorites").and_then(|v| v.as_bool()).unwrap_or(false);
    let conn = state.db.lock();

    let rows: Vec<(Option<String>, Option<String>)> = if include_fav {
        let mut stmt = conn.prepare("SELECT image_path, thumbnail_path FROM generations")?;
        let collected: Vec<rusqlite::Result<(Option<String>, Option<String>)>> = stmt.query_map([], |r| Ok((r.get(0)?, r.get(1)?)))?.collect();
        drop(stmt);
        collected.into_iter().collect::<Result<Vec<_>, _>>()?
    } else {
        let mut stmt = conn.prepare("SELECT image_path, thumbnail_path FROM generations WHERE is_favorite = 0")?;
        let collected: Vec<rusqlite::Result<(Option<String>, Option<String>)>> = stmt.query_map([], |r| Ok((r.get(0)?, r.get(1)?)))?.collect();
        drop(stmt);
        collected.into_iter().collect::<Result<Vec<_>, _>>()?
    };

    let mut files_deleted = 0;
    for (img, thumb) in rows {
        for f in [img, thumb] {
            if let Some(p) = f {
                if let Some(stripped) = p.strip_prefix("/storage/") {
                    let abs = state.paths.storage.join(stripped);
                    if std::fs::metadata(&abs).is_ok() {
                        let _ = std::fs::remove_file(&abs);
                        files_deleted += 1;
                    }
                }
            }
        }
    }

    let count = if include_fav {
        conn.execute("DELETE FROM generations", [])?
    } else {
        conn.execute("DELETE FROM generations WHERE is_favorite = 0", [])?
    };

    Ok(Json(json!({
        "ok": true,
        "deleted": count,
        "files": files_deleted,
    })))
}

async fn zip_inner(state: &SharedState, params: &HashMap<String, String>) -> AppResult<Response> {
    let only_fav = matches!(params.get("favorite").map(|s| s.as_str()), Some("1") | Some("true"));

    let conn = state.db.lock();
    let sql = if only_fav {
        "SELECT id, image_path, thumbnail_path, model, sampler, steps, scale, seed, width, height,
                is_favorite, notes, created_at, prompt, negative_prompt
         FROM generations WHERE is_favorite = 1 ORDER BY created_at DESC, id DESC LIMIT 500"
    } else {
        "SELECT id, image_path, thumbnail_path, model, sampler, steps, scale, seed, width, height,
                is_favorite, notes, created_at, prompt, negative_prompt
         FROM generations WHERE is_deleted = 0 ORDER BY created_at DESC, id DESC LIMIT 500"
    };
    let mut stmt = conn.prepare(sql)?;

    // 15 个字段：id, image_path, thumbnail_path, model, sampler, steps, scale, seed, width, height,
    // is_favorite, notes, created_at, prompt, negative_prompt
    let mapped: Vec<rusqlite::Result<(i64, Option<String>, Option<String>, Option<String>, Option<String>,
                                     i64, f64, i64, i64, i64, i64, Option<String>, String, String, Option<String>)>> =
        stmt.query_map([], |r| {
            Ok((
                r.get::<_, i64>(0)?,
                r.get::<_, Option<String>>(1)?,
                r.get::<_, Option<String>>(2)?,
                r.get::<_, Option<String>>(3)?,
                r.get::<_, Option<String>>(4)?,
                r.get::<_, i64>(5)?,
                r.get::<_, f64>(6)?,
                r.get::<_, i64>(7)?,
                r.get::<_, i64>(8)?,
                r.get::<_, i64>(9)?,
                r.get::<_, i64>(10)?,
                r.get::<_, Option<String>>(11)?,
                r.get::<_, String>(12)?,
                r.get::<_, String>(13)?,
                r.get::<_, Option<String>>(14)?,
            ))
        })?.collect();
    drop(stmt);
    drop(conn);

    let mut rows: Vec<(i64, Option<String>, Option<String>, Option<String>, Option<String>,
                       i64, f64, i64, i64, i64, i64, Option<String>, String, String, Option<String>)> = Vec::new();
    for row in mapped {
        rows.push(row?);
    }

    let tmp_zip = std::env::temp_dir().join(format!("nai-studio-gallery-{}.zip", chrono::Local::now().format("%Y%m%d-%H%M%S")));
    let file = std::fs::File::create(&tmp_zip)?;
    let mut zip = zip::ZipWriter::new(file);
    let options = zip::write::FileOptions::default()
        .compression_method(zip::CompressionMethod::Deflated);
    let mut seen_names = std::collections::HashSet::new();
    let mut manifest = Vec::new();

    for (id, img_path, _thumb, model, sampler, steps, scale, seed, w, h, is_fav, notes, created_at, prompt, neg_prompt) in rows.iter() {
        if let Some(rel) = img_path {
            let rel = rel.trim_start_matches("/storage/");
            let abs = state.paths.storage.join(rel);
            if !abs.is_file() { continue; }
            let ts_short = if created_at.len() >= 10 { &created_at[..10] } else { "x" };
            let base = format!("{}_seed{}_{}x{}", ts_short.replace([':', ' '], "-"), seed, w, h);
            let mut name = format!("{}.png", base);
            let mut i = 1;
            while seen_names.contains(&name) {
                name = format!("{}_{}.png", base, i);
                i += 1;
            }
            seen_names.insert(name.clone());

            zip.start_file(&name, options).map_err(|e| AppError::Internal(format!("zip start: {}", e)))?;
            let mut f = std::fs::File::open(&abs)?;
            let _ = std::io::copy(&mut f, &mut zip);

            manifest.push(json!({
                "file": name,
                "id": id,
                "created_at": created_at,
                "model": model,
                "sampler": sampler,
                "steps": steps,
                "scale": scale,
                "seed": seed,
                "width": w,
                "height": h,
                "is_favorite": is_fav,
                "notes": notes,
                "prompt": prompt,
                "negative_prompt": neg_prompt,
            }));
        }
    }

    zip.start_file("manifest.json", options).map_err(|e| AppError::Internal(format!("zip: {}", e)))?;
    let manifest_str = serde_json::to_string_pretty(&manifest).unwrap_or_else(|_| "[]".to_string());
    zip.write_all(manifest_str.as_bytes()).map_err(|e| AppError::Io(e.to_string()))?;
    zip.start_file("README.txt", options).map_err(|e| AppError::Internal(format!("zip: {}", e)))?;
    let readme = format!("NAI Studio Gallery Export\nGenerated: {}\nTotal images: {}\n\nmanifest.json contains full prompt / seed / params.\n", chrono::Local::now().to_rfc3339(), manifest.len());
    zip.write_all(readme.as_bytes()).map_err(|e| AppError::Io(e.to_string()))?;
    zip.finish().map_err(|e| AppError::Internal(format!("zip finish: {}", e)))?;

    let bytes = std::fs::read(&tmp_zip)?;
    let _ = std::fs::remove_file(&tmp_zip);

    let filename = format!("nai-studio-{}.zip", chrono::Local::now().format("%Y%m%d-%H%M%S"));
    let resp = Response::builder()
        .status(StatusCode::OK)
        .header(header::CONTENT_TYPE, "application/zip")
        .header(header::CONTENT_DISPOSITION, format!("attachment; filename=\"{}\"", filename))
        .header(header::CONTENT_LENGTH, bytes.len())
        .body(Body::from(bytes))
        .map_err(|e| AppError::Internal(format!("response build: {}", e)))?;
    Ok(resp)
}

pub async fn zip(
    State(state): State<SharedState>,
    Query(params): Query<HashMap<String, String>>,
) -> AppResult<Response> {
    zip_inner(&state, &params).await
}

/// DELETE /api/gallery?id=N[&hard=1]  — soft / hard delete
pub async fn delete(
    State(state): State<SharedState>,
    Query(params): Query<HashMap<String, String>>,
) -> AppResult<Json<Value>> {
    let id: i64 = params.get("id").and_then(|s| s.parse().ok())
        .ok_or_else(|| AppError::BadRequest("id required".into()))?;
    let hard = matches!(params.get("hard").map(|s| s.as_str()), Some("1") | Some("true"));

    let conn = state.db.lock();
    if hard {
        let row: Option<(Option<String>, Option<String>)> = conn.query_row(
            "SELECT image_path, thumbnail_path FROM generations WHERE id = ?",
            [id],
            |r| Ok((r.get(0)?, r.get(1)?))
        ).optional()?;
        if let Some((img, thumb)) = row {
            for p in [img, thumb] {
                if let Some(rel) = p.and_then(|p| p.strip_prefix("/storage/").map(String::from)) {
                    let abs = state.paths.storage.join(&rel);
                    let _ = std::fs::remove_file(&abs);
                }
            }
        }
        conn.execute("DELETE FROM generations WHERE id = ?", [id])?;
    } else {
        conn.execute("UPDATE generations SET is_deleted = 1 WHERE id = ?", [id])?;
    }
    Ok(Json(json!({"ok": true, "id": id, "hard": hard})))
}
