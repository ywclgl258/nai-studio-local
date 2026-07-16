//! 命令面板 (Linear / Raycast 风 Ctrl+K)
//!
//! 全局快捷键 Ctrl+K (⌘K on Mac) 弹出, 模糊搜索:
//!   - View 切换 (生图 / 画廊 / 标签 / 设置)
//!   - 高级功能 (Vibe / Precise / Mask / 队列 / AI 助手 / 上传 / 导入 / 清理)
//!   - 工具 (主题切换 / 数据目录 / 备份 / 重启)
//!
//! 操作:
//!   - 键盘 ↑↓ 选中, Enter 执行, Esc 关闭
//!   - 点击 / 鼠标 hover 选中
//!   - 模糊匹配 (label + hint 子串)

use eframe::egui;

use super::theme;

/// 单个命令
#[derive(Clone)]
pub struct Command {
    pub id: String,
    pub label: String,
    pub hint: String,
    pub icon: String,
    pub kind: CommandKind,
    pub shortcut: Option<String>,
}

#[derive(Clone, PartialEq)]
pub enum CommandKind {
    SwitchView(super::app::View),
    /// 占位, Phase B/C/D 实装
    Placeholder(String),
}

/// 命令面板状态
pub struct CommandPalette {
    pub open: bool,
    pub query: String,
    pub commands: Vec<Command>,
    pub selected: usize,
    pub just_opened: bool,
    pub pending_action: Option<CommandKind>,
}

impl CommandPalette {
    pub fn new() -> Self {
        let commands = build_default_commands();
        Self {
            open: false,
            query: String::new(),
            commands,
            selected: 0,
            just_opened: false,
            pending_action: None,
        }
    }

    /// 取出待执行命令 (NaiApp 在 update 里调)
    pub fn take_action(&mut self) -> Option<CommandKind> {
        self.pending_action.take()
    }

    /// 用户输入变化时, 重置 selected 到第一项
    pub fn on_query_change(&mut self) {
        self.selected = 0;
    }

    /// 过滤后的命令列表 (模糊匹配)
    pub fn filtered(&self) -> Vec<(usize, &Command)> {
        let q = self.query.to_lowercase();
        if q.is_empty() {
            return self.commands.iter().enumerate().collect();
        }
        self.commands
            .iter()
            .enumerate()
            .filter(|(_, c)| {
                c.label.to_lowercase().contains(&q)
                    || c.hint.to_lowercase().contains(&q)
                    || c.id.to_lowercase().contains(&q)
            })
            .collect()
    }

    /// 键盘处理
    pub fn handle_input(&mut self, ctx: &egui::Context) {
        ctx.input(|i| {
            // 关闭快捷键
            if i.key_pressed(egui::Key::Escape) && self.open {
                self.open = false;
            }
        });
    }

    /// 弹窗渲染
    pub fn show(&mut self, ctx: &egui::Context) {
        if !self.open { return; }

        // 居中弹窗
        let mut open = self.open;
        egui::Window::new("command_palette")
            .id(egui::Id::new("command_palette_window"))
            .anchor(egui::Align2::CENTER_TOP, [0.0, 80.0])
            .fixed_size([560.0, 420.0])
            .resizable(false)
            .collapsible(false)
            .title_bar(false)
            .frame(egui::Frame::none()
                .fill(theme::tokens::BG_PANEL)
                .stroke(egui::Stroke::new(1.0, theme::tokens::BORDER_STRONG))
                .rounding(0.0)
                .inner_margin(egui::Margin::same(0.0)))
            .open(&mut open)
            .show(ctx, |ui| {
                self.render(ui);
            });
        self.open = open;
    }

    fn render(&mut self, ui: &mut egui::Ui) {
        // 1. 选择命令后, 返回 Some(kind) 让外层执行
        // 2. 命令面板内部只负责过滤 + 渲染 + 键盘输入
        // 外层用 take_action() 拿结果
        let action = self.render_inner(ui);
        if let Some(kind) = action {
            // 通知外层 (在 show() 之前我们让 NaiApp 检查)
            self.pending_action = Some(kind);
        }
    }

