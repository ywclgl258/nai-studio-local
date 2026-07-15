//! /api/prompts -- 主提示词预设 CRUD
//!
//! 跟 NAI Studio PHP 项目的 prompts.php 等价
//!   - GET    /api/prompts        -> 列表（含 favorite 排序）
//!   - POST   /api/prompts        -> 新增 {title, positive, negative?, tags_json?, model?, size?, uc_preset?, is_favorite?}
//!   - PUT    /api/prompts?id=N   -> 更新
//!   - DELETE /api/prompts?id=N   -> 删除
//!   - POST   {action:use,id}     -> 标记 use_count++ + last_used_at

use std::collections::HashMap;

use axum::Json;
use axum::extract::{Query, State};
use axum::http::StatusCode;
use axum::response::{IntoResponse, Response};
use rusqlite::types::Value as SqlValue;
use serde_json::{Value, json};

use crate::api::SharedState;
use crate::error::{AppError, AppResult};

/// GET /api/prompts?favorite=1
pub async fn list(
    State(state): State<SharedState>,
    Query(params): Query<HashMap<String, String>>,
) -> AppResult<Json<Value>> {
    let conn = state.db.lock();
    let only_fav = matches!(params.get("favorite").map(|s| s.as_str()), Some("1") | Some("true"));

    let sql = if only_fav {
        "SELECT id, title, description, positive, negative, tags_json, model, size, uc_preset, is_favorite, use_count, last_used_at, created_at, updated_at
         FROM prompts WHERE is_favorite = 1 ORDER BY last_used_at DESC, id DESC"
    } else {
        "SELECT id, title, description, positive, negative, tags_json, model, size, uc_preset, is_favorite, use_count, last_used_at, created_at, updated_at
         FROM prompts ORDER BY is_favorite DESC, last_used_at DESC, id DESC"
    };
    let mut stmt = conn.prepare(sql)?;
    let col_names: Vec<String> = stmt.column_names().into_iter().map(|c| c.to_string()).collect();
    let rows: Vec<Value> = stmt.query_map([], |r| {
        let mut obj = serde_json::Map::new();
        for (i, name) in col_names.iter().enumerate() {
            let v: rusqlite::types::Value = r.get(i)?;
            let jv = match v {
                rusqlite::types::Value::Null => Value::Null,
                rusqlite::types::Value::Integer(i) => json!(i),
                rusqlite::types::Value::Real(f) => json!(f),
                rusqlite::types::Value::Text(s) => {
                    if matches!(name.as_str(), "tags_json") {
                        serde_json::from_str(&s).unwrap_or(Value::Null)
                    } else {
                        Value::String(s)
                    }
                }
                _ => Value::Null,
            };
            obj.insert(name.clone(), jv);
        }
        Ok(Value::Object(obj))
    })?.collect::<Result<Vec<_>, _>>()?;
    Ok(Json(json!({"ok": true, "rows": rows})))
}

