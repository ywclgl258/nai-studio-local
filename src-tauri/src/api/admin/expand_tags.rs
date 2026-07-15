//! /api/admin/expand-tags -- AI 把 prompt 拆成 Danbooru tag 分类
//!
//! 跟 NAI Studio PHP 项目 admin/expand_tags.php 等价
//!   GET  /api/admin/expand-tags        -> 状态
//!   POST /api/admin/expand-tags        -> 启动
//!   DELETE /api/admin/expand-tags      -> 停止
//!
//! Phase 3.4: 基础版 — 启动后扫描 generations 表，对没 tag_json 的记录调 AI
//! Phase 4: 优化 + 增量 + 缓存

use std::time::Duration;

use axum::Json;
use axum::extract::State;
use serde_json::{Value, json};
use tokio::time::sleep;

use super::{JobState, get_or_create, is_cancelled, tick, update};
use crate::api::SharedState;
use crate::error::AppResult;

const NAME: &str = "expand_tags";

pub async fn status(State(_state): State<SharedState>) -> AppResult<Json<Value>> {
    let j = get_or_create(NAME);
    Ok(Json(j.to_json()))
}

pub async fn start(State(_state): State<SharedState>) -> AppResult<Json<Value>> {
    let j = get_or_create(NAME);
    if j.status == "running" {
        return Ok(Json(json!({"ok": false, "error": "已经在运行"})));
    }
    // 重置 + 启动后台 task
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

async fn run_worker(cancel: std::sync::Arc<std::sync::atomic::AtomicBool>) {
    update(NAME, |j| {
        j.message = "扫描待处理记录...".to_string();
    });
    // Phase 3.4: 模拟工作（不实际调 AI）
    let total: i64 = 20;
    for i in 0..total {
        if cancel.load(std::sync::atomic::Ordering::Relaxed) {
            return;
        }
        sleep(Duration::from_millis(200)).await;
        if is_cancelled(NAME) { return; }
        tick(NAME, i + 1, total);
        if (i + 1) % 5 == 0 {
            update(NAME, |j| { j.message = format!("处理中 {}/{}", i + 1, total); });
        }
    }
    update(NAME, |j| {
        j.status = "done".to_string();
        j.message = "完成（Phase 3.4 占位）".to_string();
        j.finished_at = Some(std::time::Instant::now());
    });
    // 防止 cancel warning
    let _ = cancel;
}

#[allow(dead_code)]
fn _phantom(_: JobState) {}
