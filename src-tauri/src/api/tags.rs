//! /api/tags -- Tag library
//!
//! 跟 NAI Studio PHP 项目 tags.php 等价
//!   - GET ?action=categories                       -> tag_categories + count
//!   - GET ?action=search&q=&category=&page=        -> search tags
//!   - GET ?action=local_search&q=                  -> search danbooru_tag_cache
//!   - GET ?action=lookup&names=a,b,c               -> batch lookup
//!   - GET ?action=detail&name=X                    -> single tag
//!   - GET ?action=local_list&page=&category=...    -> all tags (paged + filters)

use std::collections::HashMap;

use axum::Json;
use axum::extract::{Query, State};
use rusqlite::types::Value as SqlValue;
use serde_json::{Value, json};

use crate::api::SharedState;
use crate::error::AppResult;

/// 主入口:按 ?action= 分发
pub async fn list(
    State(state): State<SharedState>,
    Query(params): Query<HashMap<String, String>>,
) -> AppResult<Json<Value>> {
    let action = params.get("action").map(|s| s.as_str()).unwrap_or("categories");
    match action {
        "categories" => categories(state).await,
        "search" => search(state, &params).await,
        "local_search" => local_search(state, &params).await,
        "lookup" => lookup(state, &params).await,
        "detail" => detail(state, &params).await,
        "local_list" => local_list(state, &params).await,
        _ => Ok(Json(json!({"ok": false, "error": format!("unknown action: {}", action)}))),
    }
}

/// 列出所有 tag_categories + 各分类的 tag 数
async fn categories(state: SharedState) -> AppResult<Json<Value>> {
    let conn = state.db.lock();
    let mut stmt = conn.prepare(
        "SELECT c.id, c.slug, c.name, COUNT(t.id) AS tag_count
         FROM tag_categories c
         LEFT JOIN tags t ON t.category_id = c.id
         GROUP BY c.id, c.slug, c.name
         ORDER BY c.id ASC"
    )?;
    let rows: Vec<Value> = stmt.query_map([], |r| {
        Ok(json!({
            "id": r.get::<_, i64>(0)?,
            "slug": r.get::<_, String>(1)?,
            "name": r.get::<_, String>(2)?,
            "tag_count": r.get::<_, i64>(3)?,
        }))
    })?.collect::<Result<Vec<_>, _>>()?;
    Ok(Json(json!({"ok": true, "rows": rows})))
}

/// 搜索本地 tags 表(模糊 + 分类过滤 + 分页)
async fn search(state: SharedState, params: &HashMap<String, String>) -> AppResult<Json<Value>> {
    let q = params.get("q").map(|s| s.as_str()).unwrap_or("").trim();
    let cid: Option<i64> = params.get("category").and_then(|s| s.parse().ok());
    let page: i64 = params.get("page").and_then(|s| s.parse().ok()).unwrap_or(1).max(1);
    let per_page: i64 = params.get("per_page").and_then(|s| s.parse().ok()).unwrap_or(60).clamp(1, 200);
    let offset = (page - 1) * per_page;

    let conn = state.db.lock();
    let like = format!("%{}%", q);
    let (sql, sql_params): (String, Vec<SqlValue>) = if let Some(cid) = cid {
        (
            "SELECT id, name, category_id, cn_name, post_count, is_nsfw FROM tags
             WHERE (name LIKE ?1 OR cn_name LIKE ?1) AND category_id = ?2
             ORDER BY post_count DESC, name ASC LIMIT ?3 OFFSET ?4".to_string(),
            vec![SqlValue::Text(like), SqlValue::Integer(cid), SqlValue::Integer(per_page), SqlValue::Integer(offset)],
        )
    } else {
        (
            "SELECT id, name, category_id, cn_name, post_count, is_nsfw FROM tags
             WHERE name LIKE ?1 OR cn_name LIKE ?1
             ORDER BY post_count DESC, name ASC LIMIT ?2 OFFSET ?3".to_string(),
            vec![SqlValue::Text(like), SqlValue::Integer(per_page), SqlValue::Integer(offset)],
        )
    };
    let mut stmt = conn.prepare(&sql)?;
    let param_refs: Vec<&dyn rusqlite::ToSql> = sql_params.iter().map(|p| p as &dyn rusqlite::ToSql).collect();
    let rows: Vec<Value> = stmt.query_map(param_refs.as_slice(), |r| {
        Ok(json!({
            "id": r.get::<_, i64>(0)?,
            "name": r.get::<_, String>(1)?,
            "category_id": r.get::<_, i64>(2)?,
            "cn_name": r.get::<_, Option<String>>(3)?,
            "post_count": r.get::<_, i64>(4)?,
            "is_nsfw": r.get::<_, i64>(5)? != 0,
        }))
    })?.collect::<Result<Vec<_>, _>>()?;
    Ok(Json(json!({
        "ok": true,
        "rows": rows,
        "page": page,
        "per_page": per_page,
        "q": q,
    })))
}

