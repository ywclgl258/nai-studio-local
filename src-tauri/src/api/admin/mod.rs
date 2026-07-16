//! Admin / long-task API  (detached workers)
//!
//! 三个长时间任务:
//!   - expand_tags      AI 把 prompt 拆成 Danbooru tag 分类
//!   - import_all_tags  从 Danbooru 全量导入 tag 库
//!   - fetch_all_images 拉取 NAI 历史作品
//!
//! 状态查询:GET /api/admin/<name>
//! 启动:    POST /api/admin/<name>  (body 可带参数)
//! 停止:    DELETE /api/admin/<name>

use std::collections::HashMap;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use std::time::Instant;

use once_cell::sync::Lazy;
use parking_lot::Mutex;
use serde_json::{Value, json};

/// 全局任务状态表
static JOBS: Lazy<Mutex<HashMap<String, JobState>>> = Lazy::new(|| Mutex::new(HashMap::new()));

#[derive(Clone)]
pub struct JobState {
    pub name: &'static str,
    pub status: String,         // "idle" | "running" | "done" | "error" | "cancelled" | "stopped"
    pub started_at: Option<Instant>,
    pub finished_at: Option<Instant>,
    pub done: i64,
    pub total: i64,
    pub message: String,
    pub cancel: Arc<AtomicBool>,
    // 扩展统计字段(各 worker 自行更新,通过 update(|j| ...) 改)
    pub added: i64,
    pub translated: i64,
    pub images: i64,
    pub skipped: i64,
    pub errors: i64,
    pub current_tag: String,
    pub fetched: i64,
    pub current_page: i64,
    pub pages_total: i64,
    pub last_error: String,
    pub rate_per_sec: f64,
}

impl JobState {
    pub fn to_json(&self) -> Value {
        let elapsed = self.started_at.map(|s| s.elapsed().as_secs()).unwrap_or(0);
        json!({
            "name": self.name,
            "status": self.status,
            "done": self.done,
            "total": self.total,
            "message": self.message,
            "elapsed_sec": elapsed,
            "added": self.added,
            "translated": self.translated,
            "images": self.images,
            "skipped": self.skipped,
            "errors": self.errors,
            "current_tag": self.current_tag,
            "fetched": self.fetched,
            "current_page": self.current_page,
            "pages_total": self.pages_total,
            "last_error": self.last_error,
            "rate_per_sec": self.rate_per_sec,
            "progress": self.done,
        })
    }
}

/// 拿一个 job 状态(name 决定哪个 job)
pub fn get_or_create(name: &'static str) -> JobState {
    let mut map = JOBS.lock();
    if let Some(j) = map.get(name) {
        return j.clone();
    }
    let state = JobState {
        name,
        status: "idle".to_string(),
        started_at: None,
        finished_at: None,
        done: 0,
        total: 0,
        message: String::new(),
        cancel: Arc::new(AtomicBool::new(false)),
        added: 0,
        translated: 0,
        images: 0,
        skipped: 0,
        errors: 0,
        current_tag: String::new(),
        fetched: 0,
        current_page: 0,
        pages_total: 0,
        last_error: String::new(),
        rate_per_sec: 0.0,
    };
    map.insert(name.to_string(), state.clone());
    state
}

/// 更新 job 状态
pub fn update<F: FnOnce(&mut JobState)>(name: &'static str, f: F) {
    let mut map = JOBS.lock();
    if let Some(j) = map.get_mut(name) {
        f(j);
    }
}

/// 取消 flag
pub fn cancel(name: &'static str) {
    let map = JOBS.lock();
    if let Some(j) = map.get(name) {
        j.cancel.store(true, Ordering::Relaxed);
    }
}

/// 拿 cancel flag 引用
pub fn is_cancelled(name: &'static str) -> bool {
    let map = JOBS.lock();
    map.get(name).map(|j| j.cancel.load(Ordering::Relaxed)).unwrap_or(false)
}

/// 检查 cancel + 增加 progress
pub fn tick(name: &'static str, n: i64, total: i64) -> bool {
    let mut map = JOBS.lock();
    if let Some(j) = map.get_mut(name) {
        j.done = n;
        if total > 0 { j.total = total; }
        return j.cancel.load(Ordering::Relaxed);
    }
    false
}

pub mod expand_tags;
pub mod import_all_tags;
pub mod fetch_all_images;
