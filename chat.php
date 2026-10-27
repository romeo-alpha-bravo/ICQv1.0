<?php
declare(strict_types=1);

// Messenger: contact list with groups and authorization requests on the left,
// the conversation on the right. Everything after the first render happens in
// assets/messenger.js through the JSON API in api/.

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/messages.php';
require_once __DIR__ . '/includes/presence.php';

$user = require_login();
$id   = (int)$user['id'];

page_start('Messenger', '', $user);
mail_nav($id, 'chat.php', '', 'messenger');
?>
<div id="app" hidden
     data-csrf="<?= e(csrf_token()) ?>"
     data-uin="<?= e((string)$user['uin']) ?>"
     data-status="<?= e($user['status']) ?>"
     data-status-text="<?= e((string)$user['status_text']) ?>"></div>

<aside class="roster" aria-label="Contacts">
  <div class="roster-head">
    <button type="button" class="status-btn" id="status-btn" aria-haspopup="menu" aria-expanded="false">
      <span class="status-dot st-online" id="my-dot"></span>
      <span id="my-status">Online</span>
      <span class="status-text" id="my-status-text"></span>
      <span class="chev" aria-hidden="true">▾</span>
    </button>
    <button type="button" class="icon-btn" id="add-btn" title="Add a contact" aria-label="Add a contact">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="10" cy="8" r="4"/><path d="M3 20c0-3.5 3-6 7-6s7 2.5 7 6M19 8v6M16 11h6"/></svg>
    </button>

    <div class="menu" id="status-menu" role="menu" hidden>
      <?php foreach (STATUSES as $key => $label): ?>
        <button type="button" class="menu-item" role="menuitemradio" aria-checked="false" data-status="<?= e($key) ?>">
          <span class="status-dot st-<?= e($key) ?>"></span><?= e($label) ?>
        </button>
      <?php endforeach; ?>
      <form id="status-text-form">
        <input type="text" id="status-text-input" maxlength="<?= STATUS_TEXT_MAX ?>"
               placeholder="What are you up to?" aria-label="Status text">
        <button type="submit">Save</button>
      </form>
    </div>
  </div>

  <div class="roster-filter">
    <input type="search" id="filter" placeholder="Filter contacts" aria-label="Filter contacts">
  </div>
  <button type="button" class="notif-hint" id="notif-btn" hidden>Turn on desktop notifications</button>
  <div class="roster-list" id="roster-list"><p class="roster-empty">Loading…</p></div>
</aside>

<section class="chat" aria-label="Conversation">
  <div class="chat-empty" id="chat-empty">
    <div class="big" aria-hidden="true">💬</div>
    <h2>Pick someone to talk to</h2>
    <p>Your contacts are on the left. To add someone, use the button next to your status
       and search by UIN or name.</p>
  </div>

  <div class="chat-view" id="chat-view" hidden>
    <header class="chat-head">
      <button type="button" class="icon-btn back-btn" id="back-btn" aria-label="Back to contacts">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>
      </button>
      <span class="avatar-wrap"><img class="avatar" id="peer-photo" alt=""><span class="status-dot" id="peer-dot"></span></span>
      <div class="chat-title"><b id="peer-name"></b><span id="peer-sub"></span></div>
      <a class="icon-btn" id="peer-mail" title="Write a letter" aria-label="Write a letter">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>
      </a>
      <button type="button" class="icon-btn" id="peer-info" title="Contact info" aria-label="Contact info">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.5v.5"/></svg>
      </button>
    </header>

    <div class="chat-log" id="chat-log">
      <button type="button" class="btn-quiet btn-small older-btn" id="older-btn" hidden>Show earlier messages</button>
      <ol class="msgs" id="msgs" role="log" aria-live="polite"></ol>
      <div class="typing-row" id="typing-row" hidden aria-hidden="true"><span class="typing-dots"><i></i><i></i><i></i></span></div>
    </div>

    <p class="error composer-error" id="composer-error" hidden></p>
    <form class="composer" id="composer" autocomplete="off">
      <button type="button" class="icon-btn" id="emoji-btn" aria-label="Smileys" aria-expanded="false">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M8.5 14.5c1 1.3 2.2 2 3.5 2s2.5-.7 3.5-2"/><circle cx="9" cy="10" r=".8" fill="currentColor"/><circle cx="15" cy="10" r=".8" fill="currentColor"/></svg>
      </button>
      <textarea id="msg-input" rows="1" maxlength="2000" placeholder="Write a message…" aria-label="Message"></textarea>
      <button type="submit" class="send-btn" id="send-btn" aria-label="Send">
        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M3.4 20.6l17.9-8.1c.6-.3.6-1.1 0-1.4L3.4 3c-.6-.3-1.3.3-1.1.9L4.6 11l8.4 1-8.4 1-2.3 7.1c-.2.6.5 1.2 1.1.9z"/></svg>
      </button>
    </form>
    <div class="emoji-panel" id="emoji-panel" hidden role="dialog" aria-label="Smileys"></div>
  </div>
</section>

<dialog id="dlg-add" aria-labelledby="dlg-add-title">
  <div class="dlg-head"><h2 id="dlg-add-title">Add a contact</h2><button type="button" class="dlg-close" data-close aria-label="Close">×</button></div>
  <div class="dlg-body">
    <input type="search" id="search-input" placeholder="UIN, login or name" aria-label="Search people" minlength="2" maxlength="50">
    <p class="hint">The person has to accept your request. After that you can chat and see each other's status.</p>
    <ul class="results" id="search-results"></ul>
  </div>
</dialog>

<dialog id="dlg-info" aria-labelledby="info-name">
  <div class="dlg-head"><h2>Contact info</h2><button type="button" class="dlg-close" data-close aria-label="Close">×</button></div>
  <div class="dlg-body" id="info-body"></div>
</dialog>

<dialog id="dlg-group" aria-labelledby="dlg-group-title">
  <div class="dlg-head"><h2 id="dlg-group-title">Move to group</h2><button type="button" class="dlg-close" data-close aria-label="Close">×</button></div>
  <form class="dlg-body" id="group-form">
    <label for="group-input">Group</label>
    <input type="text" id="group-input" list="group-names" required maxlength="40">
    <datalist id="group-names"></datalist>
    <p class="hint">Pick an existing group or type a new name.</p>
    <p class="error" id="group-error" hidden></p>
    <button type="submit">Move</button>
  </form>
</dialog>

<script src="assets/messenger.js" defer></script>
<?php page_end(); ?>
