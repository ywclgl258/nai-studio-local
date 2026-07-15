//! /api/pose-dict -- 姿势/动作词库
//!
//! 跟 NAI Studio PHP 项目 pose-dict.php 等价
//!   GET /api/pose-dict?q=站   -> 模糊过滤(中英文)
//!   GET /api/pose-dict       -> 全部
//!
//! Phase 3.3: 基础内置字典(常用 50+ 姿势)
//! Phase 4: 从 DB 加载用户自定义 + 翻译(对接 Danbooru)

use std::collections::BTreeMap;

use axum::Json;
use axum::extract::{Query, State};
use serde_json::{Value, json};

use crate::api::SharedState;
use crate::error::AppResult;

/// 全部姿势(按分类)
fn builtin_pose_dict() -> BTreeMap<&'static str, Vec<(&'static str, &'static str)>> {
    let mut d: BTreeMap<&'static str, Vec<(&'static str, &'static str)>> = BTreeMap::new();
    d.insert("基本姿势", vec![
        ("standing", "站立"),
        ("sitting", "坐"),
        ("kneeling", "跪"),
        ("lying", "躺"),
        ("walking", "走路"),
        ("running", "跑步"),
        ("jumping", "跳"),
        ("crouching", "蹲"),
    ]);
    d.insert("手部", vec![
        ("arms_crossed", "抱臂"),
        ("hands_on_hips", "叉腰"),
        ("hands_clasped", "双手合十"),
        ("hand_on_own_chest", "手放胸口"),
        ("pointing", "指向"),
        ("waving", "挥手"),
        ("thumbs_up", "点赞"),
        ("peace_sign", "比 V"),
    ]);
    d.insert("表情", vec![
        ("smile", "微笑"),
        ("grin", "咧嘴笑"),
        ("laughing", "大笑"),
        ("frown", "皱眉"),
        ("pout", "嘟嘴"),
        ("open_mouth", "张嘴"),
        ("blush", "脸红"),
        ("crying", "哭"),
        ("angry", "生气"),
        ("surprised", "惊讶"),
    ]);
    d.insert("视线", vec![
        ("looking_at_viewer", "看观众"),
        ("looking_away", "看别处"),
        ("looking_up", "看上方"),
        ("looking_down", "看下方"),
        ("eye_contact", "对视"),
        ("closed_eyes", "闭眼"),
    ]);
    d.insert("视角", vec![
        ("from_above", "俯视"),
        ("from_below", "仰视"),
        ("from_side", "侧视"),
        ("from_behind", "背后"),
        ("close-up", "特写"),
        ("wide_shot", "远景"),
    ]);
    d.insert("互动", vec![
        ("hug", "拥抱"),
        ("kiss", "接吻"),
        ("handshake", "握手"),
        ("carrying", "背着"),
        ("wedding", "婚纱"),
        ("couple", "情侣"),
    ]);
    d
}

pub async fn handle(
    State(_state): State<SharedState>,
    Query(params): Query<std::collections::HashMap<String, String>>,
) -> AppResult<Json<Value>> {
    let q = params.get("q").map(|s| s.as_str()).unwrap_or("").trim().to_lowercase();
    let all = builtin_pose_dict();
    let mut filtered: BTreeMap<String, Vec<(String, String)>> = BTreeMap::new();
    let mut total = 0i64;

    for (cat, items) in all.iter() {
        let mut bucket: Vec<(String, String)> = Vec::new();
        for (en, cn) in items {
            if q.is_empty() || en.contains(&q) || cn.contains(&q) {
                bucket.push((en.to_string(), cn.to_string()));
                total += 1;
            }
        }
        if !bucket.is_empty() {
            filtered.insert(cat.to_string(), bucket);
        }
    }

    let categories: Vec<Value> = filtered.iter().map(|(cat, items)| {
        json!({
            "category": cat,
            "items": items.iter().map(|(en, cn)| json!({"en": en, "cn": cn})).collect::<Vec<_>>(),
        })
    }).collect();

    Ok(Json(json!({
        "ok": true,
        "query": q,
        "total": total,
        "categories": categories,
        "note": "Phase 3.3 基础内置字典；Phase 4 接 DB 自定义 + Danbooru",
    })))
}
