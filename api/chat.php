<?php
declare(strict_types=1);

// GET  ?with=UIN[&before=ID]   one page of the conversation (latest, or older than ID)
// POST {"to": UIN, "body": "..."}  send a message (CSRF token in X-CSRF-Token)
// Both work only with accepted contacts.
define('AUTH_API', true);

require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/chat.php';

$user = require_login();

api_run(static function () use ($user): array {
    $me = (int)$user['id'];

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $in   = api_post_body();
        $peer = user_by_uin(api_int($in, 'to'));
        if ($peer === null) {
            throw new InvalidArgumentException('There is no user with this UIN.');
        }
        return ['message' => chat_send($me, (int)$peer['id'], api_str($in, 'body'))];
    }

    $peer = user_by_uin(api_int($_GET, 'with'));
    if ($peer === null || !contacts_are_friends($me, (int)$peer['id'])) {
        api_error('Conversation not found.', 404); // same answer for "no user" and "not a contact"
    }
    $peerId = (int)$peer['id'];
    return [
        'messages' => chat_fetch($me, $peerId, 0, api_int($_GET, 'before')),
        'receipts' => chat_receipts($me, $peerId),
        'page'     => CHAT_PAGE,
    ];
});
