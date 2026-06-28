-- =============================================================================
-- NAI Studio - Database Schema
-- =============================================================================
-- Charset: utf8mb4 (full Unicode incl. emoji, Chinese)
-- Engine:  InnoDB (transactions, FKs)
-- Note:    ngram plugin not available in bundled MariaDB; using LIKE + regular
--          FULLTEXT (good enough for short tag names and our scale).
-- =============================================================================

CREATE DATABASE IF NOT EXISTS `nai_studio`
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE `nai_studio`;

-- =============================================================================
-- settings: singleton row storing user preferences and encrypted API key
-- =============================================================================
CREATE TABLE IF NOT EXISTS `settings` (
    `id`                            TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `api_key_encrypted`             TEXT NULL,
    `api_key_fingerprint`           VARCHAR(16) NULL COMMENT 'Last 4 chars of key for display',
    `default_model`                 VARCHAR(64) NOT NULL DEFAULT 'nai-diffusion-4-5-curated',
    `default_sampler`               VARCHAR(64) NOT NULL DEFAULT 'k_euler_ancestral',
    `default_steps`                 INT NOT NULL DEFAULT 28,
    `default_scale`                 DECIMAL(4,2) NOT NULL DEFAULT 5.00,
    `default_cfg_rescale`           DECIMAL(4,2) NOT NULL DEFAULT 0.00,
    `default_noise_schedule`        VARCHAR(32) NOT NULL DEFAULT 'karras',
    `default_size`                  VARCHAR(16) NOT NULL DEFAULT '832x1216',
    `default_uc_preset`             TINYINT NOT NULL DEFAULT 0,
    `quality_toggle`                TINYINT(1) NOT NULL DEFAULT 1,
    `emphasis_highlight`            TINYINT(1) NOT NULL DEFAULT 1,
    `theme`                         VARCHAR(16) NOT NULL DEFAULT 'dark',
    `ui_state`                      JSON NULL,
    `anlas_balance`                 INT NULL,
    `anlas_updated_at`              TIMESTAMP NULL,
    `created_at`                    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`id`) VALUES (1) ON DUPLICATE KEY UPDATE `id` = 1;

