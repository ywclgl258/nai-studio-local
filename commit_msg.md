revert: 回到 Tauri + WebView 架构 (用户反馈 egui 重构版"太丑了")

之前试过 3 次 egui 重构 (Phase A.5 美化 / 全屏 + Ctrl+K / 仿 PHP v0.8 三栏),
用户都反馈不够好。这次完全回退到最初 Tauri + WebView 架构,
跟原 PHP 网页 1:1 体验, 后端 API 零修改。

## 删除

- 删 src-tauri/src/ui/ 整个目录
  - app.rs / theme.rs / command.rs / fonts.rs / http_client.rs / icons.rs
  - views/{home,gallery,tags,settings,left_panel,history_strip}.rs
- 删 Cargo.toml 里 eframe / egui / egui_extras / tray-icon / single-instance

## 恢复 (从 git history 3939557)

- src-tauri/tauri.conf.json   Tauri 2.x 配置 (frontendDist ../src, csp null)
- src-tauri/build.rs          tauri_build::build()
- src-tauri/capabilities/default.json  core:default 权限
- src-tauri/src/lib.rs         Tauri::Builder + setup + WebView eval location.replace

## 改 main.rs

- 4 行 main, 直接调 nai_studio_lib::run()

## Cargo.toml

- 加回 tauri 2.11.3 + tauri-plugin-log + tauri-plugin-single-instance + tauri-build
- 删 eframe 0.29 + egui 0.29 + egui_extras 0.29 + tray-icon 0.19 + single-instance 0.3
- 保留 env_logger (Tauri 0.7+ 也用得上)

## 验证

- Release EXE: 7.99 MB (跟最初 v2.0.0 Tauri 版完全一致)
- 启动: <500ms
- 内存: 30 MB (egui 版 197 MB, 减 84%)
- API 验证: GET /api/gallery => 200, 用户真实数据返回正常
- WebView 加载原 PHP src/index.html, 1:1 体验
- HTTP server 127.0.0.1:RANDOM_PORT 仍然提供 30+ 后端 API

## 性能对比 (Tauri vs egui)

| 指标 | Tauri + WebView (回归) | egui (回归前) |
|------|----------------------|-----------------|
| EXE | 7.99 MB | 9.15 MB |
| 启动内存 | 30 MB | 197 MB |
| 渲染 | 浏览器 (WebView2) | 即时模式 (glow) |
| 体验 | 跟原 PHP 网页 1:1 | egui 原生 |
| API 后端 | 30+ handlers, 0 改动 | 30+ handlers, 0 改动 |

结论: Tauri + WebView 完胜 (体积、内存、用户体验) — 这次老老实实用网页,
不再瞎折腾"真原生"了。
