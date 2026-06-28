-- =============================================================================
-- NAI Studio - Migration 006: Multi API key support
-- =============================================================================
USE `nai_studio`;

CREATE TABLE IF NOT EXISTS `nai_api_keys` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `label`                 VARCHAR(64) NULL,
    `api_key_encrypted`     TEXT NOT NULL,
    `api_key_fingerprint`   VARCHAR(8) NOT NULL,
    `enabled`               TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order`            INT NOT NULL DEFAULT 0,
    `last_used_at`          TIMESTAMP NULL,
    `last_error_code`       INT NULL,
    `last_error_msg`        VARCHAR(255) NULL,
    `last_error_at`         TIMESTAMP NULL,
    `fail_count`            INT NOT NULL DEFAULT 0,
    `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_enabled_order` (`enabled`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 把旧的 settings.api_key_encrypted (如果存在) 迁过来作为第一个 key
INSERT INTO `nai_api_keys` (`label`, `api_key_encrypted`, `api_key_fingerprint`, `enabled`, `sort_order`)
SELECT '从旧设置迁移',
       `api_key_encrypted`,
       COALESCE(`api_key_fingerprint`, '----'),
       1,
       0
FROM `settings`
WHERE `id` = 1
  AND `api_key_encrypted` IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `nai_api_keys`);
