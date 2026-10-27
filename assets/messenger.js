// ICQ1.0 messenger client (chat.php).
// Polls api/poll.php every 3 s (10 s while the tab is hidden) and renders the contact list,
// authorization requests and the open conversation. Actions are POST requests with the
// CSRF token in the X-CSRF-Token header.
// Security: every value from the server is inserted with textContent / attributes,
// never as HTML, so a message like "<script>" is shown as text.
(function () {
  'use strict';

  var app = document.getElementById('app');
  if (!app) { return; }

  var CSRF = app.dataset.csrf;
  var POLL_VISIBLE = 3000;
  var POLL_HIDDEN  = 10000;
  var TYPING_EVERY = 3000;
  var LABELS = {
    online: 'Online', away: 'Away', na: 'Not available', dnd: 'Do not disturb',
    invisible: 'Invisible', offline: 'Offline'
  };
  var EMOJI = ['😀', '😂', '🙂', '😉', '😍', '😘', '😎', '🤔', '😴', '😢', '😭', '😡',
               '😱', '🥳', '🤗', '🙈', '👍', '👎', '👋', '🙏', '👏', '💪', '🤝', '✌️',
               '❤️', '💔', '🔥', '⭐', '🎉', '🎁', '☕', '🍕', '🍺', '⚽', '🎮', '📚',
               '💻', '📱', '✉️', '⏰', '🌞', '🌙', '☔', '❄️', '🚀', '🏠', '💡', '✅'];

  var main   = document.querySelector('main.messenger');
  var msgsEl = byId('msgs');
  var logEl  = byId('chat-log');
  var input  = byId('msg-input');

  var state = {
    me: { status: app.dataset.status, text: app.dataset.statusText || '', presence: app.dataset.status },
    contacts: [], incoming: [], outgoing: [],
    open: null,          // UIN of the open conversation
    msgs: [], ids: {},   // messages of the open conversation, oldest first
    lastId: 0,           // newest message id received from the server
    receipts: { delivered: 0, read: 0 },
    prevUnread: null,    // unread per contact at the last poll, to detect new messages
    prevIncoming: null, prevMail: null,
    rosterKey: '', filter: '', collapsed: loadCollapsed(),
    drafts: {}, polling: false, timer: null, lastTyping: 0, sending: false
  };

  var sounds = { message: new Audio('assets/message.wav'), mail: new Audio('assets/notify.wav') };
  var baseTitle = document.title;
  var blinkTimer = null;

  // ---------- small helpers ----------

  function byId(id) { return document.getElementById(id); }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined && text !== null) { n.textContent = text; }
    return n;
  }

  function button(text, cls, onClick) {
    var b = el('button', cls, text);
    b.type = 'button';
    b.addEventListener('click', onClick);
    return b;
  }

  function photoUrl(photo) { return 'uploads/' + encodeURIComponent(photo); }

  function avatar(photo, status, size) {
    var wrap = el('span', 'avatar-wrap');
    var img = el('img', 'avatar');
    img.src = photoUrl(photo);
    img.alt = '';
    img.loading = 'lazy';
    if (size) { img.style.width = img.style.height = size + 'px'; }
    wrap.appendChild(img);
    if (status) { wrap.appendChild(dot(status)); }
    return wrap;
  }

  function dot(status) {
    var d = el('span', 'status-dot st-' + status);
    d.title = LABELS[status] || '';
    return d;
  }

  function fmtUin(uin) { return String(uin).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }

  function statusLine(c) {
    if (c.status === 'offline') { return 'Offline'; }
    return c.status_text ? LABELS[c.status] + ': ' + c.status_text : LABELS[c.status];
  }

  function findContact(uin) {
    for (var i = 0; i < state.contacts.length; i++) {
      if (state.contacts[i].uin === uin) { return state.contacts[i]; }
    }
    return null;
  }

  function isFocused() { return !document.hidden && document.hasFocus(); }

  function play(name) {
    if (state.me.status === 'dnd') { return; }
    sounds[name].currentTime = 0;
    sounds[name].play().catch(function () {}); // blocked until the user has clicked once
  }

  function loadCollapsed() {
    try { return JSON.parse(localStorage.getItem('icq1.collapsed') || '[]'); } catch (e) { return []; }
  }

  function saveCollapsed() {
    try { localStorage.setItem('icq1.collapsed', JSON.stringify(state.collapsed)); } catch (e) { /* private mode */ }
  }

  // ---------- API ----------

  function api(path, body) {
    var opts = { credentials: 'same-origin', cache: 'no-store', headers: {} };
    if (body !== undefined) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.headers['X-CSRF-Token'] = CSRF;
      opts.body = JSON.stringify(body);
    }
    return fetch('api/' + path, opts).then(function (res) {
      if (res.status === 401) {
        window.location.href = 'login.php?timeout=1';
        throw new Error('Logged out.');
      }
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok) {
          var msg = res.status === 403 ? 'The page is out of date. Reload it and try again.'
                                       : (data.error || 'Something went wrong. Please try again.');
          throw new Error(msg);
        }
        return data;
      });
    });
  }

  // ---------- polling ----------

  function schedule() {
    clearTimeout(state.timer);
    state.timer = setTimeout(poll, document.hidden ? POLL_HIDDEN : POLL_VISIBLE);
  }

  function poll() {
    if (state.polling) { return; }
    state.polling = true;
    var uin = state.open;
    var q = uin ? '?with=' + uin + '&after=' + state.lastId + '&focus=' + (isFocused() ? 1 : 0) : '';

    api('poll.php' + q)
      .then(function (d) { onPoll(d, uin); })
      .catch(function () { /* offline for a moment: try again next time */ })
      .then(function () { state.polling = false; schedule(); });
  }

  function onPoll(d, askedUin) {
    state.me.status = d.me.status;
    state.me.text = d.me.status_text || '';
    state.me.presence = d.me.presence;
    renderMyStatus();

    var newPeerText = null;
    if (d.chat && d.chat.with === state.open && askedUin === state.open) {
      var added = addMessages(d.chat.messages, true);
      for (var i = added.length - 1; i >= 0; i--) {
        if (!added[i].mine) { newPeerText = added[i].body; break; }
      }
      if (added.length) { scrollBottomIfNear(); }
      updateReceipts(d.chat.receipts);
      setTyping(d.chat.typing);
    }

    detectNews(d, newPeerText);

    state.contacts = d.contacts;
    state.incoming = d.incoming;
    state.outgoing = d.outgoing;
    renderRoster();

    if (state.open) {
      var c = findContact(state.open);
      if (c) { fillHeader(c); } else { closeChat(); } // removed from contacts meanwhile
    }

    setBadge('unread-badge', d.mail_unread);
    setBadge('chat-badge', totalUnread());
    updateTitle();
  }

  // Sound, desktop notification and blinking title for anything new since the last poll.
  function detectNews(d, newPeerText) {
    var now = {};
    d.contacts.forEach(function (c) { now[c.uin] = c.unread; });

    if (state.prevUnread !== null) {
      d.contacts.forEach(function (c) {
        if (c.unread > (state.prevUnread[c.uin] || 0)) {
          var text = c.uin === state.open && newPeerText ? newPeerText : 'New message';
          play('message');
          desktopNotify(c.name, text, c.photo, c.uin);
          blinkTitle(c.name + ' writes…');
        }
      });
      if (d.incoming.length > state.prevIncoming) {
        var r = d.incoming[d.incoming.length - 1];
        play('message');
        desktopNotify(r.name, 'wants to add you to their contacts', r.photo, null);
        blinkTitle('Authorization request');
      }
      if (d.mail_unread > state.prevMail) {
        play('mail');
        blinkTitle('New letter');
      }
    }
    state.prevUnread = now;
    state.prevIncoming = d.incoming.length;
    state.prevMail = d.mail_unread;
  }

  function desktopNotify(title, body, photo, uin) {
    if (!('Notification' in window) || Notification.permission !== 'granted') { return; }
    if (!document.hidden || state.me.status === 'dnd') { return; }
    try {
      var n = new Notification(title, {
        body: body.length > 120 ? body.slice(0, 117) + '…' : body,
        icon: photoUrl(photo),
        tag: 'icq1-' + (uin || 'request')
      });
      n.onclick = function () { window.focus(); if (uin) { openChat(uin); } n.close(); };
    } catch (e) { /* some mobile browsers allow notifications only from a service worker */ }
  }

  function blinkTitle(text) {
    if (!document.hidden || blinkTimer) { return; }
    var on = false;
    blinkTimer = setInterval(function () {
      on = !on;
      document.title = on ? '✉ ' + text : baseTitle;
    }, 1000);
  }

  function totalUnread() {
    return state.contacts.reduce(function (sum, c) { return sum + c.unread; }, 0);
  }

  function updateTitle() {
    var n = totalUnread() + state.incoming.length;
    baseTitle = (n ? '(' + n + ') ' : '') + 'Messenger - ICQ1.0';
    if (!blinkTimer) { document.title = baseTitle; }
  }

  function setBadge(id, count) {
    var b = byId(id);
    if (!b) { return; }
    b.textContent = String(count);
    b.hidden = count === 0;
  }

  // ---------- my status ----------

  function renderMyStatus() {
    var p = state.me.presence;
    var autoAway = p === 'away' && state.me.status === 'online';
    byId('my-dot').className = 'status-dot st-' + p;
    byId('my-status').textContent = LABELS[p] || LABELS[state.me.status];
    byId('my-status-text').textContent = state.me.text;
    byId('status-btn').title = autoAway ? 'Away automatically after 10 minutes without activity' : '';
    var topDot = byId('me-dot');
    if (topDot) { topDot.className = 'status-dot st-' + p; }
    Array.prototype.forEach.call(document.querySelectorAll('#status-menu [data-status]'), function (b) {
      b.setAttribute('aria-checked', String(b.dataset.status === state.me.status));
    });
  }

  function setStatus(status, text) {
    return api('status.php', { status: status, text: text }).then(function () {
      state.me.status = status;
      state.me.text = text.trim();
      state.me.presence = status;
      renderMyStatus();
      toggleStatusMenu(false);
      poll();
    }).catch(function (err) { window.alert(err.message); });
  }

  function toggleStatusMenu(open) {
    var menu = byId('status-menu');
    var show = open === undefined ? menu.hidden : open;
    menu.hidden = !show;
    byId('status-btn').setAttribute('aria-expanded', String(show));
    if (show) { byId('status-text-input').value = state.me.text; }
  }

  // ---------- contact list ----------

  function renderRoster(force) {
    var key = JSON.stringify([state.contacts, state.incoming, state.outgoing, state.filter, state.open, state.collapsed]);
    if (key === state.rosterKey && !force) { return; } // nothing changed: keep hover and focus
    state.rosterKey = key;

    var list = byId('roster-list');
    list.textContent = '';
    var f = state.filter.trim().toLowerCase();

    // 1. authorization requests to me
    if (state.incoming.length) {
      list.appendChild(el('div', 'group-head', 'Authorization requests (' + state.incoming.length + ')'));
      state.incoming.forEach(function (r) {
        var box = el('div', 'request');
        var who = el('div', 'who');
        who.appendChild(avatar(r.photo));
        var t = el('div', 'contact-body');
        t.appendChild(el('span', 'contact-name', r.name));
        t.appendChild(el('span', 'contact-sub', 'UIN ' + fmtUin(r.uin) + ' wants to add you'));
        who.appendChild(t);
        box.appendChild(who);
        var actions = el('div', 'actions');
        actions.appendChild(button('Accept', 'btn-small', function () { contactAction('accept', r.uin); }));
        actions.appendChild(button('Decline', 'btn-quiet btn-small', function () { contactAction('decline', r.uin); }));
        actions.appendChild(button('Info', 'btn-quiet btn-small', function () { openInfo(r.uin); }));
        box.appendChild(actions);
        list.appendChild(box);
      });
    }

    // 2. contacts by group, online first
    var groups = {};
    var order = [];
    state.contacts.forEach(function (c) {
      if (f && c.name.toLowerCase().indexOf(f) === -1 && String(c.uin).indexOf(f) === -1) { return; }
      if (!groups[c.group]) { groups[c.group] = []; order.push(c.group); }
      groups[c.group].push(c);
    });

    order.forEach(function (g) {
      var members = groups[g].sort(function (a, b) {
        var ao = a.status === 'offline' ? 1 : 0;
        var bo = b.status === 'offline' ? 1 : 0;
        return ao - bo || a.name.localeCompare(b.name);
      });
      var online = members.filter(function (c) { return c.status !== 'offline'; }).length;
      var collapsed = state.collapsed.indexOf(g) !== -1;

      var head = button('', 'group-head', function () {
        var i = state.collapsed.indexOf(g);
        if (i === -1) { state.collapsed.push(g); } else { state.collapsed.splice(i, 1); }
        saveCollapsed();
        renderRoster();
      });
      head.setAttribute('aria-expanded', String(!collapsed));
      head.appendChild(el('span', 'caret', '▾'));
      head.appendChild(document.createTextNode(g + ' '));
      head.appendChild(el('span', 'count', online + '/' + members.length));
      list.appendChild(head);

      if (collapsed) { return; }
      members.forEach(function (c) { list.appendChild(contactRow(c)); });
    });

    // 3. my own requests nobody answered yet
    if (state.outgoing.length && !f) {
      list.appendChild(el('div', 'group-head', 'Waiting for authorization (' + state.outgoing.length + ')'));
      state.outgoing.forEach(function (r) {
        var box = el('div', 'request outgoing');
        var who = el('div', 'who');
        who.appendChild(avatar(r.photo));
        var t = el('div', 'contact-body');
        t.appendChild(el('span', 'contact-name', r.name));
        t.appendChild(el('span', 'contact-sub', 'Request sent'));
        who.appendChild(t);
        who.appendChild(button('Cancel', 'btn-quiet btn-small', function () { contactAction('remove', r.uin); }));
        box.appendChild(who);
        list.appendChild(box);
      });
    }

    if (!state.contacts.length && !state.incoming.length && !state.outgoing.length) {
      var empty = el('div', 'roster-empty');
      empty.appendChild(el('p', null, 'No contacts yet. Find people by UIN or name and send them a request.'));
      empty.appendChild(button('Find people', 'btn-small', openAdd));
      list.appendChild(empty);
    } else if (f && !order.length) {
      list.appendChild(el('p', 'roster-empty', 'Nobody matches “' + state.filter + '”.'));
    }
  }

  function contactRow(c) {
    var row = button('', 'contact' + (c.uin === state.open ? ' is-open' : '') + (c.status === 'offline' ? ' is-offline' : ''),
                     function () { openChat(c.uin); });
    row.appendChild(avatar(c.photo, c.status));
    var body = el('span', 'contact-body');
    body.appendChild(el('span', 'contact-name', c.name));
    body.appendChild(el('span', 'contact-sub', statusLine(c)));
    row.appendChild(body);
    if (c.unread) {
      var b = el('span', 'badge', String(c.unread));
      b.setAttribute('aria-label', c.unread + ' unread');
      row.appendChild(b);
    }
    row.title = c.name + ', UIN ' + fmtUin(c.uin);
    return row;
  }

  function contactAction(action, uin, extra) {
    var body = { action: action, uin: uin };
    if (extra) { body.group = extra; }
    return api('contacts.php', body).then(function (d) {
      if ((action === 'remove' || action === 'decline') && state.open === uin) { closeChat(); }
      poll();
      return d;
    }).catch(function (err) { window.alert(err.message); throw err; });
  }

  // ---------- conversation ----------

  function openChat(uin) {
    var c = findContact(uin);
    if (!c) { return; }
    if (state.open !== null) { state.drafts[state.open] = input.value; }

    state.open = uin;
    state.msgs = [];
    state.ids = {};
    state.lastId = 0;
    state.receipts = { delivered: 0, read: 0 };
    msgsEl.textContent = '';
    byId('older-btn').hidden = true;
    setTyping(false);
    hideComposerError();
    closeEmoji();

    byId('chat-empty').hidden = true;
    byId('chat-view').hidden = false;
    main.classList.add('chat-open');
    fillHeader(c);
    byId('peer-mail').href = 'compose.php?to=' + uin;
    renderRoster();

    input.value = state.drafts[uin] || '';
    autosize();
    if (!window.matchMedia('(max-width: 760px)').matches) { input.focus(); }
    try { history.replaceState(null, '', '#' + uin); } catch (e) { /* ignore */ }

    api('chat.php?with=' + uin).then(function (d) {
      if (state.open !== uin) { return; }
      addMessages(d.messages, true);
      byId('older-btn').hidden = d.messages.length < d.page;
      updateReceipts(d.receipts);
      logEl.scrollTop = logEl.scrollHeight;
      poll(); // marks the messages as read right away
    }).catch(showComposerError);
  }

  function closeChat() {
    if (state.open !== null) { state.drafts[state.open] = input.value; }
    state.open = null;
    byId('chat-view').hidden = true;
    byId('chat-empty').hidden = false;
    main.classList.remove('chat-open');
    try { history.replaceState(null, '', 'chat.php'); } catch (e) { /* ignore */ }
    renderRoster();
  }

  function fillHeader(c) {
    byId('peer-photo').src = photoUrl(c.photo);
    byId('peer-dot').className = 'status-dot st-' + c.status;
    byId('peer-name').textContent = c.name;
    var sub = byId('peer-sub');
    if (!byId('typing-row').hidden) {
      sub.textContent = 'typing…';
      sub.className = 'typing';
    } else {
      sub.textContent = statusLine(c);
      sub.className = '';
    }
  }

  // Adds messages that are not shown yet. advance=false for my own just-sent message:
  // the poll cursor must not skip a message from the other side with a smaller id.
  function addMessages(list, advance) {
    var added = [];
    var resort = false;
    list.forEach(function (m) {
      if (state.ids[m.id]) { return; }
      state.ids[m.id] = true;
      var last = state.msgs[state.msgs.length - 1];
      if (last && m.id < last.id) { resort = true; }
      state.msgs.push(m);
      added.push(m);
      if (advance) { state.lastId = Math.max(state.lastId, m.id); }
    });
    if (resort) {
      state.msgs.sort(function (a, b) { return a.id - b.id; });
      renderAll();
    } else {
      added.forEach(addMsgNode);
    }
    return added;
  }

  var lastDay = null;
  var lastMine = null;

  function renderAll() {
    msgsEl.textContent = '';
    lastDay = null;
    lastMine = null;
    state.msgs.forEach(addMsgNode);
    updateReceipts(state.receipts);
  }

  function addMsgNode(m) {
    var d = new Date(m.time);
    var day = d.toDateString();
    if (day !== lastDay) {
      msgsEl.appendChild(el('li', 'day', dayLabel(d)));
      lastDay = day;
      lastMine = null;
    }
    var li = el('li', 'msg ' + (m.mine ? 'mine' : 'theirs'));
    if (lastMine !== m.mine) { li.classList.add('first'); }
    lastMine = m.mine;
    li.dataset.id = String(m.id);

    var bubble = el('div', 'bubble');
    if (isEmojiOnly(m.body)) { bubble.classList.add('emoji-only'); }
    bubble.appendChild(document.createTextNode(m.body));

    var meta = el('span', 'meta');
    var t = el('time', null, d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }));
    t.dateTime = m.time;
    t.title = d.toLocaleString();
    meta.appendChild(t);
    if (m.mine) {
      var ticks = el('span', 'ticks');
      setTicks(ticks, m.state);
      meta.appendChild(ticks);
    }
    bubble.appendChild(meta);
    li.appendChild(bubble);
    msgsEl.appendChild(li);
  }

  function dayLabel(d) {
    var today = new Date();
    var yesterday = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 1);
    if (d.toDateString() === today.toDateString()) { return 'Today'; }
    if (d.toDateString() === yesterday.toDateString()) { return 'Yesterday'; }
    var opts = { day: 'numeric', month: 'long' };
    if (d.getFullYear() !== today.getFullYear()) { opts.year = 'numeric'; }
    return d.toLocaleDateString(undefined, opts);
  }

  function isEmojiOnly(text) {
    var s = text.trim();
    try {
      return s.length <= 16 && /^[\p{Extended_Pictographic}‍️\u{1F3FB}-\u{1F3FF}\s]+$/u.test(s);
    } catch (e) { return false; } // very old browsers without Unicode property escapes
  }

  function setTicks(node, st) {
    var label = st === 'read' ? 'Read' : (st === 'delivered' ? 'Delivered' : 'Sent');
    node.textContent = st === 'sent' ? '✓' : '✓✓';
    node.className = 'ticks ' + st;
    node.title = label;
    node.setAttribute('aria-label', label);
  }

  function updateReceipts(r) {
    state.receipts = r;
    state.msgs.forEach(function (m) {
      if (!m.mine) { return; }
      m.state = m.id <= r.read ? 'read' : (m.id <= r.delivered ? 'delivered' : 'sent');
    });
    Array.prototype.forEach.call(msgsEl.querySelectorAll('.msg.mine'), function (li) {
      var id = parseInt(li.dataset.id, 10);
      var st = id <= r.read ? 'read' : (id <= r.delivered ? 'delivered' : 'sent');
      var ticks = li.querySelector('.ticks');
      if (ticks && !ticks.classList.contains(st)) { setTicks(ticks, st); }
    });
  }

  function setTyping(on) {
    var row = byId('typing-row');
    if (row.hidden === !on) { return; }
    row.hidden = !on;
    var c = state.open ? findContact(state.open) : null;
    if (c) { fillHeader(c); }
    if (on) { scrollBottomIfNear(); }
  }

  function scrollBottomIfNear() {
    if (logEl.scrollHeight - logEl.scrollTop - logEl.clientHeight < 140) {
      logEl.scrollTop = logEl.scrollHeight;
    }
  }

  function loadOlder() {
    var uin = state.open;
    if (!uin || !state.msgs.length) { return; }
    var btn = byId('older-btn');
    btn.disabled = true;
    api('chat.php?with=' + uin + '&before=' + state.msgs[0].id).then(function (d) {
      if (state.open !== uin) { return; }
      var fromBottom = logEl.scrollHeight - logEl.scrollTop;
      d.messages.forEach(function (m) { state.ids[m.id] = true; });
      state.msgs = d.messages.concat(state.msgs);
      renderAll();
      logEl.scrollTop = logEl.scrollHeight - fromBottom; // keep the view where it was
      btn.hidden = d.messages.length < d.page;
    }).catch(showComposerError).then(function () { btn.disabled = false; });
  }

  // ---------- writing ----------

  function send(ev) {
    ev.preventDefault();
    var uin = state.open;
    var body = input.value.trim();
    if (!uin || !body || state.sending) { return; }

    state.sending = true;
    byId('send-btn').disabled = true;
    api('chat.php', { to: uin, body: body }).then(function (d) {
      input.value = '';
      state.drafts[uin] = '';
      autosize();
      hideComposerError();
      closeEmoji();
      if (state.open === uin) {
        addMessages([d.message], false);
        logEl.scrollTop = logEl.scrollHeight;
      }
      state.lastTyping = 0;
    }).catch(showComposerError).then(function () {
      state.sending = false;
      byId('send-btn').disabled = false;
      input.focus();
    });
  }

  function onType() {
    autosize();
    var now = Date.now();
    if (state.open && input.value.trim() && now - state.lastTyping > TYPING_EVERY) {
      state.lastTyping = now;
      api('typing.php', { to: state.open }).catch(function () {});
    }
  }

  function autosize() {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight + 2, 160) + 'px';
  }

  function showComposerError(err) {
    var p = byId('composer-error');
    p.textContent = err && err.message ? err.message : 'Something went wrong. Please try again.';
    p.hidden = false;
  }

  function hideComposerError() { byId('composer-error').hidden = true; }

  // ---------- smileys ----------

  function buildEmoji() {
    var panel = byId('emoji-panel');
    EMOJI.forEach(function (e) {
      var b = button(e, null, function () {
        var start = input.selectionStart;
        var end = input.selectionEnd;
        input.setRangeText(e, start, end, 'end');
        input.focus();
        onType();
      });
      b.setAttribute('aria-label', e);
      panel.appendChild(b);
    });
  }

  function toggleEmoji() {
    var panel = byId('emoji-panel');
    panel.hidden = !panel.hidden;
    byId('emoji-btn').setAttribute('aria-expanded', String(!panel.hidden));
  }

  function closeEmoji() {
    byId('emoji-panel').hidden = true;
    byId('emoji-btn').setAttribute('aria-expanded', 'false');
  }

  // ---------- dialogs: add contact, info card, groups ----------

  var searchTimer = null;

  function openAdd() {
    var dlg = byId('dlg-add');
    byId('search-input').value = '';
    byId('search-results').textContent = '';
    dlg.showModal();
    byId('search-input').focus();
  }

  function search() {
    clearTimeout(searchTimer);
    var q = byId('search-input').value.trim();
    var list = byId('search-results');
    if (q.length < 2) { list.textContent = ''; return; }
    searchTimer = setTimeout(function () {
      api('search.php?q=' + encodeURIComponent(q)).then(function (d) {
        if (byId('search-input').value.trim() !== q) { return; } // a newer query is on its way
        list.textContent = '';
        if (!d.results.length) {
          list.appendChild(el('li', 'hint', 'Nobody found. Check the UIN or try another name.'));
          return;
        }
        d.results.forEach(function (u) { list.appendChild(resultRow(u)); });
      }).catch(function (err) {
        list.textContent = '';
        list.appendChild(el('li', 'error', err.message));
      });
    }, 300);
  }

  function resultRow(u) {
    var li = el('li');
    li.appendChild(avatar(u.photo));
    var who = el('span', 'who');
    who.appendChild(el('b', null, u.name));
    who.appendChild(el('small', null, 'UIN ' + fmtUin(u.uin) + (u.city ? ', ' + u.city : '')));
    li.appendChild(who);

    var act;
    switch (u.relation) {
      case 'self':
        act = el('small', null, 'That is you');
        break;
      case 'contact':
        act = button('Chat', 'btn-small', function () { byId('dlg-add').close(); openChat(u.uin); });
        break;
      case 'outgoing':
        act = button('Requested', 'btn-quiet btn-small', function () {});
        act.disabled = true;
        break;
      case 'incoming':
        act = button('Accept', 'btn-small', function () {
          contactAction('accept', u.uin).then(function () { li.replaceChild(el('small', null, 'Added'), act); });
        });
        break;
      default:
        act = button('Add', 'btn-small', function () {
          act.disabled = true;
          contactAction('request', u.uin).then(function (d) {
            act.textContent = d.relation === 'contact' ? 'Added' : 'Requested';
            act.className = 'btn-quiet btn-small';
          }).catch(function () { act.disabled = false; });
        });
    }
    li.appendChild(act);
    return li;
  }

  function openInfo(uin) {
    var body = byId('info-body');
    body.textContent = 'Loading…';
    byId('dlg-info').showModal();

    api('user.php?uin=' + uin).then(function (d) {
      var u = d.user;
      body.textContent = '';
      var top = el('div', 'card-top');
      top.appendChild(avatar(u.photo, u.status || null, 96));
      var name = el('b', null, u.name);
      name.id = 'info-name';
      top.appendChild(name);
      top.appendChild(el('span', 'uin', 'UIN ' + fmtUin(u.uin)));
      if (u.status) { top.appendChild(el('span', null, statusLine(u))); }
      body.appendChild(top);

      var dl = el('dl');
      if (u.city) { addRow(dl, 'City', u.city); }
      if (u.status === 'offline' && u.last_seen) { addRow(dl, 'Last seen', new Date(u.last_seen).toLocaleString()); }
      if (u.relation === 'none' || u.relation === 'outgoing') {
        addRow(dl, 'Status', 'Visible after they accept your request');
      }
      if (dl.childNodes.length) { body.appendChild(dl); }
      if (u.about) { body.appendChild(el('div', 'card-about', u.about)); }

      var actions = el('div', 'card-actions');
      var close = function () { byId('dlg-info').close(); };
      if (u.relation === 'contact') {
        actions.appendChild(button('Write a message', null, function () { close(); openChat(u.uin); }));
        var mail = el('a', 'btn btn-quiet', 'Write a letter');
        mail.href = 'compose.php?to=' + u.uin;
        actions.appendChild(mail);
        actions.appendChild(button('Move to group', 'btn-quiet', function () { close(); openGroup(u.uin); }));
        actions.appendChild(button('Remove contact', 'btn-danger', function () {
          if (window.confirm('Remove ' + u.name + ' from your contacts? You will not see each other\'s status or be able to chat until a new request is accepted.')) {
            close();
            contactAction('remove', u.uin);
          }
        }));
      } else if (u.relation === 'none') {
        actions.appendChild(button('Add to contacts', null, function () { close(); contactAction('request', u.uin); }));
      } else if (u.relation === 'incoming') {
        actions.appendChild(button('Accept', null, function () { close(); contactAction('accept', u.uin); }));
        actions.appendChild(button('Decline', 'btn-quiet', function () { close(); contactAction('decline', u.uin); }));
      } else if (u.relation === 'outgoing') {
        actions.appendChild(button('Cancel request', 'btn-quiet', function () { close(); contactAction('remove', u.uin); }));
      }
      body.appendChild(actions);
    }).catch(function (err) { body.textContent = err.message; });
  }

  function addRow(dl, term, value) {
    dl.appendChild(el('dt', null, term));
    dl.appendChild(el('dd', null, value));
  }

  var groupUin = null;

  function openGroup(uin) {
    var c = findContact(uin);
    if (!c) { return; }
    groupUin = uin;
    var names = byId('group-names');
    names.textContent = '';
    var seen = {};
    state.contacts.forEach(function (x) {
      if (seen[x.group]) { return; }
      seen[x.group] = true;
      var o = el('option');
      o.value = x.group;
      names.appendChild(o);
    });
    byId('dlg-group-title').textContent = 'Move ' + c.name + ' to group';
    byId('group-input').value = c.group;
    byId('group-error').hidden = true;
    byId('dlg-group').showModal();
    byId('group-input').select();
  }

  function saveGroup(ev) {
    ev.preventDefault();
    var g = byId('group-input').value.trim();
    api('contacts.php', { action: 'group', uin: groupUin, group: g }).then(function () {
      byId('dlg-group').close();
      poll();
    }).catch(function (err) {
      var p = byId('group-error');
      p.textContent = err.message;
      p.hidden = false;
    });
  }

  // ---------- desktop notifications permission ----------

  function setupNotifButton() {
    var btn = byId('notif-btn');
    if (!('Notification' in window) || Notification.permission !== 'default') { btn.hidden = true; return; }
    btn.hidden = false;
    btn.addEventListener('click', function () {
      Notification.requestPermission().then(function () { btn.hidden = true; });
    });
  }

  // ---------- wiring ----------

  byId('composer').addEventListener('submit', send);
  input.addEventListener('input', onType);
  input.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing) { send(ev); } // Shift+Enter = new line
  });
  byId('older-btn').addEventListener('click', loadOlder);
  byId('back-btn').addEventListener('click', closeChat);
  byId('peer-info').addEventListener('click', function () { if (state.open) { openInfo(state.open); } });
  byId('emoji-btn').addEventListener('click', toggleEmoji);
  byId('add-btn').addEventListener('click', openAdd);
  byId('search-input').addEventListener('input', search);
  byId('group-form').addEventListener('submit', saveGroup);
  byId('filter').addEventListener('input', function (ev) { state.filter = ev.target.value; renderRoster(); });

  byId('status-btn').addEventListener('click', function () { toggleStatusMenu(); });
  Array.prototype.forEach.call(document.querySelectorAll('#status-menu [data-status]'), function (b) {
    b.addEventListener('click', function () { setStatus(b.dataset.status, state.me.text); });
  });
  byId('status-text-form').addEventListener('submit', function (ev) {
    ev.preventDefault();
    setStatus(state.me.status, byId('status-text-input').value);
  });

  Array.prototype.forEach.call(document.querySelectorAll('dialog [data-close]'), function (b) {
    b.addEventListener('click', function () { b.closest('dialog').close(); });
  });

  document.addEventListener('click', function (ev) {
    if (!byId('status-menu').hidden && !ev.target.closest('#status-menu, #status-btn')) { toggleStatusMenu(false); }
    if (!byId('emoji-panel').hidden && !ev.target.closest('#emoji-panel, #emoji-btn')) { closeEmoji(); }
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') { toggleStatusMenu(false); closeEmoji(); }
  });

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) {
      clearInterval(blinkTimer);
      blinkTimer = null;
      document.title = baseTitle;
    }
    poll(); // coming back marks the open chat as read; going away slows polling down
  });
  window.addEventListener('focus', poll);

  buildEmoji();
  setupNotifButton();
  renderMyStatus();

  // First load, then open the chat from the address (#UIN), e.g. after a notification click.
  api('poll.php').then(function (d) {
    onPoll(d, null);
    var fromHash = parseInt(window.location.hash.slice(1), 10);
    if (fromHash && findContact(fromHash)) { openChat(fromHash); }
  }).catch(function () {
    byId('roster-list').textContent = '';
    byId('roster-list').appendChild(el('p', 'roster-empty', 'Could not load your contacts. Check the connection.'));
  }).then(schedule);
})();
