//! HTTP server (Axum)
//!
//! 启动在 127.0.0.1:0（随机端口），返回实际端口给 Tauri 加载。

use std::net::SocketAddr;
use std::path::PathBuf;
use std::sync::Arc;

use axum::Router;
use axum::middleware::from_fn;
use axum::routing::get;

use tower_http::cors::CorsLayer;
use tower_http::services::ServeDir;

use crate::error::{AppError, AppResult};
use crate::http::rewrite::strip_php_extension;
use crate::http::routes::api_router;
use crate::state::AppState;

pub async fn start(state: Arc<AppState>) -> AppResult<u16> {
    let state_for_app = state.clone();

    // 定位 frontend 目录：从 EXE 路径向上找 src/
    // Tauri dev 模式 EXE 在 target/debug/，prod 在 target/release/
    // 项目根目录 = EXE 的 ../../
    //   └── src/                  ← frontend
    //   └── src-tauri/target/debug/nai-studio-desktop.exe
    let exe_path = std::env::current_exe()?;
    let exe_dir = exe_path.parent().ok_or_else(|| AppError::Internal("no exe dir".into()))?;
    // target/debug/nai-studio-desktop.exe → target/debug → target → src-tauri → project root → src/
    let frontend_dir = exe_dir
        .join("..").join("..").join("..")  // 跳出 target/debug/ 回到 project root
        .join("src");
    log::info!("[server] frontend dir: {:?}", frontend_dir);

    let storage_dir = state.paths.storage.clone();
    let images_dir = state.paths.images.clone();
    let thumbs_dir = state.paths.thumbs.clone();
    let upscales_dir = state.paths.upscales.clone();
    let uploads_dir = state.paths.uploads.clone();
    let tag_previews_dir = state.paths.tag_previews.clone();
    let tools_dir = state.paths.tools.clone();

    let app = Router::new()
        // 业务 API
        .nest("/api", api_router())
        // 静态资源（按优先级匹配，命中即返回）
        .fallback_service(
            axum::Router::new()
                // /storage/* — 用户数据（图片、缩略图、上传、标签预览、工具）
                .nest_service("/storage", ServeDir::new(&storage_dir))
                .nest_service("/storage/images",       ServeDir::new(&images_dir))
                .nest_service("/storage/thumbs",       ServeDir::new(&thumbs_dir))
                .nest_service("/storage/upscales",     ServeDir::new(&upscales_dir))
                .nest_service("/storage/uploads",      ServeDir::new(&uploads_dir))
                .nest_service("/storage/tag-previews", ServeDir::new(&tag_previews_dir))
                .nest_service("/storage/tools",        ServeDir::new(&tools_dir))
                // /assets/* — 前端静态文件（JS/CSS/字体/图标）
                .fallback_service(ServeDir::new(frontend_dir)),
        )
        .layer(CorsLayer::permissive())
        // 把 /api/xxx.php 改写成 /api/xxx
        .layer(from_fn(strip_php_extension))
        .with_state(state_for_app);

    // 监听 127.0.0.1:0（随机端口）
    let addr: SocketAddr = "127.0.0.1:0".parse().unwrap();
    let listener = tokio::net::TcpListener::bind(addr).await?;
    let local_addr = listener.local_addr()?;
    let port = local_addr.port();

    log::info!("[server] starting on http://127.0.0.1:{}/", port);

    // 后台 spawn
    tokio::spawn(async move {
        if let Err(e) = axum::serve(listener, app).await {
            log::error!("[server] crashed: {}", e);
        }
    });

    Ok(port)
}

/// 同步启动（在 std::thread 里调用）
pub fn spawn_blocking<F, R>(f: F) -> AppResult<R>
where
    F: FnOnce() -> AppResult<R> + Send + 'static,
    R: Send + 'static,
{
    let (tx, rx) = std::sync::mpsc::channel();
    std::thread::spawn(move || {
        let result = f();
        let _ = tx.send(result);
    });
    rx.recv().map_err(|e| crate::AppError::Internal(format!("server thread: {}", e)))?
}