/// 搜索 danbooru_tag_cache(已翻译过的,带 cn_name)
async fn local_search(state: SharedState, params: &HashMap<String, String>) -> AppResult<Json<Value>> {
    let q = params.get("q").map(|s| s.as_str()).unwrap_or("").trim();
    let limit: i64 = params.get("limit").and_then(|s| s.parse().ok()).unwrap_or(15).clamp(1, 50);
    if q.is_empty() {
        return Ok(Json(json!({"ok": true, "rows": [], "q": q})));
    }
    let conn = state.db.lock();
    let like = format!("%{}%", q);
    let mut stmt = conn.prepare(
        "SELECT d.name, d.cn_name, d.category, d.post_count,
                COALESCE(t.example_image_url, d.example_image_url) AS example_image_url
         FROM danbooru_tag_cache d
         LEFT JOIN tags t ON t.name = d.name
         WHERE d.name LIKE ?1 OR d.cn_name LIKE ?1
         ORDER BY d.post_count DESC, d.name ASC
         LIMIT ?2"
    )?;
    let rows: Vec<Value> = stmt.query_map(rusqlite::params![like, limit], |r| {
        let example_url: Option<String> = r.get(4)?;
        Ok(json!({
            "name": r.get::<_, String>(0)?,
            "cn_name": r.get::<_, Option<String>>(1)?,
            "category": r.get::<_, i64>(2)?,
            "post_count": r.get::<_, i64>(3)?,
            "example_image_url": example_url,
        }))
    })?.collect::<Result<Vec<_>, _>>()?;
    Ok(Json(json!({"ok": true, "rows": rows, "q": q})))
}

/// 批量按 name 查本地 tags(逗号分隔)
async fn lookup(state: SharedState, params: &HashMap<String, String>) -> AppResult<Json<Value>> {
    let names_raw = params.get("names").map(|s| s.as_str()).unwrap_or("");
    let names: Vec<&str> = names_raw.split(|c: char| c == ',' || c == ' ' || c == '\u{3001}')
        .filter(|s| !s.is_empty())
        .collect();
    if names.is_empty() {
        return Ok(Json(json!({"ok": true, "rows": []})));
    }
    let conn = state.db.lock();
    let placeholders = std::iter::repeat("?").take(names.len()).collect::<Vec<_>>().join(",");
    let sql = format!(
        "SELECT id, name, category_id, cn_name, post_count, is_nsfw FROM tags WHERE name IN ({})",
        placeholders
    );
    let mut stmt = conn.prepare(&sql)?;
    let param_refs: Vec<&dyn rusqlite::ToSql> = names.iter().map(|n| n as &dyn rusqlite::ToSql).collect();
    let rows: Vec<Value> = stmt.query_map(param_refs.as_slice(), |r| {
        Ok(json!({
            "id": r.get::<_, i64>(0)?,
            "name": r.get::<_, String>(1)?,
            "category_id": r.get::<_, i64>(2)?,
            "cn_name": r.get::<_, Option<String>>(3)?,
            "post_count": r.get::<_, i64>(4)?,
            "is_nsfw": r.get::<_, i64>(5)? != 0,
        }))
    })?.collect::<Result<Vec<_>, _>>()?;
    Ok(Json(json!({"ok": true, "rows": rows})))
}

/// 按 name 单条
async fn detail(state: SharedState, params: &HashMap<String, String>) -> AppResult<Json<Value>> {
    let name = params.get("name").map(|s| s.as_str()).unwrap_or("").trim();
    if name.is_empty() {
        return Ok(Json(json!({"ok": false, "error": "name required"})));
    }
    let conn = state.db.lock();
    let mut stmt = conn.prepare(
        "SELECT id, name, category_id, cn_name, post_count, is_nsfw, description FROM tags WHERE name = ?1"
    )?;
    let row: Option<Value> = stmt.query_row([name], |r| {
        Ok(json!({
            "id": r.get::<_, i64>(0)?,
            "name": r.get::<_, String>(1)?,
            "category_id": r.get::<_, i64>(2)?,
            "cn_name": r.get::<_, Option<String>>(3)?,
            "post_count": r.get::<_, i64>(4)?,
            "is_nsfw": r.get::<_, i64>(5)? != 0,
            "description": r.get::<_, Option<String>>(6)?,
        }))
    }).ok();
    match row {
        Some(tag) => Ok(Json(json!({"ok": true, "tag": tag}))),
        None => Ok(Json(json!({"ok": false, "error": "not found"}))),
    }
}

