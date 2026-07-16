//! NaiApp — egui 主 App (重构: Linear 风全屏架构)
//!
//! 布局:
//!   ┌─ TopBar 32px (极薄, brand + 状态 + 搜索) ─────┐
//!   ├─ CentralPanel (全屏, 当前 view 内容)         ─┤
//!   ├─ StatusBar 24px (极薄, 当前视图)             ─┤
//!   └─ Ctrl+K 命令面板 (悬浮 modal)                  ┘
//!
//! 关键改动 (vs Phase A.5):
//!   - 删 SideBar (220px 浪费)
//!   - TopBar 56 → 32 (chrome 减半)
//!   - StatusBar 28 → 24
//!   - 主视图用 mini tab 切换 (顶栏内嵌)
//!   - 高级功能进 Ctrl+K 命令面板

use std::sync::Arc;
use std::sync::atomic::{AtomicBool, Ordering};

use eframe::egui;

use crate::state::AppState;
use super::command::{CommandPalette};
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

    /// 处理全局快捷键
    fn handle_global_shortcuts(&mut self, ctx: &egui::Context) {
        ctx.input(|i| {
            // Ctrl+K / Cmd+K 弹命令面板
            if i.key_pressed(egui::Key::K)
                && (i.modifiers.ctrl || i.modifiers.command)
                && !self.command.open
            {
                self.command.open = true;
                self.command.just_opened = true;
                self.command.query.clear();
                self.command.selected = 0;
            }
            // 顶栏 tab 切换 (1-4 数字键)
            if !self.command.open {
                if i.key_pressed(egui::Key::Num1) { self.current_view = View::Generate; }
                if i.key_pressed(egui::Key::Num2) { self.current_view = View::Gallery; }
                if i.key_pressed(egui::Key::Num3) { self.current_view = View::Tags; }
                if i.key_pressed(egui::Key::Num4) { self.current_view = View::Settings; }
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

        // === TopBar 32px (极薄) ===
        egui::TopBottomPanel::top("top_bar")
            .exact_height(32.0)
            .frame(egui::Frame::none()
                .fill(theme::tokens::BG_PANEL)
                .stroke(egui::Stroke::new(1.0, theme::tokens::BORDER_SUBTLE)))
            .show(ctx, |ui| {
                ui.horizontal(|ui| {
                    ui.add_space(theme::tokens::SPACING_MD);

                    // Logo + 品牌 (极简)
                    ui.label(egui::RichText::new(icons::ICON_LOGO)
                        .size(16.0).color(theme::tokens::ACCENT));
                    ui.add_space(6.0);
                    ui.label(egui::RichText::new("NAI Studio")
                        .size(12.0).strong().color(theme::tokens::TEXT_PRIMARY));

                    ui.add_space(theme::tokens::SPACING_LG);

                    // Mini tabs (4 视图切换)
                    for v in View::all() {
                        let selected = self.current_view == v;
                        let text_color = if selected { theme::tokens::TEXT_PRIMARY } else { theme::tokens::TEXT_MUTED };
                        let btn = egui::Button::new(
                            egui::RichText::new(format!("{} {}", v.icon(), v.label()))
                                .size(11.0).color(text_color)
                        )
                        .min_size(egui::vec2(64.0, 24.0))
                        .fill(if selected { theme::tokens::BG_CARD } else { egui::Color32::TRANSPARENT })
                        .stroke(if selected {
                            egui::Stroke::new(1.0, theme::tokens::BORDER_NORMAL)
                        } else {
                            egui::Stroke::NONE
                        });
                        if ui.add(btn).clicked() {
                            self.current_view = v;
                        }
                    }

                    ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                        ui.add_space(theme::tokens::SPACING_MD);

                        // 后端状态 (极小)
                        let (color, text, icon) = if self.last_ping_ok {
                            (theme::tokens::SUCCESS, "已连接", icons::ICON_CONNECTED)
                        } else {
                            (theme::tokens::ERROR, "未连接", icons::ICON_DISCONNECTED)
                        };
                        ui.label(egui::RichText::new(icon).size(10.0).color(color));
                        ui.label(egui::RichText::new(text).size(10.0).color(color));
                        ui.add_space(theme::tokens::SPACING_MD);

                        // Ctrl+K 搜索按钮
                        let search_btn = egui::Button::new(
                            egui::RichText::new("⌕ 搜索...   Ctrl+K")
                                .size(11.0).color(theme::tokens::TEXT_MUTED)
                        )
                        .min_size(egui::vec2(120.0, 22.0))
                        .fill(theme::tokens::BG_BASE)
                        .stroke(egui::Stroke::new(1.0, theme::tokens::BORDER_SUBTLE));
                        if ui.add(search_btn).clicked() {
                            self.command.open = true;
                            self.command.just_opened = true;
                        }
                    });
                });
            });

        // === StatusBar 24px (极薄) ===
        egui::TopBottomPanel::bottom("status_bar")
            .exact_height(24.0)
            .frame(egui::Frame::none()
                .fill(theme::tokens::BG_PANEL)
                .stroke(egui::Stroke::new(1.0, theme::tokens::BORDER_SUBTLE)))
            .show(ctx, |ui| {
                ui.horizontal(|ui| {
                    ui.add_space(theme::tokens::SPACING_MD);
                    ui.label(egui::RichText::new(format!("{} {}",
                        self.current_view.icon(), self.current_view.label()))
                        .size(10.0).color(theme::tokens::TEXT_SECONDARY));
                    ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                        ui.add_space(theme::tokens::SPACING_MD);
                        if let Some(res) = &self.last_status {
                            match res {
                                Ok(msg) => ui.label(egui::RichText::new(format!("✓ {}", msg))
                                    .size(10.0).color(theme::tokens::SUCCESS)),
                                Err(e) => ui.label(egui::RichText::new(format!("✗ {}", e))
                                    .size(10.0).color(theme::tokens::ERROR)),
                            };
                        } else {
                            ui.label(egui::RichText::new("就绪")
                                .size(10.0).color(theme::tokens::TEXT_MUTED));
                        }
                    });
                });
            });

        // === CentralPanel: 全屏主内容 ===
        egui::CentralPanel::default()
            .frame(egui::Frame::none()
                .fill(theme::tokens::BG_BASE)
                .inner_margin(egui::Margin::same(theme::tokens::SPACING_LG)))
            .show(ctx, |ui| {
                match self.current_view {
                    View::Generate => super::views::home::show(ui, &self.http),
                    View::Gallery  => super::views::gallery::show(ui, &self.http),
                    View::Tags     => super::views::tags::show(ui, &self.http),
                    View::Settings => super::views::settings::show(ui, &self.http),
                }
            });

        // === Ctrl+K 命令面板 (悬浮 modal, 在最上层) ===
        self.command.show(ctx);

        // 命令面板执行 action (在 NaiApp 上)
        if let Some(kind) = self.command.take_action() {
            use super::command::CommandKind;
            match kind {
                CommandKind::SwitchView(v) => {
                    self.current_view = v;
                }
                CommandKind::Placeholder(name) => {
                    self.last_status = Some(Err(format!("'{}' 还在 Phase B/C/D 规划中", name)));
                }
            }
        }
    }
}
