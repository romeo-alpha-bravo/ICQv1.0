<?php
declare(strict_types=1);

// GET ?q=...  find people by UIN, login or name (at least 2 characters).
define('AUTH_API', true);

require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/contacts.php';

$user = require_login();

api_run(static function () use ($user): array {
    $q = is_string($_GET['q'] ?? null) ? $_GET['q'] : '';
    return ['results' => users_search((int)$user['id'], $q)];
});
