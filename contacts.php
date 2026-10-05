<?php
declare(strict_types=1);

// Contact list: everyone else in the system, split into online and offline,
// with a shortcut to write to them.

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/messages.php';

$user = require_login();
$id   = (int)$user['id'];

$error = '';
$online = [];
$offline = [];

try {
    foreach (contacts_list($id) as $c) {
        if (is_online($c['last_activity'])) {
            $online[] = $c;
        } else {
            $offline[] = $c;
        }
    }
} catch (Throwable $ex) {
    error_log('UIN-Mail contacts: ' . $ex->getMessage());
    $error = ERR_GENERIC;
}

// Renders one group of the list.
function contact_group(string $title, array $rows, bool $isOnline): void
{
    if (!$rows) {
        return;
    }
    echo '<h2 class="group-title">' . e($title) . ' (' . count($rows) . ')</h2>';
    echo '<ul class="contacts">';
    foreach ($rows as $c) {
        echo '<li class="' . ($isOnline ? 'is-online' : '') . '">'
           . '<span class="flower" aria-hidden="true"></span>'
           . avatar_img($c['photo_path'])
           . '<span class="name">' . e($c['first_name'] . ' ' . $c['last_name']) . '</span>'
           . '<span class="uin">#' . e((string)$c['uin']) . '</span>'
           . '<a class="write" href="compose.php?to=' . e((string)$c['uin']) . '">Write</a>'
           . '</li>';
    }
    echo '</ul>';
}

page_start('Contacts', '', $user);
mail_nav($id, 'contacts.php');
?>
<h1>Contacts</h1>

<?php if ($error !== ''): ?>
  <p class="error"><?= e($error) ?></p>
<?php elseif (!$online && !$offline): ?>
  <p>You are the only registered user so far. Invite someone and they will show up here.</p>
<?php else: ?>
  <?php
    contact_group('Online', $online, true);
    contact_group('Offline', $offline, false);
  ?>
  <p><small>Someone counts as online when they used the site in the last 5 minutes.</small></p>
<?php endif; ?>
<?php page_end(); ?>
