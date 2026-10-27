<?php
declare(strict_types=1);

// Contact list with authorization, as in classic messengers:
//   1. A adds B by UIN          -> row (A, B, 'pending'): a request B has to answer
//   2. B accepts                 -> (A, B) becomes 'accepted' and (B, A) is added
//   3. B declines                -> the request is deleted
// Only accepted contacts can chat with each other and see each other's status.
// Every function checks the relationship in the database itself.
// Include with require_once.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/presence.php';

const CONTACT_GROUP_DEFAULT = 'General';
const CONTACT_GROUP_MAX     = 40;

// Internal: public data of a user for lists (never e-mail, phone or role).
function contact_public(array $row): array
{
    return [
        'uin'   => (int)$row['uin'],
        'name'  => $row['first_name'] . ' ' . $row['last_name'],
        'photo' => $row['photo_path'],
        'city'  => $row['city'] ?? null,
    ];
}

// User row by public UIN, or null.
function user_by_uin(int $uin): ?array
{
    $st = db()->prepare(
        'SELECT id, uin, first_name, last_name, photo_path, city, about,
                status, status_text, last_seen, last_activity
           FROM users WHERE uin = ?'
    );
    $st->execute([$uin]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

// Relationship of $me to $other: 'self', 'contact', 'outgoing' (I asked),
// 'incoming' (they asked me) or 'none'.
function contact_relation(int $me, int $other): string
{
    if ($me === $other) {
        return 'self';
    }
    $st = db()->prepare(
        'SELECT owner_id, state FROM contacts
          WHERE (owner_id = ? AND contact_id = ?) OR (owner_id = ? AND contact_id = ?)'
    );
    $st->execute([$me, $other, $other, $me]);

    $mine = $theirs = null;
    foreach ($st->fetchAll() as $row) {
        if ((int)$row['owner_id'] === $me) {
            $mine = $row['state'];
        } else {
            $theirs = $row['state'];
        }
    }
    if ($mine === 'accepted') {
        return 'contact';
    }
    if ($mine === 'pending') {
        return 'outgoing';
    }
    if ($theirs === 'pending') {
        return 'incoming';
    }
    return 'none';
}

// True when both users have accepted each other.
function contacts_are_friends(int $a, int $b): bool
{
    $st = db()->prepare(
        "SELECT COUNT(*) FROM contacts
          WHERE ((owner_id = ? AND contact_id = ?) OR (owner_id = ? AND contact_id = ?))
            AND state = 'accepted'"
    );
    $st->execute([$a, $b, $b, $a]);
    return (int)$st->fetchColumn() === 2;
}

// Asks $other for authorization. If $other has already asked $me, both are connected at once.
// Returns the new relation ('outgoing' or 'contact').
function contact_request(int $me, int $other): string
{
    $relation = contact_relation($me, $other);
    if ($relation === 'self') {
        throw new InvalidArgumentException('You cannot add yourself.');
    }
    if ($relation === 'contact' || $relation === 'outgoing') {
        return $relation;
    }
    if ($relation === 'incoming') {
        contact_accept($me, $other);
        return 'contact';
    }

    $st = db()->prepare(
        "INSERT INTO contacts (owner_id, contact_id, state, group_name) VALUES (?, ?, 'pending', ?)"
    );
    $st->execute([$me, $other, CONTACT_GROUP_DEFAULT]);
    return 'outgoing';
}

// $me accepts the request from $requester. Returns false if there is no such request.
function contact_accept(int $me, int $requester): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            "UPDATE contacts SET state = 'accepted'
              WHERE owner_id = ? AND contact_id = ? AND state = 'pending'"
        );
        $st->execute([$requester, $me]);
        if ($st->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }

        // The mirror row: $requester appears in my list too.
        $st = $pdo->prepare(
            "INSERT INTO contacts (owner_id, contact_id, state, group_name) VALUES (?, ?, 'accepted', ?)
             ON DUPLICATE KEY UPDATE state = 'accepted'"
        );
        $st->execute([$me, $requester, CONTACT_GROUP_DEFAULT]);

        $pdo->commit();
        return true;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

// $me declines the request from $requester.
function contact_decline(int $me, int $requester): void
{
    $st = db()->prepare("DELETE FROM contacts WHERE owner_id = ? AND contact_id = ? AND state = 'pending'");
    $st->execute([$requester, $me]);
}

// Removes the contact on both sides (also cancels my own pending request).
function contact_remove(int $me, int $other): void
{
    $st = db()->prepare(
        'DELETE FROM contacts WHERE (owner_id = ? AND contact_id = ?) OR (owner_id = ? AND contact_id = ?)'
    );
    $st->execute([$me, $other, $other, $me]);
}

// Moves a contact into a group of my list (the group exists as long as it has contacts).
function contact_move(int $me, int $other, string $group): void
{
    $group = trim(preg_replace('/\s+/u', ' ', $group) ?? '');
    if (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._\-]{0,' . (CONTACT_GROUP_MAX - 1) . '}\z/u', $group)) {
        throw new InvalidArgumentException('Group name: letters, digits, spaces, dot, dash; up to ' . CONTACT_GROUP_MAX . ' characters.');
    }
    $st = db()->prepare(
        "UPDATE contacts SET group_name = ? WHERE owner_id = ? AND contact_id = ? AND state = 'accepted'"
    );
    $st->execute([$group, $me, $other]);
}

