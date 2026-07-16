//! 画廊界面(占位, Phase B 实现)

use eframe::egui;

pub fn show(ui: &mut egui::Ui) {
    ui.vertical(|ui| {
        ui.heading("🖼️ 画廊");
        ui.add_space(20.0);
        ui.label("Phase A 占位: 画廊网格 + 大图预览, Phase B 实现");
    });
}
