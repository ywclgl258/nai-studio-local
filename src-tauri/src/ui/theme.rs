//! egui 主题 — 现代深色 UI (Linear / Vercel 风)
//!
//! 用 egui 0.29 基础 API (避免新版本的 CornerRadius / Rounding 兼容性坑)

use eframe::egui;

pub mod tokens {
    use eframe::egui::Color32;

    // === 背景层 (由深到浅 5 级) ===
    pub const BG_BASE:     Color32 = Color32::from_rgb(10, 10, 14);
    pub const BG_PANEL:    Color32 = Color32::from_rgb(17, 17, 22);
    pub const BG_CARD:     Color32 = Color32::from_rgb(24, 24, 30);
    pub const BG_RAISED:   Color32 = Color32::from_rgb(32, 32, 40);
    pub const BG_OVERLAY:  Color32 = Color32::from_rgb(40, 40, 50);

    // === 边框 ===
    pub const BORDER_SUBTLE: Color32 = Color32::from_rgb(38, 38, 48);
    pub const BORDER_NORMAL:  Color32 = Color32::from_rgb(54, 54, 68);
    pub const BORDER_STRONG:  Color32 = Color32::from_rgb(82, 82, 100);

    // === 主色: 紫 + 青 ===
    pub const ACCENT:        Color32 = Color32::from_rgb(124, 58, 237);
    pub const ACCENT_HOVER:  Color32 = Color32::from_rgb(140, 80, 250);
    pub const ACCENT_SUBTLE: Color32 = Color32::from_rgb(48, 32, 70);

    pub const SECONDARY:     Color32 = Color32::from_rgb(6, 182, 212);

    // === 文字 ===
    pub const TEXT_PRIMARY:   Color32 = Color32::from_rgb(240, 240, 245);
    pub const TEXT_SECONDARY: Color32 = Color32::from_rgb(180, 180, 195);
    pub const TEXT_MUTED:     Color32 = Color32::from_rgb(120, 120, 140);
    pub const TEXT_PLACEHOLDER: Color32 = Color32::from_rgb(80, 80, 100);
    pub const TEXT_ON_ACCENT: Color32 = Color32::from_rgb(255, 255, 255);

    // === 状态色 ===
    pub const SUCCESS: Color32 = Color32::from_rgb(34, 197, 94);
    pub const WARNING: Color32 = Color32::from_rgb(245, 158, 11);
    pub const ERROR:   Color32 = Color32::from_rgb(239, 68, 68);

    // === 间距 ===
    pub const SPACING_XS: f32 = 4.0;
    pub const SPACING_SM: f32 = 8.0;
    pub const SPACING_MD: f32 = 12.0;
    pub const SPACING_LG: f32 = 16.0;
    pub const SPACING_XL: f32 = 24.0;
    pub const SPACING_2XL: f32 = 32.0;
}

/// 应用主题
pub fn apply_default(ctx: &egui::Context) {
    let mut visuals = egui::Visuals::dark();
    use tokens::*;

    // 背景
    visuals.window_fill = BG_PANEL;
    visuals.panel_fill = BG_PANEL;
    visuals.faint_bg_color = BG_CARD;
    visuals.extreme_bg_color = BG_BASE;
    visuals.code_bg_color = BG_CARD;

    // 文字
    visuals.override_text_color = Some(TEXT_PRIMARY);

    // 边框
    visuals.window_stroke = egui::Stroke::new(1.0, BORDER_SUBTLE);
    visuals.widgets.noninteractive.bg_stroke = egui::Stroke::new(1.0, BORDER_SUBTLE);
    visuals.widgets.noninteractive.bg_fill = BG_CARD;
    visuals.widgets.noninteractive.fg_stroke = egui::Stroke::new(1.0, TEXT_PRIMARY);

    visuals.widgets.inactive.bg_fill = BG_RAISED;
    visuals.widgets.inactive.bg_stroke = egui::Stroke::new(1.0, BORDER_SUBTLE);
    visuals.widgets.inactive.fg_stroke = egui::Stroke::new(1.0, TEXT_SECONDARY);

    visuals.widgets.hovered.bg_fill = BG_OVERLAY;
    visuals.widgets.hovered.bg_stroke = egui::Stroke::new(1.0, BORDER_NORMAL);
    visuals.widgets.hovered.fg_stroke = egui::Stroke::new(1.0, TEXT_PRIMARY);

    visuals.widgets.active.bg_fill = ACCENT;
    visuals.widgets.active.bg_stroke = egui::Stroke::new(1.0, ACCENT);
    visuals.widgets.active.fg_stroke = egui::Stroke::new(1.0, TEXT_ON_ACCENT);

    visuals.widgets.open.bg_fill = ACCENT_SUBTLE;
    visuals.widgets.open.bg_stroke = egui::Stroke::new(1.0, ACCENT);
    visuals.widgets.open.fg_stroke = egui::Stroke::new(1.0, TEXT_PRIMARY);

    visuals.selection.bg_fill = ACCENT;
    visuals.selection.stroke = egui::Stroke::new(1.0, ACCENT);
    visuals.hyperlink_color = SECONDARY;
    visuals.error_fg_color = ERROR;
    visuals.warn_fg_color = WARNING;

    ctx.set_visuals(visuals);

    // 间距 / 字体
    let mut style = (*ctx.style()).clone();
    style.spacing.item_spacing = egui::vec2(tokens::SPACING_MD, tokens::SPACING_SM);
    style.spacing.button_padding = egui::vec2(tokens::SPACING_LG, tokens::SPACING_SM);
    style.spacing.indent = tokens::SPACING_LG;
    style.spacing.interact_size = egui::vec2(40.0, 24.0);
    style.spacing.slider_width = 160.0;
    style.spacing.combo_width = 160.0;
    style.spacing.window_margin = egui::Margin::same(tokens::SPACING_LG);

    ctx.set_style(style);
}

// === 字体 helper (RichText) ===
pub fn title(text: &str) -> egui::RichText {
    egui::RichText::new(text).size(20.0).strong().color(tokens::TEXT_PRIMARY)
}
pub fn h1(text: &str) -> egui::RichText {
    egui::RichText::new(text).size(28.0).strong().color(tokens::TEXT_PRIMARY)
}
pub fn subtitle(text: &str) -> egui::RichText {
    egui::RichText::new(text).size(14.0).color(tokens::TEXT_SECONDARY)
}
pub fn label(text: &str) -> egui::RichText {
    egui::RichText::new(text).size(13.0).color(tokens::TEXT_PRIMARY)
}
pub fn small(text: &str) -> egui::RichText {
    egui::RichText::new(text).size(11.0).color(tokens::TEXT_MUTED)
}
pub fn placeholder(text: &str) -> egui::RichText {
    egui::RichText::new(text).size(13.0).color(tokens::TEXT_PLACEHOLDER).italics()
}

/// 通用卡片 helper (无圆角, 仅填充 + 描边)
pub fn card_frame() -> egui::Frame {
    egui::Frame::none()
        .fill(tokens::BG_CARD)
        .stroke(egui::Stroke::new(1.0, tokens::BORDER_SUBTLE))
        .inner_margin(egui::Margin::same(tokens::SPACING_LG))
}

pub fn card(ui: &mut egui::Ui, title: &str, add_contents: impl FnOnce(&mut egui::Ui)) {
    card_frame().show(ui, |ui| {
        ui.label(egui::RichText::new(title)
            .size(13.0)
            .strong()
            .color(tokens::TEXT_PRIMARY));
        ui.add_space(tokens::SPACING_SM);
        add_contents(ui);
    });
}
