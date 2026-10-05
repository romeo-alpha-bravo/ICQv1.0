<?php
declare(strict_types=1);

// Opened from the e-mailed link (reset.php?token=...): sets a new password.

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/users.php';          // password_error()
require_once __DIR__ . '/includes/password_reset.php';

start_secure_session();
if (!empty($_SESSION['uid'])) {
    header('Location: profile.php');
    exit;
}

$source = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$token  = is_string($source['token'] ?? null) ? $source['token'] : '';

$errors = [];
$valid  = false;

try {
    $valid = $token !== '' && reset_find_valid($token) !== null;

    if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();

        $pw  = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $pw2 = is_string($_POST['password2'] ?? null) ? $_POST['password2'] : '';

        if (($msg = password_error($pw)) !== null) {
            $errors['password'] = $msg;
        } elseif ($pw !== $pw2) {
            $errors['password2'] = 'Passwords do not match.';
        } elseif (reset_complete($token, $pw)) {
            header('Location: login.php?reset=1');
            exit;
        } else {
            $valid = false; // the link was used or expired a moment ago
        }
    }
} catch (Throwable $ex) {
    error_log('ICQ1.0 reset: ' . $ex->getMessage());
    $errors['form'] = ERR_GENERIC;
}

page_start('New password');
?>
<h1>Choose a new password</h1>
<?php field_error($errors, 'form'); ?>

<?php if (!$valid): ?>
  <p class="error">This link is invalid or has expired.</p>
  <p><a href="forgot.php">Request a new link</a></p>
<?php else: ?>
  <form method="post" action="reset.php" id="reset-form">
    <?php csrf_field(); ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <p>
      <label for="password">New password (min 8 characters)</label>
      <input type="password" id="password" name="password" required minlength="8" maxlength="128"
             autocomplete="new-password">
      <?php field_error($errors, 'password'); ?>
    </p>
    <p>
      <label for="password2">Repeat new password</label>
      <input type="password" id="password2" name="password2" required minlength="8" maxlength="128"
             autocomplete="new-password">
      <?php field_error($errors, 'password2'); ?>
    </p>
    <p><button type="submit">Save password</button></p>
  </form>

  <script>
  // Instant check; the server repeats it.
  (function () {
    var f = document.getElementById('reset-form');
    var a = f.elements['password'], b = f.elements['password2'];
    function check() { b.setCustomValidity(a.value === b.value ? '' : 'Passwords do not match.'); }
    a.addEventListener('input', check);
    b.addEventListener('input', check);
  })();
  </script>
<?php endif; ?>
<?php page_end(); ?>
