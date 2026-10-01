<?php
declare(strict_types=1);

/**
 * Symmetric encryption for secrets stored in the database (the Google refresh token).
 * AES-256-GCM with the key from config.php's encryption_key_hex.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    public static function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed');
        }
        return base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('Malformed ciphertext');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed (wrong encryption_key_hex?)');
        }
        return $plaintext;
    }

    private static function key(): string
    {
        $hex = (string)(lagohm_config()['encryption_key_hex'] ?? '');
        if (!preg_match('/^[0-9a-f]{64}$/i', $hex)) {
            throw new RuntimeException('encryption_key_hex in config.php must be 64 hex characters');
        }
        return hex2bin($hex);
    }
}
