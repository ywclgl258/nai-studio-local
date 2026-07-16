//! 视图模块
//!
//! 布局 (仿 PHP v0.8 app-shell v2):
//!   - TopBar (顶栏 56px) — 在 app.rs
//!   - left_panel (左 280px) — 此文件
//!   - CentralPanel (中 1fr) — view 切换
//!   - history_strip (右 280px) — 此文件
//!   - StatusBar (底 24px) — 在 app.rs

pub mod left_panel;
pub mod history_strip;
pub mod home;
pub mod gallery;
pub mod tags;
pub mod settings;
