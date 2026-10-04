<?php
declare(strict_types=1);

// Admin only: list of all users, with links to edit them and a role switch.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/users.php';
require_once __DIR__ . '/../includes/messages.php';

$admin = require_admin();   // role is checked on the server, not by hiding a link
$adminId = (int)$admin['id'];

$notice = '';
$error  = '';

// Role change
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $targetId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $role     = is_string($_POST['role'] ?? null) ? $_POST['role'] : '';

    try {
        if (!$targetId) {
            $error = 'Unknown user.';
        } elseif ($targetId === $adminId) {
            // Taking your own admin rights away would lock you out of this page.
            $error = 'You cannot change your own role.';
        } elseif ($role === 'user' && admin_count() <= 1) {
            $error = 'The system must keep at least one admin.';
        } else {
            user_set_role($targetId, $role);
            header('Location: users.php?saved=1');
            exit;
        }
    } catch (InvalidArgumentException $ex) {
        $error = $ex->getMessage();
    } catch (Throwable $ex) {
        error_log('UIN-Mail admin/users: ' . $ex->getMessage());
        $error = ERR_GENERIC;
    }
}

try {
    $rows = users_list();
} catch (Throwable $ex) {
    error_log('UIN-Mail admin/users list: ' . $ex->getMessage());
    $rows = null;
    $error = ERR_GENERIC;
}

$online = (int)(require __DIR__ . '/../config.php')['online_window'];

page_start('Users', '../');
mail_nav($adminId, 'admin/users.php', '../');
?>
<h1>Users</h1>
<p><a href="../profile.php">Back to my profile</a></p>

<?php if (isset($_GET['saved'])): ?><p class="info">Role changed.</p><?php endif; ?>
<?php if ($error !== ''): ?><p class="error"><?= e($error) ?></p><?php endif; ?>

<?php if ($rows): ?>
<div class="table-wrap">
  <table class="user-list">
    <thead>
      <tr><th>UIN</th><th>Login</th><th>Name</th><th>Role</th><th>Status</th><th>Registered</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $u):
        $isOnline = $u['last_activity'] !== null
            && (time() - strtotime($u['last_activity'])) <= $online;
        $isSelf = (int)$u['id'] === $adminId;
    ?>
      <tr>
        <td><?= e((string)$u['uin']) ?></td>
        <td><?= e($u['login']) ?></td>
        <td><?= e($u['first_name'] . ' ' . $u['last_name']) ?></td>
        <td><?= e($u['role']) ?></td>
        <td><?= $isOnline ? 'online' : 'offline' ?></td>
        <td><?= e(msg_time($u['created_at'])) ?></td>
        <td class="actions">
          <a href="edit.php?id=<?= e((string)$u['id']) ?>">Edit</a>
          <?php if (!$isSelf): ?>
            <form method="post" action="users.php" class="inline">
              <?php csrf_field(); ?>
              <input type="hidden" name="id" value="<?= e((string)$u['id']) ?>">
              <input type="hidden" name="role" value="<?= $u['role'] === 'admin' ? 'user' : 'admin' ?>">
              <button type="submit"><?= $u['role'] === 'admin' ? 'Revoke admin' : 'Make admin' ?></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php page_end(); ?>
