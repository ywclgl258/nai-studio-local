//! 全局应用状态（DB + paths + 缓存）

use std::sync::Arc;

use parking_lot::Mutex;
use rusqlite::Connection;

use crate::paths::AppPaths;

// Compile-time assertion: AppState must be Send + Sync so axum::extract::State<Arc<AppState>> works.
fn _assert_send_sync<T: Send + Sync>() {}
fn _assert_appstate_send_sync() { _assert_send_sync::<AppState>(); }
fn _assert_arc_appstate_send_sync() { _assert_send_sync::<Arc<AppState>>(); }

pub struct AppState {
    /// SQLite 连接（用 Mutex 包装 — 简单场景够用，未来可换 connection pool）
    pub db: Mutex<Connection>,

    /// 用户数据路径
    pub paths: AppPaths,
}

impl AppState {
    pub fn new(conn: Connection, paths: AppPaths) -> Self {
        Self {
            db: Mutex::new(conn),
            paths,
        }
    }

    /// 方便 clone（Arc 内部）
    pub fn shared(self) -> Arc<Self> {
        Arc::new(self)
    }
}
