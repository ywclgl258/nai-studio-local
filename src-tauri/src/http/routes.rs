//! API 路由分发
//!
//! 所有 /api/* 路由在这里注册。
//! 暂时只挂 1-2 个，等 Phase 2+ 逐步加。

use std::sync::Arc;

use axum::Router;
use axum::routing::{get, post, put, delete};

use crate::api;
use crate::state::AppState;

pub fn api_router() -> Router<Arc<AppState>> {
    Router::new()
        // ===== 系统状态 =====
        .route("/status",           get(api::status::status))
        .route("/backend/status",   get(api::backend::status))
        .route("/backend/stop",     post(api::backend::stop))

        // ===== Settings =====
        .route("/settings",         get(api::settings::get).post(api::settings::update))

        // ===== Gallery =====
        .route("/gallery",          get(api::gallery::list).post(api::gallery::action).delete(api::gallery::delete))
        .route("/gallery/zip",      get(api::gallery::zip))
        .route("/gallery/clear",    post(api::gallery::clear))

        // ===== Generate (NAI) =====
        .route("/generate",         post(api::generate::generate))
        .route("/anlas",            get(api::anlas::get))

        // ===== Upscale (Real-ESRGAN) =====
        .route("/upscale",          post(api::upscale::handle))

        // ===== Tags =====
        .route("/tags",             get(api::tags::list))

        // ===== Artists =====
        .route("/artists",          get(api::artists::list))

        // ===== Danbooru =====
        .route("/danbooru",         get(api::danbooru::handle))

        // ===== AI analyze (DeepSeek) =====
        .route("/ai_analyze",       post(api::ai_analyze::handle))

        // ===== Presets (prompt / character / pose / artist) =====
        .route("/prompts",          get(api::prompts::list).post(api::prompts::create))
        .route("/character_presets",get(api::character_presets::list).post(api::character_presets::create))
        .route("/pose_presets",     get(api::pose_presets::list).post(api::pose_presets::create))
        .route("/artist_presets",   get(api::artist_presets::list).post(api::artist_presets::create))

        // ===== Cleanup =====
        .route("/cleanup",          post(api::cleanup::handle))

        // ===== Decompose =====
        .route("/decompose",        post(api::decompose::handle))

        // ===== Tag image =====
        .route("/tag_image",        get(api::tag_image::handle))

        // ===== Settings AI =====
        .route("/settings_ai",      get(api::settings_ai::get).post(api::settings_ai::update))
        .route("/settings_ai/test", get(api::settings_ai::test))

        // ===== API keys =====
        .route("/api-keys",         get(api::api_keys::list).post(api::api_keys::create))

        // ===== Proxy =====
        .route("/proxy",            get(api::proxy::status).post(api::proxy::test))

        // ===== Upload + import =====
        .route("/upload",           post(api::upload::handle))
        .route("/import_meta",      post(api::import_meta::handle))

        // ===== Pose dict =====
        .route("/pose-dict",        get(api::pose_dict::handle))

        // ===== Admin (long tasks) =====
        .route("/admin/expand-tags",      get(api::admin::expand_tags::status).post(api::admin::expand_tags::start).delete(api::admin::expand_tags::stop))
        .route("/admin/import-all-tags",  get(api::admin::import_all_tags::status).post(api::admin::import_all_tags::start).delete(api::admin::import_all_tags::stop))
        .route("/admin/fetch_all_images", get(api::admin::fetch_all_images::status_or_stats).post(api::admin::fetch_all_images::start).delete(api::admin::fetch_all_images::stop))
}
