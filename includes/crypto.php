<?php
declare(strict_types=1);

// Encryption of personal data that must be readable later
// (email, phone, message subject and body) with AES-256-GCM,
// plus HMAC-SHA256 of the email for the UNIQUE email_hash column.
// Stored format: iv (12 bytes) || tag (16 bytes) || ciphertext.
// Include with require_once.

const CRYPTO_CIPHER  = 'aes-256-gcm';
const CRYPTO_IV_LEN  = 12; // recommended IV length for GCM
const CRYPTO_TAG_LEN = 16; // full-length authentication tag

// Internal helper: returns a raw 32-byte key from config (loaded once).
function crypto_key(string $name): string
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/../config.php';
    }
    return $cfg[$name];
}

// Internal helper: log the reason, show the user only a generic error.
function crypto_fail(string $reason): never
{
    error_log('UIN-Mail crypto: ' . $reason);
    throw new RuntimeException('Internal error');
}

// Encrypts a string; returns binary iv||tag||ciphertext for a VARBINARY/BLOB column.
function encrypt(string $plain): string
{
    $iv  = random_bytes(CRYPTO_IV_LEN); // new random IV for every record
    $tag = '';

    $cipher = openssl_encrypt(
        $plain, CRYPTO_CIPHER, crypto_key('enc_key'),
        OPENSSL_RAW_DATA, $iv, $tag, '', CRYPTO_TAG_LEN
    );
    if ($cipher === false) {
        crypto_fail('encryption failed');
    }

    return $iv . $tag . $cipher;
}

// Decrypts a blob made by encrypt(); throws if it is damaged or was modified.
function decrypt(string $blob): string
{
    if (strlen($blob) < CRYPTO_IV_LEN + CRYPTO_TAG_LEN) {
        crypto_fail('blob too short');
    }

    $iv     = substr($blob, 0, CRYPTO_IV_LEN);
    $tag    = substr($blob, CRYPTO_IV_LEN, CRYPTO_TAG_LEN);
    $cipher = substr($blob, CRYPTO_IV_LEN + CRYPTO_TAG_LEN);

    $plain = openssl_decrypt(
        $cipher, CRYPTO_CIPHER, crypto_key('enc_key'),
        OPENSSL_RAW_DATA, $iv, $tag
    );
    if ($plain === false) {
        crypto_fail('authentication failed (wrong key or modified data)');
    }

    return $plain;
}

// Returns a 64-char hex HMAC-SHA256 of the normalized email (for email_hash).
function hmac_email(string $email): string
{
    // Same address in any letter case must give the same hash.
    $normalized = mb_strtolower(trim($email), 'UTF-8');
    return hash_hmac('sha256', $normalized, crypto_key('hmac_key'));
}
