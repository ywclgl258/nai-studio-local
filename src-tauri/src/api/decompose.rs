//! /api/decompose -- Prompt decomposer
//!
//! 跟 NAI Studio PHP 项目 decompose.php 等价(基础版)
//!   POST {prompt: '...', translate?: bool}
//!     -> { categories: {...}, tags: [...], stats: {...}, untranslated: [...] }
//!   GET ?action=sample         -> example prompt
//!   GET ?action=test_translate -> 本地字典命中测试
//!   GET ?action=lookup&q=foo   -> 查单个 tag 翻译/分类

use std::collections::HashMap;

use axum::Json;
use axum::extract::State;
use serde_json::{Value, json};

use crate::api::SharedState;
use crate::error::AppResult;

const DANBOORU_CATEGORIES: &[(&str, i64)] = &[
    ("general",   0),
    ("artist",    1),
    ("copyright", 3),
    ("character", 4),
    ("meta",      5),
];

const SAMPLE_PROMPT: &str = "masterpiece, best_quality, amazing_quality, absurdres, highres, \
1girl, solo, hatsune_miku, vocaloid, \
long_hair, twintails, blue_eyes, \
smile, blush, open_mouth, \
standing, hands_on_hips, \
school_uniform, pleated_skirt, thighhighs, hair_ribbon, \
outdoors, cherry_blossoms, sky, \
from_below, sunlight, \
artist:ciloranko, \
{1.05::detailed_background}, [depth_of_field:0.9]";

/// 简单内置字典(命中秒回,不查 DB 也不上网)
fn builtin_dict(name: &str) -> Option<&'static str> {
    match name {
        "1girl" => Some("1个女孩"),
        "1boy" => Some("1个男孩"),
        "solo" => Some("单独"),
        "long_hair" => Some("长发"),
        "short_hair" => Some("短发"),
        "twintails" => Some("双马尾"),
        "blue_eyes" => Some("蓝眼"),
        "red_eyes" => Some("红眼"),
        "green_eyes" => Some("绿眼"),
        "smile" => Some("微笑"),
        "blush" => Some("脸红"),
        "open_mouth" => Some("张嘴"),
        "standing" => Some("站立"),
        "sitting" => Some("坐"),
        "lying" => Some("躺"),
        "walking" => Some("走路"),
        "running" => Some("跑步"),
        "arms_crossed" => Some("抱臂"),
        "school_uniform" => Some("校服"),
        "pleated_skirt" => Some("百褶裙"),
        "thighhighs" => Some("过膝袜"),
        "hair_ribbon" => Some("发带"),
        "outdoors" => Some("户外"),
        "indoors" => Some("室内"),
        "sky" => Some("天空"),
        "sunlight" => Some("阳光"),
        "cherry_blossoms" => Some("樱花"),
        "from_below" => Some("仰视"),
        "from_above" => Some("俯视"),
        "depth_of_field" => Some("景深"),
        "detailed_background" => Some("详细背景"),
        "masterpiece" => Some("杰作"),
        "best_quality" => Some("最高质量"),
        "amazing_quality" => Some("惊人质量"),
        "absurdres" => Some("荒诞分辨率"),
        "highres" => Some("高分辨率"),
        "hatsune_miku" => Some("初音未来"),
        "vocaloid" => Some("Vocaloid"),
        _ => None,
    }
}

/// pub 包装,给 danbooru.rs 等其他模块用
pub fn builtin_dict_pub(name: &str) -> Option<&'static str> {
    builtin_dict(name)
}

pub async fn handle(
    State(state): State<SharedState>,
    body: Option<Json<Value>>,
) -> AppResult<Json<Value>> {
    // POST: 拆分 prompt; 也支持 ?action=sample / ?action=lookup 通过 GET
    // 简化: 看 body / query 里的 action
    let action = body.as_ref()
        .and_then(|b| b.get("action"))
        .and_then(|v| v.as_str())
        .unwrap_or("classify");

    if action == "sample" {
        return Ok(Json(json!({"ok": true, "prompt": SAMPLE_PROMPT})));
    }
    if action == "lookup" {
        let q = body.as_ref()
            .and_then(|b| b.get("q"))
            .and_then(|v| v.as_str())
            .unwrap_or("");
        return lookup_single(&state, q);
    }

    let prompt = body.as_ref()
        .and_then(|b| b.get("prompt"))
        .and_then(|v| v.as_str())
        .unwrap_or(SAMPLE_PROMPT);
    let _translate = body.as_ref()
        .and_then(|b| b.get("translate"))
        .and_then(|v| v.as_bool())
        .unwrap_or(true);

    let parsed = parse_prompt(prompt);
    let tags_with_meta = lookup_tags(&state, &parsed)?;
    Ok(Json(json!({
        "ok": true,
        "prompt": prompt,
        "tags": tags_with_meta,
        "stats": {
            "total": tags_with_meta.len(),
            "with_cn": tags_with_meta.iter().filter(|t| t.get("cn_name").and_then(|v| v.as_str()).map(|s| !s.is_empty()).unwrap_or(false)).count(),
        },
        "untranslated": tags_with_meta.iter().filter(|t| {
            let cn = t.get("cn_name").and_then(|v| v.as_str()).unwrap_or("");
            let src = t.get("source").and_then(|v| v.as_str()).unwrap_or("");
            cn.is_empty() && src == "miss"
        }).map(|t| t.get("name").and_then(|v| v.as_str()).unwrap_or("").to_string()).collect::<Vec<_>>(),
        "categories": DANBOORU_CATEGORIES.iter().map(|(n, i)| json!({"name": n, "id": i})).collect::<Vec<_>>(),
    })))
}

