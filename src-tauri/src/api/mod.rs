//! 业务 API 模块
//!
//! 每个子模块对应原 PHP 项目的 public/api/*.php 一个 endpoint。
//! 重构策略：保持 URL path 兼容（前端 fetch URL 几乎不用改），内部用 Rust 重写。

use std::sync::Arc;

use crate::state::AppState;

/// Handler 端用的 state 别名 — 直接给 Arc<AppState>，
/// 配合 `use axum::extract::State;` + `State(s): State<SharedState>`
/// 让 axum 推断 S = Arc<AppState>（与 routes.rs Router<Arc<AppState>> 对齐）
pub type SharedState = Arc<AppState>;

pub mod status;
pub mod backend;
pub mod settings;
pub mod gallery;
pub mod generate;
pub mod anlas;
pub mod upscale;
pub mod tags;
pub mod artists;
pub mod danbooru;
pub mod ai_client;
pub mod ai_analyze;
pub mod prompts;
pub mod character_presets;
pub mod pose_presets;
pub mod artist_presets;
pub mod cleanup;
pub mod decompose;
pub mod tag_image;
pub mod settings_ai;
pub mod api_keys;
pub mod proxy;
pub mod upload;
pub mod import_meta;
pub mod pose_dict;
pub mod admin;
