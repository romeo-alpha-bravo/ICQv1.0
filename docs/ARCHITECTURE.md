# Architecture

ICQ1.0 is a server-rendered PHP application without a framework. Each page is one PHP file
that renders HTML; shared logic lives in small modules under `includes/`. The messenger
(`chat.php`) is the one page that renders only its frame and then works through a small JSON
API in `api/`, driven by `assets/messenger.js`.

## Overview

```
 Browser
   │  HTML forms (POST + CSRF)        messenger.js: fetch() every 3 s,
   │  links (GET)                     POST actions with X-CSRF-Token
   ▼                                        ▼
 Page scripts                         api/poll, chat, typing, status,
 register, login, chat, inbox,        contacts, search, user, unread (JSON)
 compose, profile, admin/*, ...             │
   │  require_once ◄────────────────────────┘
   ▼
 includes/
   auth.php ─── sessions, roles, CSRF, passive polling vs. real actions
   users.php ── validation, user data      ─┐
   messages.php mail data, top bar          │
   chat.php ─── instant messages, ticks     ├─► crypto.php ─► AES-256-GCM / HMAC
   contacts.php authorization, groups       │
   presence.php statuses, auto Away         │
   password_reset.php, throttle.php        ─┘
   image.php ── photo conversion ─────────────► uploads/  (JPEG files)
   mailer.php ─ e-mail delivery
   helpers.php  escaping, page frame
   db.php ───── PDO ──────────────────────────► MariaDB  (users, messages, ...)
   │
   └─ config.php ◄── environment variables / ../uin-mail.local.php (secrets)
```

## Anatomy of a page

Every page follows the same order, so they read alike:

```php
require_once __DIR__ . '/includes/auth.php';      // 1. load modules
require_once __DIR__ . '/includes/helpers.php';
$user = require_login();                          // 2. access check (or require_admin)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();                                 // 3. CSRF check first
    [$clean, $errors] = validate_...($_POST);     // 4. server-side validation
    if (!$errors) {
        ...save...;                               // 5. act through a module function
        header('Location: ...');                  // 6. Post/Redirect/Get
        exit;
    }
}

page_start('Title', '', $user);                   // 7. render: document head,
mail_nav($user['id'], 'inbox.php');               //    top bar with badges + <main>,
?> ...HTML, every value through e()... <?php      //    escaped content,
page_end();                                       //    closing tags
```

Post/Redirect/Get means that refreshing the page after a successful form never submits it a
second time.

## Modules and dependencies

| Module | Depends on | Responsibility |
|---|---|---|
| `config.php` | – | reads settings and secrets, validates keys, returns one array |
| `db.php` | config | single shared PDO connection, UTC session time zone |
| `crypto.php` | config | `encrypt`, `decrypt`, `hmac_email` |
| `auth.php` | db, config | secure session start, timeout, login state, roles, CSRF |
| `helpers.php` | auth (for `csrf_field`) | `e()`, page frame, form helpers, `APP_NAME` |
| `users.php` | db, crypto | user validation, uniqueness, create, update, load, roles |
| `image.php` | config | photo validation and conversion |
| `messages.php` | db, crypto, auth, helpers, chat | mail queries, time formatting, top bar with badges |
| `presence.php` | db, config | chosen status vs. what others see, auto Away, Invisible, status text |
| `contacts.php` | db, presence | authorization requests, contact list with groups, search |
| `chat.php` | db, crypto, contacts | instant messages, delivered/read ticks, "is typing" |
| `api.php` | auth | JSON responses, POST + CSRF check, error mapping for `api/*` |
| `throttle.php` | db | failed-login counter |
| `mailer.php` | config | e-mail delivery (log, SMTP or `mail()`) |
| `password_reset.php` | db, crypto, helpers, mailer, throttle | reset tokens |

Modules load each other with `require_once`, so any page can include them in any order.
Every file starts with `declare(strict_types=1);`.

## Database

All tables use InnoDB and `utf8mb4`. Every `DATETIME` is stored in **UTC**: `db.php` sets
`time_zone = '+00:00'` on each connection, and `msg_time()` converts to the display time zone.

```
users 1 ──── * messages        (letters, as sender / as recipient)
users 1 ──── * chat_messages   (instant messages, as sender / as recipient)
users 1 ──── * contacts        (as owner of the list / as the contact)
users 1 ──── * password_resets
login_attempts   (no foreign key; keyed by login name and IP)
```

### users

