//! NaiApp — egui 主 App
//!
//! 布局:
//!   ┌──────────────────────────────────────────────────┐
//!   │ TopBar: 标题 + 状态指示灯 (后端连通)                │
//!   ├──────┬───────────────────────────────────────────┤
//!   │ Side │ MainContent                                │
//!   │ Bar  │ (视图: Home / Gallery / Settings / ...)    │
//!   │ 220  │                                            │
//!   │      │                                            │
//!   ├──────┴───────────────────────────────────────────┤
//!   │ StatusBar: 后端 port, 当前视图, 错误提示            │
//!   └──────────────────────────────────────────────────┘

use std::sync::Arc;
use std::sync::atomic::{AtomicU16, Ordering};

use eframe::egui;
use serde_json::Value;

use crate::state::AppState;
use super::http_client::HttpClient;

#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum View {
    Home,
    Gallery,
    Settings,
}

impl View {
    pub fn label(self) -> &'static str {
        match self {
            View::Home     => "🎨 生图",
            View::Gallery  => "🖼️ 画廊",
            View::Settings => "⚙️ 设置",
        }
    }
}

pub struct NaiApp {
    pub state: Arc<AppState>,
    pub http: HttpClient,
    pub current_view: View,
    pub last_status: Option<Result<String, String>>,
    pub last_status_at_ms: i64,
    pub last_ping_ok: bool,
    pub last_ping_at_ms: i64,
}

impl NaiApp {
    pub fn new(state: Arc<AppState>, port: u16) -> Self {
        let http = HttpClient::new(port);
        Self {
            state,
            http,
            current_view: View::Home,
            last_status: None,
            last_status_at_ms: 0,
            last_ping_ok: false,
            last_ping_at_ms: 0,
        }
    }

    /// 同步 ping 后端(在 update 入口调一次, 检查 server 状态)
    /// 因为 update 不能 await, 我们用 std::thread::spawn 异步跑
    pub fn maybe_ping(&mut self, ctx: &egui::Context) {
        // 每 5 秒 ping 一次
        let now_ms = current_time_ms();
        if now_ms - self.last_ping_at_ms < 5000 {
            return;
        }
        self.last_ping_at_ms = now_ms;

        let http = self.http.clone();
        let ctx = ctx.clone();
        std::thread::spawn(move || {
            // 同步 block_on 调用 (UI 线程外, 自己的线程, 没问题)
            let rt = tokio::runtime::Builder::new_current_thread()
                .enable_all()
                .build()
                .unwrap();
            let result = rt.block_on(async move {
                http.get_text("/status").await
            });
            // 通过 ctx.request_repaint() 触发 UI 重绘
            match result {
                Ok(_text) => {
                    GLOBAL_PING_OK.store(true, Ordering::SeqCst);
                }
                Err(_) => {
                    GLOBAL_PING_OK.store(false, Ordering::SeqCst);
                }
            }
            ctx.request_repaint();
        });
    }
}

use once_cell::sync::Lazy;
use parking_lot::Mutex;

// 跨线程传递 ping 状态 (简化: 直接用全局 atomic)
static GLOBAL_PING_OK: std::sync::atomic::AtomicBool = std::sync::atomic::AtomicBool::new(false);

fn current_time_ms() -> i64 {
    use std::time::{SystemTime, UNIX_EPOCH};
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_millis() as i64)
        .unwrap_or(0)
}

impl eframe::App for NaiApp {
    fn update(&mut self, ctx: &egui::Context, _frame: &mut eframe::Frame) {
        // 0. 后端连通检查
        self.last_ping_ok = GLOBAL_PING_OK.load(Ordering::SeqCst);
        self.maybe_ping(ctx);

        // 1. 顶部状态条
        egui::TopBottomPanel::top("top_bar").show(ctx, |ui| {
            ui.horizontal(|ui| {
                ui.heading("🎨 NAI Studio Desktop");
                ui.add_space(20.0);
                // 后端连通指示灯
                let (color, text) = if self.last_ping_ok {
                    (egui::Color32::from_rgb(80, 200, 120), "● 后端已连接")
                } else {
                    (egui::Color32::from_rgb(200, 80, 80), "● 后端未连接")
                };
                ui.colored_label(color, text);
                ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                    ui.label(format!("v2.0.0 · egui · 0 WebView"));
                });
            });
        });

        // 2. 左侧导航
        egui::SidePanel::left("side_bar").resizable(false).exact_width(140.0).show(ctx, |ui| {
            ui.add_space(10.0);
            for v in [View::Home, View::Gallery, View::Settings] {
                let selected = self.current_view == v;
                let btn = egui::Button::new(v.label())
                    .min_size(egui::vec2(120.0, 36.0))
                    .selected(selected);
                if ui.add(btn).clicked() {
                    self.current_view = v;
                }
            }
            ui.add_space(20.0);
            ui.separator();
            ui.add_space(10.0);
            ui.label("Phase A");
            ui.label("  ✓ 架构切换");
            ui.label("  ✓ 窗口 + 后端");
            ui.add_space(5.0);
            ui.label("Phase B");
            ui.label("  □ 主生图");
            ui.label("  □ 画廊");
            ui.add_space(5.0);
            ui.label("Phase C");
            ui.label("  □ 设置");
            ui.label("  □ 标签 / 画师");
        });

        // 3. 底部状态栏
        egui::TopBottomPanel::bottom("status_bar").show(ctx, |ui| {
            ui.horizontal(|ui| {
                ui.label(format!("后端: {}", self.http.base()));
                ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                    if let Some(res) = &self.last_status {
                        match res {
                            Ok(msg) => ui.colored_label(egui::Color32::from_rgb(80, 200, 120), format!("✓ {}", msg)),
                            Err(e) => ui.colored_label(egui::Color32::from_rgb(200, 80, 80), format!("✗ {}", e)),
                        };
                    } else {
                        ui.label(format!("当前视图: {:?}", self.current_view));
                    }
                });
            });
        });

        // 4. 主内容
        egui::CentralPanel::default().show(ctx, |ui| {
            match self.current_view {
                View::Home     => super::views::home::show(ui, ctx),
                View::Gallery  => super::views::gallery::show(ui),
                View::Settings => super::views::settings::show(ui),
            }
        });
    }
}

// 避免 unused warnings
#[allow(dead_code)]
fn _phantom(_v: Arc<Mutex<Value>>, _a: AtomicU16, _l: Lazy<()>) {}
