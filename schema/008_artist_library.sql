-- =============================================================================
-- NAI Studio - Migration 008: 画师库 (Artist Library) + 画师串预设
--
-- 借鉴 Monxia 的数据模型：
--   artists         - 画师主表（NOOB/NAI 双格式 + Danbooru 链接 + 备注 + 示例图）
--   artist_categories - 分类表（多对多关联）
--   artist_category_map - 画师-分类关联
--   artist_presets  - 画师串预设（NOOB/NAI 双格式）
-- =============================================================================

USE `nai_studio`;

-- 画师分类
CREATE TABLE IF NOT EXISTS `artist_categories` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(64) NOT NULL,
    `display_order` INT NOT NULL DEFAULT 0,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_name` (`name`),
    KEY `idx_order` (`display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 画师主表
CREATE TABLE IF NOT EXISTS `artists` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`            CHAR(36) NULL COMMENT '唯一标识',
    `name_noob`       VARCHAR(128) NULL COMMENT 'NOOB AI 格式（带 artist:xxx）',
    `name_nai`        VARCHAR(128) NULL COMMENT 'NAI 格式（裸名）',
    `name_cn`         VARCHAR(64) NULL COMMENT '中文名（可选）',
    `danbooru_link`   VARCHAR(255) NULL COMMENT 'https://danbooru.donmai.us/posts?tags=artist%3Axxx',
    `post_count`      INT NULL COMMENT 'Danbooru 作品数',
    `example_post_id` INT NULL,
    `example_image_url` VARCHAR(500) NULL,
    `example_image_path` VARCHAR(255) NULL COMMENT '本地缓存路径 /storage/artist_images/xxx.jpg',
    `notes`           TEXT NULL COMMENT '备注/风格描述',
    `tags`            VARCHAR(500) NULL COMMENT 'JSON 数组，自定义标签',
    `style`           VARCHAR(32) NULL COMMENT 'thick_anime / soft_anime / realistic / cinematic / ...',
    `skip_danbooru`   TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否跳过 Danbooru 抓取',
    `fetched_at`      TIMESTAMP NULL COMMENT '最后一次从 Danbooru 抓取时间',
    `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_uuid` (`uuid`),
    KEY `idx_name_noob` (`name_noob`),
    KEY `idx_name_nai` (`name_nai`),
    KEY `idx_post_count` (`post_count` DESC),
    KEY `idx_style` (`style`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 画师-分类关联（多对多）
CREATE TABLE IF NOT EXISTS `artist_category_map` (
    `artist_id`   INT UNSIGNED NOT NULL,
    `category_id` INT UNSIGNED NOT NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`artist_id`, `category_id`),
    KEY `idx_category` (`category_id`),
    CONSTRAINT `fk_acm_artist`  FOREIGN KEY (`artist_id`)   REFERENCES `artists` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_acm_category` FOREIGN KEY (`category_id`) REFERENCES `artist_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 画师串预设
CREATE TABLE IF NOT EXISTS `artist_presets` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(64) NOT NULL,
    `description` VARCHAR(255) NULL,
    `noob_text`   TEXT NULL COMMENT 'NOOB AI 格式',
    `nai_text`    TEXT NULL COMMENT 'NAI 格式',
    `category_id` INT UNSIGNED NULL COMMENT '可选分类',
    `use_count`   INT NOT NULL DEFAULT 0,
    `is_favorite` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_name` (`name`),
    KEY `idx_use_count` (`use_count` DESC),
    KEY `idx_favorite` (`is_favorite` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 预设包含的画师（拆解后存，方便快速加载）
CREATE TABLE IF NOT EXISTS `artist_preset_items` (
    `preset_id` INT UNSIGNED NOT NULL,
    `artist_id` INT UNSIGNED NOT NULL,
    `weight`    DECIMAL(3,2) NOT NULL DEFAULT 1.00,
    `position`  INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`preset_id`, `artist_id`),
    KEY `idx_artist` (`artist_id`),
    CONSTRAINT `fk_api_preset` FOREIGN KEY (`preset_id`) REFERENCES `artist_presets` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_api_artist` FOREIGN KEY (`artist_id`) REFERENCES `artists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 默认分类种子
INSERT IGNORE INTO `artist_categories` (`id`, `name`, `display_order`) VALUES
    (1, '未分类', 99),
    (2, '厚涂二次元', 1),
    (3, '软萌二次元', 2),
    (4, '写实派', 3),
    (5, '电影感', 4),
    (6, '插画风', 5),
    (7, '黑暗系', 6),
    (8, '经典派', 7);

-- Danbooru API key 配置（从 settings 表里取也支持，这里单独存一份冗余）
ALTER TABLE `settings`
    ADD COLUMN `danbooru_username` VARCHAR(64) NULL AFTER `aggressive_fallback_enabled`,
    ADD COLUMN `danbooru_api_key`   VARCHAR(128) NULL AFTER `danbooru_username`;
