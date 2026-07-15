//! Path rewrite middleware
//!
//! 把 `/api/xxx.php?yyy` 改写成 `/api/xxx?yyy`，
//! 这样前端 JS 可以保持原 PHP 版本的 URL（`/api/xxx.php?action=...`）不用改。
//!
//! 同时把 `/api/xxx.php/...` 也改写（不常见）。

use axum::{
    body::Body,
    extract::Request,
    http::uri::Uri,
    middleware::Next,
    response::Response,
};

pub async fn strip_php_extension(req: Request, next: Next) -> Response {
    let (mut parts, body) = req.into_parts();
    let original_uri = parts.uri.clone();
    let path_and_query = original_uri.path_and_query()
        .map(|pq| pq.as_str().to_string())
        .unwrap_or_else(|| original_uri.path().to_string());

    if let Some(new_path) = strip_php(&path_and_query) {
        if let Ok(new_uri) = new_path.parse::<Uri>() {
            parts.uri = new_uri;
        }
    }

    let req = Request::from_parts(parts, body);
    next.run(req).await
}

fn strip_php(path_and_query: &str) -> Option<String> {
    // 只处理 /api/ 前缀 + .php 后缀
    if !path_and_query.starts_with("/api/") && path_and_query != "/api" {
        return None;
    }
    let (path, query) = match path_and_query.find('?') {
        Some(idx) => (&path_and_query[..idx], &path_and_query[idx..]),
        None => (path_and_query, ""),
    };
    let new_path = match path.strip_suffix(".php") {
        Some(p) => p,
        None => return None,
    };
    Some(format!("{}{}", new_path, query))
}

#[allow(dead_code)]
fn _phantom(_b: Body) {}