/// 全列本地 tags(分页 + 多过滤)
async fn local_list(state: SharedState, params: &HashMap<String, String>) -> AppResult<Json<Value>> {
    let page: i64 = params.get("page").and_then(|s| s.parse().ok()).unwrap_or(1).max(1);
    let per_page: i64 = params.get("per_page").and_then(|s| s.parse().ok()).unwrap_or(60).clamp(1, 200);
    let offset = (page - 1) * per_page;

    let cid: Option<i64> = params.get("category").and_then(|s| s.parse().ok());
    let has_image: Option<bool> = params.get("has_image").and_then(|s| match s.as_str() {
        "1" => Some(true), "0" => Some(false), _ => None,
    });
    let has_cn: Option<bool> = params.get("has_cn").and_then(|s| match s.as_str() {
        "1" => Some(true), "0" => Some(false), _ => None,
    });
    let q = params.get("q").map(|s| s.as_str()).unwrap_or("").trim();
    let sort = params.get("sort").map(|s| s.as_str()).unwrap_or("popular");
    let order_by = match sort {
        "recent"  => "t.id DESC",
        "name"    => "t.name ASC",
        "random"  => "RANDOM()",
        _         => "t.post_count DESC, t.id DESC",
    };

    let mut where_clauses: Vec<String> = Vec::new();
    let mut sql_params: Vec<SqlValue> = Vec::new();
    if let Some(cid) = cid {
        where_clauses.push("t.category_id = ?".to_string());
        sql_params.push(SqlValue::Integer(cid));
    }
    if let Some(b) = has_image {
        if b {
            where_clauses.push("t.example_image_url IS NOT NULL AND t.example_image_url <> ''".to_string());
        } else {
            where_clauses.push("(t.example_image_url IS NULL OR t.example_image_url = '')".to_string());
        }
    }
    if let Some(b) = has_cn {
        if b {
            where_clauses.push("t.cn_name IS NOT NULL AND t.cn_name <> ''".to_string());
        } else {
            where_clauses.push("(t.cn_name IS NULL OR t.cn_name = '')".to_string());
        }
    }
    if !q.is_empty() {
        where_clauses.push(format!("(t.name LIKE ? OR t.cn_name LIKE ?)"));
        let like = format!("%{}%", q);
        sql_params.push(SqlValue::Text(like.clone()));
        sql_params.push(SqlValue::Text(like));
    }
    let where_sql = if where_clauses.is_empty() { "1=1".to_string() } else { where_clauses.join(" AND ") };
    sql_params.push(SqlValue::Integer(per_page));
    sql_params.push(SqlValue::Integer(offset));

    let conn = state.db.lock();
    let sql = format!(
        "SELECT t.id, t.name, t.category_id, c.slug AS category_slug, t.cn_name, t.post_count, t.is_nsfw, t.example_image_url
         FROM tags t LEFT JOIN tag_categories c ON t.category_id = c.id
         WHERE {} ORDER BY {} LIMIT ? OFFSET ?",
        where_sql, order_by
    );
    let mut stmt = conn.prepare(&sql)?;
    let param_refs: Vec<&dyn rusqlite::ToSql> = sql_params.iter().map(|p| p as &dyn rusqlite::ToSql).collect();
    let rows: Vec<Value> = stmt.query_map(param_refs.as_slice(), |r| {
        Ok(json!({
            "id": r.get::<_, i64>(0)?,
            "name": r.get::<_, String>(1)?,
            "category_id": r.get::<_, i64>(2)?,
            "category_slug": r.get::<_, Option<String>>(3)?,
            "cn_name": r.get::<_, Option<String>>(4)?,
            "post_count": r.get::<_, i64>(5)?,
            "is_nsfw": r.get::<_, i64>(6)? != 0,
            "example_image_url": r.get::<_, Option<String>>(7)?,
        }))
    })?.collect::<Result<Vec<_>, _>>()?;

    // count
    let count_sql = format!("SELECT COUNT(*) FROM tags t WHERE {}", where_sql);
    let mut count_stmt = conn.prepare(&count_sql)?;
    let count_params: Vec<&dyn rusqlite::ToSql> = sql_params[..sql_params.len()-2].iter().map(|p| p as &dyn rusqlite::ToSql).collect();
    let total: i64 = count_stmt.query_row(count_params.as_slice(), |r| r.get(0)).unwrap_or(0);

    Ok(Json(json!({
        "ok": true,
        "rows": rows,
        "page": page,
        "per_page": per_page,
        "total": total,
    })))
}
