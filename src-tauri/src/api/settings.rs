//! GET / POST /api/settings
//!
//! 跟 NAI Studio PHP 项目的 settings.php 等价：
//!   - get:   返回 settings 单行（解密 api_key）
//!   - update: 更新字段（加密 api_key 写到 DB）

use axum::Json;
use axum::extract::State;
use rusqlite::types::Value as SqlValue;
use rusqlite::OptionalExtension;
use serde_json::{Map, Value, json};

use crate::api::SharedState;
use crate::encryption;
use crate::error::{AppError, AppResult};

fn sql_value_to_json(v: SqlValue) -> Value {
    match v {
        SqlValue::Null => Value::Null,
        SqlValue::Integer(i) => json!(i),
        SqlValue::Real(f) => json!(f),
        SqlValue::Text(s) => Value::String(s),
        SqlValue::Blob(b) => json!(b),
    }
}

/// 读取 settings 行（解密 api_key + 解析 ui_state JSON）
fn load_settings(state: &SharedState) -> AppResult<Value> {
    let conn = state.db.lock();
    conn.execute("INSERT OR IGNORE INTO settings (id) VALUES (1)", [])?;

    let mut stmt = conn.prepare("SELECT * FROM settings WHERE id = 1")?;
    let col_names: Vec<String> = stmt.column_names().into_iter().map(|c| c.to_string()).collect();

    let row = stmt.query_row([], |r| {
        let mut obj = Map::new();
        for (i, name) in col_names.iter().enumerate() {
            let v: SqlValue = r.get(i)?;
            obj.insert(name.clone(), sql_value_to_json(v));
        }
        Ok(Value::Object(obj))
    }).optional()?;

    let mut row = row.unwrap_or_else(|| Value::Object(Map::new()));
    let obj = row.as_object_mut().unwrap();

    // 暴露 api_key_plain（解密）
    if let Some(enc) = obj.get("api_key_encrypted").and_then(|v| v.as_str()).map(|s| s.to_string()) {
        if !enc.is_empty() {
            if let Ok(plain) = encryption::decrypt(&enc) {
                obj.insert("api_key_plain".to_string(), json!(plain));
            }
        }
    }
    // 隐藏加密 blob
    obj.remove("api_key_encrypted");
    // 解析 ui_state JSON
    if let Some(ui_state) = obj.get("ui_state").and_then(|v| v.as_str()).map(|s| s.to_string()) {
        if !ui_state.is_empty() {
            if let Ok(parsed) = serde_json::from_str::<Value>(&ui_state) {
                obj.insert("ui_state".to_string(), parsed);
            }
        }
    }

    Ok(row)
}

pub async fn get(State(state): State<SharedState>) -> AppResult<Json<Value>> {
    let s = load_settings(&state)?;
    Ok(Json(json!({"ok": true, "settings": s})))
}

pub async fn update(
    State(state): State<SharedState>,
    Json(body): Json<Value>,
) -> AppResult<Json<Value>> {
    let updates = body.as_object()
        .ok_or_else(|| AppError::BadRequest("body must be JSON object".into()))?;

    let mut set_clauses: Vec<String> = Vec::new();
    let mut params: Vec<SqlValue> = Vec::new();

    // 处理 api_key：明文 → 加密后存
    if let Some(v) = updates.get("api_key") {
        let key_str = v.as_str().unwrap_or("").to_string();
        if key_str.is_empty() {
            set_clauses.push("api_key_encrypted = ?".to_string());
            set_clauses.push("api_key_fingerprint = ?".to_string());
            params.push(SqlValue::Null);
            params.push(SqlValue::Null);
        } else {
            let enc = encryption::encrypt(&key_str)?;
            // fingerprint = 后 4 字符
            let chars: Vec<char> = key_str.chars().collect();
            let fp: String = if chars.len() >= 4 {
                chars[chars.len()-4..].iter().collect()
            } else {
                key_str.clone()
            };
            set_clauses.push("api_key_encrypted = ?".to_string());
            set_clauses.push("api_key_fingerprint = ?".to_string());
            params.push(SqlValue::Text(enc));
            params.push(SqlValue::Text(fp));
        }
    }

    let allowed = [
        "default_model","default_sampler","default_steps","default_scale",
        "default_cfg_rescale","default_noise_schedule","default_size",
        "default_uc_preset","quality_toggle","emphasis_highlight",
        "theme","proxy_enabled","proxy_url",
        "local_translate_enabled","local_translate_url","translate_source",
        "aggressive_fallback_enabled",
        "danbooru_username","danbooru_api_key",
        "deepseek_api_key","deepseek_model","deepseek_base_url",
        "deepseek_status","deepseek_tested_at",
        "ai_advisor_enabled","ai_provider","ai_base_url","ai_api_key","ai_model","ai_reasoning_effort",
    ];

    for field in allowed.iter() {
        if let Some(v) = updates.get(*field) {
            set_clauses.push(format!("{} = ?", field));
            let sv: SqlValue = match v {
                Value::Null => SqlValue::Null,
                Value::Bool(b) => SqlValue::Integer(if *b { 1 } else { 0 }),
                Value::Number(n) => {
                    if let Some(i) = n.as_i64() { SqlValue::Integer(i) }
                    else if let Some(f) = n.as_f64() { SqlValue::Real(f) }
                    else { SqlValue::Null }
                }
                Value::String(s) => SqlValue::Text(s.clone()),
                _ => SqlValue::Text(v.to_string()),
            };
            params.push(sv);
        }
    }

    // ui_state：对象 → JSON 字符串
    if let Some(v) = updates.get("ui_state") {
        let s = serde_json::to_string(v).unwrap_or_else(|_| "{}".to_string());
        set_clauses.push("ui_state = ?".to_string());
        params.push(SqlValue::Text(s));
    }

    if set_clauses.is_empty() {
        return Ok(Json(json!({"ok": true, "settings": load_settings(&state)?, "note": "nothing to update"})));
    }

    let sql = format!("UPDATE settings SET {} WHERE id = 1", set_clauses.join(", "));
    let conn = state.db.lock();
    let param_refs: Vec<&dyn rusqlite::ToSql> = params.iter().map(|p| p as &dyn rusqlite::ToSql).collect();
    conn.execute(&sql, param_refs.as_slice())?;

    let updated = load_settings(&state)?;
    Ok(Json(json!({"ok": true, "settings": updated})))
}
