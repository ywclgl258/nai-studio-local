-- =============================================================================
-- NAI Studio - Migration 010: 通用 AI Provider (DeepSeek + OpenAI 兼容)
-- =============================================================================
-- 设计：
--   - ai_provider: 预设 provider 标识（deepseek / openai / siliconflow / ollama / custom）
--   - ai_base_url: API 基础 URL（provider 自动填默认值，可手改）
--   - ai_api_key:  API key（Ollama 本地不用）
--   - ai_model:    模型名（手填或下拉选）
--   - ai_reasoning_effort: 推理强度（low/medium/high，OpenAI o1/o3 系列）
-- 旧字段 deepseek_* 保留向后兼容，读取时优先用新字段（如果 ai_provider 存在）
-- =============================================================================

USE `nai_studio`;

ALTER TABLE `settings`
    ADD COLUMN `ai_provider`           VARCHAR(32)  NOT NULL DEFAULT 'deepseek' AFTER `ai_advisor_enabled`,
    ADD COLUMN `ai_base_url`           VARCHAR(255) NULL AFTER `ai_provider`,
    ADD COLUMN `ai_api_key`            VARCHAR(255) NULL AFTER `ai_base_url`,
    ADD COLUMN `ai_model`              VARCHAR(64)  NULL AFTER `ai_api_key`,
    ADD COLUMN `ai_reasoning_effort`   VARCHAR(16)  NULL AFTER `ai_model`,
    ADD KEY `idx_ai_provider` (`ai_provider`);

-- 同步老字段值到新字段（向后兼容）
UPDATE `settings`
SET
    ai_base_url = deepseek_base_url,
    ai_api_key  = deepseek_api_key,
    ai_model    = deepseek_model
WHERE id = 1 AND ai_base_url IS NULL;