    fn render_inner(&mut self, ui: &mut egui::Ui) -> Option<CommandKind> {
        // 搜索框
        let search_frame = egui::Frame::none()
            .fill(theme::tokens::BG_BASE)
            .stroke(egui::Stroke::new(1.0, theme::tokens::BORDER_SUBTLE))
            .inner_margin(egui::Margin::symmetric(theme::tokens::SPACING_LG, theme::tokens::SPACING_MD));

        let _response = search_frame.show(ui, |ui| {
            ui.horizontal(|ui| {
                ui.label(egui::RichText::new("⌕")
                    .size(18.0).color(theme::tokens::TEXT_MUTED));
                ui.add_space(theme::tokens::SPACING_SM);
                let prev = self.query.clone();
                let resp = egui::TextEdit::singleline(&mut self.query)
                    .hint_text("搜索命令、视图、功能...")
                    .desired_width(f32::INFINITY)
                    .font(egui::FontId::proportional(15.0))
                    .show(ui);
                if resp.response.changed() {
                    if prev != self.query {
                        self.on_query_change();
                    }
                }
                // 快捷键提示
                ui.label(egui::RichText::new("ESC")
                    .size(10.0).color(theme::tokens::TEXT_MUTED));
            });
        });

        // 自动 focus 搜索框 (首次打开)
        if self.just_opened {
            ui.ctx().memory_mut(|m| m.request_focus(egui::Id::new("command_palette_input")));
            self.just_opened = false;
        }

        ui.add_space(2.0);

        // 列表
        let filtered = self.filtered();
        if filtered.is_empty() {
            ui.vertical_centered(|ui| {
                ui.add_space(theme::tokens::SPACING_2XL);
                ui.label(theme::small("没有匹配的命令"));
            });
            return None;
        }

        let mut to_run: Option<CommandKind> = None;

        // 复制过滤结果, 避免 self.filtered() 在闭包外借用 self
        let filtered: Vec<(usize, Command)> = self
            .commands
            .iter()
            .enumerate()
            .filter(|(_, c)| {
                if self.query.is_empty() {
                    return true;
                }
                let q = self.query.to_lowercase();
                c.label.to_lowercase().contains(&q)
                    || c.hint.to_lowercase().contains(&q)
                    || c.id.to_lowercase().contains(&q)
            })
            .map(|(i, c)| (i, c.clone()))
            .collect();

        egui::ScrollArea::vertical()
            .max_height(340.0)
            .auto_shrink([false; 2])
            .show(ui, |ui| {
                ui.vertical(|ui| {
                    ui.style_mut().spacing.item_spacing = egui::vec2(0.0, 2.0);
                    for (i, cmd) in filtered.iter() {
                        let selected = *i == self.selected;
                        let bg = if selected { theme::tokens::ACCENT_SUBTLE } else { egui::Color32::TRANSPARENT };
                        let frame = egui::Frame::none()
                            .fill(bg)
                            .inner_margin(egui::Margin::symmetric(theme::tokens::SPACING_LG, theme::tokens::SPACING_SM));
                        let response = frame.show(ui, |ui| {
                            ui.horizontal(|ui| {
                                ui.label(egui::RichText::new(cmd.icon.clone())
                                    .size(16.0).color(
                                        if selected { theme::tokens::ACCENT } else { theme::tokens::TEXT_SECONDARY }
                                    ));
                                ui.add_space(theme::tokens::SPACING_SM);
                                ui.vertical(|ui| {
                                    ui.label(egui::RichText::new(cmd.label.clone())
                                        .size(13.0)
                                        .color(theme::tokens::TEXT_PRIMARY));
                                    if !cmd.hint.is_empty() {
                                        ui.label(theme::small(&cmd.hint));
                                    }
                                });
                                ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                                    if let Some(sc) = &cmd.shortcut {
                                        ui.label(egui::RichText::new(sc.clone())
                                            .size(11.0).color(theme::tokens::TEXT_MUTED));
                                    }
                                });
                            });
                        });

                        let resp = ui.interact(response.response.rect, egui::Id::new(format!("cmd_{}", cmd.id)), egui::Sense::click_and_drag());
                        if resp.hovered() {
                            self.selected = *i;
                        }
                        if resp.clicked() {
                            to_run = Some(cmd.kind.clone());
                        }
                    }

                    // Enter 触发选中
                    let enter = ui.ctx().input(|i| i.key_pressed(egui::Key::Enter));
                    if enter {
                        if let Some((_, cmd)) = filtered.get(self.selected.min(filtered.len().saturating_sub(1))) {
                            to_run = Some(cmd.kind.clone());
                        }
                    }

                    // 上下键
                    let max_idx = filtered.len().saturating_sub(1);
                    let cur = self.selected;
                    ui.ctx().input(|i| {
                        if i.key_pressed(egui::Key::ArrowDown) {
                            self.selected = (cur + 1).min(max_idx);
                        }
                        if i.key_pressed(egui::Key::ArrowUp) {
                            self.selected = cur.saturating_sub(1);
                        }
                    });
                });
            });

        if to_run.is_some() {
            self.open = false;
            self.query.clear();
            self.selected = 0;
        }
        to_run
    }
}

