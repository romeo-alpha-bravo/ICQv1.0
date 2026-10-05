<?php
declare(strict_types=1);

// Brute force protection for login.php: failed attempts are counted per login
// name and per IP address inside a time window. Include with require_once.

require_once __DIR__ . '/db.php';

const THROTTLE_MAX_FAILS = 10;   // failures allowed inside the window
const THROTTLE_WINDOW    = 900;  // seconds (15 minutes)

// Current client address as packed bytes (IPv4 or IPv6), empty when unknown.
function throttle_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $packed = is_string($ip) ? @inet_pton($ip) : false;
    return $packed === false ? '' : $packed;
}

// True when another login attempt is allowed right now.
function login_throttle_ok(string $login): bool
{
    $st = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
          WHERE (login = ? OR ip = ?) AND tried_at > (NOW() - INTERVAL ? SECOND)'
    );
    $st->bindValue(1, $login);
    $st->bindValue(2, throttle_ip(), PDO::PARAM_LOB);
    $st->bindValue(3, THROTTLE_WINDOW, PDO::PARAM_INT);
    $st->execute();

    return (int)$st->fetchColumn() < THROTTLE_MAX_FAILS;
}

// Records one failed attempt and now and then clears expired rows.
function login_throttle_fail(string $login): void
{
    $st = db()->prepare('INSERT INTO login_attempts (login, ip) VALUES (?, ?)');
    $st->bindValue(1, mb_substr($login, 0, 50, 'UTF-8'));
    $st->bindValue(2, throttle_ip(), PDO::PARAM_LOB);
    $st->execute();

    if (random_int(1, 20) === 1) { // cheap housekeeping, no cron needed
        $gc = db()->prepare('DELETE FROM login_attempts WHERE tried_at < (NOW() - INTERVAL ? SECOND)');
        $gc->bindValue(1, THROTTLE_WINDOW, PDO::PARAM_INT);
        $gc->execute();
    }
}

// Clears the counters after a successful login.
function login_throttle_clear(string $login): void
{
    $st = db()->prepare('DELETE FROM login_attempts WHERE login = ? OR ip = ?');
    $st->bindValue(1, $login);
    $st->bindValue(2, throttle_ip(), PDO::PARAM_LOB);
    $st->execute();
}
