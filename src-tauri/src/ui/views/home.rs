//! 主生图界面 — Phase B 骨架 (全屏版)
//!
//! 布局: 顶部 tab 切换 (提示词 / 角色 / 姿势 / 负面)
//!       主区: 大输入框 (提示词时) / 模型参数面板
//!       底部: 固定 [生成] 按钮

use eframe::egui;

use super::super::http_client::HttpClient;
use super::super::icons;
use super::super::theme;

#[derive(Clone, Copy, PartialEq)]
enum PromptTab { Prompt, Character, Pose, Negative }

pub fn show(ui: &mut egui::Ui, _http: &HttpClient) {
    let mut active_tab = PromptTab::Prompt;
    let mut prompt = String::new();
    let mut character = String::new();
    let mut pose = String::new();
    let mut negative = String::new();
    let mut model = "nai-diffusion-4-5-curated".to_string();
    let mut sampler = "k_euler_ancestral".to_string();
    let mut steps: i32 = 28;
    let mut scale: f32 = 5.0;
    let mut size = "832x1216".to_string();
    let mut uc_preset: i32 = 0;
    let mut quality_toggle = true;

    // === 顶部: 标题 + 4 tab ===
    ui.horizontal(|ui| {
        ui.label(egui::RichText::new(icons::ICON_GENERATE)
            .size(18.0).color(theme::tokens::ACCENT));
        ui.add_space(8.0);
        ui.label(egui::RichText::new("生图")
            .size(18.0).strong().color(theme::tokens::TEXT_PRIMARY));
        ui.add_space(theme::tokens::SPACING_LG);

        for (tab, label) in [
            (PromptTab::Prompt, "提示词"),
            (PromptTab::Character, "角色"),
            (PromptTab::Pose, "姿势"),
            (PromptTab::Negative, "负面"),
        ] {
            let active = active_tab == tab;
            let btn = egui::Button::new(
                egui::RichText::new(label)
                    .size(12.0)
                    .color(if active { theme::tokens::TEXT_PRIMARY } else { theme::tokens::TEXT_MUTED })
            )
            .min_size(egui::vec2(64.0, 28.0))
            .fill(if active { theme::tokens::BG_CARD } else { egui::Color32::TRANSPARENT })
            .stroke(if active {
                egui::Stroke::new(1.0, theme::tokens::ACCENT)
            } else {
                egui::Stroke::new(1.0, theme::tokens::BORDER_SUBTLE)
            });
            if ui.add(btn).clicked() {
                active_tab = tab;
            }
        }

        ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
            ui.label(theme::small("📌 保存为预设"));
            ui.add_space(theme::tokens::SPACING_MD);
            ui.label(theme::small("📂 载入预设"));
        });
    });

    ui.add_space(theme::tokens::SPACING_MD);
    ui.separator();
    ui.add_space(theme::tokens::SPACING_LG);

    // === 主区: 左侧大输入 + 右侧模型参数 (60:40) ===
    egui::SidePanel::right("model_panel")
        .resizable(true)
        .default_width(360.0)
        .min_width(280.0)
        .max_width(500.0)
        .frame(egui::Frame::none()
            .fill(theme::tokens::BG_PANEL)
            .stroke(egui::Stroke::new(1.0, theme::tokens::BORDER_SUBTLE)))
        .show_inside(ui, |ui| {
            ui.add_space(theme::tokens::SPACING_MD);
            model_panel(ui, &mut model, &mut sampler, &mut steps, &mut scale, &mut size,
                &mut uc_preset, &mut quality_toggle);
        });

    // 左侧: 大输入框 (全屏可用高度)
    prompt_panel(ui, &mut prompt, &mut character, &mut pose, &mut negative, active_tab);

    // === 底部: [生成] 按钮 + 进度条 ===
    ui.add_space(theme::tokens::SPACING_LG);
    ui.separator();
    ui.add_space(theme::tokens::SPACING_MD);
    ui.horizontal(|ui| {
        ui.label(theme::small("预估: 5-15s · 832×1216 · 28 steps · k_euler_ancestral"));
        ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
            let btn = egui::Button::new(
                egui::RichText::new("✦  生成")
                    .size(14.0).strong()
                    .color(theme::tokens::TEXT_ON_ACCENT)
            )
            .min_size(egui::vec2(180.0, 36.0))
            .fill(theme::tokens::ACCENT);
            ui.add(btn);
        });
    });
}

