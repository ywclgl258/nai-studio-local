-- =============================================================================
-- NAI Studio - Migration 007: Prompt Decomposer + Local translation
-- =============================================================================

USE `nai_studio`;

-- settings: 本地翻译 (LibreTranslate / OPUS-MT 等) 配置 + 非官方 fallback
ALTER TABLE `settings`
    ADD COLUMN `local_translate_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `proxy_tested_at`,
    ADD COLUMN `local_translate_url`     VARCHAR(255) NULL AFTER `local_translate_enabled`,
    ADD COLUMN `local_translate_status`  VARCHAR(64)  NULL AFTER `local_translate_url`,
    ADD COLUMN `local_translate_tested_at` TIMESTAMP NULL AFTER `local_translate_status`,
    -- 非官方 Google 翻译 fallback（高风险，详见 Translator.php）
    ADD COLUMN `aggressive_fallback_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `local_translate_tested_at`;
