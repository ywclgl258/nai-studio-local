-- ============================================================================
-- NAI Studio - SQLite Schema (从 MariaDB 转换)
-- 转换规则：
--   * int(N) unsigned    → INTEGER
--   * tinyint(1)         → INTEGER (0/1)
--   * varchar/char/text  → TEXT
--   * decimal(N,M)       → REAL
--   * enum(...)          → TEXT CHECK(col IN (...))
--   * timestamp          → DATETIME
--   * AUTO_INCREMENT     → INTEGER PRIMARY KEY AUTOINCREMENT
--   * ENGINE=...         → 删除
--   * KEY (col)          → CREATE INDEX ...
--   * UNIQUE KEY (col)   → UNIQUE(col) 内联
--   * CHECK (json_valid) → 保留（SQLite 也支持）
-- ============================================================================

-- 19 张表（按依赖顺序排列）
-- 注意：外键依赖的表（artists/artist_categories）放在引用方之前

-- ============== api_logs ==============
CREATE TABLE "api_logs" (
    "id"              INTEGER PRIMARY KEY AUTOINCREMENT,
    "endpoint"        TEXT NOT NULL,
    "method"          TEXT NOT NULL,
    "status_code"     INTEGER NOT NULL,
    "request_summary" TEXT,
    "response_summary" TEXT,
    "duration_ms"     INTEGER,
    "error"           TEXT,
    "created_at"      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX "api_logs_idx_created" ON "api_logs"("created_at");
CREATE INDEX "api_logs_idx_endpoint" ON "api_logs"("endpoint","created_at");

-- ============== artist_categories ==============
CREATE TABLE "artist_categories" (
    "id"            INTEGER PRIMARY KEY AUTOINCREMENT,
    "name"          TEXT NOT NULL UNIQUE,
    "display_order" INTEGER NOT NULL DEFAULT 0,
    "created_at"    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX "artist_categories_idx_order" ON "artist_categories"("display_order");

-- ============== artists ==============
CREATE TABLE "artists" (
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
CREATE INDEX "artists_idx_name_noob" ON "artists"("name_noob");
CREATE INDEX "artists_idx_name_nai" ON "artists"("name_nai");
CREATE INDEX "artists_idx_post_count" ON "artists"("post_count");
CREATE INDEX "artists_idx_style" ON "artists"("style");

-- ============== artist_category_map ==============
CREATE TABLE "artist_category_map" (
    "artist_id"   INTEGER NOT NULL,
    "category_id" INTEGER NOT NULL,
    "created_at"  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY ("artist_id","category_id"),
    FOREIGN KEY ("artist_id") REFERENCES "artists"("id") ON DELETE CASCADE,
    FOREIGN KEY ("category_id") REFERENCES "artist_categories"("id") ON DELETE CASCADE
);
CREATE INDEX "artist_category_map_idx_category" ON "artist_category_map"("category_id");

-- ============== artist_presets ==============
CREATE TABLE "artist_presets" (
    "id"          INTEGER PRIMARY KEY AUTOINCREMENT,
    "name"        TEXT NOT NULL UNIQUE,
    "description" TEXT,
    "noob_text"   TEXT,
    "nai_text"    TEXT,
    "category_id" INTEGER,
    "use_count"   INTEGER NOT NULL DEFAULT 0,
    "is_favorite" INTEGER NOT NULL DEFAULT 0,
    "created_at"  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at"  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX "artist_presets_idx_use_count" ON "artist_presets"("use_count");
CREATE INDEX "artist_presets_idx_favorite" ON "artist_presets"("is_favorite");

-- ============== artist_preset_items ==============
CREATE TABLE "artist_preset_items" (
    "preset_id" INTEGER NOT NULL,
    "artist_id" INTEGER NOT NULL,
    "weight"    REAL NOT NULL DEFAULT 1.00,
    "position"  INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY ("preset_id","artist_id"),
    FOREIGN KEY ("artist_id") REFERENCES "artists"("id") ON DELETE CASCADE,
    FOREIGN KEY ("preset_id") REFERENCES "artist_presets"("id") ON DELETE CASCADE
);
CREATE INDEX "artist_preset_items_idx_artist" ON "artist_preset_items"("artist_id");

-- ============== character_presets ==============
CREATE TABLE "character_presets" (
    "id"         INTEGER PRIMARY KEY AUTOINCREMENT,
    "name"       TEXT NOT NULL,
    "gender"     TEXT NOT NULL DEFAULT 'female' CHECK("gender" IN ('female','male','other')),
    "prompt"     TEXT NOT NULL,
    "position_x" REAL NOT NULL DEFAULT 0.50,
    "position_y" REAL NOT NULL DEFAULT 0.50,
    "is_favorite" INTEGER NOT NULL DEFAULT 0,
    "use_count"  INTEGER NOT NULL DEFAULT 0,
    "created_at" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX "character_presets_idx_favorite_used" ON "character_presets"("is_favorite","use_count");
CREATE INDEX "character_presets_idx_name" ON "character_presets"("name");

-- ============== danbooru_tag_cache ==============
CREATE TABLE "danbooru_tag_cache" (
    "id"                 INTEGER PRIMARY KEY AUTOINCREMENT,
    "name"               TEXT NOT NULL UNIQUE,
    "cn_name"            TEXT,
    "category"           INTEGER NOT NULL DEFAULT 0,
    "post_count"         INTEGER NOT NULL DEFAULT 0,
    "example_post_id"    INTEGER,
    "example_image_url"  TEXT,
    "fetched_at"         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "translated_at"      DATETIME
);
CREATE INDEX "danbooru_tag_cache_idx_post_count" ON "danbooru_tag_cache"("post_count","fetched_at");
CREATE INDEX "danbooru_tag_cache_idx_translated_at" ON "danbooru_tag_cache"("translated_at");

-- ============== generations ==============
CREATE TABLE "generations" (
    "id"             INTEGER PRIMARY KEY AUTOINCREMENT,
    "batch_id"       TEXT,
    "parent_id"      INTEGER,
    "operation"      TEXT NOT NULL DEFAULT 'generate' CHECK("operation" IN ('generate','img2img','inpaint','vibe','director')),
    "prompt"         TEXT NOT NULL,
    "negative_prompt" TEXT,
    "model"          TEXT NOT NULL,
    "sampler"        TEXT NOT NULL,
    "steps"          INTEGER NOT NULL,
    "scale"          REAL NOT NULL,
    "seed"           INTEGER NOT NULL,
    "width"          INTEGER NOT NULL,
    "height"         INTEGER NOT NULL,
    "cfg_rescale"    REAL NOT NULL DEFAULT 0.00,
    "noise_schedule" TEXT NOT NULL DEFAULT 'karras',
    "uc_preset"      INTEGER NOT NULL DEFAULT 0,
    "quality_toggle" INTEGER NOT NULL DEFAULT 1,
    "characters_json" TEXT CHECK(json_valid("characters_json") OR "characters_json" IS NULL),
    "vibe_refs_json"  TEXT CHECK(json_valid("vibe_refs_json") OR "vibe_refs_json" IS NULL),
    "precise_refs_json" TEXT CHECK(json_valid("precise_refs_json") OR "precise_refs_json" IS NULL),
    "strength"       REAL,
    "noise"          REAL,
    "image_path"     TEXT,
    "thumbnail_path" TEXT,
    "image_size_bytes" INTEGER,
    "image_width"    INTEGER,
    "image_height"   INTEGER,
    "meta_json"      TEXT CHECK(json_valid("meta_json") OR "meta_json" IS NULL),
    "anlas_cost"     INTEGER,
    "is_favorite"    INTEGER NOT NULL DEFAULT 0,
    "is_deleted"     INTEGER NOT NULL DEFAULT 0,
    "notes"          TEXT,
    "created_at"     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX "generations_idx_created" ON "generations"("created_at");
CREATE INDEX "generations_idx_batch" ON "generations"("batch_id");
CREATE INDEX "generations_idx_favorite_created" ON "generations"("is_favorite","created_at");
CREATE INDEX "generations_idx_model_created" ON "generations"("model","created_at");
CREATE INDEX "generations_idx_seed" ON "generations"("seed");

-- ============== nai_api_keys ==============
CREATE TABLE "nai_api_keys" (
    "id"                  INTEGER PRIMARY KEY AUTOINCREMENT,
    "label"               TEXT,
    "api_key_encrypted"   TEXT NOT NULL,
    "api_key_fingerprint" TEXT NOT NULL,
    "enabled"             INTEGER NOT NULL DEFAULT 1,
    "sort_order"          INTEGER NOT NULL DEFAULT 0,
    "last_used_at"        DATETIME,
    "last_error_code"     INTEGER,
    "last_error_msg"      TEXT,
    "last_error_at"       DATETIME,
    "fail_count"          INTEGER NOT NULL DEFAULT 0,
    "created_at"          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX "nai_api_keys_idx_enabled_order" ON "nai_api_keys"("enabled","sort_order");

-- ============== pose_presets ==============
CREATE TABLE "pose_presets" (
    "id"         INTEGER PRIMARY KEY AUTOINCREMENT,
    "name"       TEXT NOT NULL,
    "prompt"     TEXT NOT NULL,
    "category"   TEXT,
    "is_favorite" INTEGER NOT NULL DEFAULT 0,
    "use_count"  INTEGER NOT NULL DEFAULT 0,
    "created_at" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX "pose_presets_idx_category_used" ON "pose_presets"("category","is_favorite","use_count");
CREATE INDEX "pose_presets_idx_name" ON "pose_presets"("name");

-- ============== precise_refs ==============
CREATE TABLE "precise_refs" (
    "id"              INTEGER PRIMARY KEY AUTOINCREMENT,
    "name"            TEXT,
    "type"            TEXT NOT NULL DEFAULT 'character' CHECK("type" IN ('character','style')),
    "image_path"      TEXT NOT NULL,
    "thumbnail_path"  TEXT,
    "extracted_info"  TEXT CHECK(json_valid("extracted_info") OR "extracted_info" IS NULL),
    "use_count"       INTEGER NOT NULL DEFAULT 0,
    "created_at"      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX "precise_refs_idx_type_used" ON "precise_refs"("type","use_count","created_at");

-- ============== prompts ==============
CREATE TABLE "prompts" (
    "id"            INTEGER PRIMARY KEY AUTOINCREMENT,
    "title"         TEXT NOT NULL,
    "description"   TEXT,
    "positive"      TEXT NOT NULL,
    "negative"      TEXT,
    "tags_json"     TEXT CHECK(json_valid("tags_json") OR "tags_json" IS NULL),
    "model"         TEXT,
    "size"          TEXT,
    "uc_preset"     INTEGER,
    "is_favorite"   INTEGER NOT NULL DEFAULT 0,
    "use_count"     INTEGER NOT NULL DEFAULT 0,
    "last_used_at"  DATETIME,
    "created_at"    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at"    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX "prompts_idx_favorite_used" ON "prompts"("is_favorite","last_used_at");
CREATE INDEX "prompts_idx_title" ON "prompts"("title");

-- ============== settings ==============
CREATE TABLE "settings" (
    "id"                        INTEGER NOT NULL DEFAULT 1,
    "api_key_encrypted"         TEXT,
    "api_key_fingerprint"       TEXT,
    "default_model"             TEXT NOT NULL DEFAULT 'nai-diffusion-4-5-curated',
    "default_sampler"           TEXT NOT NULL DEFAULT 'k_euler_ancestral',
    "default_steps"             INTEGER NOT NULL DEFAULT 28,
    "default_scale"             REAL NOT NULL DEFAULT 5.00,
    "default_cfg_rescale"       REAL NOT NULL DEFAULT 0.00,
    "default_noise_schedule"    TEXT NOT NULL DEFAULT 'karras',
    "default_size"              TEXT NOT NULL DEFAULT '832x1216',
    "default_uc_preset"         INTEGER NOT NULL DEFAULT 0,
    "quality_toggle"            INTEGER NOT NULL DEFAULT 1,
    "emphasis_highlight"        INTEGER NOT NULL DEFAULT 1,
    "theme"                     TEXT NOT NULL DEFAULT 'dark',
    "proxy_enabled"             INTEGER NOT NULL DEFAULT 0,
    "proxy_url"                 TEXT,
    "proxy_test_status"         TEXT,
    "proxy_tested_at"           DATETIME,
    "local_translate_enabled"   INTEGER NOT NULL DEFAULT 0,
    "local_translate_url"       TEXT,
    "local_translate_status"    TEXT,
    "local_translate_tested_at" DATETIME,
    "aggressive_fallback_enabled" INTEGER NOT NULL DEFAULT 0,
    "danbooru_username"         TEXT,
    "danbooru_api_key"          TEXT,
    "deepseek_api_key"          TEXT,
    "deepseek_model"            TEXT NOT NULL DEFAULT 'deepseek-chat',
    "deepseek_base_url"         TEXT NOT NULL DEFAULT 'https://api.deepseek.com/v1',
    "deepseek_status"           TEXT,
    "deepseek_tested_at"        DATETIME,
    "ai_advisor_enabled"        INTEGER NOT NULL DEFAULT 0,
    "ai_provider"               TEXT NOT NULL DEFAULT 'deepseek',
    "ai_base_url"               TEXT,
    "ai_api_key"                TEXT,
    "ai_model"                  TEXT,
    "ai_reasoning_effort"       TEXT,
    "ui_state"                  TEXT CHECK(json_valid("ui_state") OR "ui_state" IS NULL),
    "anlas_balance"             INTEGER,
    "anlas_updated_at"          DATETIME,
    "created_at"                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at"                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY ("id")
);
CREATE INDEX "settings_idx_ai_provider" ON "settings"("ai_provider");

-- ============== tag_aliases ==============
CREATE TABLE "tag_aliases" (
    "id"       INTEGER PRIMARY KEY AUTOINCREMENT,
    "tag_id"   INTEGER NOT NULL,
    "alias"    TEXT NOT NULL UNIQUE,
    "alias_cn" TEXT
);
CREATE INDEX "tag_aliases_idx_tag" ON "tag_aliases"("tag_id");

-- ============== tag_categories ==============
CREATE TABLE "tag_categories" (
    "id"           INTEGER PRIMARY KEY AUTOINCREMENT,
    "slug"         TEXT NOT NULL UNIQUE,
    "name"         TEXT NOT NULL,
    "name_cn"      TEXT,
    "description"  TEXT,
    "display_order" INTEGER NOT NULL DEFAULT 0,
    "tag_count"    INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX "tag_categories_idx_order" ON "tag_categories"("display_order");

-- ============== tag_prompts ==============
CREATE TABLE "tag_prompts" (
    "tag_id"    INTEGER NOT NULL,
    "prompt_id" INTEGER NOT NULL,
    "weight"    REAL NOT NULL DEFAULT 1.00,
    PRIMARY KEY ("tag_id","prompt_id")
);
CREATE INDEX "tag_prompts_idx_prompt" ON "tag_prompts"("prompt_id");

-- ============== tags ==============
CREATE TABLE "tags" (
    "id"                INTEGER PRIMARY KEY AUTOINCREMENT,
    "name"              TEXT NOT NULL UNIQUE,
    "category_id"       INTEGER NOT NULL,
    "cn_name"           TEXT,
    "post_count"        INTEGER NOT NULL DEFAULT 0,
    "example_image_url" TEXT,
    "aliases"           TEXT,
    "description"       TEXT,
    "is_nsfw"           INTEGER NOT NULL DEFAULT 0,
    "created_at"        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "fetched_at"        DATETIME
);
CREATE INDEX "tags_idx_category_count" ON "tags"("category_id","post_count");
CREATE INDEX "tags_idx_post_count" ON "tags"("post_count");
CREATE INDEX "tags_idx_cn" ON "tags"("cn_name");
CREATE INDEX "tags_idx_with_img" ON "tags"("post_count","example_image_url");

-- ============== vibe_refs ==============
CREATE TABLE "vibe_refs" (
    "id"             INTEGER PRIMARY KEY AUTOINCREMENT,
    "name"           TEXT,
    "image_path"     TEXT NOT NULL,
    "thumbnail_path" TEXT,
    "extracted_info" TEXT CHECK(json_valid("extracted_info") OR "extracted_info" IS NULL),
    "use_count"      INTEGER NOT NULL DEFAULT 0,
    "created_at"     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX "vibe_refs_idx_use_count" ON "vibe_refs"("use_count","created_at");