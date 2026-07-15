//! /api/admin/fetch_all_images -- 拉 NAI 历史作品
//!
//! Phase 3.4: 基础版 — 模拟任务框架
//! Phase 4: 真接 NAI user/images API

use std::time::Duration;

use axum::Json;
use axum::extract::State;
use serde_json::{Value, json};
use tokio::time::sleep;

use super::{get_or_create, is_cancelled, tick, update};
use crate::api::SharedState;
use crate::error::AppResult;

const NAME: &str = "fetch_all_images";

pub async fn status(State(_state): State<SharedState>) -> AppResult<Json<Value>> {
    let j = get_or_create(NAME);
    Ok(Json(j.to_json()))
}

pub async fn start(State(_state): State<SharedState>) -> AppResult<Json<Value>> {
    let j = get_or_create(NAME);
    if j.status == "running" {
        return Ok(Json(json!({"ok": false, "error": "已经在运行"})));
    }
    let _ = j.cancel.store(false, std::sync::atomic::Ordering::Relaxed);
    update(NAME, |j| {
        j.status = "running".to_string();
        j.started_at = Some(std::time::Instant::now());
        j.finished_at = None;
        j.done = 0;
        j.total = 0;
        j.message = "启动中...".to_string();
    });
    let cancel = j.cancel.clone();
    tokio::spawn(async move {
        run_worker(cancel).await;
    });
    Ok(Json(json!({"ok": true, "message": "已启动"})))
}

pub async fn stop(State(_state): State<SharedState>) -> AppResult<Json<Value>> {
    let j = get_or_create(NAME);
    j.cancel.store(true, std::sync::atomic::Ordering::Relaxed);
    update(NAME, |j| {
        j.status = "cancelled".to_string();
        j.message = "用户取消".to_string();
        j.finished_at = Some(std::time::Instant::now());
    });
    Ok(Json(json!({"ok": true, "message": "已停止"})))
}

async fn run_worker(_cancel: std::sync::Arc<std::sync::atomic::AtomicBool>) {
    update(NAME, |j| {
        j.message = "模拟拉取（Phase 3.4 占位）...".to_string();
    });
    let total: i64 = 30;
    for i in 0..total {
        if is_cancelled(NAME) { return; }
        sleep(Duration::from_millis(200)).await;
        tick(NAME, i + 1, total);
        if (i + 1) % 5 == 0 {
            update(NAME, |j| { j.message = format!("拉取中 {}/{}", i + 1, total); });
        }
    }
    update(NAME, |j| {
        j.status = "done".to_string();
        j.message = "完成（Phase 3.4 占位）".to_string();
        j.finished_at = Some(std::time::Instant::now());
    });
}
