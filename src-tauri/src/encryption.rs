//! API key AES-256-GCM 加密
//!
//! 跟 NAI Studio PHP 项目的 Encryption::encrypt/decrypt 兼容：
//!   - 32-byte key（来自 config.security.encryption_key）
//!   - 12-byte random nonce
//!   - ciphertext + 16-byte GCM tag 拼接
//!   - Base64 编码
//!
//! 加密格式（Base64 解码后）：
//!   [12 bytes nonce][N bytes ciphertext][16 bytes GCM tag]
//!
//! 跟 PHP 端实现一致，跨语言可读（旧数据可直接解密）

use aes_gcm::aead::{Aead, KeyInit};
use aes_gcm::{Aes256Gcm, Key, Nonce};
use base64::Engine;
use base64::engine::general_purpose::STANDARD;

use crate::error::{AppError, AppResult};

/// 32-byte 加密 key（与 PHP 端一致）
/// 警告：生产环境必须改！这里先用 dev key
pub const DEV_KEY: &str = "naistudio-dev-key-CHANGE-ME-4f8b9c2d1e7a6f5b";

fn key() -> Key<Aes256Gcm> {
    // pad/truncate to 32 bytes
    let bytes = DEV_KEY.as_bytes();
    let mut buf = [0u8; 32];
    let len = bytes.len().min(32);
    buf[..len].copy_from_slice(&bytes[..len]);
    *Key::<Aes256Gcm>::from_slice(&buf)
}

fn cipher() -> Aes256Gcm {
    Aes256Gcm::new(&key())
}

/// 加密字符串，返回 Base64 编码
pub fn encrypt(plaintext: &str) -> AppResult<String> {
    let cipher = cipher();
    let mut nonce_bytes = [0u8; 12];
    use rand::RngCore;
    rand::thread_rng().fill_bytes(&mut nonce_bytes);
    let nonce = Nonce::from_slice(&nonce_bytes);

    let ciphertext = cipher
        .encrypt(nonce, plaintext.as_bytes())
        .map_err(|e| AppError::Internal(format!("encryption failed: {}", e)))?;

    // 拼接: nonce + ciphertext (已含 tag)
    let mut out = Vec::with_capacity(12 + ciphertext.len());
    out.extend_from_slice(&nonce_bytes);
    out.extend_from_slice(&ciphertext);

    Ok(STANDARD.encode(&out))
}

/// 解密 Base64 编码的密文，返回原始字符串
pub fn decrypt(b64: &str) -> AppResult<String> {
    if b64.is_empty() {
        return Err(AppError::Auth("empty ciphertext".into()));
    }
    let raw = STANDARD.decode(b64)
        .map_err(|e| AppError::Auth(format!("invalid base64: {}", e)))?;
    if raw.len() < 12 + 16 {
        return Err(AppError::Auth("ciphertext too short".into()));
    }
    let (nonce_bytes, ciphertext) = raw.split_at(12);
    let cipher = cipher();
    let nonce = Nonce::from_slice(nonce_bytes);
    let plaintext = cipher
        .decrypt(nonce, ciphertext)
        .map_err(|e| AppError::Auth(format!("decryption failed (wrong key or corrupted): {}", e)))?;
    String::from_utf8(plaintext).map_err(|e| AppError::Auth(format!("invalid utf-8: {}", e)))
}
