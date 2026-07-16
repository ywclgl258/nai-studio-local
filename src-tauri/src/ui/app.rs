//! NaiApp — egui 主 App (Phase A.5 重新设计版)
//!
//! 简化版, 用 egui 0.29 基础 API, 避免新版本 CornerRadius / Rounding 等

use std::sync::Arc;
use std::sync::atomic::{AtomicBool, Ordering};

use eframe::egui;

use crate::state::AppState;
use super::http_client::HttpClient;
use super::{icons, theme};

#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum View {
    Generate,
    Gallery,
    Tags,
    Settings,
}

impl View {
    pub fn label(self) -> &'static str {
        match self {
            View::Generate => "生图",
            View::Gallery  => "画廊",
            View::Tags     => "标签",
            View::Settings => "设置",
        }
    }
    pub fn icon(self) -> &'static str {
        match self {
            View::Generate => icons::ICON_GENERATE,
            View::Gallery  => icons::ICON_GALLERY,
            View::Tags     => icons::ICON_TAGS,
            View::Settings => icons::ICON_SETTINGS,
        }
    }
    pub fn sub(self) -> &'static str {
        match self {
            View::Generate => "主生图 + 角色姿势 + 标签 + 模型",
            View::Gallery  => "历史作品 + 收藏 + 批量打包",
            View::Tags     => "本地 + Danbooru + 画师库",
            View::Settings => "API Key + AI 助手 + 代理 + 主题",
        }
    }
    pub fn all() -> [View; 4] {
        [View::Generate, View::Gallery, View::Tags, View::Settings]
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

static GLOBAL_PING_OK: AtomicBool = AtomicBool::new(false);

impl NaiApp {
    pub fn new(state: Arc<AppState>, port: u16) -> Self {
        let http = HttpClient::new(port);
        Self {
            state, http,
            current_view: View::Generate,
            last_status: None,
            last_status_at_ms: 0,
            last_ping_ok: false,
            last_ping_at_ms: 0,
        }
    }

    pub fn maybe_ping(&mut self, ctx: &egui::Context) {
        let now_ms = current_time_ms();
        if now_ms - self.last_ping_at_ms < 5000 { return; }
        self.last_ping_at_ms = now_ms;

        let http = self.http.clone();
        let ctx = ctx.clone();
        std::thread::spawn(move || {
            let rt = tokio::runtime::Builder::new_current_thread()
                .enable_all().build().unwrap();
            let result = rt.block_on(async move { http.get_text("/status").await });
            match result {
                Ok(_) => GLOBAL_PING_OK.store(true, Ordering::SeqCst),
                Err(_) => GLOBAL_PING_OK.store(false, Ordering::SeqCst),
            }
            ctx.request_repaint();
        });
    }
}

fn current_time_ms() -> i64 {
    use std::time::{SystemTime, UNIX_EPOCH};
    SystemTime::now().duration_since(UNIX_EPOCH)
        .map(|d| d.as_millis() as i64).unwrap_or(0)
}

impl eframe::App for NaiApp {
    fn update(&mut self, ctx: &egui::Context, _frame: &mut eframe::Frame) {
        self.last_ping_ok = GLOBAL_PING_OK.load(Ordering::SeqCst);
        self.maybe_ping(ctx);

        // === TopBar (56px) ===
        egui::TopBottomPanel::top("top_bar")
            .exact_height(56.0)
            .frame(egui::Frame::none()
                .fill(theme::tokens::BG_PANEL)
                .stroke(egui::Stroke::new(1.0, theme::tokens::BORDER_SUBTLE)))
            .show(ctx, |ui| {
                ui.add_space(8.0);
                ui.horizontal(|ui| {
                    ui.add_space(theme::tokens::SPACING_LG);
                    ui.label(egui::RichText::new(icons::ICON_LOGO)
                        .size(24.0).color(theme::tokens::ACCENT));
                    ui.add_space(8.0);
                    ui.label(egui::RichText::new("NAI Studio")
                        .size(15.0).strong().color(theme::tokens::TEXT_PRIMARY));
                    ui.label(egui::RichText::new(" Desktop")
                        .size(15.0).color(theme::tokens::TEXT_MUTED));

                    ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                        ui.add_space(theme::tokens::SPACING_LG);
                        let (color, text, icon) = if self.last_ping_ok {
                            (theme::tokens::SUCCESS, "已连接", icons::ICON_CONNECTED)
                        } else {
                            (theme::tokens::ERROR, "未连接", icons::ICON_DISCONNECTED)
                        };
                        ui.label(egui::RichText::new(icon).size(12.0).color(color));
                        ui.add_space(4.0);
                        ui.label(egui::RichText::new(text).size(12.0).color(color));
                        ui.add_space(theme::tokens::SPACING_LG);
                        ui.label(egui::RichText::new("v2.0.0")
                            .size(11.0).color(theme::tokens::TEXT_MUTED));
                    });
                });
            });

        // === SideBar (220px) ===
        egui::SidePanel::left("side_bar")
            .resizable(false).exact_width(220.0)
            .frame(egui::Frame::none()
                .fill(theme::tokens::BG_PANEL)
                .stroke(egui::Stroke::new(1.0, theme::tokens::BORDER_SUBTLE)))
            .show(ctx, |ui| {
                ui.add_space(theme::tokens::SPACING_LG);
                ui.label(egui::RichText::new("  主导航")
                    .size(11.0).color(theme::tokens::TEXT_MUTED).strong());
                ui.add_space(8.0);

                for v in View::all() {
                    let selected = self.current_view == v;
                    let text_color = if selected { theme::tokens::TEXT_PRIMARY } else { theme::tokens::TEXT_SECONDARY };
                    let fill = if selected { theme::tokens::ACCENT_SUBTLE } else { theme::tokens::BG_PANEL };
                    let stroke = if selected { egui::Stroke::new(1.0, theme::tokens::ACCENT) } else { egui::Stroke::NONE };

                    let btn = egui::Button::new(
                        egui::RichText::new(format!("  {}  {}", v.icon(), v.label()))
                            .size(13.0).color(text_color)
                    )
                    .min_size(egui::vec2(190.0, 36.0))
                    .fill(fill)
                    .stroke(stroke);

                    if ui.add(btn).clicked() {
                        self.current_view = v;
                    }
                }

                ui.add_space(theme::tokens::SPACING_2XL);
                ui.label(egui::RichText::new("  高级功能 (Phase D)")
                    .size(11.0).color(theme::tokens::TEXT_MUTED).strong());
                ui.add_space(8.0);
                for (icon, label) in [
                    (icons::ICON_VIBE, "Vibe Transfer"),
                    (icons::ICON_PRECISE, "Precise"),
                    (icons::ICON_MASK, "Mask Editor"),
                    (icons::ICON_QUEUE, "批量队列"),
                    (icons::ICON_AI, "AI 助手"),
                ] {
                    let btn = egui::Button::new(
                        egui::RichText::new(format!("  {}  {}", icon, label))
                            .size(13.0).color(theme::tokens::TEXT_MUTED)
                    )
                    .min_size(egui::vec2(190.0, 32.0))
                    .fill(egui::Color32::TRANSPARENT);
                    ui.add_enabled(false, btn);
                }

                ui.with_layout(egui::Layout::bottom_up(egui::Align::LEFT), |ui| {
                    ui.add_space(theme::tokens::SPACING_LG);
                    ui.label(egui::RichText::new(format!("后端: {}", self.http.base()))
                        .size(10.0).color(theme::tokens::TEXT_MUTED));
                });
            });

        // === StatusBar (28px) ===
        egui::TopBottomPanel::bottom("status_bar")
            .exact_height(28.0)
            .frame(egui::Frame::none()
                .fill(theme::tokens::BG_PANEL)
                .stroke(egui::Stroke::new(1.0, theme::tokens::BORDER_SUBTLE)))
            .show(ctx, |ui| {
                ui.horizontal(|ui| {
                    ui.add_space(theme::tokens::SPACING_LG);
                    ui.label(egui::RichText::new(format!("{} {}",
                        self.current_view.icon(), self.current_view.label()))
                        .size(11.0).color(theme::tokens::TEXT_SECONDARY));
                    ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                        ui.add_space(theme::tokens::SPACING_LG);
                        if let Some(res) = &self.last_status {
                            match res {
                                Ok(msg) => ui.label(egui::RichText::new(format!("✓ {}", msg))
                                    .size(11.0).color(theme::tokens::SUCCESS)),
                                Err(e) => ui.label(egui::RichText::new(format!("✗ {}", e))
                                    .size(11.0).color(theme::tokens::ERROR)),
                            };
                        } else {
                            ui.label(egui::RichText::new("就绪")
                                .size(11.0).color(theme::tokens::TEXT_MUTED));
                        }
                    });
                });
            });

        // === CentralPanel: 主内容 ===
        egui::CentralPanel::default()
            .frame(egui::Frame::none()
                .fill(theme::tokens::BG_BASE)
                .inner_margin(egui::Margin::same(theme::tokens::SPACING_XL)))
            .show(ctx, |ui| {
                // 标题区
                ui.horizontal(|ui| {
                    ui.label(egui::RichText::new(self.current_view.icon())
                        .size(24.0).color(theme::tokens::ACCENT));
                    ui.add_space(8.0);
                    ui.vertical(|ui| {
                        ui.label(theme::h1(self.current_view.label()));
                        ui.label(theme::subtitle(self.current_view.sub()));
                    });
                });

                ui.add_space(theme::tokens::SPACING_2XL);

                match self.current_view {
                    View::Generate => super::views::home::show(ui, &self.http),
                    View::Gallery  => super::views::gallery::show(ui, &self.http),
                    View::Tags     => super::views::tags::show(ui, &self.http),
                    View::Settings => super::views::settings::show(ui, &self.http),
                }
            });
    }
}
