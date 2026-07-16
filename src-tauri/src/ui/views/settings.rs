//! 中央: 设置 (双栏卡片化)

use eframe::egui;

use super::super::http_client::HttpClient;
use super::super::theme;
use super::super::icons;

pub fn show(ui: &mut egui::Ui, _http: &HttpClient) {
    ui.horizontal(|ui| {
        ui.label(egui::RichText::new(icons::ICON_SETTINGS)
            .size(16.0).color(theme::tokens::ACCENT));
        ui.add_space(theme::tokens::NS_2);
        ui.label(theme::h2("设置"));
    });

    ui.add_space(theme::tokens::NS_3);

    ui.columns(2, |cols| {
        // 左栏
        cols[0].vertical(|ui| {
            theme::card_with_title(ui, "NAI API Key", |ui| {
                ui.horizontal(|ui| {
                    ui.colored_label(theme::tokens::SUCCESS, "●");
                    ui.add_space(theme::tokens::NS_1);
                    ui.label(theme::body("已配置 1 个 key"));
                });
                ui.add_space(theme::tokens::NS_1);
                ui.label(theme::micro("主号 · ••••a8b2 · 已启用"));
                ui.add_space(theme::tokens::NS_2);
                let btn = egui::Button::new(
                    egui::RichText::new("管理多 Key")
                        .size(11.0).color(theme::tokens::TEXT_2)
                )
                .fill(theme::tokens::BG_ELEVATED)
                .stroke(egui::Stroke::new(1.0, theme::tokens::LINE));
                ui.add(btn);
            });

            ui.add_space(theme::tokens::NS_3);

            theme::card_with_title(ui, "AI 助手", |ui| {
                ui.colored_label(theme::tokens::TEXT_3, "○ 未配置");
                ui.add_space(theme::tokens::NS_1);
                ui.label(theme::micro("用于 prompt 智能补全 + 图像分析"));
                ui.add_space(theme::tokens::NS_2);
                let btn = egui::Button::new(
                    egui::RichText::new("配置 AI 助手")
                        .size(11.0).color(theme::tokens::TEXT_2)
                )
                .fill(theme::tokens::BG_ELEVATED)
                .stroke(egui::Stroke::new(1.0, theme::tokens::LINE));
                ui.add(btn);
            });

            ui.add_space(theme::tokens::NS_3);

            theme::card_with_title(ui, "HTTP 代理", |ui| {
                ui.colored_label(theme::tokens::TEXT_3, "○ 未启用");
                ui.add_space(theme::tokens::NS_1);
                ui.label(theme::micro("国内直连 NAI 易被 WAF 拦截"));
                ui.add_space(theme::tokens::NS_2);
                let btn = egui::Button::new(
                    egui::RichText::new("配置代理")
                        .size(11.0).color(theme::tokens::TEXT_2)
                )
                .fill(theme::tokens::BG_ELEVATED)
                .stroke(egui::Stroke::new(1.0, theme::tokens::LINE));
                ui.add(btn);
            });
        });

        // 右栏
        cols[1].vertical(|ui| {
            theme::card_with_title(ui, "数据", |ui| {
                ui.label(theme::micro("存储位置"));
                ui.add_space(theme::tokens::NS_1);
                ui.label(theme::body2("C:\\Users\\ywclg\\AppData\\Roaming\\nai-studio-desktop"));
                ui.add_space(theme::tokens::NS_2);
                ui.horizontal(|ui| {
                    let btn = egui::Button::new(
                        egui::RichText::new("📁 打开目录")
                            .size(11.0).color(theme::tokens::TEXT_2)
                    )
                    .fill(theme::tokens::BG_ELEVATED)
                    .stroke(egui::Stroke::new(1.0, theme::tokens::LINE));
                    ui.add(btn);
                    ui.add_space(theme::tokens::NS_1);
                    let btn = egui::Button::new(
                        egui::RichText::new("💾 备份")
                            .size(11.0).color(theme::tokens::TEXT_2)
                    )
                    .fill(theme::tokens::BG_ELEVATED)
                    .stroke(egui::Stroke::new(1.0, theme::tokens::LINE));
                    ui.add(btn);
                });
            });

            ui.add_space(theme::tokens::NS_3);

            theme::card_with_title(ui, "外观", |ui| {
                ui.horizontal(|ui| {
                    ui.label(theme::body2("主题"));
                    ui.add_space(theme::tokens::NS_2);
                    egui::ComboBox::from_id_source("theme_box")
                        .selected_text("深色 v0.8")
                        .show_ui(ui, |ui| {
                            ui.selectable_value(&mut "dark".to_string(), "dark".to_string(), "深色 v0.8");
                        });
                });
            });

            ui.add_space(theme::tokens::NS_3);

            theme::card_with_title(ui, "关于", |ui| {
                ui.horizontal(|ui| {
                    ui.label(egui::RichText::new(icons::ICON_LOGO)
                        .size(18.0).color(theme::tokens::ACCENT));
                    ui.add_space(theme::tokens::NS_2);
                    ui.vertical(|ui| {
                        ui.label(theme::body("NAI Studio Desktop"));
                        ui.label(theme::micro("v2.0.0 · egui · 仿 PHP v0.8 · 9.1 MB"));
                    });
                });
            });
        });
    });
}
