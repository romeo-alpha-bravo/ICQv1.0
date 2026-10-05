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

// How e-mail is delivered: log (development, default), smtp or mail.
$mailMode = $get('UIN_MAIL_MODE', 'log');
if (!in_array($mailMode, ['log', 'smtp', 'mail'], true)) {
    $fail('UIN_MAIL_MODE must be log, smtp or mail');
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
    'timezone'        => $get('UIN_TIMEZONE', 'Europe/Prague'), // display only; DB stays UTC
    // Absolute address of the site, used in e-mailed links. Set from config, never
    // taken from the request, so a forged Host header cannot poison reset links.
    'base_url'        => rtrim((string)$get('UIN_BASE_URL', 'http://localhost:8000'), '/'),
    'mail'            => [
        'mode' => $mailMode,
        'host' => $get('UIN_MAIL_HOST', '127.0.0.1'),   // smtp mode
        'port' => (int)$get('UIN_MAIL_PORT', '1025'),   // smtp mode (Mailpit default)
        'from' => $get('UIN_MAIL_FROM', 'no-reply@icq1.local'),
    ],
    'reset_ttl'       => 1800,      // password reset link lifetime, seconds (30 min)
    'session_timeout' => 1200,      // seconds of inactivity (20 min)
    'online_window'   => 300,       // seconds for "online" status (5 min)
    'upload_dir'      => $get('UIN_UPLOAD_DIR', __DIR__ . '/uploads'),
    'photo_width'     => 800,
    'photo_quality'   => 90,
];
