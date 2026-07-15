//! 数据库层
//!
//! 启动流程：
//!   1. connection::open_and_migrate() 打开 SQLite + WAL 模式 + busy_timeout
//!   2. migrations::run() 跑所有 migrations（CREATE TABLE IF NOT EXISTS）
//!
//! 与 NAI Studio PHP 项目的表结构完全兼容（SQLite 版），方便数据迁移。

pub mod connection;
pub mod migrations;
