//! 视图模块
//!
//! 每个视图 = 一个 fn show(ui, http, ...) — 渲染 + 调 http client
//! 状态存在 NaiApp 里, 视图函数只负责渲染

pub mod home;
pub mod gallery;
pub mod tags;
pub mod settings;
