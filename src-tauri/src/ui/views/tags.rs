//! 中央: 标签库 (仿 PHP v0.8)

use eframe::egui;

use super::super::http_client::HttpClient;
use super::super::theme;
use super::super::icons;

pub fn show(ui: &mut egui::Ui, _http: &HttpClient) {
    let mut search = String::new();
    let mut category = "全部".to_string();

    ui.horizontal(|ui| {
        ui.label(egui::RichText::new(icons::ICON_TAGS)
            .size(16.0).color(theme::tokens::ACCENT));
        ui.add_space(theme::tokens::NS_2);
        ui.label(theme::h2("标签库"));
        ui.add_space(theme::tokens::NS_4);
        ui.label(egui::RichText::new("⌕")
            .size(13.0).color(theme::tokens::TEXT_3));
        ui.add_space(theme::tokens::NS_1);
        egui::TextEdit::singleline(&mut search)
            .hint_text("搜索标签 / 画师 (中英文)")
            .desired_width(280.0)
            .font(egui::TextStyle::Body)
            .show(ui);
    });

    ui.add_space(theme::tokens::NS_3);

    // 分类 chip
    ui.horizontal_wrapped(|ui| {
        ui.spacing_mut().item_spacing = egui::vec2(theme::tokens::NS_1, theme::tokens::NS_1);
        for cat in ["全部", "通用", "画师", "角色", "版权", "元数据"] {
            let active = category == cat;
            let text_color = if active { theme::tokens::ACCENT } else { theme::tokens::TEXT_2 };
            let bg = if active { theme::tokens::accent_soft() } else { theme::tokens::BG_ELEVATED };
            let btn = egui::Button::new(
                egui::RichText::new(cat).size(11.0).color(text_color)
            )
            .min_size(egui::vec2(48.0, 24.0))
            .fill(bg)
            .stroke(egui::Stroke::new(1.0, if active { theme::tokens::LINE_ACCENT } else { theme::tokens::LINE }));
            if ui.add(btn).clicked() { category = cat.to_string(); }
        }
    });

    ui.add_space(theme::tokens::NS_3);

    // 结果区
    theme::card_with_title(ui, "本地标签 (示例)", |ui| {
        ui.label(theme::small("Phase C 实装: 加载本地 tags + danbooru_tag_cache, 中文翻译"));
        ui.add_space(theme::tokens::NS_2);
        egui::ScrollArea::vertical()
            .max_height(300.0)
            .show(ui, |ui| {
                ui.horizontal_wrapped(|ui| {
                    for (en, cn) in [
                        ("1girl", "1个女孩"),
                        ("long_hair", "长发"),
                        ("blue_eyes", "蓝眼"),
                        ("smile", "微笑"),
                        ("school_uniform", "校服"),
                    ] {
                        let chip = egui::Button::new(
                            egui::RichText::new(format!("{} · {}", en, cn))
                                .size(10.0).color(theme::tokens::TEXT_2)
                        )
                        .min_size(egui::vec2(80.0, 24.0))
                        .fill(theme::tokens::BG_ELEVATED)
                        .stroke(egui::Stroke::new(1.0, theme::tokens::LINE));
                        ui.add(chip);
                    }
                });
            });
    });
}
