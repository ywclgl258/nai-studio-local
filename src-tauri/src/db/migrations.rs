//! 数据库 schema 迁移
//!
//! NAI Studio 的所有表用 CREATE TABLE IF NOT EXISTS 形式塞到 MIGRATION_V1。
//! 与原 PHP 项目的 SQLite schema 完全兼容。
//!
//! ENUM 在 SQLite 是 CHECK 约束，跟原项目一样写法。
//! 后续要加表：append MIGRATION_V2 + 改 CURRENT_VERSION。

use rusqlite::Connection;

use crate::error::AppResult;

const CURRENT_VERSION: i32 = 1;

pub fn run(conn: &Connection) -> AppResult<()> {
    // 1. 建 schema_version 表
    conn.execute(
        "CREATE TABLE IF NOT EXISTS schema_version (
            version     INTEGER PRIMARY KEY,
            applied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        [],
    )?;

    // 2. 查当前版本
    let current: i32 = conn
        .query_row(
            "SELECT COALESCE(MAX(version), 0) FROM schema_version",
            [],
            |r| r.get(0),
        )
        .unwrap_or(0);

    if current < 1 {
        log::info!("[migrations] running V1 (initial schema)");
        conn.execute_batch(MIGRATION_V1)?;
        conn.execute("INSERT INTO schema_version (version) VALUES (1)", [])?;
    }

    log::info!("[migrations] schema at version {}", CURRENT_VERSION);
    Ok(())
}