-- =============================================================================
-- tag_categories
-- =============================================================================
CREATE TABLE IF NOT EXISTS `tag_categories` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug`          VARCHAR(32) NOT NULL,
    `name`          VARCHAR(64) NOT NULL,
    `name_cn`       VARCHAR(64) NULL,
    `description`   VARCHAR(255) NULL,
    `display_order` INT NOT NULL DEFAULT 0,
    `tag_count`     INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_slug` (`slug`),
    KEY `idx_order` (`display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- tags
-- =============================================================================
CREATE TABLE IF NOT EXISTS `tags` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(128) NOT NULL,
    `category_id`   INT UNSIGNED NOT NULL,
    `cn_name`       VARCHAR(128) NULL,
    `post_count`    INT NOT NULL DEFAULT 0,
    `aliases`       TEXT NULL COMMENT 'JSON array of alias names',
    `description`   VARCHAR(512) NULL,
    `is_nsfw`       TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_name` (`name`),
    KEY `idx_category_count` (`category_id`, `post_count` DESC),
    KEY `idx_post_count` (`post_count` DESC),
    KEY `idx_cn` (`cn_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- prompts
-- =============================================================================
CREATE TABLE IF NOT EXISTS `prompts` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`         VARCHAR(255) NOT NULL,
    `description`   VARCHAR(512) NULL,
    `positive`      MEDIUMTEXT NOT NULL,
    `negative`      MEDIUMTEXT NULL,
    `tags_json`     JSON NULL,
    `model`         VARCHAR(64) NULL,
    `size`          VARCHAR(16) NULL,
    `uc_preset`     TINYINT NULL,
    `is_favorite`   TINYINT(1) NOT NULL DEFAULT 0,
    `use_count`     INT NOT NULL DEFAULT 0,
    `last_used_at`  TIMESTAMP NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_favorite_used` (`is_favorite` DESC, `last_used_at` DESC),
    KEY `idx_title` (`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- generations
-- =============================================================================
CREATE TABLE IF NOT EXISTS `generations` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `batch_id`          VARCHAR(32) NULL,
    `parent_id`         INT UNSIGNED NULL,
    `operation`         ENUM('generate','img2img','inpaint','vibe','director') NOT NULL DEFAULT 'generate',
    `prompt`            MEDIUMTEXT NOT NULL,
    `negative_prompt`   MEDIUMTEXT NULL,
    `model`             VARCHAR(64) NOT NULL,
    `sampler`           VARCHAR(64) NOT NULL,
    `steps`             INT NOT NULL,
    `scale`             DECIMAL(4,2) NOT NULL,
    `seed`              BIGINT NOT NULL,
    `width`             INT NOT NULL,
    `height`            INT NOT NULL,
    `cfg_rescale`       DECIMAL(4,2) NOT NULL DEFAULT 0.00,
    `noise_schedule`    VARCHAR(32) NOT NULL DEFAULT 'karras',
    `uc_preset`         TINYINT NOT NULL DEFAULT 0,
    `quality_toggle`    TINYINT(1) NOT NULL DEFAULT 1,
    `characters_json`   JSON NULL,
    `vibe_refs_json`    JSON NULL,
    `precise_refs_json` JSON NULL,
    `strength`          DECIMAL(3,2) NULL,
    `noise`             DECIMAL(3,2) NULL,
    `image_path`        VARCHAR(500) NULL,
    `thumbnail_path`    VARCHAR(500) NULL,
    `image_size_bytes`  INT NULL,
    `image_width`       INT NULL,
    `image_height`      INT NULL,
    `meta_json`         JSON NULL,
    `anlas_cost`        INT NULL,
    `is_favorite`       TINYINT(1) NOT NULL DEFAULT 0,
    `is_deleted`        TINYINT(1) NOT NULL DEFAULT 0,
    `notes`             VARCHAR(1000) NULL,
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_created` (`created_at` DESC),
    KEY `idx_batch` (`batch_id`),
    KEY `idx_favorite_created` (`is_favorite` DESC, `created_at` DESC),
    KEY `idx_model_created` (`model`, `created_at` DESC),
    KEY `idx_seed` (`seed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- vibe_refs
-- =============================================================================
CREATE TABLE IF NOT EXISTS `vibe_refs` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(255) NULL,
    `image_path`        VARCHAR(500) NOT NULL,
    `thumbnail_path`    VARCHAR(500) NULL,
    `extracted_info`    JSON NULL,
    `use_count`         INT NOT NULL DEFAULT 0,
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_use_count` (`use_count` DESC, `created_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- precise_refs
-- =============================================================================
CREATE TABLE IF NOT EXISTS `precise_refs` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(255) NULL,
    `type`              ENUM('character','style') NOT NULL DEFAULT 'character',
    `image_path`        VARCHAR(500) NOT NULL,
    `thumbnail_path`    VARCHAR(500) NULL,
    `extracted_info`    JSON NULL,
    `use_count`         INT NOT NULL DEFAULT 0,
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_type_used` (`type`, `use_count` DESC, `created_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- tag_prompts: many-to-many link
-- =============================================================================
CREATE TABLE IF NOT EXISTS `tag_prompts` (
    `tag_id`        INT UNSIGNED NOT NULL,
    `prompt_id`     INT UNSIGNED NOT NULL,
    `weight`        DECIMAL(4,2) NOT NULL DEFAULT 1.00,
    PRIMARY KEY (`tag_id`, `prompt_id`),
    KEY `idx_prompt` (`prompt_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- tag_aliases
-- =============================================================================
CREATE TABLE IF NOT EXISTS `tag_aliases` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tag_id`        INT UNSIGNED NOT NULL,
    `alias`         VARCHAR(128) NOT NULL,
    `alias_cn`      VARCHAR(128) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_alias` (`alias`),
    KEY `idx_tag` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- api_logs
-- =============================================================================
CREATE TABLE IF NOT EXISTS `api_logs` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `endpoint`          VARCHAR(255) NOT NULL,
    `method`            VARCHAR(8) NOT NULL,
    `status_code`       INT NOT NULL,
    `request_summary`   VARCHAR(500) NULL,
    `response_summary`  VARCHAR(500) NULL,
    `duration_ms`       INT NULL,
    `error`             TEXT NULL,
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_created` (`created_at` DESC),
    KEY `idx_endpoint` (`endpoint`, `created_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
