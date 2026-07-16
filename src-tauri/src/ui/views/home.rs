//! 主生图界面(占位, Phase B 实现)

use eframe::egui;

pub fn show(ui: &mut egui::Ui, _ctx: &egui::Context) {
    ui.vertical(|ui| {
        ui.heading("🎨 主生图界面");
        ui.add_space(20.0);
        ui.label("Phase A 占位: 后端通信验证, 出图界面 Phase B 实现");
        ui.add_space(10.0);
        ui.label("接下来会实现:");
        ui.label("  • 主 prompt / 负面 prompt / 角色 / 姿势 (4 个 textarea)");
        ui.label("  • 模型选择 (V3 / V4 / V4.5)");
        ui.label("  • Sampler / Steps / Scale / Size");
        ui.label("  • 出图按钮 + 进度显示");
        ui.label("  • 实时预览大图");
    });
}
