//! 画廊 — 全屏网格

use eframe::egui;

use super::super::http_client::HttpClient;
use super::super::theme;
use super::super::icons;

pub fn show(ui: &mut egui::Ui, _http: &HttpClient) {
    // 顶部工具栏
    ui.horizontal(|ui| {
        ui.label(egui::RichText::new(icons::ICON_GALLERY)
            .size(18.0).color(theme::tokens::ACCENT));
        ui.add_space(8.0);
        ui.label(egui::RichText::new("画廊")
            .size(18.0).strong().color(theme::tokens::TEXT_PRIMARY));

        ui.add_space(theme::tokens::SPACING_LG);

        // 筛选
        for (label, _active) in [("全部", true), ("⭐ 收藏", false), ("今日", false), ("本周", false), ("本月", false)] {
            let btn = egui::Button::new(
                egui::RichText::new(label)
                    .size(11.0)
                    .color(theme::tokens::TEXT_MUTED)
            )
            .min_size(egui::vec2(56.0, 24.0))
            .fill(theme::tokens::BG_CARD)
            .stroke(egui::Stroke::new(1.0, theme::tokens::BORDER_SUBTLE));
            ui.add(btn);
        }

        ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
            let mut search = String::new();
            egui::TextEdit::singleline(&mut search)
                .hint_text("搜索 prompt / 种子...")
                .desired_width(220.0)
                .font(egui::TextStyle::Small)
                .show(ui);
            ui.add_space(theme::tokens::SPACING_MD);
            let btn = egui::Button::new(
                egui::RichText::new("📦 打包 ZIP")
                    .size(11.0).color(theme::tokens::TEXT_SECONDARY)
            )
            .fill(theme::tokens::BG_RAISED);
            ui.add(btn);
        });
    });

    ui.add_space(theme::tokens::SPACING_MD);
    ui.separator();
    ui.add_space(theme::tokens::SPACING_LG);

    // 全屏网格
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
}

fn thumbnail_card(ui: &mut egui::Ui, idx: usize) {
    let size = egui::vec2(200.0, 280.0);
    let (rect, _resp) = ui.allocate_exact_size(size, egui::Sense::hover());
    let painter = ui.painter_at(rect);

    painter.rect_filled(rect, 0.0, theme::tokens::BG_CARD);
    painter.rect_stroke(rect, 0.0, egui::Stroke::new(1.0, theme::tokens::BORDER_SUBTLE));

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
