//! egui 主题 — 仿照 PHP v0.8 design tokens (--ns-*)
//!
//! 配色 / 圆角 / 阴影 / 间距 全部对齐 PHP CSS, 风格统一
//!   - 紫主色 #7c5cff
//!   - 青色 #22d3ee (辅)
//!   - 4 网格间距
//!   - 8/12/16 圆角
//!   - shadow 4/12/48 (egui 0.29 无原生 shadow, 用多层 Stroke 模拟)

use eframe::egui;

pub mod tokens {
    use eframe::egui::Color32;

    // === 背景 (v0.8 --ns-bg*) ===
    pub const BG:           Color32 = Color32::from_rgb(10, 12, 20);     // --ns-bg
    pub const BG_SOFT:      Color32 = Color32::from_rgb(17, 20, 31);     // --ns-bg-soft
    pub const BG_ELEVATED:  Color32 = Color32::from_rgb(23, 27, 41);     // --ns-bg-elevated
    pub const BG_OVERLAY:   Color32 = Color32::from_rgb(28, 32, 48);     // 浮层
    pub const BG_HOVER:     Color32 = Color32::from_rgb(34, 40, 58);

    // === 边框 (--ns-line, 偏白透明) ===
    // 用 lazy 而非 const, 因为 Color32::from_rgba_unmultiplied 不是 const fn
    pub fn line() -> Color32 { Color32::from_rgba_unmultiplied(255, 255, 255, 15) }       // 0.06 alpha
    pub fn line_strong() -> Color32 { Color32::from_rgba_unmultiplied(255, 255, 255, 28) }  // 0.11 alpha
    pub fn line_accent() -> Color32 { Color32::from_rgba_unmultiplied(124, 92, 255, 60) }

    // 旧名兼容 (fallback const)
    pub const LINE:         Color32 = Color32::from_rgb(38, 38, 48);
    pub const LINE_STRONG:  Color32 = Color32::from_rgb(54, 54, 68);
    pub const LINE_ACCENT:  Color32 = Color32::from_rgb(124, 92, 255);
    pub fn accent_soft() -> Color32 { Color32::from_rgba_unmultiplied(124, 92, 255, 36) }

    // === 文字 (v0.8 --ns-text) ===
    pub const TEXT:         Color32 = Color32::from_rgb(230, 235, 245); // #e6ebf5
    pub const TEXT_2:       Color32 = Color32::from_rgb(163, 172, 194); // #a3acc2
    pub const TEXT_3:       Color32 = Color32::from_rgb(108, 118, 142); // #6c768e
    pub const TEXT_ON_ACCENT: Color32 = Color32::from_rgb(255, 255, 255);

    // === 主色 (v0.8 --ns-accent) ===
    pub const ACCENT:       Color32 = Color32::from_rgb(124, 92, 255);  // #7c5cff
    pub const ACCENT_HOVER: Color32 = Color32::from_rgb(141, 112, 255);
    pub const ACCENT_2:     Color32 = Color32::from_rgb(34, 211, 238);  // #22d3ee 青

    // === 状态 (v0.8 --ns-*) ===
    pub const SUCCESS:      Color32 = Color32::from_rgb(52, 211, 153);  // #34d399
    pub const WARN:         Color32 = Color32::from_rgb(251, 191, 36);  // #fbbf24
    pub const DANGER:       Color32 = Color32::from_rgb(251, 113, 133); // #fb7185

    // === 间距 (4 网格, --ns-1..6) ===
    pub const NS_1:  f32 = 4.0;
    pub const NS_2:  f32 = 8.0;
    pub const NS_3:  f32 = 12.0;
    pub const NS_4:  f32 = 16.0;
    pub const NS_5:  f32 = 24.0;
    pub const NS_6:  f32 = 32.0;
    pub const NS_7:  f32 = 48.0;

    // === 圆角 (--ns-r-*) ===
    pub const R_SM:   f32 = 4.0;
    pub const R:      f32 = 8.0;
    pub const R_MD:   f32 = 12.0;
    pub const R_LG:   f32 = 16.0;
    pub const R_PILL: f32 = 999.0;

    // === 顶栏 / 侧栏尺寸 ===
    pub const TOPBAR_H:     f32 = 56.0;
    pub const LEFT_W:       f32 = 280.0;
    pub const RIGHT_W:      f32 = 280.0;
    pub const STATUS_H:     f32 = 24.0;
}

