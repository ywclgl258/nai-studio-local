//! 中央: 画廊 (全屏, 仿 PHP v0.8 .gallery-area)

use eframe::egui;

use super::super::http_client::HttpClient;
use super::super::theme;
use super::super::icons;

pub fn show(ui: &mut egui::Ui, _http: &HttpClient) {
    ui.horizontal(|ui| {
        ui.label(egui::RichText::new(icons::ICON_GALLERY)
            .size(16.0).color(theme::tokens::ACCENT));
        ui.add_space(theme::tokens::NS_2);
        ui.label(theme::h2("画廊"));
        ui.add_space(theme::tokens::NS_4);
        ui.label(theme::micro("共 12 个作品"));
        ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
            let btn = egui::Button::new(
                egui::RichText::new("📦 打包 ZIP")
                    .size(11.0).color(theme::tokens::TEXT_2)
            )
            .fill(theme::tokens::BG_ELEVATED)
            .stroke(egui::Stroke::new(1.0, theme::tokens::LINE));
            ui.add(btn);
        });
    });

    ui.add_space(theme::tokens::NS_3);

    egui::ScrollArea::vertical().show(ui, |ui| {
        let available = ui.available_width();
        let item_w = 180.0;
        let spacing = theme::tokens::NS_3;
        let cols = ((available + spacing) / (item_w + spacing)).floor() as usize;
        let cols = cols.max(2);

        egui::Grid::new("gallery_grid")
            .num_columns(cols)
            .spacing([spacing, spacing])
            .show(ui, |ui| {
                for i in 0..12 {
                    if i % cols == 0 && i > 0 { ui.end_row(); }
                    thumbnail_card(ui, i);
                }
            });
    });
}

fn thumbnail_card(ui: &mut egui::Ui, idx: usize) {
    let size = egui::vec2(180.0, 270.0);
    let (rect, _resp) = ui.allocate_exact_size(size, egui::Sense::hover());
    let painter = ui.painter_at(rect);
    painter.rect_filled(rect, 0.0, theme::tokens::BG_SOFT);
    painter.rect_stroke(rect, 0.0, egui::Stroke::new(1.0, theme::tokens::LINE));

    let img_rect = egui::Rect::from_min_size(
        rect.min + egui::vec2(8.0, 8.0),
        egui::vec2(rect.width() - 16.0, rect.height() - 64.0),
    );
    painter.rect_filled(img_rect, 0.0, theme::tokens::BG_ELEVATED);
    painter.text(
        img_rect.center(), egui::Align2::CENTER_CENTER,
        format!("#{}", idx + 1),
        egui::FontId::proportional(20.0), theme::tokens::TEXT_3,
    );

    painter.text(
        egui::pos2(rect.min.x + 10.0, rect.max.y - 50.0), egui::Align2::LEFT_TOP,
        format!("作品 {}", idx + 1),
        egui::FontId::proportional(12.0), theme::tokens::TEXT,
    );
    painter.text(
        egui::pos2(rect.min.x + 10.0, rect.max.y - 32.0), egui::Align2::LEFT_TOP,
        "832×1216 · 28 steps",
        egui::FontId::proportional(10.0), theme::tokens::TEXT_3,
    );
}
