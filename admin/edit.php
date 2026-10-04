<?php
declare(strict_types=1);

// Admin only: view and edit the data of any user (same fields as profile.php,
// plus role and an optional password reset).

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/users.php';
require_once __DIR__ . '/../includes/image.php';
require_once __DIR__ . '/../includes/messages.php';

$admin   = require_admin();
$adminId = (int)$admin['id'];

$targetId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
         ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

$target = $targetId ? user_get_full($targetId) : null;
if ($target === null) {
    http_response_code(404);
    page_start('User not found', '../');
    echo '<p class="error">User not found.</p><p><a href="users.php">Back to the list</a></p>';
    page_end();
    exit;
}

$errors = [];
$old = [
    'first_name' => $target['first_name'], 'last_name' => $target['last_name'],
    'email' => $target['email'], 'phone' => $target['phone'],
    'gender' => $target['gender'], 'role' => $target['role'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    [$clean, $errors] = validate_user_input($_POST, false);
    $old = $clean + ['role' => is_string($_POST['role'] ?? null) ? $_POST['role'] : $target['role']];

    // Optional password reset: the admin does not need the old password
    $newPassword = null;
    $newPw = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
    if ($newPw !== '') {
        if (($msg = password_error($newPw)) !== null) {
            $errors['new_password'] = $msg;
        } else {
            $newPassword = $newPw;
        }
    }

    // Role: same rules as in users.php
    $role = $old['role'];
    if (!in_array($role, ['user', 'admin'], true)) {
        $errors['role'] = 'Unknown role.';
    } elseif ($role !== $target['role']) {
        if ($targetId === $adminId) {
            $errors['role'] = 'You cannot change your own role.';
        } elseif ($role === 'user' && admin_count() <= 1) {
            $errors['role'] = 'The system must keep at least one admin.';
        }
    }

    try {
        if (!$errors) {
            $errors = find_conflicts(null, $clean['email'], $targetId);
        }

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
                user_update($targetId, $clean, $newPhoto, $newPassword);
                if ($role !== $target['role']) {
                    user_set_role($targetId, $role);
                }
            } catch (Throwable $ex) {
                if ($newPhoto !== null) {
                    delete_photo($newPhoto);
                }
                throw $ex;
            }
            if ($newPhoto !== null) {
                delete_photo($target['photo_path']);
            }
            header('Location: edit.php?id=' . $targetId . '&saved=1');
            exit;
        }
    } catch (InvalidArgumentException $ex) {
        $errors['form'] = $ex->getMessage();
    } catch (Throwable $ex) {
        error_log('UIN-Mail admin/edit: ' . $ex->getMessage());
        $errors['form'] = ERR_GENERIC;
    }
}

$val     = static fn(string $k): string => e((string)($old[$k] ?? ''));
$genders = ['m' => 'Male', 'f' => 'Female', 'other' => 'Other'];

page_start('Edit user', '../');
mail_nav($adminId, 'admin/users.php', '../');
?>
<h1>Edit user <?= e($target['login']) ?> (UIN <?= e((string)$target['uin']) ?>)</h1>
<p><a href="users.php">Back to the list</a></p>

<?php if (isset($_GET['saved'])): ?><p class="info">Changes saved.</p><?php endif; ?>
<?php field_error($errors, 'form'); ?>

<img src="../uploads/<?= e($target['photo_path']) ?>" alt="Profile photo" width="120" onerror="this.hidden=true">

<form method="post" action="edit.php" enctype="multipart/form-data" id="edit-form">
  <?php csrf_field(); ?>
  <input type="hidden" name="id" value="<?= e((string)$targetId) ?>">

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
    <label for="role">Role</label>
    <select id="role" name="role" required<?= $targetId === $adminId ? ' disabled' : '' ?>>
      <?php foreach (['user' => 'User', 'admin' => 'Admin'] as $k => $label): ?>
        <option value="<?= e($k) ?>"<?= ($old['role'] ?? '') === $k ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($targetId === $adminId): ?>
      <input type="hidden" name="role" value="<?= e($target['role']) ?>">
      <small>You cannot change your own role.</small>
    <?php endif; ?>
    <?php field_error($errors, 'role'); ?>
  </p>
  <p>
    <label for="photo">New profile photo (optional, at least 800 px wide)</label>
    <input type="file" id="photo" name="photo"
           accept="image/jpeg,image/png,image/gif,image/bmp,image/tiff">
    <?php field_error($errors, 'photo'); ?>
  </p>
  <p>
    <label for="new_password">Set a new password (optional, min 8 characters)</label>
    <input type="password" id="new_password" name="new_password" minlength="8" maxlength="128"
           autocomplete="new-password">
    <?php field_error($errors, 'new_password'); ?>
  </p>
  <p><button type="submit">Save</button></p>
</form>

<script>
// Instant client-side checks. The server repeats all of them.
(function () {
  var photo = document.getElementById('edit-form').elements['photo'];

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
