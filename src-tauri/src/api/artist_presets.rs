//! /api/artist_presets -- 画师预设（含 items 子表）
//!
//! 跟 NAI Studio PHP 项目的 artist_presets.php 等价
//!   - GET    /api/artist_presets        -> 列表
//!   - POST   /api/artist_presets        -> CRUD (action:create|update|delete|use)
//!   - DELETE ?id=N

use std::collections::HashMap;

use axum::Json;
use axum::extract::{Query, State};
use axum::http::StatusCode;
use axum::response::{IntoResponse, Response};
use rusqlite::types::Value as SqlValue;
use serde_json::{Value, json};

use crate::api::SharedState;
use crate::error::{AppError, AppResult};

pub async fn list(State(state): State<SharedState>) -> AppResult<Json<Value>> {
    let conn = state.db.lock();
    let mut stmt = conn.prepare(
        "SELECT id, title, description, is_favorite, use_count, last_used_at, created_at, updated_at
         FROM artist_presets ORDER BY is_favorite DESC, last_used_at DESC, id DESC"
    )?;
    let col_names: Vec<String> = stmt.column_names().into_iter().map(|c| c.to_string()).collect();
    let presets: Vec<Value> = stmt.query_map([], |r| {
        let mut obj = serde_json::Map::new();
        for (i, name) in col_names.iter().enumerate() {
            let v: rusqlite::types::Value = r.get(i)?;
            let jv = match v {
                rusqlite::types::Value::Null => Value::Null,
                rusqlite::types::Value::Integer(i) => json!(i),
                rusqlite::types::Value::Real(f) => json!(f),
                rusqlite::types::Value::Text(s) => Value::String(s),
                _ => Value::Null,
            };
            obj.insert(name.clone(), jv);
        }
        Ok(Value::Object(obj))
    })?.collect::<Result<Vec<_>, _>>()?;

    // 加载每个 preset 的 items
    let mut result = Vec::new();
    for mut p in presets {
        let preset_id = p.get("id").and_then(|v| v.as_i64()).unwrap_or(0);
        if preset_id > 0 {
            let mut stmt2 = conn.prepare(
                "SELECT api.id, api.artist_id, api.weight, api.display_order,
                        a.name_noob, a.name_nai, a.name_cn, a.danbooru_link
                 FROM artist_preset_items api
                 LEFT JOIN artists a ON api.artist_id = a.id
                 WHERE api.preset_id = ? ORDER BY api.display_order ASC, api.id ASC"
            )?;
            let items: Vec<Value> = stmt2.query_map([preset_id], |r| {
                Ok(json!({
                    "id": r.get::<_, i64>(0)?,
                    "artist_id": r.get::<_, i64>(1)?,
                    "weight": r.get::<_, f64>(2)?,
                    "display_order": r.get::<_, i64>(3)?,
                    "name_noob": r.get::<_, Option<String>>(4)?,
                    "name_nai": r.get::<_, Option<String>>(5)?,
                    "name_cn": r.get::<_, Option<String>>(6)?,
                    "danbooru_link": r.get::<_, Option<String>>(7)?,
                }))
            })?.collect::<Result<Vec<_>, _>>()?;
            p.as_object_mut().unwrap().insert("items".to_string(), json!(items));
        }
        result.push(p);
    }

    Ok(Json(json!({"ok": true, "rows": result})))
}

