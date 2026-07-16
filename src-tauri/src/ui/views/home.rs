//! 中央: 生图界面 (主视图)
//!
//! 仿 PHP v0.8 .form-area
//!   - 顶部: 标题 + 元数据
//!   - 中央: 大预览区 (生成图后显示)
//!   - 底部: 进度条 + 操作 (FAB 风格)

use eframe::egui;

use super::super::http_client::HttpClient;
use super::super::icons;
use super::super::theme;

pub fn show(ui: &mut egui::Ui, _http: &HttpClient) {
    // 顶部: 标题 + 元数据
    ui.vertical(|ui| {
        ui.horizontal(|ui| {
            ui.label(egui::RichText::new(icons::ICON_GENERATE)
                .size(16.0).color(theme::tokens::ACCENT));
            ui.add_space(theme::tokens::NS_2);
            ui.label(theme::h2("主预览"));
            ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                ui.label(theme::micro("Seed: 随机"));
                ui.add_space(theme::tokens::NS_2);
                ui.label(theme::micro("V4.5 · 28 steps"));
            });
        });
    });

    ui.add_space(theme::tokens::NS_3);

    // 中央: 大预览区 (占满中央面板可用高度)
    let available = ui.available_size();
    let preview_h = (available.x * 1.46).min(available.y - 100.0);
    let preview_w = available.x.min(preview_h / 1.46);
    let preview_h = preview_w * 1.46;

    let (rect, _resp) = ui.allocate_exact_size(
        egui::vec2(preview_w, preview_h),
        egui::Sense::hover(),
    );
    let painter = ui.painter_at(rect);
    painter.rect_filled(rect, 0.0, theme::tokens::BG_SOFT);
    painter.rect_stroke(rect, 0.0, egui::Stroke::new(1.0, theme::tokens::LINE));

    // 占位 (v0.8 .form-area 空态)
    painter.text(
        rect.center(), egui::Align2::CENTER_CENTER,
        "✦", egui::FontId::proportional(64.0), theme::tokens::TEXT_3,
    );
    painter.text(
        rect.center() + egui::vec2(0.0, 80.0), egui::Align2::CENTER_CENTER,
        "尚未生成",
        egui::FontId::proportional(13.0), theme::tokens::TEXT_2,
    );
    painter.text(
        rect.center() + egui::vec2(0.0, 100.0), egui::Align2::CENTER_CENTER,
        "在左侧填入 prompt, 点击右上角 ✦ 生成",
        egui::FontId::proportional(11.0), theme::tokens::TEXT_3,
    );

    ui.add_space(theme::tokens::NS_4);

    // 底部: 进度条 + 操作
    ui.horizontal(|ui| {
        ui.vertical(|ui| {
            ui.label(theme::micro("进度"));
            let (_, progress_rect) = ui.allocate_space(egui::vec2(280.0, 6.0));
            ui.painter().rect_filled(progress_rect, 0.0, theme::tokens::BG_ELEVATED);
        });

        ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
            // FAB 风格大按钮 (仿 v0.8 .gallery-main-fab)
            let fab = egui::Button::new(
                egui::RichText::new("✦  生成")
                    .size(13.0).strong()
                    .color(theme::tokens::TEXT_ON_ACCENT)
            )
            .min_size(egui::vec2(120.0, 36.0))
            .fill(theme::tokens::ACCENT);
            ui.add(fab);

            ui.add_space(theme::tokens::NS_2);

            let more = egui::Button::new(
                egui::RichText::new("⋮")
                    .size(18.0).color(theme::tokens::TEXT_2)
            )
            .min_size(egui::vec2(32.0, 36.0))
            .fill(theme::tokens::BG_ELEVATED)
            .stroke(egui::Stroke::new(1.0, theme::tokens::LINE));
            ui.add(more);
        });
    });
}
