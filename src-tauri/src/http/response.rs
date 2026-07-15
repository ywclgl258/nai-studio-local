//! HTTP 响应辅助

use axum::Json;
use axum::http::StatusCode;
use serde_json::{Value, json};

/// 成功响应：`{"ok": true, "data": ...}`
pub fn ok<T: serde::Serialize>(data: T) -> (StatusCode, Json<Value>) {
    (StatusCode::OK, Json(json!({"ok": true, "data": data})))
}

/// 成功响应 + 顶层额外字段
pub fn ok_with<T: serde::Serialize>(extra: Value) -> impl FnOnce(T) -> (StatusCode, Json<Value>) {
    move |data| {
        let mut body = json!({"ok": true, "data": data});
        if let Some(obj) = body.as_object_mut() {
            if let Some(extra_obj) = extra.as_object() {
                for (k, v) in extra_obj {
                    obj.insert(k.clone(), v.clone());
                }
            }
        }
        (StatusCode::OK, Json(body))
    }
}

/// 列表响应：`{"ok": true, "rows": [...], "total": N, "page": P, "per_page": PP, "pages": X}`
pub fn list<T: serde::Serialize>(rows: T, total: i64, page: i64, per_page: i64) -> (StatusCode, Json<Value>) {
    let pages = if per_page > 0 { (total as f64 / per_page as f64).ceil() as i64 } else { 0 };
    (StatusCode::OK, Json(json!({
        "ok": true,
        "rows": rows,
        "total": total,
        "page": page,
        "per_page": per_page,
        "pages": pages,
    })))
}