pub async fn create(
    State(state): State<SharedState>,
    Json(body): Json<Value>,
) -> AppResult<Json<Value>> {
    let obj = body.as_object().ok_or_else(|| AppError::BadRequest("body must be object".into()))?;
    let action = obj.get("action").and_then(|v| v.as_str()).unwrap_or("create");
    let conn = state.db.lock();

    match action {
        "create" => {
            let title = obj.get("title").and_then(|v| v.as_str())
                .ok_or_else(|| AppError::BadRequest("title required".into()))?;
            let description = obj.get("description").and_then(|v| v.as_str()).map(|s| s.to_string());
            let is_favorite = if obj.get("is_favorite").and_then(|v| v.as_bool()).unwrap_or(false) { 1 } else { 0 };
            conn.execute(
                "INSERT INTO artist_presets (title, description, is_favorite) VALUES (?, ?, ?)",
                rusqlite::params![title, description, is_favorite]
            )?;
            let preset_id = conn.last_insert_rowid();
            // 插入 items
            if let Some(arr) = obj.get("items").and_then(|v| v.as_array()) {
                for (i, item) in arr.iter().enumerate() {
                    let artist_id = item.get("artist_id").and_then(|v| v.as_i64()).unwrap_or(0);
                    let weight = item.get("weight").and_then(|v| v.as_f64()).unwrap_or(1.0);
                    if artist_id > 0 {
                        conn.execute(
                            "INSERT INTO artist_preset_items (preset_id, artist_id, weight, display_order) VALUES (?, ?, ?, ?)",
                            rusqlite::params![preset_id, artist_id, weight, i as i64]
                        )?;
                    }
                }
            }
            Ok(Json(json!({"ok": true, "id": preset_id})))
        }
        "update" => {
            let id = obj.get("id").and_then(|v| v.as_i64())
                .ok_or_else(|| AppError::BadRequest("id required".into()))?;
            let mut sets: Vec<String> = Vec::new();
            let mut params: Vec<SqlValue> = Vec::new();
            for field in ["title", "description"].iter() {
                if let Some(v) = obj.get(*field) {
                    sets.push(format!("{} = ?", field));
                    params.push(SqlValue::Text(v.as_str().unwrap_or("").to_string()));
                }
            }
            if let Some(v) = obj.get("is_favorite") {
                sets.push("is_favorite = ?".to_string());
                params.push(SqlValue::Integer(if v.as_bool().unwrap_or(false) { 1 } else { 0 }));
            }
            if !sets.is_empty() {
                let sql = format!("UPDATE artist_presets SET {} WHERE id = ?", sets.join(", "));
                params.push(SqlValue::Integer(id));
                let param_refs: Vec<&dyn rusqlite::ToSql> = params.iter().map(|p| p as &dyn rusqlite::ToSql).collect();
                conn.execute(&sql, param_refs.as_slice())?;
            }
            // 替换 items
            if let Some(arr) = obj.get("items").and_then(|v| v.as_array()) {
                conn.execute("DELETE FROM artist_preset_items WHERE preset_id = ?", [id])?;
                for (i, item) in arr.iter().enumerate() {
                    let artist_id = item.get("artist_id").and_then(|v| v.as_i64()).unwrap_or(0);
                    let weight = item.get("weight").and_then(|v| v.as_f64()).unwrap_or(1.0);
                    if artist_id > 0 {
                        conn.execute(
                            "INSERT INTO artist_preset_items (preset_id, artist_id, weight, display_order) VALUES (?, ?, ?, ?)",
                            rusqlite::params![id, artist_id, weight, i as i64]
                        )?;
                    }
                }
            }
            Ok(Json(json!({"ok": true, "id": id})))
        }
        "use" => {
            let id = obj.get("id").and_then(|v| v.as_i64())
                .ok_or_else(|| AppError::BadRequest("id required".into()))?;
            conn.execute("UPDATE artist_presets SET use_count = use_count + 1, last_used_at = CURRENT_TIMESTAMP WHERE id = ?", [id])?;
            Ok(Json(json!({"ok": true, "id": id})))
        }
        "delete" => {
            let id = obj.get("id").and_then(|v| v.as_i64())
                .ok_or_else(|| AppError::BadRequest("id required".into()))?;
            conn.execute("DELETE FROM artist_preset_items WHERE preset_id = ?", [id])?;
            conn.execute("DELETE FROM artist_presets WHERE id = ?", [id])?;
            Ok(Json(json!({"ok": true, "id": id})))
        }
        _ => Err(AppError::BadRequest(format!("unknown action: {}", action))),
    }
}

pub async fn delete(
    State(state): State<SharedState>,
    Query(params): Query<HashMap<String, String>>,
) -> AppResult<Response> {
    let id: i64 = params.get("id").and_then(|s| s.parse().ok())
        .ok_or_else(|| AppError::BadRequest("id required".into()))?;
    let conn = state.db.lock();
    conn.execute("DELETE FROM artist_preset_items WHERE preset_id = ?", [id])?;
    let n = conn.execute("DELETE FROM artist_presets WHERE id = ?", [id])?;
    Ok((StatusCode::OK, Json(json!({"ok": true, "id": id, "deleted": n}))).into_response())
}