/// POST /api/prompts -- 新增 (body: {action:create|use, ...})
/// 也支持 PUT /api/prompts?id=N -- 更新 (action:update)
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
            let positive = obj.get("positive").and_then(|v| v.as_str()).unwrap_or("").to_string();
            let negative = obj.get("negative").and_then(|v| v.as_str()).map(|s| s.to_string());
            let description = obj.get("description").and_then(|v| v.as_str()).map(|s| s.to_string());
            let tags_json = obj.get("tags_json").map(|v| serde_json::to_string(v).unwrap_or_else(|_| "[]".to_string()));
            let model = obj.get("model").and_then(|v| v.as_str()).map(|s| s.to_string());
            let size = obj.get("size").and_then(|v| v.as_str()).map(|s| s.to_string());
            let uc_preset = obj.get("uc_preset").and_then(|v| v.as_i64()).map(|x| x as i64);
            let is_favorite = if obj.get("is_favorite").and_then(|v| v.as_bool()).unwrap_or(false) { 1 } else { 0 };

            conn.execute(
                "INSERT INTO prompts (title, description, positive, negative, tags_json, model, size, uc_preset, is_favorite) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                rusqlite::params![title, description, positive, negative, tags_json, model, size, uc_preset, is_favorite]
            )?;
            let id = conn.last_insert_rowid();
            Ok(Json(json!({"ok": true, "id": id})))
        }
        "use" => {
            let id = obj.get("id").and_then(|v| v.as_i64())
                .ok_or_else(|| AppError::BadRequest("id required".into()))?;
            conn.execute(
                "UPDATE prompts SET use_count = use_count + 1, last_used_at = CURRENT_TIMESTAMP WHERE id = ?",
                [id]
            )?;
            Ok(Json(json!({"ok": true, "id": id})))
        }
        "favorite" => {
            let id = obj.get("id").and_then(|v| v.as_i64())
                .ok_or_else(|| AppError::BadRequest("id required".into()))?;
            let v = if obj.get("value").and_then(|x| x.as_bool()).unwrap_or(false) { 1 } else { 0 };
            conn.execute("UPDATE prompts SET is_favorite = ? WHERE id = ?", rusqlite::params![v, id])?;
            Ok(Json(json!({"ok": true, "id": id, "is_favorite": v})))
        }
        "update" => {
            let id = obj.get("id").and_then(|v| v.as_i64())
                .ok_or_else(|| AppError::BadRequest("id required".into()))?;
            let mut sets: Vec<String> = Vec::new();
            let mut params: Vec<SqlValue> = Vec::new();
            for field in ["title", "description", "positive", "negative", "model", "size"].iter() {
                if let Some(v) = obj.get(*field) {
                    sets.push(format!("{} = ?", field));
                    params.push(SqlValue::Text(v.as_str().unwrap_or("").to_string()));
                }
            }
            if let Some(v) = obj.get("tags_json") {
                sets.push("tags_json = ?".to_string());
                params.push(SqlValue::Text(serde_json::to_string(v).unwrap_or_else(|_| "[]".to_string())));
            }
            if let Some(v) = obj.get("uc_preset") {
                sets.push("uc_preset = ?".to_string());
                params.push(SqlValue::Integer(v.as_i64().unwrap_or(0)));
            }
            if let Some(v) = obj.get("is_favorite") {
                sets.push("is_favorite = ?".to_string());
                params.push(SqlValue::Integer(if v.as_bool().unwrap_or(false) { 1 } else { 0 }));
            }
            if sets.is_empty() {
                return Ok(Json(json!({"ok": true, "note": "nothing to update"})));
            }
            let sql = format!("UPDATE prompts SET {} WHERE id = ?", sets.join(", "));
            params.push(SqlValue::Integer(id));
            let param_refs: Vec<&dyn rusqlite::ToSql> = params.iter().map(|p| p as &dyn rusqlite::ToSql).collect();
            conn.execute(&sql, param_refs.as_slice())?;
            Ok(Json(json!({"ok": true, "id": id})))
        }
        "delete" => {
            let id = obj.get("id").and_then(|v| v.as_i64())
                .ok_or_else(|| AppError::BadRequest("id required".into()))?;
            conn.execute("DELETE FROM prompts WHERE id = ?", [id])?;
            Ok(Json(json!({"ok": true, "id": id})))
        }
        _ => Err(AppError::BadRequest(format!("unknown action: {}", action))),
    }
}

/// DELETE /api/prompts?id=N
pub async fn delete(
    State(state): State<SharedState>,
    Query(params): Query<HashMap<String, String>>,
) -> AppResult<Response> {
    let id: i64 = params.get("id").and_then(|s| s.parse().ok())
        .ok_or_else(|| AppError::BadRequest("id required".into()))?;
    let conn = state.db.lock();
    let n = conn.execute("DELETE FROM prompts WHERE id = ?", [id])?;
    Ok((StatusCode::OK, Json(json!({"ok": true, "id": id, "deleted": n}))).into_response())
}
