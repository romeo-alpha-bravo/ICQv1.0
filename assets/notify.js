// Pages other than the messenger: polls api/unread.php every 20 s and updates the
// Messenger and Mail badges in the top bar. Something new: count in the tab title and a
// short sound. Expired session (HTTP 401): go to the login page.
(function () {
  'use strict';
  var mailBadge = document.getElementById('unread-badge');
  var chatBadge = document.getElementById('chat-badge');
  if (!mailBadge || !chatBadge) { return; }

  var base  = mailBadge.dataset.base || '';
  var last  = { mail: parseInt(mailBadge.dataset.count || '0', 10), chat: parseInt(chatBadge.dataset.count || '0', 10) };
  var title = document.title;
  var sounds = { mail: new Audio(base + 'assets/notify.wav'), chat: new Audio(base + 'assets/message.wav') };

  function set(badge, count) {
    badge.textContent = String(count);
    badge.hidden = count === 0;
  }

  function show(mail, chat) {
    set(mailBadge, mail);
    set(chatBadge, chat);
    var total = mail + chat;
    document.title = (total > 0 ? '(' + total + ') ' : '') + title;
    if (chat > last.chat) { sounds.chat.play().catch(function () {}); }
    else if (mail > last.mail) { sounds.mail.play().catch(function () {}); }
    last = { mail: mail, chat: chat };
  }

  function poll() {
    fetch(base + 'api/unread.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (res) {
        if (res.status === 401) {
          window.location.href = base + 'login.php?timeout=1';
          return null;
        }
        return res.ok ? res.json() : null;
      })
      .then(function (data) {
        if (data && typeof data.unread === 'number') { show(data.unread, data.chat || 0); }
      })
      .catch(function () {}); // network hiccup: try again next time
  }

  show(last.mail, last.chat);
  setInterval(poll, 20000);
})();
