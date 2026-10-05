<?php
declare(strict_types=1);

// Internal mail: validation, sending, inbox / sent lists, reading one message,
// unread counter and the navigation bar with the unread badge.
// Subject and body are stored encrypted (crypto.php). Include with require_once.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

const MSG_SUBJECT_MAX = 200;    // characters (fits VARBINARY(1024) after encryption)
const MSG_BODY_MAX    = 10000;  // characters

// ---------- Internal helpers (not part of the module contract) ----------

// Decrypts for display; one damaged row must not break the whole folder.
function msg_decrypt_safe(string $blob): string
{
    try {
        return decrypt($blob);
    } catch (RuntimeException) {
        return '(unreadable message)';
    }
}

// Parses a DATETIME coming from the DB (always UTC, see db.php).
function msg_utc(?string $dt): ?DateTimeImmutable
{
    if ($dt === null || $dt === '') {
        return null;
    }
    try {
        return new DateTimeImmutable($dt, new DateTimeZone('UTC'));
    } catch (Exception) {
        return null;
    }
}

// Formats a DATETIME from the DB in the configured display timezone.
function msg_time(?string $dt): string
{
    static $tz = null;
    if ($tz === null) {
        $tz = new DateTimeZone((string)(require __DIR__ . '/../config.php')['timezone']);
    }
    $time = msg_utc($dt);
    return $time === null ? '' : $time->setTimezone($tz)->format('d.m.Y H:i');
}

// Online = active within the window from config.php (5 minutes by default).
function is_online(?string $lastActivity): bool
{
    static $window = null;
    if ($window === null) {
        $window = (int)(require __DIR__ . '/../config.php')['online_window'];
    }
    $time = msg_utc($lastActivity);
    return $time !== null && (time() - $time->getTimestamp()) <= $window;
}

// ---------- Public functions ----------

// Validates compose form input. Returns [clean values, errors by field].
// clean: 'uin' (int), 'subject', 'body'.
function message_validate(array $in): array
{
    $clean  = [];
    $errors = [];

    $uin = is_string($in['to'] ?? null) ? trim($in['to']) : '';
    $clean['uin'] = preg_match('/^[0-9]{5,10}\z/', $uin) ? (int)$uin : 0;
    if ($clean['uin'] === 0) {
        $errors['to'] = 'Recipient: enter a valid UIN (digits only).';
    }

    $subject = is_string($in['subject'] ?? null) ? trim($in['subject']) : '';
    $clean['subject'] = $subject;
    if ($subject === '' || !mb_check_encoding($subject, 'UTF-8')
        || mb_strlen($subject, 'UTF-8') > MSG_SUBJECT_MAX
        || preg_match('/[\x00-\x1F\x7F]/', $subject)) {          // single line, no control chars
        $errors['subject'] = 'Subject: 1 to ' . MSG_SUBJECT_MAX . ' characters on one line.';
    }

    $body = is_string($in['body'] ?? null) ? trim(str_replace("\r\n", "\n", $in['body'])) : '';
    $clean['body'] = $body;
    if ($body === '' || !mb_check_encoding($body, 'UTF-8')
        || mb_strlen($body, 'UTF-8') > MSG_BODY_MAX
        || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $body)) { // newlines and tabs allowed
        $errors['body'] = 'Message: 1 to ' . MSG_BODY_MAX . ' characters.';
    }

    return [$clean, $errors];
}

