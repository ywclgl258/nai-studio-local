//! SQLite 连接管理

use std::path::Path;

use rusqlite::Connection;

use crate::error::AppResult;
use crate::db::migrations;

pub fn open_and_migrate(db_file: &Path) -> AppResult<Connection> {
    let conn = Connection::open(db_file)?;

    // PRAGMAs — 跟 NAI Studio PHP 一样
    conn.pragma_update(None, "journal_mode", "WAL")?;
    conn.pragma_update(None, "synchronous", "NORMAL")?;
    conn.pragma_update(None, "foreign_keys", "ON")?;
    conn.pragma_update(None, "busy_timeout", 5000)?;

    // 跑 migrations
    migrations::run(&conn)?;

    Ok(conn)
}
