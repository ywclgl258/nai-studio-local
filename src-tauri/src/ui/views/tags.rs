//! 标签 / 画师 — 全屏布局

use eframe::egui;

use super::super::http_client::HttpClient;
use super::super::theme;
use super::super::icons;

pub fn show(ui: &mut egui::Ui, _http: &HttpClient) {
    let mut search = String::new();

    // 顶部: 标题 + 搜索
    ui.horizontal(|ui| {
        ui.label(egui::RichText::new(icons::ICON_TAGS)
            .size(18.0).color(theme::tokens::ACCENT));
        ui.add_space(8.0);
        ui.label(egui::RichText::new("标签 / 画师")
            .size(18.0).strong().color(theme::tokens::TEXT_PRIMARY));

        ui.add_space(theme::tokens::SPACING_LG);

        // 搜索框
        ui.label(egui::RichText::new("⌕")
            .size(14.0).color(theme::tokens::TEXT_MUTED));
        ui.add_space(4.0);
        egui::TextEdit::singleline(&mut search)
            .hint_text("搜索标签 / 画师 (中英文 / 拼音)")
            .desired_width(360.0)
            .font(egui::TextStyle::Body)
            .show(ui);
    });

    ui.add_space(theme::tokens::SPACING_MD);
    ui.separator();
    ui.add_space(theme::tokens::SPACING_LG);

    // 主区: 左侧分类 + 右侧结果
    egui::SidePanel::left("tag_categories")
        .resizable(false)
        .exact_width(160.0)
        .frame(egui::Frame::none()
            .fill(theme::tokens::BG_PANEL)
            .stroke(egui::Stroke::new(1.0, theme::tokens::BORDER_SUBTLE)))
        .show_inside(ui, |ui| {
            ui.add_space(theme::tokens::SPACING_MD);
            for (cat, icon, _active) in [
                ("全部", "⊞", true),
                ("通用", "#", false),
                ("画师", "@", false),
                ("角色", "★", false),
                ("版权", "©", false),
                ("元数据", "⌬", false),
            ] {
                let btn = egui::Button::new(
                    egui::RichText::new(format!("  {}  {}", icon, cat))
                        .size(12.0)
                        .color(if _active { theme::tokens::TEXT_PRIMARY } else { theme::tokens::TEXT_SECONDARY })
                )
                .min_size(egui::vec2(140.0, 28.0))
                .fill(if _active { theme::tokens::BG_CARD } else { egui::Color32::TRANSPARENT })
                .stroke(if _active {
                    egui::Stroke::new(1.0, theme::tokens::ACCENT)
                } else {
                    egui::Stroke::NONE
                });
                ui.add(btn);
            }
        });

    // 右侧: 标签网格占位
    egui::ScrollArea::vertical().show(ui, |ui| {
        let available = ui.available_width();
        let item_w = 130.0;
        let spacing = theme::tokens::SPACING_SM;
        let cols = ((available + spacing) / (item_w + spacing)).floor() as usize;
        let cols = cols.max(2);

        egui::Grid::new("tag_grid")
            .num_columns(cols)
            .spacing([spacing, spacing])
            .show(ui, |ui| {
                for i in 0..24 {
                    if i % cols == 0 && i > 0 { ui.end_row(); }
                    tag_chip(ui, &format!("标签 {}", i + 1), "中文翻译");
                }
            });
    });
}

fn tag_chip(ui: &mut egui::Ui, en: &str, cn: &str) {
    let size = egui::vec2(130.0, 56.0);
    let (rect, _resp) = ui.allocate_exact_size(size, egui::Sense::hover());
    let painter = ui.painter_at(rect);
    painter.rect_filled(rect, 0.0, theme::tokens::BG_CARD);
    painter.rect_stroke(rect, 0.0, egui::Stroke::new(1.0, theme::tokens::BORDER_SUBTLE));
    painter.text(
        egui::pos2(rect.min.x + 10.0, rect.min.y + 10.0), egui::Align2::LEFT_TOP,
        en, egui::FontId::proportional(12.0), theme::tokens::TEXT_PRIMARY,
    );
    painter.text(
        egui::pos2(rect.min.x + 10.0, rect.min.y + 30.0), egui::Align2::LEFT_TOP,
        cn, egui::FontId::proportional(10.0), theme::tokens::TEXT_MUTED,
    );
}
