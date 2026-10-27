<?php
declare(strict_types=1);

// Instant messages between two accepted contacts. Bodies are stored encrypted (crypto.php).
// Message states, shown as ticks to the sender:
//   sent       stored in the database                       ✓
//   delivered  the recipient's open app has received it     ✓✓ (grey)
//   read       the recipient had the chat open and visible  ✓✓ (blue)
// Include with require_once.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/contacts.php';

const CHAT_BODY_MAX = 2000;  // characters
const CHAT_PAGE     = 50;    // messages per history page
const TYPING_TTL    = 6;     // seconds an "is typing" signal stays valid

// Internal: UTC DATETIME from the database as ISO 8601 for the browser ("2026-10-27T10:15:00Z").
function chat_iso(?string $dt): ?string
{
    return $dt === null ? null : str_replace(' ', 'T', $dt) . 'Z';
}

// Internal: database row -> message for the browser.
function chat_row(array $row, int $me): array
{
    try {
        $body = decrypt($row['body_enc']);
    } catch (RuntimeException) {
        $body = '(unreadable message)';
    }
    $mine = (int)$row['sender_id'] === $me;

    return [
        'id'    => (int)$row['id'],
        'mine'  => $mine,
        'body'  => $body,
        'time'  => chat_iso($row['sent_at']),
        // The state matters only for my own messages.
        'state' => !$mine ? null : ($row['read_at'] !== null ? 'read' : ($row['delivered_at'] !== null ? 'delivered' : 'sent')),
    ];
}

// Normalizes a message text. Returns [clean text, error or null].
function chat_validate(string $body): array
{
    $body = trim(str_replace(["\r\n", "\r"], "\n", $body));
    if ($body === '') {
        return [$body, 'Type a message first.'];
    }
    if (!mb_check_encoding($body, 'UTF-8') || mb_strlen($body, 'UTF-8') > CHAT_BODY_MAX
        || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $body)) {
        return [$body, 'A message can have up to ' . CHAT_BODY_MAX . ' characters.'];
    }
    return [$body, null];
}

// Stores a message from $me to $peer. Only accepted contacts can write to each other.
// Returns the stored message as chat_row() does.
function chat_send(int $me, int $peer, string $body): array
{
    [$body, $error] = chat_validate($body);
    if ($error !== null) {
        throw new InvalidArgumentException($error);
    }
    if (!contacts_are_friends($me, $peer)) {
        throw new InvalidArgumentException('You can only write to people in your contact list.');
    }

    $st = db()->prepare('INSERT INTO chat_messages (sender_id, recipient_id, body_enc) VALUES (?, ?, ?)');
    $st->bindValue(1, $me, PDO::PARAM_INT);
    $st->bindValue(2, $peer, PDO::PARAM_INT);
    $st->bindValue(3, encrypt($body), PDO::PARAM_LOB);
    $st->execute();
    $id = (int)db()->lastInsertId();

    // Sending ends the "is typing" signal.
    $st = db()->prepare('UPDATE users SET typing_to = NULL WHERE id = ?');
    $st->execute([$me]);

    $st = db()->prepare('SELECT * FROM chat_messages WHERE id = ?');
    $st->execute([$id]);
    return chat_row($st->fetch(), $me);
}

// Conversation of $me with $peer, oldest first.
//   $afterId > 0:  messages newer than that id (polling)
//   $beforeId > 0: the page of older messages before that id (scrolling up)
//   both 0:        the latest page
function chat_fetch(int $me, int $peer, int $afterId = 0, int $beforeId = 0): array
{
    // Fixed SQL fragment (no input in it): messages in either direction between the two.
    $pair = '((sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?))';

    if ($afterId > 0) {
        $st = db()->prepare("SELECT * FROM chat_messages WHERE $pair AND id > ? ORDER BY id ASC LIMIT ?");
        $params = [$me, $peer, $peer, $me, $afterId, 200];
    } elseif ($beforeId > 0) {
        $st = db()->prepare("SELECT * FROM chat_messages WHERE $pair AND id < ? ORDER BY id DESC LIMIT ?");
        $params = [$me, $peer, $peer, $me, $beforeId, CHAT_PAGE];
    } else {
        $st = db()->prepare("SELECT * FROM chat_messages WHERE $pair ORDER BY id DESC LIMIT ?");
        $params = [$me, $peer, $peer, $me, CHAT_PAGE];
    }
    foreach ($params as $i => $value) {
        $st->bindValue($i + 1, $value, PDO::PARAM_INT);
    }
    $st->execute();

    $rows = $st->fetchAll();
    if ($afterId === 0) {
        $rows = array_reverse($rows); // pages are read newest first, shown oldest first
    }
    return array_map(static fn(array $r): array => chat_row($r, $me), $rows);
}

// Everything sent to $me is now "delivered": called on every poll of my open app.
function chat_mark_delivered(int $me): void
{
    $st = db()->prepare(
        'UPDATE chat_messages SET delivered_at = NOW() WHERE recipient_id = ? AND delivered_at IS NULL'
    );
    $st->execute([$me]);
}

// Messages from $peer are "read": called only while I have that chat open and visible.
function chat_mark_read(int $me, int $peer): void
{
    $st = db()->prepare(
        'UPDATE chat_messages SET read_at = NOW(), delivered_at = COALESCE(delivered_at, NOW())
          WHERE recipient_id = ? AND sender_id = ? AND read_at IS NULL'
    );
    $st->execute([$me, $peer]);
}

// Highest ids of my messages to $peer that are delivered / read, for updating the ticks.
// Both only grow, because messages are always marked in bulk, oldest included.
function chat_receipts(int $me, int $peer): array
{
    $st = db()->prepare(
        'SELECT MAX(CASE WHEN delivered_at IS NOT NULL THEN id END) AS delivered,
                MAX(CASE WHEN read_at      IS NOT NULL THEN id END) AS `read`
           FROM chat_messages WHERE sender_id = ? AND recipient_id = ?'
    );
    $st->execute([$me, $peer]);
    $row = $st->fetch();
    return ['delivered' => (int)($row['delivered'] ?? 0), 'read' => (int)($row['read'] ?? 0)];
}

// Unread chat messages for $me, all contacts together.
function chat_unread_total(int $me): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM chat_messages WHERE recipient_id = ? AND read_at IS NULL');
    $st->execute([$me]);
    return (int)$st->fetchColumn();
}

// "$me is typing to $peer" – only to contacts. The signal expires after TYPING_TTL seconds.
function typing_set(int $me, int $peer): void
{
    if (!contacts_are_friends($me, $peer)) {
        return;
    }
    $st = db()->prepare('UPDATE users SET typing_to = ?, typing_at = NOW() WHERE id = ?');
    $st->execute([$peer, $me]);
}

// Is $peer typing to $me right now?
function typing_is(int $peer, int $me): bool
{
    $st = db()->prepare(
        'SELECT COUNT(*) FROM users WHERE id = ? AND typing_to = ? AND typing_at > NOW() - INTERVAL ? SECOND'
    );
    $st->bindValue(1, $peer, PDO::PARAM_INT);
    $st->bindValue(2, $me, PDO::PARAM_INT);
    $st->bindValue(3, TYPING_TTL, PDO::PARAM_INT);
    $st->execute();
    return (int)$st->fetchColumn() > 0;
}
