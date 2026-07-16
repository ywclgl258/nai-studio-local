//! 左侧栏 280px — 仿 PHP v0.8 .left-panel
//!
//! 内容 (从上到下):
//!   - 4 tab (主提示词 / 角色 / 姿势 / 负面) — 仿 .history-tabs 风格
//!   - 大输入框 (当前 tab)
//!   - 模型参数卡片
//!   - 预设按钮

use eframe::egui;

use super::super::http_client::HttpClient;
use super::super::theme;

#[derive(Clone, Copy, PartialEq, Debug)]
pub enum LeftTab { Prompt, Character, Pose, Negative }

pub fn show(ui: &mut egui::Ui, _http: &HttpClient) {
    let mut active = LeftTab::Prompt;
    let mut prompt = String::new();
    let mut character = String::new();
    let mut pose = String::new();
    let mut negative = String::new();

    // 顶部 padding (仿 v0.8 --ns-4)
    ui.add_space(theme::tokens::NS_4);

    // === 4 tab 切换 (仿 .history-tabs flex 1 风格) ===
    egui::Frame::none()
        .fill(theme::tokens::BG_SOFT)
        .show(ui, |ui| {
            ui.horizontal(|ui| {
                ui.spacing_mut().item_spacing = egui::vec2(theme::tokens::NS_1, 0.0);
                for (tab, label) in [
                    (LeftTab::Prompt, "提示词"),
                    (LeftTab::Character, "角色"),
                    (LeftTab::Pose, "姿势"),
                    (LeftTab::Negative, "负面"),
                ] {
                    let selected = active == tab;
                    let text_color = if selected { theme::tokens::ACCENT } else { theme::tokens::TEXT_3 };
                    let bg = if selected { theme::tokens::accent_soft() } else { egui::Color32::TRANSPARENT };
                    let btn = egui::Button::new(
                        egui::RichText::new(label)
                            .size(11.0).strong().color(text_color)
                    )
                    .min_size(egui::vec2(0.0, 30.0))
                    .fill(bg);
                    if ui.add(btn).clicked() {
                        active = tab;
                    }
                }
            });
        });

    ui.add_space(theme::tokens::NS_3);

    // === 大输入框 (仿 .prompt-workspace 卡片) ===
    let current_text = match active {
        LeftTab::Prompt => &mut prompt,
        LeftTab::Character => &mut character,
        LeftTab::Pose => &mut pose,
        LeftTab::Negative => &mut negative,
    };
    let current_hint = match active {
        LeftTab::Prompt => "masterpiece, 1girl, solo, hatsune_miku, ...",
        LeftTab::Character => "角色描述...",
        LeftTab::Pose => "姿势描述...",
        LeftTab::Negative => "负面提示: lowres, bad anatomy, ...",
    };
    let current_label = match active {
        LeftTab::Prompt => "主提示词",
        LeftTab::Character => "角色",
        LeftTab::Pose => "姿势",
        LeftTab::Negative => "负面提示",
    };

    theme::card_with_title(ui, current_label, |ui| {
        egui::TextEdit::multiline(current_text)
            .hint_text(current_hint)
            .desired_width(f32::INFINITY)
            .min_size(egui::vec2(0.0, 140.0))
            .font(egui::TextStyle::Body)
            .show(ui);

        ui.add_space(theme::tokens::NS_2);
        ui.horizontal(|ui| {
            ui.label(theme::micro(&format!("{} 字符", current_text.chars().count())));
            ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                ui.label(theme::micro("📌 保存为预设"));
            });
        });
    });

    ui.add_space(theme::tokens::NS_3);

    // === 模型参数 (仿 .model-params card) ===
    theme::card_with_title(ui, "模型参数", |ui| {
        let mut model = "nai-diffusion-4-5-curated".to_string();
        let mut sampler = "k_euler_ancestral".to_string();
        let mut steps: i32 = 28;
        let mut scale: f32 = 5.0;
        let mut size = "832x1216".to_string();

        egui::Grid::new("model_grid")
            .num_columns(2)
            .spacing([theme::tokens::NS_3, theme::tokens::NS_2])
            .min_col_width(60.0)
            .show(ui, |ui| {
                ui.label(theme::body2("Model"));
                egui::ComboBox::from_id_source("model_box")
                    .selected_text(model.clone())
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

                ui.label(theme::body2("Sampler"));
                egui::ComboBox::from_id_source("sampler_box")
                    .selected_text(sampler.clone())
                    .show_ui(ui, |ui| {
                        for s in ["k_euler_ancestral", "k_euler", "k_dpmpp_2m", "k_dpmpp_sde"] {
                            ui.selectable_value(&mut sampler, s.to_string(), s);
                        }
                    });
                ui.end_row();

                ui.label(theme::body2("Steps"));
                ui.add(egui::DragValue::new(&mut steps).range(1..=50));
                ui.end_row();

                ui.label(theme::body2("Scale"));
                ui.add(egui::DragValue::new(&mut scale).range(0.0..=10.0).speed(0.1));
                ui.end_row();

                ui.label(theme::body2("尺寸"));
                egui::ComboBox::from_id_source("size_box")
                    .selected_text(size.clone())
                    .show_ui(ui, |ui| {
                        for s in ["832x1216", "1216x832", "1024x1024", "640x640", "1920x1080"] {
                            ui.selectable_value(&mut size, s.to_string(), s);
                        }
                    });
                ui.end_row();
            });
    });

    ui.add_space(theme::tokens::NS_3);

    // === 预设按钮 ===
    theme::card_with_title(ui, "预设", |ui| {
        ui.horizontal_wrapped(|ui| {
            for preset_name in ["空模板", "人像", "风景", "角色卡", "1girl 通用"] {
                let btn = egui::Button::new(
                    egui::RichText::new(preset_name)
                        .size(11.0).color(theme::tokens::TEXT_2)
                )
                .fill(theme::tokens::BG_ELEVATED)
                .stroke(egui::Stroke::new(1.0, theme::tokens::LINE));
                ui.add(btn);
            }
        });
    });
}
