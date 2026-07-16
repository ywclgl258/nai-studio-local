//! 视图模块
//!
//! 每个视图 = 一个 fn update(&mut self, ui: &mut egui::Ui, ...)
//! 视图的状态存在 NaiApp 里, 这里只负责渲染 + 调 http client

pub mod home;
pub mod gallery;
pub mod settings;
