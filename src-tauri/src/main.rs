// NAI Studio Desktop — Tauri + WebView 入口
//   - Tauri 起一个 webview 窗口
//   - 后台 thread 跑 Axum HTTP server
//   - webview eval 跳到 http://127.0.0.1:PORT/ (同源, 前端 fetch /api/*)
//
// 之前试过 egui 重构 3 次, 用户反馈"太丑了还不如网页版"。
// 回退到 Tauri 架构, 跟原 PHP 网页 1:1 体验。

#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

fn main() {
    nai_studio_lib::run();
}