// Finds a user by UIN (for the recipient). Null if there is none.
function recipient_by_uin(int $uin): ?array
{
    $st = db()->prepare('SELECT id, uin, first_name, last_name FROM users WHERE uin = ?');
    $st->execute([$uin]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

// All other users (UIN and name) for the recipient picker / contact list.
function contacts_list(int $exceptId): array
{
    $st = db()->prepare(
        'SELECT id, uin, first_name, last_name, last_activity FROM users
          WHERE id <> ? ORDER BY last_name, first_name'
    );
    $st->execute([$exceptId]);
    return $st->fetchAll();
}

// Stores a new encrypted message. Returns its id.
function message_send(int $senderId, int $recipientId, string $subject, string $body): int
{
    $st = db()->prepare(
        'INSERT INTO messages (sender_id, recipient_id, subject_enc, body_enc) VALUES (?, ?, ?, ?)'
    );
    $st->bindValue(1, $senderId, PDO::PARAM_INT);
    $st->bindValue(2, $recipientId, PDO::PARAM_INT);
    $st->bindValue(3, encrypt($subject), PDO::PARAM_LOB);
    $st->bindValue(4, encrypt($body), PDO::PARAM_LOB);

    try {
        $st->execute();
    } catch (PDOException $ex) {
        error_log('UIN-Mail message_send: ' . $ex->getMessage());
        throw new RuntimeException('Internal error');
    }
    return (int)db()->lastInsertId();
}

// Incoming messages of a user, newest first, with decrypted 'subject' and sender data.
function messages_inbox(int $userId): array
{
    $st = db()->prepare(
        'SELECT m.id, m.subject_enc, m.sent_at, m.read_at,
                u.uin, u.first_name, u.last_name
           FROM messages m JOIN users u ON u.id = m.sender_id
          WHERE m.recipient_id = ?
          ORDER BY m.sent_at DESC, m.id DESC
          LIMIT 200'                               // newest 200 messages
    );
    $st->execute([$userId]);
    return array_map('msg_list_row', $st->fetchAll());
}

// Sent messages of a user, newest first, with decrypted 'subject' and recipient data.
function messages_sent(int $userId): array
{
    $st = db()->prepare(
        'SELECT m.id, m.subject_enc, m.sent_at, m.read_at,
                u.uin, u.first_name, u.last_name
           FROM messages m JOIN users u ON u.id = m.recipient_id
          WHERE m.sender_id = ?
          ORDER BY m.sent_at DESC, m.id DESC
          LIMIT 200'
    );
    $st->execute([$userId]);
    return array_map('msg_list_row', $st->fetchAll());
}

// Internal: replaces the encrypted subject of a list row with plain text.
function msg_list_row(array $row): array
{
    $row['subject'] = msg_decrypt_safe($row['subject_enc']);
    unset($row['subject_enc']);
    return $row;
}

// One message, only if the user is its sender or recipient (else null).
// Opening it as the recipient marks it as read.
function message_get(int $messageId, int $userId): ?array
{
    $st = db()->prepare(
        'SELECT m.id, m.sender_id, m.recipient_id, m.subject_enc, m.body_enc,
                m.sent_at, m.read_at,
                s.uin AS sender_uin, s.first_name AS sender_first, s.last_name AS sender_last,
                r.uin AS recipient_uin, r.first_name AS recipient_first, r.last_name AS recipient_last
           FROM messages m
           JOIN users s ON s.id = m.sender_id
           JOIN users r ON r.id = m.recipient_id
          WHERE m.id = ? AND (m.sender_id = ? OR m.recipient_id = ?)'
    );
    $st->execute([$messageId, $userId, $userId]);
    $row = $st->fetch();
    if ($row === false) {
        return null;
    }

    if ((int)$row['recipient_id'] === $userId && $row['read_at'] === null) {
        $up = db()->prepare(
            'UPDATE messages SET read_at = NOW() WHERE id = ? AND recipient_id = ? AND read_at IS NULL'
        );
        $up->execute([$messageId, $userId]);
    }

    $row['subject'] = msg_decrypt_safe($row['subject_enc']);
    $row['body']    = msg_decrypt_safe($row['body_enc']);
    unset($row['subject_enc'], $row['body_enc']);
    return $row;
}

// Number of unread incoming messages.
function messages_unread_count(int $userId): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM messages WHERE recipient_id = ? AND read_at IS NULL');
    $st->execute([$userId]);
    return (int)$st->fetchColumn();
}

// Navigation bar for logged-in pages, with the unread badge and the polling script.
// $active is the current page file name; $base is '' for root pages, '../' in admin/.
// The Users link is only shown to admins; admin pages check the role themselves.
function mail_nav(int $userId, string $active, string $base = ''): void
{
    $unread = messages_unread_count($userId);
    $items  = [
        'inbox.php'    => 'Inbox',
        'sent.php'     => 'Sent',
        'compose.php'  => 'Write',
        'contacts.php' => 'Contacts',
        'profile.php'  => 'Profile',
    ];

    $st = db()->prepare('SELECT role FROM users WHERE id = ?');
    $st->execute([$userId]);
    if ($st->fetchColumn() === 'admin') {
        $items['admin/users.php'] = 'Users';
    }

    echo '<nav class="mail-nav">';
    foreach ($items as $page => $label) {
        $cls = $page === $active ? ' class="active"' : '';
        echo '<a href="' . e($base . $page) . '"' . $cls . '>' . e($label);
        if ($page === 'inbox.php') {
            echo ' <span id="unread-badge" class="badge" data-count="' . $unread . '"'
               . ' data-base="' . e($base) . '"' . ($unread === 0 ? ' hidden' : '') . '>'
               . $unread . '</span>';
        }
        echo '</a> ';
    }
    echo '<form method="post" action="' . e($base . 'logout.php') . '" class="logout">';
    csrf_field();
    echo '<button type="submit">Log out</button></form>';
    echo '</nav>';
    echo '<script src="' . e($base . 'assets/notify.js') . '" defer></script>';
}
