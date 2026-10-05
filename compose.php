<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/messages.php';

$user = require_login();
$id   = (int)$user['id'];

$errors = [];
$old    = ['to' => '', 'subject' => '', 'body' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    [$clean, $errors] = message_validate($_POST);
    $old = [
        'to'      => is_string($_POST['to'] ?? null) ? trim($_POST['to']) : '',
        'subject' => $clean['subject'],
        'body'    => $clean['body'],
    ];

    try {
        if (!isset($errors['to'])) {
            $to = recipient_by_uin($clean['uin']);
            if ($to === null) {
                $errors['to'] = 'There is no user with this UIN.';
            } elseif ((int)$to['id'] === $id) {
                $errors['to'] = 'You cannot send a message to yourself.';
            }
        }
        if (!$errors) {
            message_send($id, (int)$to['id'], $clean['subject'], $clean['body']);
            header('Location: sent.php?sent=1');
            exit;
        }
    } catch (Throwable $ex) {
        error_log('UIN-Mail compose: ' . $ex->getMessage());
        $errors['form'] = ERR_GENERIC;
    }
} else {
    // Prefill: ?to=UIN (from a contact) or ?re=ID (reply to a message of this user)
    $toParam = filter_input(INPUT_GET, 'to', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $reParam = filter_input(INPUT_GET, 're', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    try {
        if ($toParam) {
            $old['to'] = (string)$toParam;
        }
        if ($reParam && ($orig = message_get($reParam, $id)) !== null) {
            $old['to'] = (string)$orig['sender_uin'];
            $subject   = $orig['subject'];
            if (stripos($subject, 'Re:') !== 0) {
                $subject = 'Re: ' . $subject;
            }
            $old['subject'] = mb_substr($subject, 0, MSG_SUBJECT_MAX, 'UTF-8');
        }
    } catch (Throwable $ex) {
        error_log('UIN-Mail compose prefill: ' . $ex->getMessage());
    }
}

try {
    $contacts = contacts_list($id);
} catch (Throwable $ex) {
    error_log('UIN-Mail compose contacts: ' . $ex->getMessage());
    $contacts = [];
}

page_start('Write a message', '', $user);
mail_nav($id, 'compose.php');
?>
<h1>Write a message</h1>
<?php field_error($errors, 'form'); ?>

<form method="post" action="compose.php">
  <?php csrf_field(); ?>
  <p>
    <label for="to">To (UIN)</label>
    <input type="text" id="to" name="to" required inputmode="numeric" pattern="[0-9]{5,10}"
           maxlength="10" list="contacts" autocomplete="off" value="<?= e($old['to']) ?>">
    <datalist id="contacts">
      <?php foreach ($contacts as $c): ?>
        <option value="<?= e((string)$c['uin']) ?>"><?= e($c['first_name'] . ' ' . $c['last_name']) ?></option>
      <?php endforeach; ?>
    </datalist>
    <?php field_error($errors, 'to'); ?>
  </p>
  <p>
    <label for="subject">Subject</label>
    <input type="text" id="subject" name="subject" required maxlength="<?= MSG_SUBJECT_MAX ?>"
           value="<?= e($old['subject']) ?>">
    <?php field_error($errors, 'subject'); ?>
  </p>
  <p>
    <label for="body">Message</label>
    <textarea id="body" name="body" required maxlength="<?= MSG_BODY_MAX ?>" rows="10"><?= e($old['body']) ?></textarea>
    <?php field_error($errors, 'body'); ?>
  </p>
  <p><button type="submit">Send</button></p>
</form>
<?php page_end(); ?>
