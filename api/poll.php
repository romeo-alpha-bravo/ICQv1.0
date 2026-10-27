<?php
declare(strict_types=1);

// GET: everything the messenger screen needs, every few seconds.
//   ?with=UIN   the open chat (optional)
//   &after=ID   only messages newer than this id
//   &focus=1    the chat is visible right now -> its messages count as read
// Answer: my status, contact list with presence and unread counts, authorization
// requests, unread mail, and for the open chat new messages, ticks and "is typing".
define('AUTH_API', true);

require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/chat.php';
require_once __DIR__ . '/../includes/messages.php';

$user = require_login();

api_run(static function () use ($user): array {
    $me = (int)$user['id'];
    chat_mark_delivered($me);

    $chat = null;
    $withUin = api_int($_GET, 'with');
    if ($withUin > 0) {
        $peer = user_by_uin($withUin);
        // Ownership check: only a conversation with an accepted contact is returned.
        if ($peer !== null && contacts_are_friends($me, (int)$peer['id'])) {
            $peerId = (int)$peer['id'];
            if (($_GET['focus'] ?? '') === '1') {
                chat_mark_read($me, $peerId);
            }
            $chat = [
                'with'     => $withUin,
                'messages' => chat_fetch($me, $peerId, api_int($_GET, 'after')),
                'receipts' => chat_receipts($me, $peerId),
                'typing'   => typing_is($peerId, $me),
            ];
        }
    }

    return [
        'me' => [
            'uin'         => (int)$user['uin'],
            'status'      => $user['status'],
            'presence'    => presence_of($user, true),
            'status_text' => $user['status_text'],
        ],
        'contacts'    => contacts_of($me),     // after mark_read, so the counts are current
        'incoming'    => contact_requests($me, 'incoming'),
        'outgoing'    => contact_requests($me, 'outgoing'),
        'mail_unread' => messages_unread_count($me),
        'chat'        => $chat,
    ];
});
