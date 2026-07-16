//! NaiApp — 仿 PHP v2 三栏布局
//!
//! 完全照搬 NAI Studio PHP v0.8 的 .app-shell[data-shell="v2"] 布局:
//!
//!   ┌─ TopBar 56px ──────────────────────────────────────┐
//!   │  ◈ NAI Studio  标签  ⌕ 搜索...    ● 已连接 v2.0  │
//!   ├─ Left 280px ──┬─ Central 1fr ──────────┬─ Right 280px ─┤
//!   │ 角色 / 姿势    │  视图内容              │  历史画廊     │
//!   │ 负面 / 预设    │  (生图 / 画廊 / 标签  │  (2 列 2:3  │
//!   │  / 模型参数   │   / 设置)              │   缩略图)     │
//!   │              │                        │              │
//!   ├─ StatusBar 24px ─────────────────────────────────────┤
//!   └──────────────────────────────────────────────────┘

use std::sync::Arc;
use std::sync::atomic::{AtomicBool, Ordering};

use eframe::egui;

use crate::state::AppState;
use super::command::CommandPalette;
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
    pub command: CommandPalette,
}

static GLOBAL_PING_OK: AtomicBool = AtomicBool::new(false);

impl NaiApp {
    pub fn new(state: Arc<AppState>, port: u16) -> Self {
        Self {
            state,
            http: HttpClient::new(port),
            current_view: View::Generate,
            last_status: None,
            last_status_at_ms: 0,
            last_ping_ok: false,
            last_ping_at_ms: 0,
            command: CommandPalette::new(),
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

    fn handle_global_shortcuts(&mut self, ctx: &egui::Context) {
        ctx.input(|i| {
            if i.key_pressed(egui::Key::K)
                && (i.modifiers.ctrl || i.modifiers.command)
                && !self.command.open
            {
                self.command.open = true;
                self.command.just_opened = true;
                self.command.query.clear();
                self.command.selected = 0;
            }
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
        self.handle_global_shortcuts(ctx);

        // ============================================================
        // TopBar 56px (仿 PHP --ns-topbar-h)
        // ============================================================
        egui::TopBottomPanel::top("topbar")
            .exact_height(theme::tokens::TOPBAR_H)
            .frame(egui::Frame::none()
                .fill(theme::tokens::BG_SOFT)
                .stroke(egui::Stroke::new(1.0, theme::tokens::LINE)))
            .show(ctx, |ui| {
                ui.add_space(theme::tokens::NS_1);
                ui.horizontal(|ui| {
                    ui.add_space(theme::tokens::NS_4);

                    // Logo + 品牌
                    ui.label(egui::RichText::new(icons::ICON_LOGO)
                        .size(18.0).color(theme::tokens::ACCENT));
                    ui.add_space(theme::tokens::NS_2);
                    ui.label(egui::RichText::new("NAI Studio")
                        .size(14.0).strong().color(theme::tokens::TEXT));

                    ui.add_space(theme::tokens::NS_4);

                    // 主导航 (4 tab, 仿 v2 .history-tabs)
                    for v in View::all() {
                        let selected = self.current_view == v;
                        let text_color = if selected { theme::tokens::TEXT } else { theme::tokens::TEXT_2 };
                        let bg = if selected { theme::tokens::accent_soft() } else { egui::Color32::TRANSPARENT };
                        let stroke = if selected {
                            egui::Stroke::new(1.0, theme::tokens::LINE_ACCENT)
                        } else {
                            egui::Stroke::NONE
                        };

                        let btn = egui::Button::new(
                            egui::RichText::new(format!("{} {}", v.icon(), v.label()))
                                .size(12.0).color(text_color)
                        )
                        .min_size(egui::vec2(64.0, 32.0))
                        .fill(bg)
                        .stroke(stroke);
                        if ui.add(btn).clicked() {
                            self.current_view = v;
                        }
                    }

                    ui.add_space(theme::tokens::NS_2);

                    // 主生图按钮 (高亮, 仿 FAB)
                    if self.current_view == View::Generate {
                        let gen_btn = egui::Button::new(
                            egui::RichText::new("✦ 生成")
                                .size(12.0).strong()
                                .color(theme::tokens::TEXT_ON_ACCENT)
                        )
                        .min_size(egui::vec2(72.0, 32.0))
                        .fill(theme::tokens::ACCENT);
                        ui.add(gen_btn);
                    }

                    ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                        ui.add_space(theme::tokens::NS_4);

                        // 后端状态
                        let (color, text, icon) = if self.last_ping_ok {
                            (theme::tokens::SUCCESS, "已连接", icons::ICON_CONNECTED)
                        } else {
                            (theme::tokens::DANGER, "未连接", icons::ICON_DISCONNECTED)
                        };
                        ui.label(egui::RichText::new(icon).size(10.0).color(color));
                        ui.add_space(2.0);
                        ui.label(theme::micro(text).color(color));
                        ui.add_space(theme::tokens::NS_4);

                        // 搜索按钮 (Ctrl+K)
                        let search_btn = egui::Button::new(
                            egui::RichText::new("⌕  搜索  Ctrl+K")
                                .size(11.0).color(theme::tokens::TEXT_3)
                        )
                        .min_size(egui::vec2(120.0, 28.0))
                        .fill(theme::tokens::BG_ELEVATED)
                        .stroke(egui::Stroke::new(1.0, theme::tokens::LINE));
                        if ui.add(search_btn).clicked() {
                            self.command.open = true;
                            self.command.just_opened = true;
                        }

                        ui.add_space(theme::tokens::NS_2);
                        ui.label(theme::micro("v2.0"));
                    });
                });
            });

        // ============================================================
        // StatusBar 24px (仿 PHP 状态条)
        // ============================================================
        egui::TopBottomPanel::bottom("statusbar")
            .exact_height(theme::tokens::STATUS_H)
            .frame(egui::Frame::none()
                .fill(theme::tokens::BG_SOFT)
                .stroke(egui::Stroke::new(1.0, theme::tokens::LINE)))
            .show(ctx, |ui| {
                ui.horizontal(|ui| {
                    ui.add_space(theme::tokens::NS_4);
                    ui.label(theme::micro(&format!("{} {}",
                        self.current_view.icon(), self.current_view.label()))
                        .color(theme::tokens::TEXT_2));
                    ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                        ui.add_space(theme::tokens::NS_4);
                        if let Some(res) = &self.last_status {
                            match res {
                                Ok(msg) => ui.label(theme::micro(&format!("✓ {}", msg))
                                    .color(theme::tokens::SUCCESS)),
                                Err(e) => ui.label(theme::micro(&format!("✗ {}", e))
                                    .color(theme::tokens::DANGER)),
                            };
                        } else {
                            ui.label(theme::micro("就绪").color(theme::tokens::TEXT_3));
                        }
                    });
                });
            });

        // ============================================================
        // 左侧栏 280px (仿 PHP --ns-sidebar-w, .left-panel)
        // 内容: 4 tab (主提示词 / 角色 / 姿势 / 负面) + 模型参数 + 预设
        // ============================================================
        egui::SidePanel::left("leftpanel")
            .resizable(false)
            .exact_width(theme::tokens::LEFT_W)
            .frame(egui::Frame::none()
                .fill(theme::tokens::BG_SOFT)
                .stroke(egui::Stroke::new(1.0, theme::tokens::LINE)))
            .show(ctx, |ui| {
                super::views::left_panel::show(ui, &self.http);
            });

        // ============================================================
        // 右侧栏 280px (仿 PHP --ns-history-w, .gallery-history-strip)
        // 内容: 历史画廊 (2 列 2:3 缩略图, hover 显示 seed/model meta)
        // ============================================================
        egui::SidePanel::right("history")
            .resizable(false)
            .exact_width(theme::tokens::RIGHT_W)
            .frame(egui::Frame::none()
                .fill(theme::tokens::BG_SOFT)
                .stroke(egui::Stroke::new(1.0, theme::tokens::LINE)))
            .show(ctx, |ui| {
                super::views::history_strip::show(ui, &self.http);
            });

        // ============================================================
        // 中央 1fr (仿 PHP .form-area / .gallery-area)
        // 内容: 当前 view (生图 / 画廊 / 标签 / 设置)
        // ============================================================
        egui::CentralPanel::default()
            .frame(egui::Frame::none()
                .fill(theme::tokens::BG)
                .inner_margin(egui::Margin::same(theme::tokens::NS_4)))
            .show(ctx, |ui| {
                match self.current_view {
                    View::Generate => super::views::home::show(ui, &self.http),
                    View::Gallery  => super::views::gallery::show(ui, &self.http),
                    View::Tags     => super::views::tags::show(ui, &self.http),
                    View::Settings => super::views::settings::show(ui, &self.http),
                }
            });

        // Ctrl+K 命令面板
        self.command.show(ctx);
        if let Some(kind) = self.command.take_action() {
            use super::command::CommandKind;
            match kind {
                CommandKind::SwitchView(v) => { self.current_view = v; }
                CommandKind::Placeholder(name) => {
                    self.last_status = Some(Err(format!("'{}' 还在 Phase B/C/D 规划中", name)));
                }
            }
        }
    }
}
