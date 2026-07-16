//! 画廊 — 卡片网格, 简化版

use eframe::egui;

use super::super::http_client::HttpClient;
use super::super::theme;

pub fn show(ui: &mut egui::Ui, _http: &HttpClient) {
    ui.vertical(|ui| {
        // 筛选条
        theme::card(ui, "筛选", |ui| {
            ui.horizontal(|ui| {
                ui.label(theme::small("⭐ 收藏"));
                ui.label(theme::small("🕐 最近"));
                ui.label(theme::small("📁 全部"));
            });
        });

        ui.add_space(theme::tokens::SPACING_LG);

        // 网格占位
        egui::ScrollArea::vertical().show(ui, |ui| {
            let available = ui.available_width();
            let card_w = 200.0;
            let spacing = theme::tokens::SPACING_MD;
            let cols = ((available + spacing) / (card_w + spacing)).floor() as usize;
            let cols = cols.max(2);

            egui::Grid::new("gallery_grid")
                .num_columns(cols)
                .spacing([spacing, spacing])
                .show(ui, |ui| {
                    for i in 0..12 {
                        if i % cols == 0 && i > 0 {
                            ui.end_row();
                        }
                        thumbnail_card(ui, i);
                    }
                });
        });
    });
}

fn thumbnail_card(ui: &mut egui::Ui, idx: usize) {
    let size = egui::vec2(200.0, 280.0);
    let (rect, _resp) = ui.allocate_exact_size(size, egui::Sense::hover());
    let painter = ui.painter_at(rect);

    // 背景
    painter.rect_filled(rect, 0.0, theme::tokens::BG_CARD);
    painter.rect_stroke(rect, 0.0, egui::Stroke::new(1.0, theme::tokens::BORDER_SUBTLE));

    // 缩略图占位
    let img_rect = egui::Rect::from_min_size(
        rect.min + egui::vec2(8.0, 8.0),
        egui::vec2(rect.width() - 16.0, rect.height() - 80.0),
    );
    painter.rect_filled(img_rect, 0.0, theme::tokens::BG_RAISED);
    painter.text(
        img_rect.center(), egui::Align2::CENTER_CENTER,
        format!("#{}", idx + 1),
        egui::FontId::proportional(24.0), theme::tokens::TEXT_MUTED,
    );

    let info_y = rect.max.y - 60.0;
    painter.text(
        egui::pos2(rect.min.x + 12.0, info_y), egui::Align2::LEFT_TOP,
        format!("作品 {}", idx + 1),
        egui::FontId::proportional(12.0), theme::tokens::TEXT_PRIMARY,
    );
    painter.text(
        egui::pos2(rect.min.x + 12.0, info_y + 18.0), egui::Align2::LEFT_TOP,
        "832×1216 · 28 steps",
        egui::FontId::proportional(10.0), theme::tokens::TEXT_MUTED,
    );
}
