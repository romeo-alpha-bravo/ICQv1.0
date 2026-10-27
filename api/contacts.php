<?php
declare(strict_types=1);

// POST {"action": "...", "uin": UIN, "group": "..."}
//   request  ask for authorization (add to my list)
//   accept   accept a request I received
//   decline  decline a request I received
//   remove   remove a contact on both sides, or cancel my own request
//   group    move a contact into a group of my list
define('AUTH_API', true);

require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/contacts.php';

$user = require_login();

api_run(static function () use ($user): array {
    $in    = api_post_body();
    $me    = (int)$user['id'];
    $other = user_by_uin(api_int($in, 'uin'));
    if ($other === null) {
        throw new InvalidArgumentException('There is no user with this UIN.');
    }
    $otherId = (int)$other['id'];

    switch (api_str($in, 'action')) {
        case 'request':
            contact_request($me, $otherId);
            break;
        case 'accept':
            if (!contact_accept($me, $otherId)) {
                throw new InvalidArgumentException('This request no longer exists.');
            }
            break;
        case 'decline':
            contact_decline($me, $otherId);
            break;
        case 'remove':
            contact_remove($me, $otherId);
            break;
        case 'group':
            contact_move($me, $otherId, api_str($in, 'group'));
            break;
        default:
            throw new InvalidArgumentException('Unknown action.');
    }
    return ['relation' => contact_relation($me, $otherId)];
});