/// V1: 全部表 — 与 nai-studio PHP 项目 SQLite schema 一致
const MIGRATION_V1: &str = r#"
-- ===== settings: 单行存所有用户配置 =====
CREATE TABLE IF NOT EXISTS "settings" (
    "id"                            INTEGER PRIMARY KEY,
    "api_key_encrypted"             TEXT,
    "api_key_fingerprint"           TEXT,
    "default_model"                 TEXT NOT NULL DEFAULT 'nai-diffusion-4-5-curated',
    "default_sampler"               TEXT NOT NULL DEFAULT 'k_euler_ancestral',
    "default_steps"                 INTEGER NOT NULL DEFAULT 28,
    "default_scale"                 REAL NOT NULL DEFAULT 5.0,
    "default_cfg_rescale"           REAL NOT NULL DEFAULT 0.0,
    "default_noise_schedule"        TEXT NOT NULL DEFAULT 'karras',
    "default_size"                  TEXT NOT NULL DEFAULT '832x1216',
    "default_uc_preset"             INTEGER NOT NULL DEFAULT 0,
    "quality_toggle"                INTEGER NOT NULL DEFAULT 1,
    "emphasis_highlight"            INTEGER NOT NULL DEFAULT 1,
    "theme"                         TEXT NOT NULL DEFAULT 'dark',
    "ui_state"                      TEXT,
    "anlas_balance"                 INTEGER,
    "anlas_updated_at"              DATETIME,
    "proxy_enabled"                 INTEGER NOT NULL DEFAULT 0,
    "proxy_url"                     TEXT,
    "proxy_test_status"             TEXT,
    "proxy_tested_at"               TEXT,
    "local_translate_enabled"       INTEGER NOT NULL DEFAULT 0,
    "local_translate_url"           TEXT,
    "local_translate_status"        TEXT,
    "local_translate_tested_at"     TEXT,
    "translate_source"              TEXT NOT NULL DEFAULT 'fallback',
    "aggressive_fallback_enabled"   INTEGER NOT NULL DEFAULT 0,
    "danbooru_username"             TEXT,
    "danbooru_api_key"              TEXT,
    "deepseek_api_key"              TEXT,
    "deepseek_model"                TEXT,
    "deepseek_base_url"             TEXT,
    "deepseek_status"               TEXT,
    "deepseek_tested_at"            TEXT,
    "ai_advisor_enabled"            INTEGER NOT NULL DEFAULT 0,
    "ai_provider"                   TEXT NOT NULL DEFAULT 'deepseek',
    "ai_base_url"                   TEXT,
    "ai_api_key"                    TEXT,
    "ai_model"                      TEXT,
    "ai_reasoning_effort"           TEXT,
    "created_at"                    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at"                    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT OR IGNORE INTO settings (id) VALUES (1);

-- ===== tag_categories =====
CREATE TABLE IF NOT EXISTS "tag_categories" (
    "id"            INTEGER PRIMARY KEY AUTOINCREMENT,
    "slug"          TEXT NOT NULL UNIQUE,
    "name"          TEXT NOT NULL,
    "name_cn"       TEXT,
    "description"   TEXT,
    "display_order" INTEGER NOT NULL DEFAULT 0,
    "tag_count"     INTEGER NOT NULL DEFAULT 0
);

-- ===== tags =====
CREATE TABLE IF NOT EXISTS "tags" (
    "id"            INTEGER PRIMARY KEY AUTOINCREMENT,
    "name"          TEXT NOT NULL UNIQUE,
    "category_id"   INTEGER NOT NULL,
    "cn_name"       TEXT,
    "post_count"    INTEGER NOT NULL DEFAULT 0,
    "aliases"       TEXT,
    "description"   TEXT,
    "is_nsfw"       INTEGER NOT NULL DEFAULT 0,
    "created_at"    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS "tags_idx_category_count" ON "tags"("category_id", "post_count" DESC);
CREATE INDEX IF NOT EXISTS "tags_idx_post_count" ON "tags"("post_count" DESC);

-- ===== prompts =====
CREATE TABLE IF NOT EXISTS "prompts" (
    "id"            INTEGER PRIMARY KEY AUTOINCREMENT,
    "title"         TEXT NOT NULL,
    "description"   TEXT,
    "positive"      TEXT NOT NULL,
    "negative"      TEXT,
    "tags_json"     TEXT,
    "model"         TEXT,
    "size"          TEXT,
    "uc_preset"     INTEGER,
    "is_favorite"   INTEGER NOT NULL DEFAULT 0,
    "use_count"     INTEGER NOT NULL DEFAULT 0,
    "last_used_at"  DATETIME,
    "created_at"    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at"    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ===== generations (含 upscale ENUM) =====
CREATE TABLE IF NOT EXISTS "generations" (
    "id"                INTEGER PRIMARY KEY AUTOINCREMENT,
    "batch_id"          TEXT,
    "parent_id"         INTEGER,
    "operation"         TEXT NOT NULL DEFAULT 'generate' CHECK("operation" IN ('generate','img2img','inpaint','vibe','director','upscale')),
    "prompt"            TEXT NOT NULL,
    "negative_prompt"   TEXT,
    "model"             TEXT NOT NULL,
    "sampler"           TEXT NOT NULL,
    "steps"             INTEGER NOT NULL,
    "scale"             REAL NOT NULL,
    "seed"              INTEGER NOT NULL,
    "width"             INTEGER NOT NULL,
    "height"            INTEGER NOT NULL,
    "cfg_rescale"       REAL NOT NULL DEFAULT 0.0,
    "noise_schedule"    TEXT NOT NULL DEFAULT 'karras',
    "uc_preset"         INTEGER NOT NULL DEFAULT 0,
    "quality_toggle"    INTEGER NOT NULL DEFAULT 1,
    "characters_json"   TEXT,
    "vibe_refs_json"    TEXT,
    "precise_refs_json" TEXT,
    "strength"          REAL,
    "noise"             REAL,
    "image_path"        TEXT,
    "thumbnail_path"    TEXT,
    "image_size_bytes"  INTEGER,
    "image_width"       INTEGER,
    "image_height"      INTEGER,
    "meta_json"         TEXT,
    "anlas_cost"        INTEGER,
    "is_favorite"       INTEGER NOT NULL DEFAULT 0,
    "is_deleted"        INTEGER NOT NULL DEFAULT 0,
    "notes"             TEXT,
    "created_at"        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS "gen_idx_created" ON "generations"("created_at" DESC);
CREATE INDEX IF NOT EXISTS "gen_idx_batch" ON "generations"("batch_id");
CREATE INDEX IF NOT EXISTS "gen_idx_parent" ON "generations"("parent_id");
CREATE INDEX IF NOT EXISTS "gen_idx_favorite" ON "generations"("is_favorite" DESC, "created_at" DESC);

-- ===== vibe_refs =====
CREATE TABLE IF NOT EXISTS "vibe_refs" (
    "id"                INTEGER PRIMARY KEY AUTOINCREMENT,
    "name"              TEXT,
    "image_path"        TEXT NOT NULL,
    "thumbnail_path"    TEXT,
    "extracted_info"    TEXT,
    "use_count"         INTEGER NOT NULL DEFAULT 0,
    "created_at"        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ===== precise_refs =====
CREATE TABLE IF NOT EXISTS "precise_refs" (
    "id"                INTEGER PRIMARY KEY AUTOINCREMENT,
    "name"              TEXT,
    "type"              TEXT NOT NULL DEFAULT 'character' CHECK("type" IN ('character','style')),
    "image_path"        TEXT NOT NULL,
    "thumbnail_path"    TEXT,
    "extracted_info"    TEXT,
    "use_count"         INTEGER NOT NULL DEFAULT 0,
    "created_at"        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ===== tag_prompts =====
CREATE TABLE IF NOT EXISTS "tag_prompts" (
    "tag_id"        INTEGER NOT NULL,
    "prompt_id"     INTEGER NOT NULL,
    "weight"        REAL NOT NULL DEFAULT 1.0,
    PRIMARY KEY ("tag_id", "prompt_id")
);

-- ===== tag_aliases =====
CREATE TABLE IF NOT EXISTS "tag_aliases" (
    "id"            INTEGER PRIMARY KEY AUTOINCREMENT,
    "tag_id"        INTEGER NOT NULL,
    "alias"         TEXT NOT NULL UNIQUE,
    "alias_cn"      TEXT
);

-- ===== api_logs =====
CREATE TABLE IF NOT EXISTS "api_logs" (
    "id"                INTEGER PRIMARY KEY AUTOINCREMENT,
    "endpoint"          TEXT NOT NULL,
    "method"            TEXT NOT NULL,
    "status_code"       INTEGER NOT NULL,
    "request_summary"   TEXT,
    "response_summary"  TEXT,
    "duration_ms"       INTEGER,
    "error"             TEXT,
    "created_at"        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ===== nai_api_keys (多 key 轮换) =====
CREATE TABLE IF NOT EXISTS "nai_api_keys" (
    "id"                    INTEGER PRIMARY KEY AUTOINCREMENT,
    "label"                 TEXT,
    "api_key_encrypted"     TEXT NOT NULL,
    "api_key_fingerprint"   TEXT NOT NULL,
    "enabled"               INTEGER NOT NULL DEFAULT 1,
    "sort_order"            INTEGER NOT NULL DEFAULT 0,
    "last_used_at"          DATETIME,
    "last_error_code"       INTEGER,
    "last_error_msg"        TEXT,
    "last_error_at"         DATETIME,
    "fail_count"            INTEGER NOT NULL DEFAULT 0,
    "created_at"            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS "nai_keys_idx_enabled_order" ON "nai_api_keys"("enabled", "sort_order");

-- ===== character_presets =====
CREATE TABLE IF NOT EXISTS "character_presets" (
    "id"            INTEGER PRIMARY KEY AUTOINCREMENT,
    "title"         TEXT NOT NULL,
    "prompt"        TEXT NOT NULL,
    "is_favorite"   INTEGER NOT NULL DEFAULT 0,
    "use_count"     INTEGER NOT NULL DEFAULT 0,
    "last_used_at"  DATETIME,
    "created_at"    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at"    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ===== pose_presets =====
CREATE TABLE IF NOT EXISTS "pose_presets" (
    "id"            INTEGER PRIMARY KEY AUTOINCREMENT,
    "title"         TEXT NOT NULL,
    "prompt"        TEXT NOT NULL,
    "is_favorite"   INTEGER NOT NULL DEFAULT 0,
    "use_count"     INTEGER NOT NULL DEFAULT 0,
    "last_used_at"  DATETIME,
    "created_at"    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at"    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ===== danbooru_tag_cache =====
CREATE TABLE IF NOT EXISTS "danbooru_tag_cache" (
    "name"          TEXT NOT NULL PRIMARY KEY,
    "category"      INTEGER,
    "post_count"    INTEGER,
    "is_nsfw"       INTEGER,
    "cn_name"       TEXT,
    "translated_at" DATETIME,
    "fetched_at"    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ===== artists + artist_categories + 关系表 =====
CREATE TABLE IF NOT EXISTS "artist_categories" (
    "id"            INTEGER PRIMARY KEY AUTOINCREMENT,
    "name"          TEXT NOT NULL UNIQUE,
    "display_order" INTEGER NOT NULL DEFAULT 0,
    "created_at"    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS "artists" (
    "id"                 INTEGER PRIMARY KEY AUTOINCREMENT,
    "uuid"               TEXT UNIQUE,
    "name_noob"          TEXT,
    "name_nai"           TEXT,
    "name_cn"            TEXT,
    "danbooru_link"      TEXT,
    "post_count"         INTEGER,
    "example_post_id"    INTEGER,
    "example_image_url"  TEXT,
    "example_image_path" TEXT,
    "notes"              TEXT,
    "tags"               TEXT,
    "style"              TEXT,
    "skip_danbooru"      INTEGER NOT NULL DEFAULT 0,
    "fetched_at"         DATETIME,
    "created_at"         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at"         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS "artists_idx_name_noob" ON "artists"("name_noob");
CREATE INDEX IF NOT EXISTS "artists_idx_name_nai" ON "artists"("name_nai");
CREATE INDEX IF NOT EXISTS "artists_idx_post_count" ON "artists"("post_count" DESC);

CREATE TABLE IF NOT EXISTS "artist_category_map" (
    "artist_id"   INTEGER NOT NULL,
    "category_id" INTEGER NOT NULL,
    "created_at"  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY ("artist_id","category_id")
);

-- ===== artist_presets + items =====
CREATE TABLE IF NOT EXISTS "artist_presets" (
    "id"            INTEGER PRIMARY KEY AUTOINCREMENT,
    "title"         TEXT NOT NULL,
    "description"   TEXT,
    "is_favorite"   INTEGER NOT NULL DEFAULT 0,
    "use_count"     INTEGER NOT NULL DEFAULT 0,
    "last_used_at"  DATETIME,
    "created_at"    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at"    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS "artist_preset_items" (
    "id"            INTEGER PRIMARY KEY AUTOINCREMENT,
    "preset_id"     INTEGER NOT NULL,
    "artist_id"     INTEGER NOT NULL,
    "weight"        REAL NOT NULL DEFAULT 1.0,
    "display_order" INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY ("preset_id") REFERENCES "artist_presets"("id") ON DELETE CASCADE,
    FOREIGN KEY ("artist_id") REFERENCES "artists"("id") ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS "api_idx_preset" ON "artist_preset_items"("preset_id");
"#;