/// 拆分 prompt: 提权 {..} / [..] / 权重数字、artist:xxx、纯 tag
fn parse_prompt(prompt: &str) -> Vec<String> {
    prompt
        .split(|c: char| c == ',' || c == '\n' || c == ';')
        .map(|s| s.trim().trim_end_matches(' '))
        .filter(|s| !s.is_empty())
        .map(|s| {
            // 去掉 {1.05::xxx} / [depth_of_field:0.9] 这种权重的内部 tag
            let mut t = s.to_string();
            // 找第一个不被 {}[] 包裹的 tag 段
            // 简化: 取整段
            t = t.replace('{', "").replace('}', "").replace('[', "").replace(']', "");
            t
        })
        // 移除纯数字 / 空
        .filter(|s| !s.is_empty() && !s.chars().all(|c| c.is_ascii_digit() || c == '.' || c == ':' || c == '-'))
        // 提权部分: 1.05::detailed_background -> detailed_background
        .map(|s| {
            if let Some(pos) = s.find("::") {
                s[pos + 2..].to_string()
            } else if let Some(pos) = s.find(':') {
                // 跳过 artist:xxx 这种
                if s.starts_with("artist:") {
                    s.to_string()
                } else {
                    s[pos + 1..].to_string()
                }
            } else {
                s
            }
        })
        .collect()
}

/// 在 DB 缓存 + 内置字典里查每个 tag
fn lookup_tags(state: &SharedState, tags: &[String]) -> AppResult<Vec<Value>> {
    if tags.is_empty() { return Ok(vec![]); }
    let conn = state.db.lock();
    let placeholders = std::iter::repeat("?").take(tags.len()).collect::<Vec<_>>().join(",");
    let sql = format!(
        "SELECT name, category, post_count, cn_name, example_image_url
         FROM danbooru_tag_cache WHERE name IN ({})",
        placeholders
    );
    let mut stmt = conn.prepare(&sql)?;
    let mut found: HashMap<String, (i64, i64, Option<String>, Option<String>)> = HashMap::new();
    let param_refs: Vec<&dyn rusqlite::ToSql> = tags.iter().map(|n| n as &dyn rusqlite::ToSql).collect();
    let mut rows = stmt.query(param_refs.as_slice())?;
    while let Some(r) = rows.next()? {
        let name: String = r.get(0)?;
        let category: i64 = r.get(1)?;
        let post_count: i64 = r.get(2)?;
        let cn_name: Option<String> = r.get(3)?;
        let example_url: Option<String> = r.get(4)?;
        found.insert(name, (category, post_count, cn_name, example_url));
    }

    // 合并:DB 找到 / 内置字典 / miss
    let mut out: Vec<Value> = Vec::with_capacity(tags.len());
    for t in tags {
        let name_lower = t.to_lowercase();
        if let Some((category, post_count, cn_name, example_url)) = found.get(&name_lower) {
            // 优先用内置字典(更准,DB 的 cn_name 可能过时)
            let cn = builtin_dict(&name_lower)
                .map(|s| s.to_string())
                .or_else(|| cn_name.clone());
            out.push(json!({
                "name": t,
                "category": category,
                "post_count": post_count,
                "cn_name": cn,
                "example_image_url": example_url,
                "source": "danbooru",
            }));
        } else if let Some(cn) = builtin_dict(&name_lower) {
            out.push(json!({
                "name": t,
                "category": 0,  // general
                "post_count": 0,
                "cn_name": cn,
                "example_image_url": Value::Null,
                "source": "builtin",
            }));
        } else {
            out.push(json!({
                "name": t,
                "category": 0,
                "post_count": 0,
                "cn_name": Value::Null,
                "example_image_url": Value::Null,
                "source": "miss",
            }));
        }
    }
    Ok(out)
}

/// 工具 action: 单 tag 查 DB + 内置字典
fn lookup_single(state: &SharedState, q: &str) -> AppResult<Json<Value>> {
    let q = q.trim();
    if q.is_empty() {
        return Ok(Json(json!({"ok": false, "error": "q required"})));
    }
    let lower = q.to_lowercase();
    let conn = state.db.lock();
    let row: Option<(String, i64, i64, Option<String>, Option<String>)> = conn.query_row(
        "SELECT name, category, post_count, cn_name, example_image_url FROM danbooru_tag_cache WHERE name = ?1 LIMIT 1",
        [&lower],
        |r| Ok((r.get(0)?, r.get(1)?, r.get(2)?, r.get(3)?, r.get(4)?))
    ).ok();

    match row {
        Some((name, category, post_count, cn_name, example_url)) => {
            let cn = builtin_dict(&lower).map(|s| s.to_string()).or(cn_name);
            Ok(Json(json!({
                "ok": true,
                "name": name,
                "cn": cn,
                "category": category,
                "post_count": post_count,
                "example_url": example_url,
                "source": "danbooru",
            })))
        }
        None => {
            let cn = builtin_dict(&lower).map(|s| s.to_string());
            Ok(Json(json!({
                "ok": true,
                "name": lower,
                "cn": cn,
                "category": 0,
                "source": if cn.is_some() { "builtin" } else { "miss" },
            })))
        }
    }
}