| Column | Type | Notes |
|---|---|---|
| `id` | INT, PK | internal key, never shown |
| `uin` | INT UNSIGNED, UNIQUE | public number; random 10,000,000–999,999,999; admin has 10000 |
| `login` | VARCHAR(50), UNIQUE | case-insensitive through the collation |
| `password_hash` | VARCHAR(255) | Argon2id; `!` means a locked account |
| `first_name`, `last_name` | VARCHAR(100) | plain text |
| `email_enc` | VARBINARY(512) | AES-256-GCM, normalized lowercase address |
| `email_hash` | CHAR(64), UNIQUE | HMAC-SHA256 of the address, for uniqueness and lookup |
| `phone_enc` | VARBINARY(512) | AES-256-GCM |
| `gender` | ENUM `m`, `f`, `other` | |
| `photo_path` | VARCHAR(255) | file name inside `uploads/` |
| `role` | ENUM `user`, `admin` | read from the DB on every request |
| `status` | ENUM `online`, `away`, `na`, `dnd`, `invisible` | the status the user picked |
| `status_text` | VARCHAR(100) NULL | "At a lecture" |
| `city`, `about` | VARCHAR(100) / VARCHAR(500) NULL | info card, optional |
| `last_activity` | DATETIME NULL | last real action (page view, POST to the API); drives auto Away |
| `last_seen` | DATETIME NULL | any request, background polling included (written at most every 15 s); drives online/offline |
| `typing_to`, `typing_at` | INT / DATETIME NULL | "is typing" signal: to whom, and when (valid 6 s) |
| `created_at` | DATETIME | |

### messages

| Column | Type | Notes |
|---|---|---|
| `id` | INT, PK | |
| `sender_id`, `recipient_id` | INT, FK → users | |
| `subject_enc` | VARBINARY(1024) | encrypted subject (up to 200 characters) |
| `body_enc` | MEDIUMBLOB | encrypted body (up to 10,000 characters) |
| `sent_at` | DATETIME | |
| `read_at` | DATETIME NULL | `NULL` means unread |

Index `(recipient_id, read_at)` makes the unread counter cheap; it is queried every 20 seconds
by every open tab.

### login_attempts

One row per failed login: `login`, `ip` (packed, `VARBINARY(16)`, IPv4 or IPv6) and
`tried_at`. Indexed by `(login, tried_at)` and `(ip, tried_at)`. Old rows are deleted by
the application itself.

### password_resets

| Column | Notes |
|---|---|
| `user_id` | FK → users, `ON DELETE CASCADE`; `NULL` for requests with an unknown e-mail |
| `token_hash` | SHA-256 of the e-mailed token, UNIQUE; `NULL` for unknown e-mails |
| `ip` | packed address, for the per-IP limit |
| `created_at`, `expires_at` | lifetime 30 minutes |
| `used_at` | set when the token is used or replaced by a newer one |

Rows for unknown e-mails exist only so that the per-IP limit counts every request.

### contacts

| Column | Notes |
|---|---|
| `owner_id`, `contact_id` | PK together; FK → users, `ON DELETE CASCADE` |
| `state` | `pending`: owner asked contact for authorization; `accepted` |
| `group_name` | group in the owner's list, default `General` |

An accepted contact is always **two** rows, (A, B) and (B, A), so each side has its own group.
`contacts_are_friends()` requires both.

### chat_messages

| Column | Notes |
|---|---|
| `sender_id`, `recipient_id` | FK → users |
| `body_enc` | encrypted body (up to 2,000 characters) |
| `sent_at`, `delivered_at`, `read_at` | the three tick states |

Index `(sender_id, recipient_id, id)` serves a conversation in both directions;
`(recipient_id, read_at)` serves the unread counters.

### Encrypted value format

```
┌──────────────┬───────────────┬────────────────────┐
│ IV, 12 bytes │ tag, 16 bytes │ ciphertext, n bytes│
└──────────────┴───────────────┴────────────────────┘
```

A new random IV is generated for every value, so the same text never encrypts to the same
bytes. Encrypted values are bound to PDO parameters as `PDO::PARAM_LOB` because they are
arbitrary binary data.

## Main flows

### Registration

1. `validate_user_input($_POST, true)` checks every text field; the page checks that both
   passwords match.
2. `find_conflicts()` looks up the login and `hmac_email(email)`.
3. Only when everything is valid, `process_photo()` converts and stores the photo.
4. `user_create()` hashes the password, encrypts e-mail and phone, picks a random UIN and
   inserts the row. A duplicate UIN (unique index) is retried up to 5 times.
5. If the insert fails, `delete_photo()` removes the stored file.
6. `login_user()` starts a fresh session; the browser is sent to the profile.

### Login

1. `login_throttle_ok()`: fewer than 10 failures in 15 minutes for this login or IP.
2. The password hash is loaded by login. For an unknown login a dummy hash is still verified,
   so response time does not reveal whether the login exists.
3. Success: counters are cleared, the hash is upgraded if PHP's settings changed
   (`password_needs_rehash`), `login_user()` regenerates the session id.
4. Failure: `login_throttle_fail()`, and the same message for both a wrong login and a wrong
   password.

### Every protected request

`require_login()` → `start_secure_session()`:

1. Session cookie `UINSESSID` with `HttpOnly`, `SameSite=Strict`, `Secure` on HTTPS.
2. If more than 1200 s passed since `$_SESSION['last']`, or the browser fingerprint
   (`$_SESSION['fp']`) does not match, the session is wiped and the user is sent to
   `login.php?timeout=1`.
3. Otherwise `last` is refreshed, unless the request is **passive**: a GET to an API script
   (`AUTH_API`), i.e. background polling. POST requests to the API (sending a message,
   changing status) are real actions and do refresh it.
