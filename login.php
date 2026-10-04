<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

start_secure_session();
if (!empty($_SESSION['uid'])) {
    header('Location: profile.php');
    exit;
}

$error = '';
$info  = isset($_GET['timeout']) ? 'You were logged out after 20 minutes of inactivity.' : '';
$login = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $login = is_string($_POST['login'] ?? null) ? trim($_POST['login']) : '';
    $pw    = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    try {
        $st = db()->prepare('SELECT id, password_hash FROM users WHERE login = ?');
        $st->execute([$login]);
        $row = $st->fetch();

        // Verify even for an unknown login, so the response time does not reveal it.
        $hash = $row !== false ? $row['password_hash'] : password_hash('dummy', PASSWORD_ARGON2ID);
        $ok   = strlen($pw) <= 128 && password_verify($pw, $hash) && $row !== false;

        if ($ok) {
            if (password_needs_rehash($row['password_hash'], PASSWORD_ARGON2ID)) {
                $up = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                $up->execute([password_hash($pw, PASSWORD_ARGON2ID), $row['id']]);
            }
            login_user((int)$row['id']);
            header('Location: profile.php');
            exit;
        }
        $error = 'Invalid login or password.'; // same text for both cases
    } catch (Throwable $ex) {
        error_log('UIN-Mail login: ' . $ex->getMessage());
        $error = ERR_GENERIC;
    }
}

page_start('Log in');
?>
<h1>Log in</h1>
<?php if ($info !== ''): ?><p class="info"><?= e($info) ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="error"><?= e($error) ?></p><?php endif; ?>

<form method="post" action="login.php">
  <?php csrf_field(); ?>
  <p>
    <label for="login">Login</label>
    <input type="text" id="login" name="login" required maxlength="50"
           autocomplete="username" value="<?= e($login) ?>">
  </p>
  <p>
    <label for="password">Password</label>
    <input type="password" id="password" name="password" required maxlength="128"
           autocomplete="current-password">
  </p>
  <p><button type="submit">Log in</button></p>
</form>
<p>No account yet? <a href="register.php">Register</a></p>
<?php page_end(); ?>
