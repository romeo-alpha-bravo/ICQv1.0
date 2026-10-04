<?php
declare(strict_types=1);

// User data layer shared by registration, profile, admin pages and install_admin.php:
// validation, uniqueness checks, create / update / load with encryption.
// Include with require_once.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';

// Returns an error text for a bad password, or null when it is acceptable.
function password_error(string $pw): ?string
{
    $len = strlen($pw);
    if ($len < 8 || $len > 128) {
        return 'Password must be 8 to 128 characters long.';
    }
    return null;
}

// Validates and normalizes user input (e.g. $_POST).
// $isNew = true also checks login and password (registration).
// Returns [clean values, errors by field name]; errors is empty when all is valid.
function validate_user_input(array $in, bool $isNew): array
{
    $clean  = [];
    $errors = [];
    $text   = static fn(string $k): string => is_string($in[$k] ?? null) ? trim($in[$k]) : '';

    foreach (['first_name' => 'First name', 'last_name' => 'Last name'] as $k => $label) {
        $clean[$k] = $text($k);
        // \z (not $) so that a trailing newline is not accepted
        if (!preg_match('/^\p{L}[\p{L} \'\-]{0,99}\z/u', $clean[$k])) {
            $errors[$k] = "$label: letters, spaces, apostrophe and hyphen only (max 100).";
        }
    }

    $clean['email'] = mb_strtolower($text('email'), 'UTF-8');
    if ($clean['email'] === '' || strlen($clean['email']) > 254
        || !filter_var($clean['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    $clean['phone'] = preg_replace('/[ \-]/', '', $text('phone')); // allow spaces and hyphens
    if (!preg_match('/^\+?[0-9]{9,15}\z/', $clean['phone'])) {
        $errors['phone'] = 'Phone: 9 to 15 digits, optional leading +.';
    }

    $clean['gender'] = $text('gender');
    if (!in_array($clean['gender'], ['m', 'f', 'other'], true)) {
        $errors['gender'] = 'Please choose a gender.';
    }

    if ($isNew) {
        $clean['login'] = $text('login');
        if (!preg_match('/^[A-Za-z0-9_]{3,30}\z/', $clean['login'])) {
            $errors['login'] = 'Login: 3 to 30 characters, letters, digits and underscore only.';
        }

        $clean['password'] = is_string($in['password'] ?? null) ? $in['password'] : ''; // not trimmed
        if (($msg = password_error($clean['password'])) !== null) {
            $errors['password'] = $msg;
        }
    }

    return [$clean, $errors];
}

// Checks whether the login / email already belong to another user.
// $login = null skips the login check; $exceptId excludes the user being edited.
// Returns errors by field name (empty array = no conflicts).
function find_conflicts(?string $login, string $email, int $exceptId = 0): array
{
    $emailHash = hmac_email($email);

    $st = db()->prepare(
        'SELECT login, email_hash FROM users
          WHERE (login = ? OR email_hash = ?) AND id <> ?'
    );
    $st->execute([$login ?? '', $emailHash, $exceptId]);

    $errors = [];
    foreach ($st->fetchAll() as $row) {
        if ($login !== null && strcasecmp($row['login'], $login) === 0) {
            $errors['login'] = 'This login is already taken.';
        }
        if (hash_equals($row['email_hash'], $emailHash)) {
            $errors['email'] = 'This email is already registered.';
        }
    }
    return $errors;
}

// Inserts a new user with role 'user'. $c comes from validate_user_input(..., true).
// Returns the new user id. Throws InvalidArgumentException (safe message) or RuntimeException.
function user_create(array $c, string $photoName): int
{
    $sql = 'INSERT INTO users (uin, login, password_hash, first_name, last_name,
                               email_enc, email_hash, phone_enc, gender, photo_path)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    $hash = password_hash($c['password'], PASSWORD_ARGON2ID);

    for ($try = 0; $try < 5; $try++) {
        $st = db()->prepare($sql);
        $st->bindValue(1, random_int(10000000, 999999999), PDO::PARAM_INT); // random UIN
        $st->bindValue(2, $c['login']);
        $st->bindValue(3, $hash);
        $st->bindValue(4, $c['first_name']);
        $st->bindValue(5, $c['last_name']);
        $st->bindValue(6, encrypt($c['email']), PDO::PARAM_LOB);
        $st->bindValue(7, hmac_email($c['email']));
        $st->bindValue(8, encrypt($c['phone']), PDO::PARAM_LOB);
        $st->bindValue(9, $c['gender']);
        $st->bindValue(10, $photoName);

        try {
            $st->execute();
            return (int)db()->lastInsertId();
        } catch (PDOException $ex) {
            if (($ex->errorInfo[1] ?? 0) !== 1062) { // not a duplicate key
                error_log('UIN-Mail user_create: ' . $ex->getMessage());
                throw new RuntimeException('Internal error');
            }
            // Duplicate UIN: try another random one. Duplicate login/email: tell the user.
            if (!preg_match("/for key '(?:users\\.)?uin'/", $ex->getMessage())) {
                throw new InvalidArgumentException('This login or email is already registered.');
            }
        }
    }
    throw new RuntimeException('Internal error');
}

// Updates personal data of one user. $c comes from validate_user_input(..., false).
// $photoName / $newPassword = null keep the current values.
// Throws InvalidArgumentException (safe message) or RuntimeException.
function user_update(int $id, array $c, ?string $photoName = null, ?string $newPassword = null): void
{
    $hash = $newPassword === null ? null : password_hash($newPassword, PASSWORD_ARGON2ID);

    $st = db()->prepare(
        'UPDATE users SET first_name = ?, last_name = ?, email_enc = ?, email_hash = ?,
                          phone_enc = ?, gender = ?,
                          photo_path = COALESCE(?, photo_path),
                          password_hash = COALESCE(?, password_hash)
          WHERE id = ?'
    );
    $st->bindValue(1, $c['first_name']);
    $st->bindValue(2, $c['last_name']);
    $st->bindValue(3, encrypt($c['email']), PDO::PARAM_LOB);
    $st->bindValue(4, hmac_email($c['email']));
    $st->bindValue(5, encrypt($c['phone']), PDO::PARAM_LOB);
    $st->bindValue(6, $c['gender']);
    $st->bindValue(7, $photoName);
    $st->bindValue(8, $hash);
    $st->bindValue(9, $id, PDO::PARAM_INT);

    try {
        $st->execute();
    } catch (PDOException $ex) {
        if (($ex->errorInfo[1] ?? 0) === 1062) {
            throw new InvalidArgumentException('This email is already registered.');
        }
        error_log('UIN-Mail user_update: ' . $ex->getMessage());
        throw new RuntimeException('Internal error');
    }
}

// Loads one user with decrypted 'email' and 'phone' (no password hash). Null if not found.
function user_get_full(int $id): ?array
{
    $st = db()->prepare(
        'SELECT id, uin, login, first_name, last_name, email_enc, phone_enc, gender,
                photo_path, role, last_activity, created_at
           FROM users WHERE id = ?'
    );
    $st->execute([$id]);
    $row = $st->fetch();
    if ($row === false) {
        return null;
    }

    // The default admin has empty encrypted fields until install_admin.php is run.
    $row['email'] = $row['email_enc'] === '' ? '' : decrypt($row['email_enc']);
    $row['phone'] = $row['phone_enc'] === '' ? '' : decrypt($row['phone_enc']);
    unset($row['email_enc'], $row['phone_enc']);

    return $row;
}

// Checks a password against the stored hash of the user.
function user_check_password(int $id, string $password): bool
{
    $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $st->execute([$id]);
    $hash = $st->fetchColumn();

    return is_string($hash) && strlen($password) <= 128 && password_verify($password, $hash);
}
