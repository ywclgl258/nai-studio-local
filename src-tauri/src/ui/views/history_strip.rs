//! 右侧栏 280px — 仿 PHP v0.8 .gallery-history-strip
//!
//! 内容 (从上到下):
//!   - 头部 (筛选 + 操作)
//!   - 4 tab (全部 / 收藏 / 今日 / 本周) — 仿 .history-tabs
//!   - 2 列 2:3 缩略图网格
//!   - hover 显示 seed/model 浮层 (仿 .v2-meta)

use eframe::egui;

use super::super::http_client::HttpClient;
use super::super::icons;
use super::super::theme;

#[derive(Clone, Copy, PartialEq, Debug)]
enum HistoryTab { All, Favorite, Today, Week }

pub fn show(ui: &mut egui::Ui, _http: &HttpClient) {
    // === 头部 (仿 v0.8 .strip-header) ===
    ui.add_space(theme::tokens::NS_3);
    ui.horizontal(|ui| {
        ui.add_space(theme::tokens::NS_3);
        ui.label(egui::RichText::new(icons::ICON_GALLERY)
            .size(14.0).color(theme::tokens::ACCENT));
        ui.add_space(theme::tokens::NS_2);
        ui.label(theme::h2("历史"));
        ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
            ui.add_space(theme::tokens::NS_3);
            ui.label(theme::micro("🗑"));
            ui.add_space(theme::tokens::NS_2);
            ui.label(theme::micro("📦"));
        });
    });

    ui.add_space(theme::tokens::NS_2);
    ui.add_space(theme::tokens::NS_1);
    ui.separator();
    ui.add_space(theme::tokens::NS_1);

    // === 4 tab (仿 .history-tabs flex 1) ===
    ui.horizontal(|ui| {
        ui.spacing_mut().item_spacing = egui::vec2(theme::tokens::NS_1, 0.0);
        ui.add_space(theme::tokens::NS_2);
        for (tab, label, badge) in [
            (HistoryTab::All, "全部", "12"),
            (HistoryTab::Favorite, "⭐", "0"),
            (HistoryTab::Today, "今日", "0"),
            (HistoryTab::Week, "本周", "5"),
        ] {
            let _ = tab;
            ui.vertical(|ui| {
                let btn = egui::Button::new(
                    egui::RichText::new(label)
                        .size(10.0).color(theme::tokens::TEXT_2)
                )
                .min_size(egui::vec2(50.0, 26.0))
                .fill(egui::Color32::TRANSPARENT);
                ui.add(btn);
                ui.label(theme::micro(badge).color(theme::tokens::TEXT_3));
            });
        }
    });

    ui.add_space(theme::tokens::NS_1);
    ui.add_space(theme::tokens::NS_1);
    ui.separator();
    ui.add_space(theme::tokens::NS_2);

    // === 2 列 2:3 缩略图网格 (仿 .gallery-item aspect-ratio: 2/3) ===
    let mut current_hover: Option<usize> = None;
    egui::ScrollArea::vertical().show(ui, |ui| {
        ui.spacing_mut().item_spacing = egui::vec2(0.0, theme::tokens::NS_2);
        ui.add_space(theme::tokens::NS_1);
        let available = ui.available_width();
        let item_w = (available - theme::tokens::NS_3) / 2.0;
        let item_h = item_w * 1.5;  // 2:3 比例

        egui::Grid::new("history_grid")
            .num_columns(2)
            .spacing([theme::tokens::NS_1, theme::tokens::NS_2])
            .min_col_width(item_w)
            .show(ui, |ui| {
                for i in 0..12 {
                    if i % 2 == 0 && i > 0 { ui.end_row(); }
                    let size = egui::vec2(item_w, item_h);
                    let (rect, resp) = ui.allocate_exact_size(size, egui::Sense::hover());
                    let is_hover = resp.hovered();
                    if is_hover { current_hover = Some(i); }
                    let painter = ui.painter_at(rect);

                    // 缩略图背景
                    painter.rect_filled(rect, 0.0, theme::tokens::BG_ELEVATED);
                    painter.rect_stroke(rect, 0.0, egui::Stroke::new(1.0, theme::tokens::LINE));

                    // 缩略图占位
                    painter.text(
                        rect.center(), egui::Align2::CENTER_CENTER,
                        format!("#{}", i + 1),
                        egui::FontId::proportional(20.0), theme::tokens::TEXT_3,
                    );

                    // hover 显示 meta 浮层 (仿 .v2-meta)
                    if is_hover {
                        let meta_h = 32.0;
                        let meta_rect = egui::Rect::from_min_size(
                            rect.min + egui::vec2(0.0, rect.height() - meta_h),
                            egui::vec2(rect.width(), meta_h),
                        );
                        painter.rect_filled(
                            meta_rect, 0.0,
                            egui::Color32::from_rgba_unmultiplied(10, 12, 20, 217),
                        );
                        painter.text(
                            egui::pos2(meta_rect.min.x + 6.0, meta_rect.min.y + 4.0),
                            egui::Align2::LEFT_TOP,
                            "Seed 1234567",
                            egui::FontId::proportional(10.0), theme::tokens::TEXT,
                        );
                        painter.text(
                            egui::pos2(meta_rect.min.x + 6.0, meta_rect.min.y + 18.0),
                            egui::Align2::LEFT_TOP,
                            "nai-diffusion-4-5",
                            egui::FontId::proportional(8.0), theme::tokens::TEXT_3,
                        );
                    }
                }
            });
    });
}
