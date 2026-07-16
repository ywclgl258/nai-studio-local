//! NAI Studio Desktop — main entry
//!
//! 架构：
//!   - Tauri 启动时，先在后台跑一个 Axum HTTP server（127.0.0.1:随机端口）
//!   - 拿到的端口号传给 WebviewWindowBuilder，让 WebView 加载 `http://127.0.0.1:PORT/`
//!   - 前端 fetch /api/xxx 走 HTTP，同源无 CORS 问题
//!   - 所有业务逻辑（NAI 调用 / DB / Real-ESRGAN）都在 Rust 端
//!
//! 端口：随机选取（避免多实例冲突），失败回退 17890

use std::sync::Arc;
use std::path::Path;
use tauri::Manager;

mod state;
mod paths;
mod error;
mod server;
mod http;
mod db;
mod api;
mod encryption;
mod nai_api;

pub use error::{AppError, AppResult};

pub fn run() {
    tauri::Builder::default()
        .plugin(tauri_plugin_log::Builder::default()
            .level(log::LevelFilter::Info)
            .build())
        .plugin(tauri_plugin_single_instance::init(|app, _argv, _cwd| {
            // 第二次启动时聚焦已有窗口
            if let Some(win) = app.get_webview_window("main") {
                let _ = win.set_focus();
            }
        }))
        .setup(|app| {
            // 1. 准备 paths
            let paths = paths::AppPaths::new()?;
            paths.ensure_dirs()?;
            log::info!("[NAI Studio] paths: storage={:?}", paths.storage);

            // 2. 打开 SQLite + 跑 migrations
            let conn = db::connection::open_and_migrate(&paths.db_file)?;
            let app_state = Arc::new(state::AppState::new(conn, paths.clone()));
            log::info!("[NAI Studio] DB ready at {:?}", paths.db_file);

            // 3. 启动 HTTP server（后台线程 / tokio runtime）
            let state_for_server = app_state.clone();
            let server_port = server::spawn_blocking(move || {
                // 用 tauri 内置的 tokio runtime
                tauri::async_runtime::block_on(async move {
                    server::start(state_for_server).await
                })
            })?;
            log::info!("[NAI Studio] HTTP server on http://127.0.0.1:{}/", server_port);

            // 4. 注入端口到 frontend
            let url = format!("http://127.0.0.1:{}/", server_port);
            log::info!("[NAI Studio] Loading webview at {}", url);

            // 5. 让 webview 加载 HTTP URL（而不是 frontendDist）
            if let Some(win) = app.get_webview_window("main") {
                use tauri::WebviewUrl;
                let _ = win.eval(&format!("window.__NAI_API_BASE__ = '{}';", ""));
                // Tauri 2.x: 通过 webview navigate 到外部 URL
                // 注意：setup 阶段窗口已创建（来自 tauri.conf.json），需要 navigate 覆盖默认 URL
                let _ = win.eval(&format!("window.location.replace('{}');", url));
            }

            // 6. 把 state 存到 app handle
            app.manage(app_state);

            Ok(())
        })
        .run(tauri::generate_context!())
        .expect("error while running tauri application");
}
