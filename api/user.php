<?php
declare(strict_types=1);

// GET ?uin=UIN  the info card of a user.
// Everyone logged in sees name, UIN, photo, city and "about me".
// Status, status text and "last seen" are shown only to accepted contacts.
// E-mail and phone are never part of it.
define('AUTH_API', true);

require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/chat.php';

$user = require_login();

api_run(static function () use ($user): array {
    $other = user_by_uin(api_int($_GET, 'uin'));
    if ($other === null) {
        api_error('User not found.', 404);
    }
    $me       = (int)$user['id'];
    $relation = contact_relation($me, (int)$other['id']);
    $card     = contact_public($other) + [
        'about'    => $other['about'],
        'relation' => $relation,
    ];

    if ($relation === 'contact' || $relation === 'self') {
        $presence = presence_of($other, $relation === 'self');
        $seen     = presence_last_seen($other);
        $card += [
            'status'      => $presence,
            'status_text' => $presence === 'offline' ? null : $other['status_text'],
            'last_seen'   => $seen === null ? null : chat_iso($seen),
        ];
    }
    return ['user' => $card];
});
