<?php
declare(strict_types=1);

// App configuration. This file holds NO secrets and is safe to commit.
// Values are read from environment variables, or from a local PHP file that
// returns an array (path: UIN_CONFIG_FILE, default: ../uin-mail.local.php,
// i.e. next to the project, outside Git). Environment wins over the file.

$localFile = getenv('UIN_CONFIG_FILE') ?: dirname(__DIR__) . '/uin-mail.local.php';
$local = is_file($localFile) ? require $localFile : [];
if (!is_array($local)) {
    $local = [];
}

// Read one setting (env first, then local file, then default).
$get = static function (string $key, ?string $default = null) use ($local): ?string {
    $env = getenv($key);
    if ($env !== false && $env !== '') {
        return $env;
    }
    if (isset($local[$key]) && $local[$key] !== '') {
        return (string)$local[$key];
    }
    return $default;
};

// Fail with a generic message; the real reason goes to the server log only.
$fail = static function (string $reason): never {
    error_log('UIN-Mail config error: ' . $reason);
    throw new RuntimeException('Server configuration error');
};

// Required setting.
$need = static function (string $key) use ($get, $fail): string {
    $value = $get($key);
    if ($value === null) {
        $fail("missing $key");
    }
    return $value;
};

// Base64 key that must decode to exactly 32 raw bytes (256 bit).
$key32 = static function (string $key) use ($need, $fail): string {
    $raw = base64_decode($need($key), true);
    if ($raw === false || strlen($raw) !== 32) {
        $fail("$key must be base64 of 32 random bytes");
    }
    return $raw;
};

$encKey  = $key32('UIN_ENC_KEY');   // AES-256-GCM key
$hmacKey = $key32('UIN_HMAC_KEY');  // HMAC key for email_hash
if (hash_equals($encKey, $hmacKey)) {
    $fail('UIN_ENC_KEY and UIN_HMAC_KEY must differ');
}

return [
    'db' => [
        'host'    => $get('DB_HOST', 'localhost'),
        'port'    => (int)$get('DB_PORT', '3306'),
        'name'    => $need('DB_NAME'),
        'user'    => $need('DB_USER'),
        'pass'    => $get('DB_PASS', ''),   // may be empty on a local dev server
        'charset' => 'utf8mb4',
    ],
    'enc_key'         => $encKey,   // raw 32 bytes
    'hmac_key'        => $hmacKey,  // raw 32 bytes
    'session_timeout' => 1200,      // seconds of inactivity (20 min)
    'online_window'   => 300,       // seconds for "online" status (5 min)
    'upload_dir'      => $get('UIN_UPLOAD_DIR', __DIR__ . '/uploads'),
    'photo_width'     => 800,
    'photo_quality'   => 90,
];
