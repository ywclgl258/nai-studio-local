<?php
/**
 * NAI Studio - Encryption for API key storage
 * Uses AES-256-GCM with a derived key.
 */

declare(strict_types=1);

namespace NaiStudio;

class Encryption {
    /**
     * Encrypt plaintext using AES-256-GCM.
     * Returns base64-encoded: nonce (12) || ciphertext || tag (16)
     */
    public static function encrypt(string $plaintext): string {
        $key = self::key();
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed: ' . openssl_error_string());
        }
        return base64_encode($nonce . $cipher . $tag);
    }

    /** Decrypt base64 blob produced by encrypt(). */
    public static function decrypt(string $blob): ?string {
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 28) return null;
        $key = self::key();
        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, -16);
        $cipher = substr($raw, 12, -16);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '');
        return $plain === false ? null : $plain;
    }

    /** Derive a 32-byte key from the configured secret. */
    private static function key(): string {
        $secret = (string)config('security.encryption_key');
        return hash('sha256', 'nai-studio:' . $secret, true);
    }
}