fn prompt_panel(
    ui: &mut egui::Ui,
    prompt: &mut String,
    character: &mut String,
    pose: &mut String,
    negative: &mut String,
    active_tab: PromptTab,
) {
    let (text, hint) = match active_tab {
        PromptTab::Prompt => (prompt, "masterpiece, 1girl, solo, hatsune_miku, long_hair, twintails, blue_eyes, ..."),
        PromptTab::Character => (character, "角色描述: 银发, 蓝色眼睛, 115cm, 傲娇..."),
        PromptTab::Pose => (pose, "姿势: 双手叉腰, 侧身, 仰视..."),
        PromptTab::Negative => (negative, "负面提示: lowres, bad anatomy, blurry, ..."),
    };

    ui.vertical(|ui| {
        ui.label(theme::label(match active_tab {
            PromptTab::Prompt => "主提示词",
            PromptTab::Character => "角色描述",
            PromptTab::Pose => "姿势描述",
            PromptTab::Negative => "负面提示",
        }));
        ui.add_space(theme::tokens::SPACING_SM);

        // 大输入框 (全屏)
        egui::ScrollArea::vertical()
            .max_height(ui.available_height() - 80.0)
            .show(ui, |ui| {
                egui::TextEdit::multiline(text)
                    .hint_text(hint)
                    .desired_width(f32::INFINITY)
                    .min_size(egui::vec2(0.0, 280.0))
                    .font(egui::TextStyle::Body)
                    .show(ui);
            });

        ui.add_space(4.0);
        ui.horizontal(|ui| {
            ui.label(theme::small(&format!("{} 字符", text.chars().count())));
            ui.add_space(theme::tokens::SPACING_LG);
            ui.label(theme::small("拼接到主 prompt"));
            ui.add_space(theme::tokens::SPACING_LG);
            ui.label(theme::small("🔢 权重 0.0-1.5"));
        });
    });
}

fn model_panel(
    ui: &mut egui::Ui,
    model: &mut String,
    sampler: &mut String,
    steps: &mut i32,
    scale: &mut f32,
    size: &mut String,
    uc_preset: &mut i32,
    quality_toggle: &mut bool,
) {
    ui.vertical(|ui| {
        ui.label(egui::RichText::new("⚙ 模型参数")
            .size(13.0).strong().color(theme::tokens::TEXT_PRIMARY));
        ui.add_space(theme::tokens::SPACING_MD);

        theme::card(ui, "采样", |ui| {
            egui::Grid::new("sampler_grid")
                .num_columns(2)
                .spacing([theme::tokens::SPACING_MD, theme::tokens::SPACING_XS])
                .show(ui, |ui| {
                    ui.label(theme::label("Model"));
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
                                ui.selectable_value(model, m.to_string(), m);
                            }
                        });
                    ui.end_row();

                    ui.label(theme::label("Sampler"));
                    egui::ComboBox::from_id_source("sampler_box")
                        .selected_text(sampler.clone())
                        .show_ui(ui, |ui| {
                            for s in ["k_euler_ancestral", "k_euler", "k_dpmpp_2m", "k_dpmpp_sde"] {
                                ui.selectable_value(sampler, s.to_string(), s);
                            }
                        });
                    ui.end_row();

                    ui.label(theme::label("Steps"));
                    ui.add(egui::DragValue::new(steps).range(1..=50));
                    ui.end_row();

                    ui.label(theme::label("Scale"));
                    ui.add(egui::DragValue::new(scale).range(0.0..=10.0).speed(0.1));
                    ui.end_row();

                    ui.label(theme::label("尺寸"));
                    egui::ComboBox::from_id_source("size_box")
                        .selected_text(size.clone())
                        .show_ui(ui, |ui| {
                            for s in ["832x1216", "1216x832", "1024x1024", "640x640", "1920x1080"] {
                                ui.selectable_value(size, s.to_string(), s);
                            }
                        });
                    ui.end_row();
                });
        });

        ui.add_space(theme::tokens::SPACING_MD);

        theme::card(ui, "质量", |ui| {
            ui.checkbox(quality_toggle, "启用质量优化 (V4 专属)");
            ui.add_space(theme::tokens::SPACING_XS);
            ui.label(theme::label("UC Preset"));
            egui::ComboBox::from_id_source("uc_box")
                .selected_text(uc_preset.to_string())
                .show_ui(ui, |ui| {
                    for v in [0, 1, 2, 3] {
                        ui.selectable_value(uc_preset, v, v.to_string());
                    }
                });
        });

        ui.add_space(theme::tokens::SPACING_MD);

        theme::card(ui, "变体", |ui| {
            ui.label(theme::small("种子: 随机"));
            ui.add_space(4.0);
            ui.label(theme::small("变体强度: 0.0 (无)"));
        });
    });
}