/// 应用主题 (仿 PHP --ns-* token)
pub fn apply_default(ctx: &egui::Context) {
    let mut visuals = egui::Visuals::dark();
    use tokens::*;

    visuals.window_fill = BG;
    visuals.panel_fill = BG;
    visuals.faint_bg_color = BG_SOFT;
    visuals.extreme_bg_color = BG;
    visuals.code_bg_color = BG_SOFT;

    visuals.override_text_color = Some(TEXT);

    visuals.window_stroke = egui::Stroke::new(1.0, LINE);
    visuals.widgets.noninteractive.bg_fill = BG_SOFT;
    visuals.widgets.noninteractive.bg_stroke = egui::Stroke::new(1.0, LINE);
    visuals.widgets.noninteractive.fg_stroke = egui::Stroke::new(1.0, TEXT);

    visuals.widgets.inactive.bg_fill = BG_ELEVATED;
    visuals.widgets.inactive.bg_stroke = egui::Stroke::new(1.0, LINE);
    visuals.widgets.inactive.fg_stroke = egui::Stroke::new(1.0, TEXT_2);

    visuals.widgets.hovered.bg_fill = BG_HOVER;
    visuals.widgets.hovered.bg_stroke = egui::Stroke::new(1.0, LINE_STRONG);
    visuals.widgets.hovered.fg_stroke = egui::Stroke::new(1.0, TEXT);

    visuals.widgets.active.bg_fill = ACCENT;
    visuals.widgets.active.bg_stroke = egui::Stroke::new(1.0, ACCENT);
    visuals.widgets.active.fg_stroke = egui::Stroke::new(1.0, TEXT_ON_ACCENT);

    visuals.widgets.open.bg_fill = accent_soft();
    visuals.widgets.open.bg_stroke = egui::Stroke::new(1.0, ACCENT);
    visuals.widgets.open.fg_stroke = egui::Stroke::new(1.0, TEXT);

    visuals.selection.bg_fill = ACCENT;
    visuals.selection.stroke = egui::Stroke::new(1.0, ACCENT);
    visuals.hyperlink_color = ACCENT_2;
    visuals.error_fg_color = DANGER;
    visuals.warn_fg_color = WARN;

    ctx.set_visuals(visuals);

    let mut style = (*ctx.style()).clone();
    style.spacing.item_spacing = egui::vec2(tokens::NS_2, tokens::NS_2);
    style.spacing.button_padding = egui::vec2(tokens::NS_3, tokens::NS_2);
    style.spacing.indent = tokens::NS_3;
    style.spacing.interact_size = egui::vec2(40.0, 32.0);
    style.spacing.slider_width = 160.0;
    style.spacing.combo_width = 160.0;
    style.spacing.window_margin = egui::Margin::same(tokens::NS_4);

    ctx.set_style(style);
}

// === 字体 helper ===
pub fn h1(text: &str) -> egui::RichText {
    egui::RichText::new(text).size(20.0).strong().color(tokens::TEXT)
}
pub fn h2(text: &str) -> egui::RichText {
    egui::RichText::new(text).size(15.0).strong().color(tokens::TEXT)
}
pub fn h3(text: &str) -> egui::RichText {
    egui::RichText::new(text).size(13.0).strong().color(tokens::TEXT)
}
pub fn body(text: &str) -> egui::RichText {
    egui::RichText::new(text).size(13.0).color(tokens::TEXT)
}
pub fn body2(text: &str) -> egui::RichText {
    egui::RichText::new(text).size(12.0).color(tokens::TEXT_2)
}
pub fn small(text: &str) -> egui::RichText {
    egui::RichText::new(text).size(11.0).color(tokens::TEXT_3)
}
pub fn micro(text: &str) -> egui::RichText {
    egui::RichText::new(text).size(10.0).color(tokens::TEXT_3)
}
pub fn accent(text: &str) -> egui::RichText {
    egui::RichText::new(text).size(13.0).color(tokens::ACCENT)
}
pub fn hint(text: &str) -> egui::RichText {
    egui::RichText::new(text).size(13.0).color(tokens::TEXT_3).italics()
}

/// 卡片 helper (v0.8 ns-r = 8px, ns-bg-soft 背景, ns-line 1px 边框)
pub fn card() -> egui::Frame {
    egui::Frame::none()
        .fill(tokens::BG_SOFT)
        .stroke(egui::Stroke::new(1.0, tokens::LINE))
        .rounding(0.0)  // egui 0.29 暂时不设
        .inner_margin(egui::Margin::same(tokens::NS_4))
}

pub fn card_label(ui: &mut egui::Ui, title: &str) {
    ui.label(egui::RichText::new(title)
        .size(11.0).strong().color(tokens::TEXT_3));
    ui.add_space(tokens::NS_2);
}

pub fn card_with_title(ui: &mut egui::Ui, title: &str, add_contents: impl FnOnce(&mut egui::Ui)) {
    card().show(ui, |ui| {
        card_label(ui, title);
        add_contents(ui);
    });
}
