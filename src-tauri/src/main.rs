// NAI Studio Desktop — egui entry point
//   - 后端 HTTP server 跑在后台 thread (Axum, 30+ API)
//   - GUI 跑在主线程 (egui 即时模式, 0 WebView)
//   - GUI 通过 reqwest 调 http://127.0.0.1:RANDOM_PORT/ 与后端通信
//
// 启动流程:
//   1. 准备 paths + DB
//   2. 后台 thread 启动 tokio runtime, 跑 Axum server, 拿 port
//   3. 把 port 写入文件 / atomic, GUI 启动后读
//   4. eframe::run_native 启动 GUI 主循环

#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

use std::sync::Arc;
use std::sync::atomic::{AtomicU16, Ordering};

mod paths;
mod error;
mod db;
mod state;
mod server;
mod http;
mod api;
mod encryption;
mod nai_api;
mod ui;

pub use error::{AppError, AppResult};

/// 后端 HTTP server 监听的端口(后台 thread 启动后写入, GUI 启动时读)
static SERVER_PORT: AtomicU16 = AtomicU16::new(0);

fn main() {
    // 初始化 logger
    env_logger::Builder::from_default_env()
        .filter_level(log::LevelFilter::Info)
        .try_init()
        .ok();

    // 1. 准备 paths + DB
    let app_paths = match paths::AppPaths::new() {
        Ok(p) => p,
        Err(e) => {
            eprintln!("[FATAL] failed to create app paths: {}", e);
            std::process::exit(1);
        }
    };
    if let Err(e) = app_paths.ensure_dirs() {
        eprintln!("[FATAL] failed to ensure dirs: {}", e);
        std::process::exit(1);
    }
    log::info!("[NAI Studio] paths: storage={:?}", app_paths.storage);

    let conn = match db::connection::open_and_migrate(&app_paths.db_file) {
        Ok(c) => c,
        Err(e) => {
            eprintln!("[FATAL] failed to open DB: {}", e);
            std::process::exit(1);
        }
    };
    let app_state = Arc::new(state::AppState::new(conn, app_paths));
    log::info!("[NAI Studio] DB ready");

    // 2. 后台 thread 跑 Axum server
    let state_for_server = app_state.clone();
    let server_thread = std::thread::Builder::new()
        .name("nai-http-server".to_string())
        .spawn(move || {
            let runtime = tokio::runtime::Builder::new_multi_thread()
                .enable_all()
                .worker_threads(2)
                .thread_name("nai-tokio")
                .build()
                .expect("failed to build tokio runtime");
            runtime.block_on(async move {
                match server::start(state_for_server).await {
                    Ok(port) => {
                        log::info!("[server] listening on http://127.0.0.1:{}/", port);
                        SERVER_PORT.store(port, Ordering::SeqCst);
                    }
                    Err(e) => {
                        log::error!("[server] failed to start: {}", e);
                    }
                }
                // 保持 runtime 不退出
                loop {
                    tokio::time::sleep(std::time::Duration::from_secs(60)).await;
                }
            });
        })
        .expect("failed to spawn server thread");

    // 3. 等 server 起来(等最多 3 秒)
    let mut waited = 0u32;
    while SERVER_PORT.load(Ordering::SeqCst) == 0 && waited < 30 {
        std::thread::sleep(std::time::Duration::from_millis(100));
        waited += 1;
    }
    let port = SERVER_PORT.load(Ordering::SeqCst);
    if port == 0 {
        eprintln!("[FATAL] HTTP server failed to start within 3 seconds");
        std::process::exit(1);
    }
    log::info!("[main] server port = {}", port);

    // 4. 启动 eframe GUI
    let options = eframe::NativeOptions {
        viewport: egui::ViewportBuilder::default()
            .with_title("NAI Studio Desktop")
            .with_inner_size([1280.0, 800.0])
            .with_min_inner_size([1024.0, 700.0]),
        ..Default::default()
    };

    let result = eframe::run_native(
        "nai-studio-desktop",
        options,
        Box::new(move |cc| {
            // 注入 egui 字体(支持中文)
            ui::fonts::install_chinese_fonts(&cc.egui_ctx);
            // 注入主题
            ui::theme::apply_default(&cc.egui_ctx);
            Ok(Box::new(ui::app::NaiApp::new(app_state, port)))
        }),
    );

    if let Err(e) = result {
        eprintln!("[FATAL] eframe error: {}", e);
    }
    drop(server_thread);
}
