//! GET /api/anlas
use axum::Json;
use axum::extract::State;
use serde_json::{Value, json};

use crate::api::SharedState;
use crate::error::AppResult;

pub async fn get(State(state): State<SharedState>) -> AppResult<Json<Value>> {
    match crate::nai_api::get_anlas(state).await {
        Ok(v) => Ok(Json(json!({
            "ok": true,
            "anlas": v.get("anlas"),
            "tier": v.get("tier"),
            "expiresAt": v.get("expiresAt"),
        }))),
        Err(e) => Ok(Json(json!({"ok": false, "error": e.to_string()}))),
    }
}
