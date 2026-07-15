//! GET /api/status -- system status
use axum::Json;
use axum::extract::State;
use serde_json::{Value, json};

use crate::api::SharedState;
use crate::error::{AppError, AppResult};

pub async fn status(State(_state): State<SharedState>) -> AppResult<Json<Value>> {
    Ok(Json(json!({"ok": true, "app": "nai-studio-desktop", "version": env!("CARGO_PKG_VERSION"), "tauri": true, "platform": std::env::consts::OS, "arch": std::env::consts::ARCH})))
}

