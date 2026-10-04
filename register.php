<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/users.php';
require_once __DIR__ . '/includes/image.php';

start_secure_session();
if (!empty($_SESSION['uid'])) {
    header('Location: profile.php');
    exit;
}

$errors = [];
$old    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // 1. Validate all text fields on the server
    [$clean, $errors] = validate_user_input($_POST, true);
    $old = $clean;
    unset($old['password']); // never send the password back to the browser

    $pw2 = is_string($_POST['password2'] ?? null) ? $_POST['password2'] : '';
    if (!isset($errors['password']) && $pw2 !== $clean['password']) {
        $errors['password2'] = 'Passwords do not match.';
    }

    try {
        // 2. Login / email must be unique
        if (!$errors) {
            $errors = find_conflicts($clean['login'], $clean['email']);
        }

        // 3. Process the photo (JPEG, 800 px wide) only when everything else is fine
        $photo = null;
        if (!$errors) {
            try {
                $photo = process_photo($_FILES['photo'] ?? []);
            } catch (InvalidArgumentException $ex) {
                $errors['photo'] = $ex->getMessage();
            }
        }

        // 4. Create the user and log in
        if (!$errors) {
            try {
                $id = user_create($clean, $photo);
                login_user($id);
                header('Location: profile.php');
                exit;
            } catch (Throwable $ex) {
                delete_photo($photo); // do not leave an orphan file
                throw $ex;
            }
        }
    } catch (InvalidArgumentException $ex) {
        $errors['form'] = $ex->getMessage();
    } catch (Throwable $ex) {
        error_log('UIN-Mail register: ' . $ex->getMessage());
        $errors['form'] = ERR_GENERIC;
    }
}

$val = static fn(string $k): string => e((string)($old[$k] ?? ''));

page_start('Register');
?>
<h1>Register</h1>
<?php field_error($errors, 'form'); ?>

<form method="post" action="register.php" enctype="multipart/form-data" id="reg-form">
  <?php csrf_field(); ?>

  <p>
    <label for="first_name">First name</label>
    <input type="text" id="first_name" name="first_name" required maxlength="100"
           pattern="\p{L}[\p{L} '\-]*" autocomplete="given-name" value="<?= $val('first_name') ?>">
    <?php field_error($errors, 'first_name'); ?>
  </p>
  <p>
    <label for="last_name">Last name</label>
    <input type="text" id="last_name" name="last_name" required maxlength="100"
           pattern="\p{L}[\p{L} '\-]*" autocomplete="family-name" value="<?= $val('last_name') ?>">
    <?php field_error($errors, 'last_name'); ?>
  </p>
  <p>
    <label for="email">Email</label>
    <input type="email" id="email" name="email" required maxlength="254"
           autocomplete="email" value="<?= $val('email') ?>">
    <?php field_error($errors, 'email'); ?>
  </p>
  <p>
    <label for="phone">Phone</label>
    <input type="tel" id="phone" name="phone" required maxlength="25"
           pattern="\+?[0-9 \-]{9,25}" placeholder="+420 123 456 789"
           autocomplete="tel" value="<?= $val('phone') ?>">
    <?php field_error($errors, 'phone'); ?>
  </p>
  <p>
    <label for="gender">Gender</label>
    <select id="gender" name="gender" required>
      <option value="">-- choose --</option>
      <?php foreach (['m' => 'Male', 'f' => 'Female', 'other' => 'Other'] as $k => $label): ?>
        <option value="<?= e($k) ?>"<?= ($old['gender'] ?? '') === $k ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <?php field_error($errors, 'gender'); ?>
  </p>
  <p>
    <label for="photo">Profile photo (JPEG, PNG, GIF, BMP or TIFF, at least 800 px wide)</label>
    <input type="file" id="photo" name="photo" required
           accept="image/jpeg,image/png,image/gif,image/bmp,image/tiff">
    <?php field_error($errors, 'photo'); ?>
  </p>
  <p>
    <label for="login">Login</label>
    <input type="text" id="login" name="login" required minlength="3" maxlength="30"
           pattern="[A-Za-z0-9_]{3,30}" autocomplete="username" value="<?= $val('login') ?>">
    <?php field_error($errors, 'login'); ?>
  </p>
  <p>
    <label for="password">Password (min 8 characters)</label>
    <input type="password" id="password" name="password" required minlength="8" maxlength="128"
           autocomplete="new-password">
    <?php field_error($errors, 'password'); ?>
  </p>
  <p>
    <label for="password2">Repeat password</label>
    <input type="password" id="password2" name="password2" required minlength="8" maxlength="128"
           autocomplete="new-password">
    <?php field_error($errors, 'password2'); ?>
  </p>
  <p><button type="submit">Create account</button></p>
</form>
<p>Already registered? <a href="login.php">Log in</a></p>

<script>
// Instant client-side checks. The server repeats all of them.
(function () {
  var form = document.getElementById('reg-form');
  var pw = form.elements['password'], pw2 = form.elements['password2'];
  var photo = form.elements['photo'];

  function checkPasswords() {
    pw2.setCustomValidity(pw.value === pw2.value ? '' : 'Passwords do not match.');
  }
  pw.addEventListener('input', checkPasswords);
  pw2.addEventListener('input', checkPasswords);

  photo.addEventListener('change', function () {
    var file = photo.files[0];
    photo.setCustomValidity('');
    if (!file) { return; }
    if (file.size > 10 * 1024 * 1024) {
      photo.setCustomValidity('The photo is too large (max 10 MB).');
      photo.reportValidity();
      return;
    }
    if (file.type === 'image/tiff') { return; } // browsers cannot decode TIFF; server checks it

    var url = URL.createObjectURL(file), img = new Image();
    img.onload = function () {
      URL.revokeObjectURL(url);
      if (img.naturalWidth < 800) { // keep in sync with photo_width in config.php
        photo.setCustomValidity('The photo must be at least 800 px wide.');
        photo.reportValidity();
      }
    };
    img.onerror = function () {
      URL.revokeObjectURL(url);
      photo.setCustomValidity('This file is not a valid image.');
      photo.reportValidity();
    };
    img.src = url;
  });
})();
</script>
<?php page_end(); ?>
