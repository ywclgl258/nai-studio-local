//! 主生图界面 — Phase B 完整版骨架
//!
//! 简化版: 左侧参数 + 右侧预览, egui 基础 API

use eframe::egui;

use super::super::http_client::HttpClient;
use super::super::theme;

pub fn show(ui: &mut egui::Ui, _http: &HttpClient) {
    // 右侧预览 (resizable, 400px 默认)
    egui::SidePanel::right("generate_preview")
        .resizable(true)
        .default_width(400.0)
        .min_width(300.0)
        .frame(egui::Frame::none()
            .fill(theme::tokens::BG_PANEL)
            .stroke(egui::Stroke::new(1.0, theme::tokens::BORDER_SUBTLE)))
        .show_inside(ui, |ui| {
            ui.add_space(theme::tokens::SPACING_LG);
            preview_panel(ui);
        });

    // 左侧: 参数面板
    params_panel(ui, _http);
}

fn params_panel(ui: &mut egui::Ui, _http: &HttpClient) {
    let mut prompt = String::new();
    let mut model = "nai-diffusion-4-5-curated".to_string();
    let mut sampler = "k_euler_ancestral".to_string();
    let mut steps: i32 = 28;
    let mut scale: f32 = 5.0;
    let mut size = "832x1216".to_string();

    // 4 标签切换
    ui.horizontal(|ui| {
        for (label, _active) in [("提示词", true), ("角色", false), ("姿势", false), ("负面", false)] {
            let btn = egui::Button::new(
                egui::RichText::new(label)
                    .size(12.0)
                    .color(if _active { theme::tokens::TEXT_PRIMARY } else { theme::tokens::TEXT_MUTED })
            )
            .min_size(egui::vec2(60.0, 28.0))
            .fill(if _active { theme::tokens::BG_CARD } else { egui::Color32::TRANSPARENT })
            .stroke(if _active {
                egui::Stroke::new(1.0, theme::tokens::ACCENT)
            } else {
                egui::Stroke::new(1.0, theme::tokens::BORDER_SUBTLE)
            });
            ui.add(btn);
        }
    });

    ui.add_space(theme::tokens::SPACING_MD);

    // Prompt 卡片
    theme::card(ui, "提示词", |ui| {
        egui::ScrollArea::vertical()
            .max_height(120.0)
            .show(ui, |ui| {
                egui::TextEdit::multiline(&mut prompt)
                    .hint_text("masterpiece, 1girl, solo, hatsune_miku, long_hair, ...")
                    .desired_width(f32::INFINITY)
                    .min_size(egui::vec2(0.0, 80.0))
                    .font(egui::TextStyle::Body)
                    .show(ui);
            });
        ui.add_space(4.0);
        ui.horizontal(|ui| {
            ui.label(theme::small("📌 保存为预设"));
            ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                ui.label(theme::small(&format!("{} 字符", prompt.chars().count())));
            });
        });
    });

    ui.add_space(theme::tokens::SPACING_MD);

    // 模型参数卡片
    theme::card(ui, "模型参数", |ui| {
        egui::Grid::new("model_grid")
            .num_columns(2)
            .spacing([theme::tokens::SPACING_LG, theme::tokens::SPACING_SM])
            .min_col_width(80.0)
            .show(ui, |ui| {
                ui.label(theme::label("Model"));
                egui::ComboBox::from_label("")
                    .selected_text(&model)
                    .show_ui(ui, |ui| {
                        for m in [
                            "nai-diffusion-4-5-curated",
                            "nai-diffusion-4-5-full",
                            "nai-diffusion-4-curated",
                            "nai-diffusion-4-full",
                            "nai-diffusion-3",
                        ] {
                            ui.selectable_value(&mut model, m.to_string(), m);
                        }
                    });
                ui.end_row();

                ui.label(theme::label("Sampler"));
                egui::ComboBox::from_label("")
                    .selected_text(&sampler)
                    .show_ui(ui, |ui| {
                        for s in ["k_euler_ancestral", "k_euler", "k_dpmpp_2m", "k_dpmpp_sde"] {
                            ui.selectable_value(&mut sampler, s.to_string(), s);
                        }
                    });
                ui.end_row();

                ui.label(theme::label("Steps"));
                ui.add(egui::DragValue::new(&mut steps).range(1..=50));
                ui.end_row();

                ui.label(theme::label("Scale"));
                ui.add(egui::DragValue::new(&mut scale).range(0.0..=10.0).speed(0.1));
                ui.end_row();

                ui.label(theme::label("尺寸"));
                egui::ComboBox::from_label("")
                    .selected_text(&size)
                    .show_ui(ui, |ui| {
                        for s in ["832x1216", "1216x832", "1024x1024", "640x640", "1920x1080"] {
                            ui.selectable_value(&mut size, s.to_string(), s);
                        }
                    });
                ui.end_row();
            });
    });

    ui.add_space(theme::tokens::SPACING_LG);

    // 生成按钮
    let gen_btn = egui::Button::new(
        egui::RichText::new("✦ 生成")
            .size(14.0).strong()
            .color(theme::tokens::TEXT_ON_ACCENT)
    )
    .min_size(egui::vec2(f32::INFINITY, 44.0))
    .fill(theme::tokens::ACCENT);
    ui.add(gen_btn);
}

fn preview_panel(ui: &mut egui::Ui) {
    ui.vertical(|ui| {
        ui.label(theme::subtitle("预览"));
        ui.add_space(theme::tokens::SPACING_SM);

        // 预览占位
        let available_w = ui.available_width();
        let h = available_w * 1.46;
        let (rect, _resp) = ui.allocate_exact_size(
            egui::vec2(available_w, h),
            egui::Sense::hover(),
        );
        ui.painter().rect_filled(
            rect, 0.0, theme::tokens::BG_CARD,
        );
        ui.painter().rect_stroke(
            rect, 0.0,
            egui::Stroke::new(1.0, theme::tokens::BORDER_SUBTLE),
        );
        let center = rect.center();
        ui.painter().text(
            center, egui::Align2::CENTER_CENTER,
            "✦", egui::FontId::proportional(48.0), theme::tokens::TEXT_MUTED,
        );
        ui.painter().text(
            center + egui::vec2(0.0, 60.0), egui::Align2::CENTER_CENTER,
            "尚未生成", egui::FontId::proportional(13.0), theme::tokens::TEXT_MUTED,
        );

        ui.add_space(theme::tokens::SPACING_LG);

        ui.label(theme::small("进度"));
        let (_, progress_rect) = ui.allocate_space(egui::vec2(ui.available_width(), 6.0));
        ui.painter().rect_filled(progress_rect, 0.0, theme::tokens::BG_CARD);

        ui.add_space(theme::tokens::SPACING_LG);

        theme::card(ui, "上次出图", |ui| {
            ui.label(theme::small("尚无历史"));
        });
    });
}
