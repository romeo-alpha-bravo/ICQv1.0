<?php
declare(strict_types=1);

// JSON: {"unread": N} for the notification badge (polled by assets/notify.js).
// AUTH_API: polling does not extend the session; errors come back as JSON.
define('AUTH_API', true);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/messages.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$user = require_login(); // 401 JSON when the session has expired

try {
    echo json_encode(['unread' => messages_unread_count((int)$user['id'])]);
} catch (Throwable $ex) {
    error_log('UIN-Mail api/unread: ' . $ex->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'internal']);
}
