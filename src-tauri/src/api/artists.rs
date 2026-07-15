//! /api/artists -- Artist library
//!
//! 跟 NAI Studio PHP 项目 artists.php 等价
//!   - GET ?action=list             -> artists with filters
//!   - GET ?action=detail&id=N      -> single artist
//!   - GET ?action=search&q=        -> name search
//!   - GET ?action=categories       -> artist_categories
//!   - POST action=create / update / delete / set_categories -> DB ops
//!   - POST action=autocomplete / fetch / batch_fetch / danbooru_search -> STUB (Phase 4)

use std::collections::HashMap;

use axum::Json;
use axum::extract::{Query, State};
use rusqlite::types::Value as SqlValue;
use serde_json::{Value, json};

use crate::api::SharedState;
use crate::error::{AppError, AppResult};

/// 主入口
pub async fn list(
    State(state): State<SharedState>,
    Query(params): Query<HashMap<String, String>>,
) -> AppResult<Json<Value>> {
    let action = params.get("action").map(|s| s.as_str()).unwrap_or("list");
    match action {
        "list"       => list_artists(state, &params).await,
        "detail"     => detail(state, &params).await,
        "search"     => search(state, &params).await,
        "categories" => categories(state).await,
        _ => Ok(Json(json!({"ok": false, "error": format!("unknown action: {}", action), "note": "stub for advanced actions (fetch/batch_fetch/autocomplete/danbooru_search)"}))),
    }
}

async fn list_artists(state: SharedState, params: &HashMap<String, String>) -> AppResult<Json<Value>> {
    let category_id: Option<i64> = params.get("category_id").and_then(|s| s.parse().ok());
    let q = params.get("q").map(|s| s.as_str()).unwrap_or("").trim();
    let sort = params.get("sort").map(|s| s.as_str()).unwrap_or("post_count");
    let limit: i64 = params.get("limit").and_then(|s| s.parse().ok()).unwrap_or(200).clamp(1, 1000);

    let order_by = match sort {
        "name"        => "name_nai ASC",
        "recent"      => "id DESC",
        _             => "post_count DESC, id DESC",
    };

    let mut wheres: Vec<String> = vec!["1=1".to_string()];
    let mut sql_params: Vec<SqlValue> = Vec::new();
    if let Some(cid) = category_id {
        // 用关联表过滤(简化:子查询)
        wheres.push(format!("id IN (SELECT artist_id FROM artist_category_map WHERE category_id = ?)"));
        sql_params.push(SqlValue::Integer(cid));
    }
    if !q.is_empty() {
        wheres.push("(name_noob LIKE ? OR name_nai LIKE ? OR name_cn LIKE ?)".to_string());
        let like = format!("%{}%", q);
        sql_params.push(SqlValue::Text(like.clone()));
        sql_params.push(SqlValue::Text(like.clone()));
        sql_params.push(SqlValue::Text(like));
    }
    sql_params.push(SqlValue::Integer(limit));

    let conn = state.db.lock();
    let sql = format!(
        "SELECT id, uuid, name_noob, name_nai, name_cn, danbooru_link, post_count, style, example_image_url, fetched_at
         FROM artists WHERE {} ORDER BY {} LIMIT ?",
        wheres.join(" AND "), order_by
    );
    let mut stmt = conn.prepare(&sql)?;
    let param_refs: Vec<&dyn rusqlite::ToSql> = sql_params.iter().map(|p| p as &dyn rusqlite::ToSql).collect();
    let rows: Vec<Value> = stmt.query_map(param_refs.as_slice(), |r| {
        Ok(json!({
            "id": r.get::<_, i64>(0)?,
            "uuid": r.get::<_, Option<String>>(1)?,
            "name_noob": r.get::<_, Option<String>>(2)?,
            "name_nai": r.get::<_, Option<String>>(3)?,
            "name_cn": r.get::<_, Option<String>>(4)?,
            "danbooru_link": r.get::<_, Option<String>>(5)?,
            "post_count": r.get::<_, Option<i64>>(6)?,
            "style": r.get::<_, Option<String>>(7)?,
            "example_image_url": r.get::<_, Option<String>>(8)?,
            "fetched_at": r.get::<_, Option<String>>(9)?,
        }))
    })?.collect::<Result<Vec<_>, _>>()?;
    Ok(Json(json!({"ok": true, "rows": rows})))
}

async fn detail(state: SharedState, params: &HashMap<String, String>) -> AppResult<Json<Value>> {
    let id: i64 = params.get("id").and_then(|s| s.parse().ok())
        .ok_or_else(|| AppError::BadRequest("id required".into()))?;
    let conn = state.db.lock();
    let mut stmt = conn.prepare(
        "SELECT id, uuid, name_noob, name_nai, name_cn, danbooru_link, post_count, example_post_id,
                example_image_url, example_image_path, notes, tags, style, skip_danbooru, fetched_at,
                created_at, updated_at FROM artists WHERE id = ?1"
    )?;
    let row: Option<Value> = stmt.query_row([id], |r| {
        Ok(json!({
            "id": r.get::<_, i64>(0)?,
            "uuid": r.get::<_, Option<String>>(1)?,
            "name_noob": r.get::<_, Option<String>>(2)?,
            "name_nai": r.get::<_, Option<String>>(3)?,
            "name_cn": r.get::<_, Option<String>>(4)?,
            "danbooru_link": r.get::<_, Option<String>>(5)?,
            "post_count": r.get::<_, Option<i64>>(6)?,
            "example_post_id": r.get::<_, Option<i64>>(7)?,
            "example_image_url": r.get::<_, Option<String>>(8)?,
            "example_image_path": r.get::<_, Option<String>>(9)?,
            "notes": r.get::<_, Option<String>>(10)?,
            "tags": r.get::<_, Option<String>>(11)?,
            "style": r.get::<_, Option<String>>(12)?,
            "skip_danbooru": r.get::<_, i64>(13)? != 0,
            "fetched_at": r.get::<_, Option<String>>(14)?,
            "created_at": r.get::<_, Option<String>>(15)?,
            "updated_at": r.get::<_, Option<String>>(16)?,
        }))
    }).ok();
    match row {
        Some(r) => Ok(Json(json!({"ok": true, "row": r}))),
        None => Ok(Json(json!({"ok": false, "error": "not found"}))),
    }
}

async fn search(state: SharedState, params: &HashMap<String, String>) -> AppResult<Json<Value>> {
    list_artists(state, params).await
}

async fn categories(state: SharedState) -> AppResult<Json<Value>> {
    let conn = state.db.lock();
    let mut stmt = conn.prepare(
        "SELECT id, name, display_order, (SELECT COUNT(*) FROM artist_category_map WHERE category_id = artist_categories.id) AS artist_count
         FROM artist_categories ORDER BY display_order ASC, id ASC"
    )?;
    let rows: Vec<Value> = stmt.query_map([], |r| {
        Ok(json!({
            "id": r.get::<_, i64>(0)?,
            "name": r.get::<_, String>(1)?,
            "display_order": r.get::<_, i64>(2)?,
            "artist_count": r.get::<_, i64>(3)?,
        }))
    })?.collect::<Result<Vec<_>, _>>()?;
    Ok(Json(json!({"ok": true, "rows": rows})))
}
