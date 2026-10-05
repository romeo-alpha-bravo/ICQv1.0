<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/messages.php';

$user = require_login();
$id   = (int)$user['id'];

$msgId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$msg   = null;
$error = '';

if ($msgId) {
    try {
        // Returns null when the message does not exist OR belongs to someone else:
        // both cases look the same, so other users' message ids reveal nothing.
        $msg = message_get($msgId, $id);
    } catch (Throwable $ex) {
        error_log('UIN-Mail message: ' . $ex->getMessage());
        $error = ERR_GENERIC;
    }
}
if ($msg === null && $error === '') {
    http_response_code(404);
    $error = 'Message not found.';
}

page_start($msg !== null ? $msg['subject'] : 'Message', '', $user);
mail_nav($id, '');
?>
<?php if ($msg === null): ?>
  <p class="error"><?= e($error) ?></p>
<?php else: ?>
  <article class="message">
    <h1><?= e($msg['subject']) ?></h1>
    <dl>
      <dt>From</dt><dd><?= e($msg['sender_first'] . ' ' . $msg['sender_last']) ?> #<?= e((string)$msg['sender_uin']) ?></dd>
      <dt>To</dt><dd><?= e($msg['recipient_first'] . ' ' . $msg['recipient_last']) ?> #<?= e((string)$msg['recipient_uin']) ?></dd>
      <dt>Date</dt><dd><?= e(msg_time($msg['sent_at'])) ?></dd>
    </dl>
    <div class="message-body"><?= nl2br(e($msg['body'])) ?></div>

    <?php if ((int)$msg['recipient_id'] === $id): ?>
      <p><a href="compose.php?re=<?= e((string)$msg['id']) ?>">Reply</a></p>
    <?php endif; ?>
  </article>
<?php endif; ?>
<?php page_end(); ?>
