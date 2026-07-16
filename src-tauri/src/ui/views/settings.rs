//! 设置 — 全屏列表 (双栏卡片化)

use eframe::egui;

use super::super::http_client::HttpClient;
use super::super::theme;
use super::super::icons;

pub fn show(ui: &mut egui::Ui, _http: &HttpClient) {
    // 顶部标题
    ui.horizontal(|ui| {
        ui.label(egui::RichText::new(icons::ICON_SETTINGS)
            .size(18.0).color(theme::tokens::ACCENT));
        ui.add_space(8.0);
        ui.label(egui::RichText::new("设置")
            .size(18.0).strong().color(theme::tokens::TEXT_PRIMARY));
    });

    ui.add_space(theme::tokens::SPACING_MD);
    ui.separator();
    ui.add_space(theme::tokens::SPACING_LG);

    // 主区: 两栏卡片
    ui.columns(2, |cols| {
        // 左栏: NAI / AI / 代理
        cols[0].vertical(|ui| {
            // NAI API Key
            theme::card(ui, "NAI API Key", |ui| {
                ui.horizontal(|ui| {
                    ui.colored_label(theme::tokens::SUCCESS, "●");
                    ui.add_space(4.0);
                    ui.label(theme::label("已配置 1 个 key"));
                });
                ui.add_space(4.0);
                ui.label(theme::small("主号 · ••••a8b2 · 已启用"));
                ui.add_space(theme::tokens::SPACING_SM);
                let btn = egui::Button::new(
                    egui::RichText::new("管理多 Key")
                        .size(11.0).color(theme::tokens::TEXT_PRIMARY)
                )
                .fill(theme::tokens::BG_RAISED)
                .stroke(egui::Stroke::new(1.0, theme::tokens::BORDER_SUBTLE));
                ui.add(btn);
            });

            ui.add_space(theme::tokens::SPACING_MD);

            theme::card(ui, "AI 助手 (DeepSeek / OpenAI / Ollama)", |ui| {
                ui.colored_label(theme::tokens::TEXT_MUTED, "○ 未配置");
                ui.add_space(theme::tokens::SPACING_SM);
                ui.label(theme::small("AI 助手用于 prompt 智能补全 + 图像分析"));
                ui.add_space(theme::tokens::SPACING_SM);
                let btn = egui::Button::new(
                    egui::RichText::new("配置 AI 助手")
                        .size(11.0).color(theme::tokens::TEXT_PRIMARY)
                )
                .fill(theme::tokens::BG_RAISED)
                .stroke(egui::Stroke::new(1.0, theme::tokens::BORDER_SUBTLE));
                ui.add(btn);
            });

            ui.add_space(theme::tokens::SPACING_MD);

            theme::card(ui, "HTTP 代理 (Clash / v2rayN)", |ui| {
                ui.colored_label(theme::tokens::TEXT_MUTED, "○ 未启用");
                ui.add_space(theme::tokens::SPACING_SM);
                ui.label(theme::small("国内直连 NAI 易被 Cloudflare WAF 拦截"));
                ui.add_space(theme::tokens::SPACING_SM);
                let btn = egui::Button::new(
                    egui::RichText::new("配置代理")
                        .size(11.0).color(theme::tokens::TEXT_PRIMARY)
                )
                .fill(theme::tokens::BG_RAISED)
                .stroke(egui::Stroke::new(1.0, theme::tokens::BORDER_SUBTLE));
                ui.add(btn);
            });
        });

        // 右栏: 数据 / 主题 / 关于
        cols[1].vertical(|ui| {
            theme::card(ui, "数据", |ui| {
                ui.label(theme::label("存储位置"));
                ui.add_space(4.0);
                ui.label(theme::small("C:\\Users\\ywclg\\AppData\\Roaming\\nai-studio-desktop"));
                ui.add_space(theme::tokens::SPACING_MD);

                ui.horizontal(|ui| {
                    let open_btn = egui::Button::new(
                        egui::RichText::new("📁 打开数据目录")
                            .size(11.0).color(theme::tokens::TEXT_PRIMARY)
                    )
                    .fill(theme::tokens::BG_RAISED)
                    .stroke(egui::Stroke::new(1.0, theme::tokens::BORDER_SUBTLE));
                    ui.add(open_btn);
                    ui.add_space(theme::tokens::SPACING_SM);
                    let backup_btn = egui::Button::new(
                        egui::RichText::new("💾 备份")
                            .size(11.0).color(theme::tokens::TEXT_PRIMARY)
                    )
                    .fill(theme::tokens::BG_RAISED)
                    .stroke(egui::Stroke::new(1.0, theme::tokens::BORDER_SUBTLE));
                    ui.add(backup_btn);
                });
            });

            ui.add_space(theme::tokens::SPACING_MD);

            theme::card(ui, "外观", |ui| {
                ui.horizontal(|ui| {
                    ui.label(theme::label("主题:"));
                    egui::ComboBox::from_id_source("theme_box")
                        .selected_text("深色 (Linear 风)")
                        .show_ui(ui, |ui| {
                            ui.selectable_value(&mut "dark".to_string(), "dark".to_string(), "深色");
                            ui.selectable_value(&mut "light".to_string(), "light".to_string(), "浅色");
                        });
                });
                ui.add_space(theme::tokens::SPACING_SM);
                ui.label(theme::small("Phase C 实装主题切换"));
            });

            ui.add_space(theme::tokens::SPACING_MD);

            theme::card(ui, "关于", |ui| {
                ui.horizontal(|ui| {
                    ui.label(egui::RichText::new(icons::ICON_LOGO)
                        .size(20.0).color(theme::tokens::ACCENT));
                    ui.add_space(theme::tokens::SPACING_SM);
                    ui.vertical(|ui| {
                        ui.label(egui::RichText::new("NAI Studio Desktop")
                            .size(13.0).strong().color(theme::tokens::TEXT_PRIMARY));
                        ui.label(theme::small("v2.0.0 · egui · 0 WebView · 9.1 MB"));
                    });
                });
                ui.add_space(theme::tokens::SPACING_SM);
                ui.label(theme::small("Tauri → egui 全量重构, 后端 API 零修改"));
            });
        });
    });
}
