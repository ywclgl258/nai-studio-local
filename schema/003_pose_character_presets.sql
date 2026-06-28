-- =============================================================================
-- NAI Studio - Migration 003: Character & Pose presets
-- =============================================================================

USE `nai_studio`;

-- =============================================================================
-- character_presets: V4+ 角色预设
-- =============================================================================
CREATE TABLE IF NOT EXISTS `character_presets` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(255) NOT NULL,
    `gender`        ENUM('female','male','other') NOT NULL DEFAULT 'female',
    `prompt`        MEDIUMTEXT NOT NULL,
    `position_x`    DECIMAL(4,2) NOT NULL DEFAULT 0.5,
    `position_y`    DECIMAL(4,2) NOT NULL DEFAULT 0.5,
    `is_favorite`   TINYINT(1) NOT NULL DEFAULT 0,
    `use_count`     INT NOT NULL DEFAULT 0,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_favorite_used` (`is_favorite` DESC, `use_count` DESC),
    KEY `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- pose_presets: 姿势提示词预设
-- =============================================================================
CREATE TABLE IF NOT EXISTS `pose_presets` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(255) NOT NULL,
    `prompt`        MEDIUMTEXT NOT NULL,
    `category`      VARCHAR(64) NULL COMMENT 'standing/sitting/lying/action/expression/custom',
    `is_favorite`   TINYINT(1) NOT NULL DEFAULT 0,
    `use_count`     INT NOT NULL DEFAULT 0,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_category_used` (`category`, `is_favorite` DESC, `use_count` DESC),
    KEY `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- danbooru_tag_cache: Danbooru API 缓存（在线搜索用）
-- =============================================================================
CREATE TABLE IF NOT EXISTS `danbooru_tag_cache` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(128) NOT NULL,
    `category`      INT NOT NULL DEFAULT 0 COMMENT '0=general, 1=artist, 3=copyright, 4=character, 5=meta',
    `post_count`    INT NOT NULL DEFAULT 0,
    `example_post_id` INT NULL,
    `example_image_url` VARCHAR(500) NULL,
    `fetched_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_name` (`name`),
    KEY `idx_post_count` (`post_count` DESC, `fetched_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- Seed: 不再自动写入姿势/角色预设
-- 用户可以自己在 UI 里保存自己常用的姿势和角色
-- =============================================================================
