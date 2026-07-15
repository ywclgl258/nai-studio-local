//! 全局错误类型
//!
//! 用 thiserror 自动派生 Display + Error。
//! HTTP 路由用 AppError → Response 的转换统一处理。

use axum::http::StatusCode;
use axum::response::{IntoResponse, Response};
use serde_json::json;
use thiserror::Error;

pub type AppResult<T> = Result<T, AppError>;

#[derive(Debug, Error)]
pub enum AppError {
    #[error("数据库错误: {0}")]
    Db(#[from] rusqlite::Error),

    #[error("I/O 错误: {0}")]
    Io(String),

    #[error("序列化错误: {0}")]
    Serde(#[from] serde_json::Error),

    #[error("网络错误: {0}")]
    Http(#[from] reqwest::Error),

    #[error("配置错误: {0}")]
    Config(String),

    #[error("认证错误: {0}")]
    Auth(String),

    #[error("参数错误: {0}")]
    BadRequest(String),

    #[error("未找到: {0}")]
    NotFound(String),

    #[error("Real-ESRGAN 未就绪: {0}")]
    UpscalerNotReady(String),

    #[error("外部 API 错误: {0}")]
    Upstream(String),

    #[error("内部错误: {0}")]
    Internal(String),
}

impl AppError {
    pub fn status(&self) -> StatusCode {
        match self {
            AppError::BadRequest(_) => StatusCode::BAD_REQUEST,
            AppError::Auth(_) => StatusCode::UNAUTHORIZED,
            AppError::NotFound(_) => StatusCode::NOT_FOUND,
            AppError::UpscalerNotReady(_) => StatusCode::SERVICE_UNAVAILABLE,
            AppError::Upstream(_) => StatusCode::BAD_GATEWAY,
            _ => StatusCode::INTERNAL_SERVER_ERROR,
        }
    }
}

impl IntoResponse for AppError {
    fn into_response(self) -> Response {
        let status = self.status();
        // 5xx 错误记到 log
        if status.is_server_error() {
            log::error!("[HTTP {}] {}", status.as_u16(), self);
        } else {
            log::warn!("[HTTP {}] {}", status.as_u16(), self);
        }
        let body = json!({
            "ok": false,
            "error": self.to_string(),
        });
        (status, axum::Json(body)).into_response()
    }
}

impl From<std::io::Error> for AppError {
    fn from(e: std::io::Error) -> Self {
        AppError::Io(e.to_string())
    }
}