/// 默认命令列表
fn build_default_commands() -> Vec<Command> {
    use super::app::View;
    use super::icons;

    vec![
        // === 主视图 (4) ===
        Command {
            id: "view.generate".into(),
            label: "切换到: 生图".into(),
            hint: "主生图界面".into(),
            icon: icons::ICON_GENERATE.into(),
            kind: CommandKind::SwitchView(View::Generate),
            shortcut: Some("G".into()),
        },
        Command {
            id: "view.gallery".into(),
            label: "切换到: 画廊".into(),
            hint: "历史作品".into(),
            icon: icons::ICON_GALLERY.into(),
            kind: CommandKind::SwitchView(View::Gallery),
            shortcut: Some("V".into()),
        },
        Command {
            id: "view.tags".into(),
            label: "切换到: 标签".into(),
            hint: "标签 + 画师库".into(),
            icon: icons::ICON_TAGS.into(),
            kind: CommandKind::SwitchView(View::Tags),
            shortcut: Some("T".into()),
        },
        Command {
            id: "view.settings".into(),
            label: "切换到: 设置".into(),
            hint: "API Key + AI + 代理".into(),
            icon: icons::ICON_SETTINGS.into(),
            kind: CommandKind::SwitchView(View::Settings),
            shortcut: Some("S".into()),
        },

        // === 高级功能 (Phase D, 暂 stub) ===
        Command {
            id: "vibe".into(),
            label: "Vibe Transfer".into(),
            hint: "风格迁移 (Phase D)".into(),
            icon: icons::ICON_VIBE.into(),
            kind: CommandKind::Placeholder("vibe".into()),
            shortcut: None,
        },
        Command {
            id: "precise".into(),
            label: "Precise".into(),
            hint: "局部重绘 (Phase D)".into(),
            icon: icons::ICON_PRECISE.into(),
            kind: CommandKind::Placeholder("precise".into()),
            shortcut: None,
        },
        Command {
            id: "mask".into(),
            label: "Mask Editor".into(),
            hint: "遮罩编辑 (Phase D)".into(),
            icon: icons::ICON_MASK.into(),
            kind: CommandKind::Placeholder("mask".into()),
            shortcut: None,
        },
        Command {
            id: "queue".into(),
            label: "批量队列".into(),
            hint: "Batch Queue (Phase D)".into(),
            icon: icons::ICON_QUEUE.into(),
            kind: CommandKind::Placeholder("queue".into()),
            shortcut: None,
        },
        Command {
            id: "ai".into(),
            label: "AI 助手".into(),
            hint: "DeepSeek / OpenAI (Phase D)".into(),
            icon: icons::ICON_AI.into(),
            kind: CommandKind::Placeholder("ai".into()),
            shortcut: None,
        },
        Command {
            id: "upload".into(),
            label: "上传图片".into(),
            hint: "Upload (Phase D)".into(),
            icon: icons::ICON_UPLOAD.into(),
            kind: CommandKind::Placeholder("upload".into()),
            shortcut: None,
        },
        Command {
            id: "import".into(),
            label: "导入元数据".into(),
            hint: "Import PNG Metadata (Phase D)".into(),
            icon: icons::ICON_IMPORT.into(),
            kind: CommandKind::Placeholder("import".into()),
            shortcut: None,
        },
        Command {
            id: "cleanup".into(),
            label: "清理孤儿".into(),
            hint: "Cleanup (Phase D)".into(),
            icon: icons::ICON_CLEANUP.into(),
            kind: CommandKind::Placeholder("cleanup".into()),
            shortcut: None,
        },
    ]
}
