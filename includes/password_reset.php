<?php
declare(strict_types=1);

// "Forgot your password": request a reset link by e-mail, check the link, set a new password.
// The e-mailed token is random and single use; only its SHA-256 hash is stored.
// Include with require_once.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/throttle.php';

const RESET_MAX_PER_IP   = 5;  // requests from one address per 15 minutes
const RESET_MAX_PER_USER = 3;  // requests for one account per hour

// Handles a "forgot password" request. Always returns the same way, whether the e-mail
// belongs to an account or not, so the form cannot be used to find out who is registered.
// Too frequent requests are silently ignored. Mail delivery errors are only logged.
function reset_request(string $email): void
{
    $email = mb_strtolower(trim($email), 'UTF-8');
    $ip    = throttle_ip();
    $cfg   = require __DIR__ . '/../config.php';

    // 1. Limit per IP address (counts requests for unknown e-mails too)
    $st = db()->prepare(
        'SELECT COUNT(*) FROM password_resets WHERE ip = ? AND created_at > (NOW() - INTERVAL 15 MINUTE)'
    );
    $st->bindValue(1, $ip, PDO::PARAM_LOB);
    $st->execute();
    if ((int)$st->fetchColumn() >= RESET_MAX_PER_IP) {
        return;
    }

    // 2. Find the account by the e-mail fingerprint
    $st = db()->prepare('SELECT id FROM users WHERE email_hash = ?');
    $st->execute([hmac_email($email)]);
    $userId = $st->fetchColumn();

    // Unknown e-mail: only leave a row for the IP counter, send nothing.
    if ($userId === false) {
        reset_store(null, null, $ip, 0);
        return;
    }
    $userId = (int)$userId;

    // 3. Limit per account
    $st = db()->prepare(
        'SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)'
    );
    $st->execute([$userId]);
    if ((int)$st->fetchColumn() >= RESET_MAX_PER_USER) {
        return;
    }

    // 4. Older links of this account stop working, then a new one is issued
    $st = db()->prepare(
        'UPDATE password_resets SET used_at = NOW()
          WHERE user_id = ? AND used_at IS NULL AND token_hash IS NOT NULL'
    );
    $st->execute([$userId]);

    $token = bin2hex(random_bytes(32));
    reset_store($userId, hash('sha256', $token), $ip, (int)$cfg['reset_ttl']);

    // 5. E-mail the link
    $minutes = intdiv((int)$cfg['reset_ttl'], 60);
    $url     = $cfg['base_url'] . '/reset.php?token=' . $token;
    $body    = "Hello,\n\n"
             . 'Someone asked to reset the password of the ' . APP_NAME . " account registered with this email address.\n\n"
             . "To choose a new password, open this link within $minutes minutes:\n\n$url\n\n"
             . "If you did not ask for this, ignore this message. Your password stays the same.\n";
    try {
        send_mail($email, 'Reset your ' . APP_NAME . ' password', $body);
    } catch (Throwable $ex) {
        error_log('ICQ1.0 reset mail failed: ' . $ex->getMessage());
    }
}

// Internal: stores one request row; also removes old rows now and then.
function reset_store(?int $userId, ?string $tokenHash, string $ip, int $ttlSeconds): void
{
    $st = db()->prepare(
        'INSERT INTO password_resets (user_id, token_hash, ip, expires_at)
         VALUES (?, ?, ?, NOW() + INTERVAL ? SECOND)'
    );
    $st->bindValue(1, $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue(2, $tokenHash, $tokenHash === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $st->bindValue(3, $ip, PDO::PARAM_LOB);
    $st->bindValue(4, $ttlSeconds, PDO::PARAM_INT);
    $st->execute();

    if (random_int(1, 20) === 1) { // housekeeping, no cron needed
        db()->exec('DELETE FROM password_resets WHERE created_at < (NOW() - INTERVAL 1 DAY)');
    }
}

// Returns the user id when the token from the link is valid (unused, not expired), else null.
function reset_find_valid(string $token): ?int
{
    if (!preg_match('/^[0-9a-f]{64}\z/', $token)) {
        return null;
    }
    $st = db()->prepare(
        'SELECT user_id FROM password_resets
          WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()'
    );
    $st->execute([hash('sha256', $token)]);
    $id = $st->fetchColumn();

    return $id === false || $id === null ? null : (int)$id;
}

// Sets the new password if the token is still valid and burns the token (one use only).
// Returns false when the link is invalid or expired. The caller validates the password.
function reset_complete(string $token, string $newPassword): bool
{
    if (!preg_match('/^[0-9a-f]{64}\z/', $token)) {
        return false;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // FOR UPDATE: two parallel requests with the same link cannot both succeed.
        $st = $pdo->prepare(
            'SELECT user_id FROM password_resets
              WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() FOR UPDATE'
        );
        $st->execute([hash('sha256', $token)]);
        $userId = $st->fetchColumn();

        if ($userId === false || $userId === null) {
            $pdo->rollBack();
            return false;
        }

        $up = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $up->execute([password_hash($newPassword, PASSWORD_ARGON2ID), $userId]);

        // This and every other open link of the account is now used up.
        $used = $pdo->prepare(
            'UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL'
        );
        $used->execute([$userId]);

        $pdo->commit();
        return true;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}
