# Code reference

Every module in `includes/`, its constants and functions, followed by the pages and the API.
Functions marked *internal* are used only inside their own module (or, where noted, by one
closely related module); pages should not call them.

Exceptions used throughout:

- `InvalidArgumentException`: invalid input; the message is safe to show to the user.
- `RuntimeException`: server problem; the message is generic and the cause is in the log.

## config.php

Returns one array. Contains no secrets; reads environment variables first, then
`uin-mail.local.php` (path from `UIN_CONFIG_FILE`, default one folder above the project).
All settings are listed in [INSTALL.md](INSTALL.md#all-settings).

| Key | Value |
|---|---|
| `db` | `host`, `port`, `name`, `user`, `pass`, `charset` (`utf8mb4`) |
| `enc_key`, `hmac_key` | raw 32-byte keys |
| `timezone` | display time zone |
| `base_url` | site address for e-mailed links |
| `mail` | `mode`, `host`, `port`, `from` |
| `reset_ttl` | 1800 |
| `session_timeout` | 1200 |
| `online_window` | 90 (seconds without any request → offline) |
| `away_after` | 600 (seconds without a real action → Away) |
| `upload_dir`, `photo_width` (800), `photo_quality` (90) | photo settings |

Throws `RuntimeException('Server configuration error')` when a required value is missing or a
key is invalid.

## includes/db.php

| Function | Description |
|---|---|
| `db(): PDO` | Shared connection, created on the first call. Exceptions mode, associative fetch, real prepared statements, session time zone UTC. Throws `RuntimeException('Service temporarily unavailable')` if the connection fails. |

## includes/crypto.php

Constants: `CRYPTO_CIPHER = 'aes-256-gcm'`, `CRYPTO_IV_LEN = 12`, `CRYPTO_TAG_LEN = 16`.

| Function | Description |
|---|---|
| `encrypt(string $plain): string` | Returns binary `IV ‖ tag ‖ ciphertext` with a new random IV. |
| `decrypt(string $blob): string` | Reverses `encrypt()`. Throws `RuntimeException` if the blob is too short, modified or made with another key. |
| `hmac_email(string $email): string` | 64 hex characters: HMAC-SHA256 of the trimmed, lowercased address. |
| `crypto_key(string $name): string` | *Internal.* Key from config. |
| `crypto_fail(string $reason): never` | *Internal.* Logs and throws. |

## includes/auth.php

API scripts define `AUTH_API` before calling `require_login()`: errors are then returned as
JSON. A GET to such a script is **passive** (background polling): it keeps the user online
(`last_seen`) but neither refreshes the session timer nor `last_activity`. A POST is a real
action.

| Function | Description |
|---|---|
| `start_secure_session(): void` | Starts the session with secure cookie settings; applies the inactivity timeout and the fingerprint check. Safe to call repeatedly. |
| `login_user(int $userId): void` | Call after a successful `password_verify()`: new session id, fresh session data, new CSRF token, updates `last_activity`. |
| `logout_user(): void` | Marks the user offline, clears the session and deletes its cookie. |
| `require_login(): array` | Returns the current user (`id, uin, login, first_name, last_name, gender, photo_path, role, status, status_text, city, about, last_activity, last_seen, created_at`; no encrypted fields). Otherwise redirects to `login.php` (401 JSON for `AUTH_API`). |
| `require_admin(): array` | Like `require_login()`, but answers 403 to non-admins. |
| `csrf_token(): string` | The session's CSRF token (64 hex characters). |
| `csrf_check(): void` | Compares the `csrf` POST field or the `X-CSRF-Token` header with the session token; stops with 403 when they differ. |
| `auth_is_passive(): bool` | True for a GET to an API script (polling). |
| `auth_config`, `auth_url`, `auth_is_https`, `auth_fingerprint`, `auth_reset_session`, `auth_stop` | *Internal.* Config access, URL building from subfolders, HTTPS detection, browser fingerprint, session wipe, ending a request with redirect/JSON/403. |

## includes/helpers.php

Constants: `APP_NAME = 'ICQ1.0'`, `ERR_GENERIC` (the generic error text).

| Function | Description |
|---|---|
| `e(string $s): string` | `htmlspecialchars($s, ENT_QUOTES, 'UTF-8')`. Use for every printed value. |
| `page_start(string $title, string $base = '', ?array $user = null): void` | Sends security headers and opens the document. Without `$user`: the centered card of guest pages. With `$user`: `mail_nav()` follows and prints the top bar. `$base` is `'../'` for pages in `admin/`. |
| `page_end(): void` | Closes whatever `page_start()` / `mail_nav()` opened. |
| `uin_fmt(int $uin): string` | `331376673` → `"331 376 673"`. |
| `brand_mark(int $size = 26): string` | The logo as inline SVG. |
| `avatar_img(string $photo, string $base = '', int $size = 32): string` | Small `<img>` of a profile photo for lists. |
| `csrf_field(): void` | Prints the hidden CSRF input. Needs `auth.php`. |
| `field_error(array $errors, string $key): void` | Prints the error of one form field, if any. |

## includes/users.php

| Function | Description |
|---|---|
| `password_error(string $pw): ?string` | Error text, or `null` when the password is 8–128 characters. |
| `validate_user_input(array $in, bool $isNew): array` | Returns `[$clean, $errors]`. Normalizes and checks names, e-mail (lowercased), phone (spaces and hyphens removed), gender, optional city and "about me"; with `$isNew` also login and password. |
| `find_conflicts(?string $login, string $email, int $exceptId = 0): array` | Errors for a login or e-mail already used by another user. `null` login skips that check; `$exceptId` excludes the user being edited. |
| `user_create(array $c, string $photoName): int` | Inserts a user with role `user` and a random UIN (retried on collision). Returns the id. |
| `user_update(int $id, array $c, ?string $photoName = null, ?string $newPassword = null): void` | Updates personal data including city and "about me"; `null` keeps the current photo or password. |
| `user_get_full(int $id): ?array` | One user with decrypted `email` and `phone`, without the password hash. |
| `user_check_password(int $id, string $password): bool` | Verifies a password against the stored hash. |
| `users_list(): array` | All users for the admin list (no encrypted fields). |
| `user_set_role(int $id, string $role): void` | Sets `user` or `admin`. The caller checks the permission. |
| `admin_count(): int` | Number of admins. |

## includes/image.php

Constants: `PHOTO_MAX_BYTES` (10 MB), `PHOTO_MAX_PIXELS` (20,000,000), `PHOTO_MAX_SIDE`
(10,000), `PHOTO_TYPES` (allowed image types).

| Function | Description |
|---|---|
| `process_photo(array $file): string` | Takes one `$_FILES` entry, validates it, converts it to JPEG (quality 90, 800 px wide) and stores it under a random name. Returns the file name. Uses Imagick when installed, otherwise GD. |
| `delete_photo(string $name): void` | Deletes a stored photo. Ignores any name not created by `process_photo()`. |
| `photo_config`, `photo_fail`, `photo_dir`, `photo_target_height`, `photo_convert_gd`, `photo_convert_imagick` | *Internal.* |

## includes/messages.php

Constants: `MSG_SUBJECT_MAX = 200`, `MSG_BODY_MAX = 10000`.

| Function | Description |
|---|---|
| `message_validate(array $in): array` | Returns `[$clean, $errors]` with `uin` (int), `subject`, `body`. |
| `recipient_by_uin(int $uin): ?array` | User (`id, uin, first_name, last_name`) or `null`. |
| `contacts_list(int $exceptId): array` | All other users with photo and `last_activity`. |
| `message_send(int $senderId, int $recipientId, string $subject, string $body): int` | Encrypts and stores a message. Returns its id. |
| `messages_inbox(int $userId): array` | Newest 200 received messages with decrypted `subject` and the sender's data. |
| `messages_sent(int $userId): array` | Newest 200 sent messages with decrypted `subject` and the recipient's data. |
| `message_get(int $messageId, int $userId): ?array` | One message for its sender or recipient only, with decrypted subject and body; marks it read for the recipient. `null` otherwise. |
| `messages_unread_count(int $userId): int` | Unread received messages. |
| `mail_nav(int $userId, string $active, string $base = '', string $mainClass = 'page'): void` | The top bar (Messenger and Mail badges, my photo with my status, logout), opens `<main class="$mainClass">`, adds the Inbox/Sent/Write tabs on mail pages and the badge poller on every page except the messenger. Shows **Users** to admins only. |
| `msg_time(?string $dt): string` | Formats a database time in the display time zone (`d.m.Y H:i`). Used by pages. |
| `msg_utc(?string $dt): ?DateTimeImmutable` | Parses a database time as UTC. |
| `msg_decrypt_safe`, `msg_list_row` | *Internal.* Decrypt for display without breaking a list; turn a list row's encrypted subject into text. |

## includes/presence.php

Constants: `STATUSES` (key → label: `online`, `away`, `na`, `dnd`, `invisible`), `STATUS_TEXT_MAX = 100`.

| Function | Description |
|---|---|
| `presence_of(array $u, bool $forSelf = false): string` | What a user looks like: `online`, `away`, `na`, `dnd`, `invisible` (only for themselves) or `offline`. Needs `status`, `last_seen`, `last_activity`. |
| `presence_label(string $presence): string` | Human label, `Offline` for unknown values. |
| `presence_last_seen(array $u): ?string` | "Last seen" for others; `null` while the user is invisible. |
| `status_set(int $userId, string $status, string $text): void` | Saves status and status text (one line, up to 100 characters). |
| `presence_config`, `presence_age` | *Internal.* |

## includes/contacts.php

Constants: `CONTACT_GROUP_DEFAULT = 'General'`, `CONTACT_GROUP_MAX = 40`.

| Function | Description |
|---|---|
| `user_by_uin(int $uin): ?array` | User row by public UIN (no e-mail, phone, role). |
| `contact_relation(int $me, int $other): string` | `self`, `contact`, `outgoing`, `incoming` or `none`. |
| `contacts_are_friends(int $a, int $b): bool` | Both sides accepted. Checked by every chat function. |
| `contact_request(int $me, int $other): string` | Asks for authorization, or accepts at once if the other side already asked. Returns the new relation. |
| `contact_accept(int $me, int $requester): bool` | Accepts a request in one transaction (adds the mirror row). |
| `contact_decline(int $me, int $requester): void` | Deletes the request. |
| `contact_remove(int $me, int $other): void` | Removes the contact on both sides, or cancels my request. |
| `contact_move(int $me, int $other, string $group): void` | Moves a contact into a group of my list. |
| `contacts_of(int $me): array` | Accepted contacts with `uin, name, photo, city, group, status, status_text, unread`. |
| `contact_requests(int $me, string $direction): array` | Pending requests, `incoming` or `outgoing`. |
| `users_search(int $me, string $q): array` | Up to 20 users by exact UIN or by login/name (LIKE wildcards escaped), each with `relation`. Status is not included. |
| `contact_public(array $row): array` | *Internal.* Public fields of a user for the browser. |

## includes/chat.php

Constants: `CHAT_BODY_MAX = 2000`, `CHAT_PAGE = 50`, `TYPING_TTL = 6`.

| Function | Description |
|---|---|
| `chat_validate(string $body): array` | `[$clean, $error]`. |
| `chat_send(int $me, int $peer, string $body): array` | Encrypts and stores a message to an accepted contact; ends my typing signal. Returns the message. |
| `chat_fetch(int $me, int $peer, int $afterId = 0, int $beforeId = 0): array` | Messages oldest first: newer than `$afterId`, the page before `$beforeId`, or the latest page. Each: `id, mine, body, time` (ISO UTC), `state` (`sent`/`delivered`/`read`, own messages only). |
| `chat_mark_delivered(int $me): void` | Everything sent to me is delivered. Called on every poll. |
| `chat_mark_read(int $me, int $peer): void` | Messages from `$peer` are read. Called only while that chat is visible. |
| `chat_receipts(int $me, int $peer): array` | `['delivered' => id, 'read' => id]`: highest such id of my messages. |
| `chat_unread_total(int $me): int` | Unread chat messages from everyone. |
| `typing_set(int $me, int $peer): void` | "I am typing to $peer" (contacts only). |
| `typing_is(int $peer, int $me): bool` | Is `$peer` typing to me within the last 6 s? |
| `chat_iso`, `chat_row` | *Internal.* |

## includes/api.php

| Function | Description |
|---|---|
| `api_json(array $data, int $status = 200): never` | JSON response with `no-store`. |
| `api_error(string $message, int $status = 400): never` | `{"error": message}`. |
| `api_run(callable $handler): never` | Runs an endpoint: `InvalidArgumentException` → 400 with its message, anything else → logged, 500 with a generic message. |
| `api_post_body(): array` | Requires POST (405 otherwise) and a valid `X-CSRF-Token` (403), returns the JSON body (400 if invalid). |
| `api_int(array $in, string $key): int`, `api_str(array $in, string $key): string` | Safe input readers. |

## includes/throttle.php

Constants: `THROTTLE_MAX_FAILS = 10`, `THROTTLE_WINDOW = 900`.

| Function | Description |
|---|---|
| `login_throttle_ok(string $login): bool` | `false` when this login or the client's IP has 10 failures in the window. |
| `login_throttle_fail(string $login): void` | Records a failure; occasionally removes expired rows. |
| `login_throttle_clear(string $login): void` | Clears the counters after a successful login. |
| `throttle_ip(): string` | Client IP as packed bytes. Also used by `password_reset.php`. |

## includes/mailer.php

| Function | Description |
|---|---|
| `send_mail(string $to, string $subject, string $body): void` | Plain-text e-mail in the configured mode (`log`, `smtp`, `mail`). Refuses line breaks in recipient and subject. Throws `RuntimeException` on delivery failure. |
| `smtp_send(...)` | *Internal.* Minimal SMTP client without authentication or TLS. |

## includes/password_reset.php

Constants: `RESET_MAX_PER_IP = 5` (per 15 minutes), `RESET_MAX_PER_USER = 3` (per hour).

| Function | Description |
|---|---|
| `reset_request(string $email): void` | Applies the limits, creates a token for a registered address and e-mails the link. Behaves the same for unknown addresses. Delivery errors are only logged. |
| `reset_find_valid(string $token): ?int` | User id for an unused, unexpired token, otherwise `null`. |
| `reset_complete(string $token, string $newPassword): bool` | Sets the new password and uses up all open tokens of the account in one transaction. `false` if the link is no longer valid. The caller validates the password. |
| `reset_store(...)` | *Internal.* Inserts a request row; occasionally deletes rows older than a day. |

## Pages

| Page | Access | Methods and parameters |
|---|---|---|
| `index.php` | anyone | GET; redirects to `chat.php` or `login.php` |
| `chat.php` | user | GET; the messenger (`#UIN` in the address opens that chat) |
| `register.php` | guests | GET form; POST registration |
| `login.php` | guests | GET (`?timeout=1`, `?reset=1` show a notice); POST login |
| `logout.php` | logged in | POST with CSRF; GET redirects to the profile |
| `forgot.php` | guests | GET form (`?sent=1` confirmation); POST e-mail |
| `reset.php` | guests | GET `?token=`; POST new password |
| `profile.php` | user | GET (`?saved=1`); POST own data |
| `inbox.php` | user | GET |
| `sent.php` | user | GET (`?sent=1` confirmation) |
| `compose.php` | user | GET (`?to=UIN` or `?re=messageId` prefill); POST send |
| `message.php` | user (sender or recipient) | GET `?id=` |
| `admin/users.php` | admin | GET (`?saved=1`); POST `id`, `role` |
| `admin/edit.php` | admin | GET `?id=`; POST user data, role, optional new password |
| `api/*.php` | user | JSON, see below |
| `install_admin.php` | command line only | interactive; answers 404 over HTTP |

"Guests" pages send a logged-in user to the messenger.

## API

All endpoints are same-origin, use the session cookie and answer JSON. **GET** endpoints only
read and do not extend the session. **POST** endpoints are actions: they need the header
`X-CSRF-Token` (the token is in `data-csrf` on the messenger page) and a JSON body. Users are
always addressed by **UIN**; internal ids never leave the server.

| Endpoint | Method | Input | Answer |
|---|---|---|---|
| `api/poll.php` | GET | `with` (UIN of the open chat), `after` (newest message id), `focus` (`1` = chat visible) | `me` (status, presence, status_text), `contacts`, `incoming`, `outgoing`, `mail_unread`, `chat` (`messages`, `receipts`, `typing`) or `null` |
| `api/chat.php` | GET | `with`, optional `before` | `messages`, `receipts`, `page`; 404 if not a contact |
| `api/chat.php` | POST | `{"to": UIN, "body": "..."}` | `message` |
| `api/typing.php` | POST | `{"to": UIN}` | `ok` |
| `api/status.php` | POST | `{"status": "dnd", "text": "Exam"}` | `ok` |
| `api/contacts.php` | POST | `{"action": "request\|accept\|decline\|remove\|group", "uin": UIN, "group": "..."}` | `relation` |
| `api/search.php` | GET | `q` (2–50 characters) | `results` with `relation` |
| `api/user.php` | GET | `uin` | `user`: name, UIN, photo, city, about, relation; status, status text and last seen for contacts only |
| `api/unread.php` | GET | – | `unread` (letters), `chat` (messages); used by `assets/notify.js` every 20 s |

| Status | Body | When |
|---|---|---|
| 400 | `{"error": "..."}` | invalid input; the message is safe to show |
| 401 | `{"error": "unauthorized"}` | not logged in or the session expired; the client goes to `login.php?timeout=1` |
| 403 | `{"error": "forbidden"}` | missing or wrong CSRF token |
| 404 | `{"error": "..."}` | user or conversation not found (also for "not your contact") |
| 405 | `{"error": "Method not allowed."}` | GET to an action |
| 500 | `{"error": "Something went wrong..."}` | server problem, details in the log |

## Command-line scripts

| Script | Purpose |
|---|---|
| `install_admin.php` | Sets password, e-mail and phone of the locked default `admin` (asks interactively; the password is not echoed). Does nothing once the account is active. |

## SQL files

| File | Purpose |
|---|---|
| `schema.sql` | Database, all six tables and the locked `admin` row. Used for a new installation. |
| `migrate_stage7.sql` | Adds `login_attempts` to a database created earlier. |
| `migrate_password_reset.sql` | Adds `password_resets` to a database created earlier. |
| `migrate_messenger.sql` | Adds the status and profile columns, `contacts` and `chat_messages`. Safe to run again. |
