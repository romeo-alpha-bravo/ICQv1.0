<?php
declare(strict_types=1);

// Messenger presence: the status a user chose, what others actually see, status text.
// Include with require_once.
//
// What others see is computed from three values in `users`:
//   last_seen      any request, also background polling -> online at all?
//   last_activity  last real action                     -> automatic "Away"
//   status         the status the user picked
//
//   no request for online_window (90 s)          -> offline (tab closed, logged out)
//   status 'invisible'                           -> offline for everyone else
//   status 'online' but no action for 10 minutes -> away (automatically)
//   otherwise                                    -> the chosen status

require_once __DIR__ . '/db.php';

// Statuses a user can choose, with their labels.
const STATUSES = [
    'online'    => 'Online',
    'away'      => 'Away',
    'na'        => 'Not available',
    'dnd'       => 'Do not disturb',
    'invisible' => 'Invisible',
];

const STATUS_TEXT_MAX = 100;

// Internal: presence settings from config.php (loaded once).
function presence_config(string $name): int
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/../config.php';
    }
    return (int)$cfg[$name];
}

// Internal: seconds since a UTC DATETIME from the database, or null when empty.
function presence_age(?string $dt): ?int
{
    if ($dt === null || $dt === '') {
        return null;
    }
    try {
        $t = new DateTimeImmutable($dt, new DateTimeZone('UTC'));
    } catch (Exception) {
        return null;
    }
    return time() - $t->getTimestamp();
}

// What a user's status looks like. $u needs status, last_seen and last_activity.
// $forSelf = true: the user looking at themselves sees "invisible" instead of "offline".
// Returns one of: online, away, na, dnd, invisible, offline.
function presence_of(array $u, bool $forSelf = false): string
{
    $seen = presence_age($u['last_seen'] ?? null);
    if ($seen === null || $seen > presence_config('online_window')) {
        return 'offline';
    }
    if ($u['status'] === 'invisible') {
        return $forSelf ? 'invisible' : 'offline';
    }
    if ($u['status'] === 'online') {
        $idle = presence_age($u['last_activity'] ?? null);
        if ($idle === null || $idle > presence_config('away_after')) {
            return 'away';
        }
    }
    return $u['status'];
}

// Label for a presence value from presence_of().
function presence_label(string $presence): string
{
    return STATUSES[$presence] ?? 'Offline';
}

// "Last seen" time for others: hidden while the user is invisible, so it cannot reveal them.
function presence_last_seen(array $u): ?string
{
    if ($u['status'] === 'invisible' || empty($u['last_seen'])) {
        return null;
    }
    return $u['last_seen'];
}

// Saves the chosen status and the optional status text ("At a lecture").
// Throws InvalidArgumentException with a safe message on bad input.
function status_set(int $userId, string $status, string $text): void
{
    if (!isset(STATUSES[$status])) {
        throw new InvalidArgumentException('Unknown status.');
    }
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if (!mb_check_encoding($text, 'UTF-8') || mb_strlen($text, 'UTF-8') > STATUS_TEXT_MAX
        || preg_match('/[\x00-\x1F\x7F]/', $text)) {
        throw new InvalidArgumentException('Status text: up to ' . STATUS_TEXT_MAX . ' characters on one line.');
    }

    $st = db()->prepare('UPDATE users SET status = ?, status_text = ? WHERE id = ?');
    $st->execute([$status, $text === '' ? null : $text, $userId]);
}
