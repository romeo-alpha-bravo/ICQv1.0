<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/messages.php';

$user = require_login();
$id   = (int)$user['id'];

try {
    $rows = messages_inbox($id);
} catch (Throwable $ex) {
    error_log('UIN-Mail inbox: ' . $ex->getMessage());
    $rows = null;
}

page_start('Inbox');
mail_nav($id, 'inbox.php');
?>
<h1>Inbox</h1>
<?php if ($rows === null): ?>
  <p class="error"><?= e(ERR_GENERIC) ?></p>
<?php elseif (!$rows): ?>
  <p>No messages yet.</p>
<?php else: ?>
  <div class="table-wrap">
    <table class="mail-list">
      <thead><tr><th>From</th><th>Subject</th><th>Received</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $m): ?>
        <tr class="<?= $m['read_at'] === null ? 'unread' : '' ?>">
          <td><?= e($m['first_name'] . ' ' . $m['last_name']) ?> <small>#<?= e((string)$m['uin']) ?></small></td>
          <td><a href="message.php?id=<?= e((string)$m['id']) ?>"><?= e($m['subject']) ?></a></td>
          <td><?= e(msg_time($m['sent_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<?php page_end(); ?>
