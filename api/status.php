<?php
declare(strict_types=1);

// POST {"status": "online|away|na|dnd|invisible", "text": "At a lecture"}
define('AUTH_API', true);

require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/presence.php';

$user = require_login();

api_run(static function () use ($user): array {
    $in = api_post_body();
    status_set((int)$user['id'], api_str($in, 'status'), api_str($in, 'text'));
    return ['ok' => true];
});
