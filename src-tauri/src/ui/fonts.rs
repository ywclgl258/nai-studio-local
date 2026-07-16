//! 中文字体注册
//!
//! Windows 上找系统字体, 注册到 egui, 避免中文乱码

use std::fs;
use std::path::PathBuf;

use eframe::egui;

const CJK_FONT_CANDIDATES: &[&str] = &[
    "C:\\Windows\\Fonts\\msyh.ttc",     // Microsoft YaHei (Win7+)
    "C:\\Windows\\Fonts\\msyh.ttf",
    "C:\\Windows\\Fonts\\msyhbd.ttc",
    "C:\\Windows\\Fonts\\simhei.ttf",   // SimHei
    "C:\\Windows\\Fonts\\simsun.ttc",   // SimSun
    "C:\\Windows\\Fonts\\simfang.ttf",  // SimFang
    "C:\\Windows\\Fonts\\Deng.ttf",     // 等线 (Win10)
    "/System/Library/Fonts/PingFang.ttc",  // macOS
    "/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc",  // Linux
    "/usr/share/fonts/truetype/wqy/wqy-microhei.ttc",          // Linux
];

/// 安装中文字体到 egui
pub fn install_chinese_fonts(ctx: &egui::Context) {
    let mut fonts = egui::FontDefinitions::default();

    let mut installed = false;
    for path_str in CJK_FONT_CANDIDATES {
        let path = PathBuf::from(path_str);
        if !path.is_file() {
            continue;
        }
        let bytes = match fs::read(&path) {
            Ok(b) => b,
            Err(e) => {
                log::warn!("[fonts] failed to read {:?}: {}", path, e);
                continue;
            }
        };
        let font_name = format!("cjk_{}", path.file_stem()
            .and_then(|s| s.to_str())
            .unwrap_or("default"));
        fonts.font_data.insert(
            font_name.clone(),
            egui::FontData::from_owned(bytes),
        );
        // 优先级: Proportional (正文) + Monospace (代码)
        if let Some(family) = fonts.families.get_mut(&egui::FontFamily::Proportional) {
            family.insert(0, font_name.clone());
        }
        if let Some(family) = fonts.families.get_mut(&egui::FontFamily::Monospace) {
            family.insert(0, font_name.clone());
        }
        log::info!("[fonts] installed CJK font: {:?}", path);
        installed = true;
        break;
    }

    if !installed {
        log::warn!("[fonts] no CJK font found, 中文可能显示为方块");
    }

    ctx.set_fonts(fonts);
}
