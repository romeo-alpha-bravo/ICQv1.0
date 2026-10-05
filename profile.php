<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/users.php';
require_once __DIR__ . '/includes/image.php';
require_once __DIR__ . '/includes/messages.php';

$user = require_login();   // only logged-in users; data of the logged-in user only
$id   = (int)$user['id'];

$full   = user_get_full($id);
$errors = [];
$saved  = isset($_GET['saved']);
$old    = [
    'first_name' => $full['first_name'], 'last_name' => $full['last_name'],
    'email' => $full['email'], 'phone' => $full['phone'], 'gender' => $full['gender'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    [$clean, $errors] = validate_user_input($_POST, false);
    $old = $clean;

    // Optional password change: needs the current password
    $newPassword = null;
    $newPw = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
    $curPw = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
    if ($newPw !== '') {
        if (($msg = password_error($newPw)) !== null) {
            $errors['new_password'] = $msg;
        } elseif (!user_check_password($id, $curPw)) {
            $errors['current_password'] = 'Current password is incorrect.';
        } else {
            $newPassword = $newPw;
        }
    }

    try {
        if (!$errors) {
            $errors = find_conflicts(null, $clean['email'], $id);
        }

        // Optional new photo
        $newPhoto = null;
        if (!$errors && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $newPhoto = process_photo($_FILES['photo']);
            } catch (InvalidArgumentException $ex) {
                $errors['photo'] = $ex->getMessage();
            }
        }

        if (!$errors) {
            try {
                user_update($id, $clean, $newPhoto, $newPassword);
            } catch (Throwable $ex) {
                if ($newPhoto !== null) {
                    delete_photo($newPhoto);
                }
                throw $ex;
            }
            if ($newPhoto !== null) {
                delete_photo($full['photo_path']); // remove the old photo file
            }
            if ($newPassword !== null) {
                session_regenerate_id(true);       // new session id after a password change
            }
            header('Location: profile.php?saved=1');
            exit;
        }
    } catch (InvalidArgumentException $ex) {
        $errors['form'] = $ex->getMessage();
    } catch (Throwable $ex) {
        error_log('UIN-Mail profile: ' . $ex->getMessage());
        $errors['form'] = ERR_GENERIC;
    }
}

$val     = static fn(string $k): string => e((string)($old[$k] ?? ''));
$genders = ['m' => 'Male', 'f' => 'Female', 'other' => 'Other'];

page_start('My profile', '', $full);
mail_nav($id, 'profile.php');
?>
<h1>My profile</h1>
<?php if ($saved): ?><p class="info">Changes saved.</p><?php endif; ?>
<?php field_error($errors, 'form'); ?>

<section>
  <div class="photo"><img src="uploads/<?= e($full['photo_path']) ?>" alt="Profile photo"></div>
  <dl>
    <dt>UIN</dt>       <dd><?= e((string)$full['uin']) ?></dd>
    <dt>Login</dt>     <dd><?= e($full['login']) ?></dd>
    <dt>Role</dt>      <dd><?= e($full['role']) ?></dd>
    <dt>Name</dt>      <dd><?= e($full['first_name'] . ' ' . $full['last_name']) ?></dd>
    <dt>Email</dt>     <dd><?= e($full['email']) ?></dd>
    <dt>Phone</dt>     <dd><?= e($full['phone']) ?></dd>
    <dt>Gender</dt>    <dd><?= e($genders[$full['gender']] ?? '') ?></dd>
    <dt>Registered</dt><dd><?= e($full['created_at']) ?></dd>
  </dl>
</section>

<section>
  <h2>Edit my data</h2>
  <form method="post" action="profile.php" enctype="multipart/form-data" id="profile-form">
    <?php csrf_field(); ?>
    <p>
      <label for="first_name">First name</label>
      <input type="text" id="first_name" name="first_name" required maxlength="100"
             pattern="\p{L}[\p{L} '\-]*" value="<?= $val('first_name') ?>">
      <?php field_error($errors, 'first_name'); ?>
    </p>
    <p>
      <label for="last_name">Last name</label>
      <input type="text" id="last_name" name="last_name" required maxlength="100"
             pattern="\p{L}[\p{L} '\-]*" value="<?= $val('last_name') ?>">
      <?php field_error($errors, 'last_name'); ?>
    </p>
    <p>
      <label for="email">Email</label>
      <input type="email" id="email" name="email" required maxlength="254" value="<?= $val('email') ?>">
      <?php field_error($errors, 'email'); ?>
    </p>
    <p>
      <label for="phone">Phone</label>
      <input type="tel" id="phone" name="phone" required maxlength="25"
             pattern="\+?[0-9 \-]{9,25}" value="<?= $val('phone') ?>">
      <?php field_error($errors, 'phone'); ?>
    </p>
    <p>
      <label for="gender">Gender</label>
      <select id="gender" name="gender" required>
        <?php foreach ($genders as $k => $label): ?>
          <option value="<?= e($k) ?>"<?= ($old['gender'] ?? '') === $k ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <?php field_error($errors, 'gender'); ?>
    </p>
    <p>
      <label for="photo">New profile photo (optional, at least 800 px wide)</label>
      <input type="file" id="photo" name="photo"
             accept="image/jpeg,image/png,image/gif,image/bmp,image/tiff">
      <?php field_error($errors, 'photo'); ?>
    </p>
    <p>
      <label for="current_password">Current password (only to change the password)</label>
      <input type="password" id="current_password" name="current_password"
             maxlength="128" autocomplete="current-password">
      <?php field_error($errors, 'current_password'); ?>
    </p>
    <p>
      <label for="new_password">New password (optional, min 8 characters)</label>
      <input type="password" id="new_password" name="new_password" minlength="8" maxlength="128"
             autocomplete="new-password">
      <?php field_error($errors, 'new_password'); ?>
    </p>
    <p><button type="submit">Save</button></p>
  </form>
</section>

<script>
// Instant client-side checks. The server repeats all of them.
(function () {
  var form = document.getElementById('profile-form');
  var cur = form.elements['current_password'], neu = form.elements['new_password'];
  var photo = form.elements['photo'];

  function checkCurrent() { // current password is needed only when a new one is typed
    cur.required = neu.value !== '';
  }
  neu.addEventListener('input', checkCurrent);

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
