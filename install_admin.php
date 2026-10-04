<?php
declare(strict_types=1);

// One-time command line script: finishes the default admin account created by schema.sql
// (sets password and encrypted email / phone). Usage:  php install_admin.php
// It refuses to run from the web and does nothing if the admin is already installed.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/includes/users.php';

// Reads one line from the terminal; $hidden = true does not echo the typed text.
function ask(string $question, bool $hidden = false): string
{
    echo $question;
    if ($hidden) {
        shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($hidden) {
        shell_exec('stty echo');
        echo PHP_EOL;
    }
    return $line === false ? '' : rtrim($line, "\r\n");
}

$in = [
    'first_name' => 'Default',
    'last_name'  => 'Admin',
    'login'      => 'admin',
    'gender'     => 'other',
    'email'      => ask('Admin email: '),
    'phone'      => ask('Admin phone: '),
    'password'   => ask('Admin password (min 8 characters): ', true),
];

[$c, $errors] = validate_user_input($in, true);
if (!$errors) {
    $errors = find_conflicts(null, $c['email']);
}
if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

// Only the locked placeholder row (password_hash = '!') can be completed.
$st = db()->prepare(
    "UPDATE users SET password_hash = ?, email_enc = ?, email_hash = ?, phone_enc = ?
      WHERE login = 'admin' AND password_hash = '!'"
);
$st->bindValue(1, password_hash($c['password'], PASSWORD_ARGON2ID));
$st->bindValue(2, encrypt($c['email']), PDO::PARAM_LOB);
$st->bindValue(3, hmac_email($c['email']));
$st->bindValue(4, encrypt($c['phone']), PDO::PARAM_LOB);
$st->execute();

echo $st->rowCount() === 1
    ? "Admin account is ready. You can log in as 'admin'." . PHP_EOL
    : 'Nothing changed: the admin account is already installed (or missing).' . PHP_EOL;
