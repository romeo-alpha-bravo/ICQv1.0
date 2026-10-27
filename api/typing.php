<?php
declare(strict_types=1);

// POST {"to": UIN}: "I am typing to this contact" (sent at most every few seconds).
define('AUTH_API', true);

require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/chat.php';

$user = require_login();

api_run(static function () use ($user): array {
    $in   = api_post_body();
    $peer = user_by_uin(api_int($in, 'to'));
    if ($peer !== null) {
        typing_set((int)$user['id'], (int)$peer['id']); // ignored for non-contacts
    }
    return ['ok' => true];
});