4. The user row is read from the database, so role changes and deleted accounts apply at once.
5. A real action updates `last_activity` and `last_seen`; a passive request only `last_seen`.

### Sending and reading a message

1. `compose.php` validates with `message_validate()`, resolves the UIN with
   `recipient_by_uin()` and refuses writing to oneself.
2. `message_send()` encrypts subject and body and inserts the row.
3. `message_get($id, $userId)` loads a message **only if the user is its sender or recipient**
   and marks it read when the recipient opens it. For anyone else it returns `null`, which
   the page shows as "Message not found", exactly like a missing id.

### Badges on the other pages

```
notify.js ──GET every 20 s──► api/unread.php ──► {"unread": 3, "chat": 1}
    │                             │
    │                             └─ AUTH_API: does not refresh the session,
    │                                errors come back as JSON (401 / 500)
    ├─ count went up → badge, tab title, sound
    └─ HTTP 401      → redirect to login.php?timeout=1
```

Because polling does not count as activity, an open but unused tab is still logged out after
20 minutes.

### Presence

`presence_of()` turns three columns into what other users see:

```
no request for 90 s (last_seen)                 → offline
status = invisible                              → offline   (the user sees "invisible")
status = online, no real action for 10 minutes  → away      (automatic)
otherwise                                       → the chosen status
```

An open tab polls, so it keeps `last_seen` fresh; closing it makes the user offline within
90 seconds, logging out at once. Polling is not an action, so an idle open tab becomes Away
after 10 minutes and is logged out after 20, exactly as the assignment requires.

### Authorization and contacts

```
A: request ──► (A,B pending) ──► B: accept ──► (A,B accepted) + (B,A accepted)
                              └► B: decline ──► row deleted
```

If B had already asked A, A's request accepts B's at once. Removing a contact deletes both rows.
Every chat, typing and status function checks `contacts_are_friends()` itself, so the API
cannot be used to write to, or watch, a stranger.

### Messenger polling

```
messenger.js ── GET api/poll.php?with=UIN&after=ID&focus=1 ── every 3 s (10 s in background)
                     │
                     ├─ chat_mark_delivered(me)        everything sent to me → ✓✓
                     ├─ focus=1 → chat_mark_read(me, peer)               → blue ✓✓
                     ├─ new messages of the open chat (id > after), decrypted
                     ├─ receipts: highest delivered / read id of my messages
                     ├─ typing_is(peer → me)
                     └─ my status, contacts with presence + unread, requests, unread mail
```

- `after` is the newest id the browser has; a just-sent message does not move it, so a reply
  with a smaller id that is still on its way is not skipped.
- Ticks only need two numbers per conversation, because messages are always marked in bulk,
  oldest included.
- "Read" is sent only while the chat is visible and the window has focus.
- The roster is re-drawn only when the data changed, so hover and keyboard focus survive polling.

### Password recovery

1. `forgot.php` → `reset_request(email)`: per-IP limit, lookup by `email_hash`, per-account
   limit, older tokens cancelled, new 32-byte token, only its SHA-256 stored, e-mail with
   `UIN_BASE_URL/reset.php?token=...`. The page answers the same way in every case.
2. `reset.php` checks the token with `reset_find_valid()`, shows the form, then
   `reset_complete()` sets the new hash and uses up all open tokens of the account inside one
   transaction (`SELECT ... FOR UPDATE`).

## Error handling

| Kind | Raised as | User sees |
|---|---|---|
| Invalid input (photo too small, duplicate e-mail, ...) | `InvalidArgumentException` with a safe message | that message next to the field |
| Login temporarily blocked | `DomainException` | the blocking message |
| Database, encryption, configuration, I/O problems | `RuntimeException('Internal error')` and similar | `ERR_GENERIC` ("Something went wrong...") |

The real reason is always written with `error_log()` and never shown, so no paths, SQL or
library messages reach the browser. `db.php` does not chain the original `PDOException`,
because its stack trace would contain the database password.

## Front end

- `assets/style.css`: one stylesheet with color tokens at the top. Status markers differ in
  color **and** shape (dot, moon, ring, bar), so they work without color vision. On screens up
  to 760 px the messenger shows either the contact list or the chat, with a back button; wide
  tables scroll inside their own box; inputs are 16 px on phones so iOS does not zoom.
- `assets/messenger.js`: the messenger client. All server data is inserted with
  `textContent` or attributes, never as HTML.
- `assets/notify.js`: menu badges on the other pages. Small page-specific scripts (password
  match, photo size and width check) are inline at the bottom of their forms.
- No external libraries, fonts or CDNs; the font is the system's rounded UI font.

## Conventions

- Code comments are short and in English.
- Functions meant only for use inside their own module are marked as internal in
  [REFERENCE.md](REFERENCE.md); pages call only the public ones.
- SQL is always a prepared statement with `?` placeholders; no variable is ever concatenated
  into SQL.
- Every value printed into HTML goes through `e()`.
- Settings come only from `config.php`; no file reads environment variables directly.
