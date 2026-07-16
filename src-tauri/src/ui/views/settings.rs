//! 设置界面(占位, Phase C 实现)

use eframe::egui;

pub fn show(ui: &mut egui::Ui) {
    ui.vertical(|ui| {
        ui.heading("⚙️ 设置");
        ui.add_space(20.0);
        ui.label("Phase A 占位: 设置界面, Phase C 实现");
        ui.add_space(10.0);
        ui.label("将包含:");
        ui.label("  • NAI API Key 管理 (多 key 轮换)");
        ui.label("  • AI 助手 (DeepSeek / OpenAI / SiliconFlow / Ollama)");
        ui.label("  • HTTP 代理 (Clash / v2rayN)");
        ui.label("  • 主题切换");
        ui.label("  • 数据目录 / 备份");
    });
}
