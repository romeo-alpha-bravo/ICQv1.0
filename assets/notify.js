// Polls api/unread.php and updates the unread badge.
// New message: badge count, number in the tab title and a short sound.
// Expired session (HTTP 401): go to the login page.
(function () {
  var badge = document.getElementById('unread-badge');
  if (!badge) { return; }

  var base  = badge.dataset.base || '';
  var last  = parseInt(badge.dataset.count || '0', 10);
  var title = document.title;
  var sound = new Audio(base + 'assets/notify.wav');

  function show(count) {
    badge.textContent = String(count);
    badge.hidden = count === 0;
    document.title = (count > 0 ? '(' + count + ') ' : '') + title;
    if (count > last) {
      sound.play().catch(function () {}); // browsers may block sound until the first click
    }
    last = count;
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
        if (data && typeof data.unread === 'number') { show(data.unread); }
      })
      .catch(function () {}); // network hiccup: try again next time
  }

  show(last);
  setInterval(poll, 20000); // every 20 s
})();