// My accepted contacts with presence, status text, group and unread chat count.
function contacts_of(int $me): array
{
    $st = db()->prepare(
        "SELECT u.id, u.uin, u.first_name, u.last_name, u.photo_path, u.city,
                u.status, u.status_text, u.last_seen, u.last_activity, c.group_name
           FROM contacts c JOIN users u ON u.id = c.contact_id
          WHERE c.owner_id = ? AND c.state = 'accepted'
          ORDER BY c.group_name, u.first_name, u.last_name"
    );
    $st->execute([$me]);
    $rows = $st->fetchAll();

    $st = db()->prepare(
        'SELECT sender_id, COUNT(*) AS n FROM chat_messages
          WHERE recipient_id = ? AND read_at IS NULL GROUP BY sender_id'
    );
    $st->execute([$me]);
    $unread = array_column($st->fetchAll(), 'n', 'sender_id');

    $out = [];
    foreach ($rows as $row) {
        $presence = presence_of($row);
        $out[] = contact_public($row) + [
            'group'       => $row['group_name'],
            'status'      => $presence,
            'status_text' => $presence === 'offline' ? null : $row['status_text'],
            'unread'      => (int)($unread[$row['id']] ?? 0),
        ];
    }
    return $out;
}

// Requests other users sent to me ('incoming') or I sent to others ('outgoing').
function contact_requests(int $me, string $direction): array
{
    $sql = $direction === 'incoming'
        ? "SELECT u.uin, u.first_name, u.last_name, u.photo_path, u.city
             FROM contacts c JOIN users u ON u.id = c.owner_id
            WHERE c.contact_id = ? AND c.state = 'pending' ORDER BY c.created_at"
        : "SELECT u.uin, u.first_name, u.last_name, u.photo_path, u.city
             FROM contacts c JOIN users u ON u.id = c.contact_id
            WHERE c.owner_id = ? AND c.state = 'pending' ORDER BY c.created_at";
    $st = db()->prepare($sql);
    $st->execute([$me]);
    return array_map('contact_public', $st->fetchAll());
}

// Search by UIN (exact) or by login / first / last name (contains). At most 20 results,
// each with my relation to that user. Status is not shown: only contacts see it.
function users_search(int $me, string $q): array
{
    $q = trim($q);
    if (mb_strlen($q, 'UTF-8') < 2 || mb_strlen($q, 'UTF-8') > 50) {
        return [];
    }

    if (ctype_digit($q)) {
        $st = db()->prepare(
            'SELECT id, uin, first_name, last_name, photo_path, city FROM users WHERE uin = ? LIMIT 20'
        );
        $st->execute([(int)$q]);
    } else {
        // Escape LIKE wildcards so "%" or "_" in the query are matched literally.
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $st = db()->prepare(
            "SELECT id, uin, first_name, last_name, photo_path, city FROM users
              WHERE login LIKE ? OR first_name LIKE ? OR last_name LIKE ?
                 OR CONCAT(first_name, ' ', last_name) LIKE ?
              ORDER BY first_name, last_name LIMIT 20"
        );
        $st->execute([$like, $like, $like, $like]);
    }

    $out = [];
    foreach ($st->fetchAll() as $row) {
        $out[] = contact_public($row) + ['relation' => contact_relation($me, (int)$row['id'])];
    }
    return $out;
}
