//! 设置 — 分组卡片化

use eframe::egui;

use super::super::http_client::HttpClient;
use super::super::theme;

pub fn show(ui: &mut egui::Ui, _http: &HttpClient) {
    ui.vertical(|ui| {
        // NAI API Key
        theme::card(ui, "NAI API Key", |ui| {
            ui.horizontal(|ui| {
                ui.colored_label(theme::tokens::SUCCESS, "●");
                ui.add_space(4.0);
                ui.label(theme::label("已配置 1 个 key"));
                ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                    let btn = egui::Button::new(
                        egui::RichText::new("管理")
                            .size(12.0).color(theme::tokens::TEXT_PRIMARY)
                    )
                    .fill(theme::tokens::BG_RAISED);
                    ui.add(btn);
                });
            });
        });

        ui.add_space(theme::tokens::SPACING_LG);

        theme::card(ui, "AI 助手", |ui| {
            ui.horizontal(|ui| {
                ui.colored_label(theme::tokens::TEXT_MUTED, "○");
                ui.add_space(4.0);
                ui.label(theme::label("未配置"));
                ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                    let btn = egui::Button::new(
                        egui::RichText::new("设置")
                            .size(12.0).color(theme::tokens::TEXT_PRIMARY)
                    )
                    .fill(theme::tokens::BG_RAISED);
                    ui.add(btn);
                });
            });
        });

        ui.add_space(theme::tokens::SPACING_LG);

        theme::card(ui, "HTTP 代理", |ui| {
            ui.horizontal(|ui| {
                ui.colored_label(theme::tokens::TEXT_MUTED, "○");
                ui.add_space(4.0);
                ui.label(theme::label("未启用"));
                ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                    let btn = egui::Button::new(
                        egui::RichText::new("配置")
                            .size(12.0).color(theme::tokens::TEXT_PRIMARY)
                    )
                    .fill(theme::tokens::BG_RAISED);
                    ui.add(btn);
                });
            });
        });

        ui.add_space(theme::tokens::SPACING_LG);

        theme::card(ui, "数据", |ui| {
            ui.horizontal(|ui| {
                ui.label(theme::label("位置:"));
                ui.label(theme::small("C:\\Users\\ywclg\\AppData\\Roaming\\nai-studio-desktop"));
            });
            ui.add_space(theme::tokens::SPACING_SM);
            ui.horizontal(|ui| {
                let open_btn = egui::Button::new(
                    egui::RichText::new("打开数据目录")
                        .size(12.0).color(theme::tokens::TEXT_PRIMARY)
                )
                .fill(theme::tokens::BG_RAISED);
                ui.add(open_btn);

                let backup_btn = egui::Button::new(
                    egui::RichText::new("备份")
                        .size(12.0).color(theme::tokens::TEXT_PRIMARY)
                )
                .fill(theme::tokens::BG_RAISED);
                ui.add(backup_btn);
            });
        });

        ui.add_space(theme::tokens::SPACING_LG);

        theme::card(ui, "关于", |ui| {
            ui.label(theme::small("NAI Studio Desktop v2.0.0 · egui · 0 WebView · 8.9MB"));
            ui.add_space(4.0);
            ui.label(theme::small("Phase A.5 重新设计版 · 后续 Phase B/C/D 实装"));
        });
    });
}
