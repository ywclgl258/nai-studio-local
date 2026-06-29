-- =============================================================================
-- NAI Studio - Migration 009: DeepSeek AI 集成
-- =============================================================================

USE `nai_studio`;

ALTER TABLE `settings`
    ADD COLUMN `deepseek_api_key`    VARCHAR(255) NULL AFTER `danbooru_api_key`,
    ADD COLUMN `deepseek_model`      VARCHAR(64)  NOT NULL DEFAULT 'deepseek-chat' AFTER `deepseek_api_key`,
    ADD COLUMN `deepseek_base_url`   VARCHAR(255) NOT NULL DEFAULT 'https://api.deepseek.com/v1' AFTER `deepseek_model`,
    ADD COLUMN `deepseek_status`     VARCHAR(64)  NULL AFTER `deepseek_base_url`,
    ADD COLUMN `deepseek_tested_at`  TIMESTAMP   NULL AFTER `deepseek_status`,
    ADD COLUMN `ai_advisor_enabled`  TINYINT(1)  NOT NULL DEFAULT 0 AFTER `deepseek_tested_at`;
