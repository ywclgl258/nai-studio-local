<?php
/**
 * NaiStudio - API key manager (multi-key rotation)
 *
 * Keys are stored encrypted in nai_api_keys table.
 * Rotation: caller iterates enabled keys in sort_order; on hard failure
 * (401 / 402 / 429 / network) advances to next key.
 */
declare(strict_types=1);

namespace NaiStudio;

class ApiKeyManager {
    /** @return array<int, array{id:int,label:?string,fingerprint:string,enabled:bool,sort_order:int,last_used_at:?string,last_error_code:?int,last_error_msg:?string,last_error_at:?string,fail_count:int,created_at:string}> */
    public static function list(bool $includeDisabled = true): array {
        $sql = "SELECT id, label, api_key_fingerprint AS fingerprint, enabled, sort_order,
                       last_used_at, last_error_code, last_error_msg, last_error_at, fail_count, created_at
                FROM nai_api_keys"
            . ($includeDisabled ? "" : " WHERE enabled = 1")
            . " ORDER BY sort_order ASC, id ASC";
        $rows = Db::pdo()->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(function ($r) {
            $r['enabled'] = (int)$r['enabled'] === 1;
            return $r;
        }, $rows ?: []);
    }

    /** Decrypted keys, enabled only, ordered. */
    public static function getEnabledKeys(): array {
        $rows = Db::pdo()->query(
            "SELECT id, api_key_encrypted, sort_order, label FROM nai_api_keys WHERE enabled = 1 ORDER BY sort_order ASC, id ASC"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $plain = Encryption::decrypt($r['api_key_encrypted']);
            if ($plain) $out[] = ['id' => (int)$r['id'], 'key' => $plain, 'label' => $r['label']];
        }
        return $out;
    }

    /** Add a new key. Returns the new row (fingerprint visible). */
    public static function add(string $key, ?string $label = null): array {
        $key = trim($key);
        if ($key === '') throw new \InvalidArgumentException('Key is empty');
        // 防止重复
        $fp = substr($key, -4);
        $stmt = Db::pdo()->prepare("SELECT id FROM nai_api_keys WHERE api_key_fingerprint = ?");
        $stmt->execute([$fp]);
        if ($stmt->fetchColumn()) {
            throw new \RuntimeException('已存在末四位为 ' . $fp . ' 的 key');
        }
        $maxSort = (int)Db::pdo()->query("SELECT COALESCE(MAX(sort_order), -1) FROM nai_api_keys")->fetchColumn();
        $row = [
            'label'               => $label ?: null,
            'api_key_encrypted'   => Encryption::encrypt($key),
            'api_key_fingerprint' => $fp,
            'enabled'             => 1,
            'sort_order'          => $maxSort + 1,
        ];
        Db::insert('nai_api_keys', $row);
        return self::list()[count(self::list()) - 1] ?? self::list()[0];
    }

    public static function delete(int $id): void {
        Db::pdo()->prepare("DELETE FROM nai_api_keys WHERE id = ?")->execute([$id]);
    }

    public static function setEnabled(int $id, bool $enabled): void {
        Db::update('nai_api_keys', $id, ['enabled' => $enabled ? 1 : 0]);
    }

    public static function setLabel(int $id, ?string $label): void {
        Db::update('nai_api_keys', $id, ['label' => $label ?: null]);
    }

    public static function reorder(array $orderedIds): void {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE nai_api_keys SET sort_order = ? WHERE id = ?");
        foreach ($orderedIds as $i => $id) {
            $stmt->execute([$i, (int)$id]);
        }
        $pdo->commit();
    }

    public static function recordUse(int $id): void {
        Db::pdo()->prepare("UPDATE nai_api_keys SET last_used_at = NOW(), fail_count = 0, last_error_code = NULL, last_error_msg = NULL, last_error_at = NULL WHERE id = ?")
            ->execute([$id]);
    }

    public static function recordError(int $id, int $code, string $msg): void {
        $msg = mb_substr($msg, 0, 250);
        Db::pdo()->prepare(
            "UPDATE nai_api_keys SET last_error_code = ?, last_error_msg = ?, last_error_at = NOW(),
              fail_count = fail_count + 1 WHERE id = ?"
        )->execute([$code, $msg, $id]);
    }

    public static function resetErrors(int $id): void {
        Db::pdo()->prepare("UPDATE nai_api_keys SET fail_count = 0, last_error_code = NULL, last_error_msg = NULL, last_error_at = NULL WHERE id = ?")
            ->execute([$id]);
    }

    public static function count(): int {
        return (int)Db::pdo()->query("SELECT COUNT(*) FROM nai_api_keys")->fetchColumn();
    }
}
