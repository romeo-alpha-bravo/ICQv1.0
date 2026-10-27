<?php
declare(strict_types=1);

// GET: unread counters for the menu badges on pages other than the messenger
// (polled by assets/notify.js). {"unread": mail, "chat": instant messages}
define('AUTH_API', true);

require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/messages.php';
require_once __DIR__ . '/../includes/chat.php';

$user = require_login(); // 401 JSON when the session has expired

api_run(static function () use ($user): array {
    $me = (int)$user['id'];
    chat_mark_delivered($me); // an open tab of the app counts as "delivered"
    return [
        'unread' => messages_unread_count($me),
        'chat'   => chat_unread_total($me),
    ];
});
