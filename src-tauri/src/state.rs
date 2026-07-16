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

    /// 从 settings 读代理 URL
    ///   None = 不走代理
    ///   阻塞,在 block 内取完就走
    pub fn proxy_url(&self) -> Option<String> {
        let conn = self.db.lock();
        let row: Option<(i64, Option<String>)> = conn.query_row(
            "SELECT proxy_enabled, proxy_url FROM settings WHERE id = 1",
            [],
            |r| Ok((r.get(0)?, r.get(1)?))
        ).ok();
        match row {
            Some((1, Some(u))) if !u.is_empty() => Some(u),
            _ => None,
        }
    }
}
