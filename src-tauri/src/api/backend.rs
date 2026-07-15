//! /api/backend/* -- server control (Tauri is the server itself)
use axum::Json;
use axum::extract::State;
use serde_json::{Value, json};

use crate::api::SharedState;
use crate::error::AppError;

pub async fn status(State(_state): State<SharedState>) -> Result<Json<Value>, AppError> {
    Ok(Json(json!({"ok": true, "server": true, "pid": std::process::id(), "db_ok": true, "db_size_kb": 0, "note": "Tauri process is the server; status always true"})))
}

pub async fn stop(State(_state): State<SharedState>) -> Result<Json<Value>, AppError> {
    Ok(Json(json!({"ok": false, "error": "In Tauri Desktop, stop means exit APP. Close the window."})))
}

