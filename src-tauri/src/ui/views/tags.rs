//! 标签 / 画师 — 简化版

use eframe::egui;

use super::super::http_client::HttpClient;
use super::super::theme;

pub fn show(ui: &mut egui::Ui, _http: &HttpClient) {
    ui.vertical(|ui| {
        let mut search = String::new();

        // 搜索栏
        theme::card(ui, "搜索", |ui| {
            ui.horizontal(|ui| {
                ui.label(theme::small("🔍"));
                ui.add_space(4.0);
                egui::TextEdit::singleline(&mut search)
                    .hint_text("搜索标签或画师 (英文 / 中文)")
                    .desired_width(f32::INFINITY)
                    .font(egui::TextStyle::Body)
                    .show(ui);
            });
        });

        ui.add_space(theme::tokens::SPACING_LG);

        // 分类
        theme::card(ui, "分类", |ui| {
            ui.horizontal_wrapped(|ui| {
                for cat in ["全部", "通用", "画师", "角色", "版权", "元数据"] {
                    let btn = egui::Button::new(
                        egui::RichText::new(cat)
                            .size(12.0)
                            .color(theme::tokens::TEXT_SECONDARY)
                    )
                    .fill(theme::tokens::BG_RAISED)
                    .stroke(egui::Stroke::new(1.0, theme::tokens::BORDER_SUBTLE));
                    ui.add(btn);
                }
            });
        });

        ui.add_space(theme::tokens::SPACING_LG);

        // 结果区
        theme::card(ui, "本地标签", |ui| {
            ui.label(theme::small("Phase C 实装: 加载本地 tags + danbooru_tag_cache, 中文翻译, 分类"));
        });
    });
}
