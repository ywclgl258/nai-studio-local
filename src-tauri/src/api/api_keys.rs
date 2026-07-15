//! /api/api-keys -- NAI 多 key 管理
//!
//! 跟 NAI Studio PHP 项目 api-keys.php 等价
//!   - GET    /api/api-keys        -> 列表（脱敏显示）
//!   - POST   /api/api-keys        -> 新增 {label, api_key}
//!   - DELETE /api/api-keys?id=N   -> 删除
//!   - POST   {action:update, id, label?, enabled?, sort_order?}

use axum::Json;
use axum::extract::{Query, State};
use axum::http::StatusCode;
use axum::response::{IntoResponse, Response};
use rusqlite::types::Value as SqlValue;
use serde_json::{Value, json};
use std::collections::HashMap;

use crate::api::SharedState;
use crate::error::{AppError, AppResult};

/// 列表 — key 脱敏（只显示 fingerprint + 前 4 后 4 字符）
pub async fn list(State(state): State<SharedState>) -> AppResult<Json<Value>> {
    let conn = state.db.lock();
    let mut stmt = conn.prepare(
        "SELECT id, label, api_key_fingerprint, enabled, sort_order, fail_count, last_error_code, last_error_msg, last_used_at
         FROM nai_api_keys ORDER BY sort_order ASC, id ASC"
    )?;
    let col_names: Vec<String> = stmt.column_names().into_iter().map(|c| c.to_string()).collect();
    let rows: Vec<Value> = stmt.query_map([], |r| {
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
    Ok(Json(json!({"ok": true, "rows": rows})))
}

/// 新增 / 更新 — body: {action:create|update|delete, ...}
pub async fn create(
    State(state): State<SharedState>,
    Json(body): Json<Value>,
) -> AppResult<Json<Value>> {
    let obj = body.as_object().ok_or_else(|| AppError::BadRequest("body must be object".into()))?;
    let action = obj.get("action").and_then(|v| v.as_str()).unwrap_or("create");

    let conn = state.db.lock();
    match action {
        "create" => {
            let label = obj.get("label").and_then(|v| v.as_str()).map(|s| s.to_string());
            let api_key = obj.get("api_key").and_then(|v| v.as_str())
                .ok_or_else(|| AppError::BadRequest("api_key required".into()))?;
            if api_key.is_empty() {
                return Err(AppError::BadRequest("api_key cannot be empty".into()));
            }
            // fingerprint = 后 4 字符
            let chars: Vec<char> = api_key.chars().collect();
            let fp: String = if chars.len() >= 4 {
                chars[chars.len()-4..].iter().collect()
            } else {
                api_key.to_string()
            };
            let enc = crate::encryption::encrypt(api_key)?;
            // sort_order = max + 1
            let next_order: i64 = conn.query_row(
                "SELECT COALESCE(MAX(sort_order), 0) + 1 FROM nai_api_keys",
                [],
                |r| r.get(0)
            ).unwrap_or(1);
            conn.execute(
                "INSERT INTO nai_api_keys (label, api_key_encrypted, api_key_fingerprint, sort_order) VALUES (?, ?, ?, ?)",
                rusqlite::params![label, enc, fp, next_order]
            )?;
            let id = conn.last_insert_rowid();
            Ok(Json(json!({"ok": true, "id": id, "fingerprint": fp})))
        }
        "update" => {
            let id = obj.get("id").and_then(|v| v.as_i64())
                .ok_or_else(|| AppError::BadRequest("id required".into()))?;
            let mut sets: Vec<String> = Vec::new();
            let mut params: Vec<SqlValue> = Vec::new();
            if let Some(v) = obj.get("label") {
                sets.push("label = ?".to_string());
                params.push(SqlValue::Text(v.as_str().unwrap_or("").to_string()));
            }
            if let Some(v) = obj.get("enabled") {
                sets.push("enabled = ?".to_string());
                params.push(SqlValue::Integer(if v.as_bool().unwrap_or(false) { 1 } else { 0 }));
            }
            if let Some(v) = obj.get("sort_order") {
                sets.push("sort_order = ?".to_string());
                params.push(SqlValue::Integer(v.as_i64().unwrap_or(0)));
            }
            if let Some(v) = obj.get("api_key") {
                let k = v.as_str().unwrap_or("");
                if k.is_empty() {
                    sets.push("api_key_encrypted = NULL".to_string());
                    sets.push("api_key_fingerprint = NULL".to_string());
                } else {
                    let chars: Vec<char> = k.chars().collect();
                    let fp: String = if chars.len() >= 4 {
                        chars[chars.len()-4..].iter().collect()
                    } else {
                        k.to_string()
                    };
                    let enc = crate::encryption::encrypt(k)?;
                    sets.push("api_key_encrypted = ?".to_string());
                    sets.push("api_key_fingerprint = ?".to_string());
                    params.push(SqlValue::Text(enc));
                    params.push(SqlValue::Text(fp));
                }
            }
            if sets.is_empty() {
                return Ok(Json(json!({"ok": true, "note": "nothing to update"})));
            }
            let sql = format!("UPDATE nai_api_keys SET {} WHERE id = ?", sets.join(", "));
            params.push(SqlValue::Integer(id));
            let param_refs: Vec<&dyn rusqlite::ToSql> = params.iter().map(|p| p as &dyn rusqlite::ToSql).collect();
            conn.execute(&sql, param_refs.as_slice())?;
            Ok(Json(json!({"ok": true, "id": id})))
        }
        "delete" => {
            let id = obj.get("id").and_then(|v| v.as_i64())
                .ok_or_else(|| AppError::BadRequest("id required".into()))?;
            conn.execute("DELETE FROM nai_api_keys WHERE id = ?", [id])?;
            Ok(Json(json!({"ok": true, "id": id})))
        }
        _ => Err(AppError::BadRequest(format!("unknown action: {}", action))),
    }
}

/// DELETE /api/api-keys?id=N
pub async fn delete(
    State(state): State<SharedState>,
    Query(params): Query<HashMap<String, String>>,
) -> AppResult<Response> {
    let id: i64 = params.get("id").and_then(|s| s.parse().ok())
        .ok_or_else(|| AppError::BadRequest("id required".into()))?;
    let conn = state.db.lock();
    let n = conn.execute("DELETE FROM nai_api_keys WHERE id = ?", [id])?;
    Ok((StatusCode::OK, Json(json!({"ok": true, "id": id, "deleted": n}))).into_response())
}
