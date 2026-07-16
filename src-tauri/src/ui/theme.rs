//! egui 主题
//!
//! 默认深色主题(类似原 PHP 项目)

use eframe::egui;

pub fn apply_default(ctx: &egui::Context) {
    let mut visuals = egui::Visuals::dark();
    visuals.window_fill = egui::Color32::from_rgb(15, 17, 23);
    visuals.panel_fill = egui::Color32::from_rgb(20, 24, 33);
    visuals.faint_bg_color = egui::Color32::from_rgb(28, 33, 44);
    visuals.widgets.noninteractive.bg_fill = egui::Color32::from_rgb(28, 33, 44);
    visuals.widgets.inactive.bg_fill = egui::Color32::from_rgb(38, 43, 56);
    visuals.widgets.hovered.bg_fill = egui::Color32::from_rgb(48, 56, 72);
    visuals.widgets.active.bg_fill = egui::Color32::from_rgb(80, 100, 160);
    visuals.selection.bg_fill = egui::Color32::from_rgb(80, 100, 160);
    visuals.hyperlink_color = egui::Color32::from_rgb(120, 180, 240);
    ctx.set_visuals(visuals);
}
