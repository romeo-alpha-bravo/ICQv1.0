<?php
declare(strict_types=1);

// "Forgot your password?": asks for the e-mail and sends a reset link.
// The answer is the same for known and unknown addresses.

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/password_reset.php';

start_secure_session();
if (!empty($_SESSION['uid'])) {
    header('Location: profile.php');
    exit;
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $email = is_string($_POST['email'] ?? null) ? mb_strtolower(trim($_POST['email']), 'UTF-8') : '';

    if ($email === '' || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            reset_request($email);
            header('Location: forgot.php?sent=1'); // Post/Redirect/Get: a refresh does not resend
            exit;
        } catch (Throwable $ex) {
            error_log('ICQ1.0 forgot: ' . $ex->getMessage());
            $error = ERR_GENERIC;
        }
    }
}

page_start('Forgot password');
?>
<h1>Forgot your password?</h1>

<?php if (isset($_GET['sent'])): ?>
  <p class="info">If an account with this email exists, we have sent a link to choose a new password.
     It works for 30 minutes.</p>
  <p><a href="login.php">Back to login</a></p>
<?php else: ?>
  <p>Enter the email address you registered with. We will send you a link to set a new password.</p>
  <?php if ($error !== ''): ?><p class="error"><?= e($error) ?></p><?php endif; ?>

  <form method="post" action="forgot.php">
    <?php csrf_field(); ?>
    <p>
      <label for="email">Email</label>
      <input type="email" id="email" name="email" required maxlength="254"
             autocomplete="email" value="<?= e($email) ?>">
    </p>
    <p><button type="submit">Send link</button></p>
  </form>
  <p><a href="login.php">Back to login</a></p>
<?php endif; ?>
<?php page_end(); ?>
