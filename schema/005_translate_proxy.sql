-- =============================================================================
-- NAI Studio - Migration 005: Danbooru translation cache + Proxy settings
-- =============================================================================

USE `nai_studio`;

-- 添加翻译字段到 danbooru 标签缓存
ALTER TABLE `danbooru_tag_cache`
    ADD COLUMN `cn_name` VARCHAR(128) NULL AFTER `name`,
    ADD COLUMN `translated_at` TIMESTAMP NULL AFTER `fetched_at`,
    ADD KEY `idx_translated_at` (`translated_at`);

-- settings 表加代理配置
ALTER TABLE `settings`
    ADD COLUMN `proxy_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `theme`,
    ADD COLUMN `proxy_url` VARCHAR(255) NULL AFTER `proxy_enabled`,
    ADD COLUMN `proxy_test_status` VARCHAR(64) NULL AFTER `proxy_url`,
    ADD COLUMN `proxy_tested_at` TIMESTAMP NULL AFTER `proxy_test_status`;