<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/messages.php';

$user = require_login();
$id   = (int)$user['id'];

try {
    $rows = messages_sent($id);
} catch (Throwable $ex) {
    error_log('UIN-Mail sent: ' . $ex->getMessage());
    $rows = null;
}

page_start('Sent');
mail_nav($id, 'sent.php');
?>
<h1>Sent</h1>
<?php if (isset($_GET['sent'])): ?><p class="info">Message sent.</p><?php endif; ?>
<?php if ($rows === null): ?>
  <p class="error"><?= e(ERR_GENERIC) ?></p>
<?php elseif (!$rows): ?>
  <p>You have not sent any messages yet.</p>
<?php else: ?>
  <div class="table-wrap">
    <table class="mail-list">
      <thead><tr><th>To</th><th>Subject</th><th>Sent</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $m): ?>
        <tr>
          <td><?= e($m['first_name'] . ' ' . $m['last_name']) ?> <small>#<?= e((string)$m['uin']) ?></small></td>
          <td><a href="message.php?id=<?= e((string)$m['id']) ?>"><?= e($m['subject']) ?></a></td>
          <td><?= e(msg_time($m['sent_at'])) ?></td>
          <td><?= $m['read_at'] === null ? 'not read' : 'read' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<?php page_end(); ?>
