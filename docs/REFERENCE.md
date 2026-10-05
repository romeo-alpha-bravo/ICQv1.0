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
| `online_window` | 300 |
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

Pages that answer `fetch()` define `AUTH_API` before calling `require_login()`; the session
timer is then not refreshed and errors are returned as JSON.

| Function | Description |
|---|---|
| `start_secure_session(): void` | Starts the session with secure cookie settings; applies the inactivity timeout and the fingerprint check. Safe to call repeatedly. |
| `login_user(int $userId): void` | Call after a successful `password_verify()`: new session id, fresh session data, new CSRF token, updates `last_activity`. |
| `logout_user(): void` | Clears the session and deletes its cookie. |
| `require_login(): array` | Returns the current user (`id, uin, login, first_name, last_name, gender, photo_path, role, last_activity, created_at`; no encrypted fields). Otherwise redirects to `login.php` (401 JSON for `AUTH_API`). |
| `require_admin(): array` | Like `require_login()`, but answers 403 to non-admins. |
| `csrf_token(): string` | The session's CSRF token (64 hex characters). |
| `csrf_check(): void` | Compares the `csrf` POST field or the `X-CSRF-Token` header with the session token; stops with 403 when they differ. |
| `auth_config`, `auth_url`, `auth_is_https`, `auth_fingerprint`, `auth_reset_session`, `auth_stop` | *Internal.* Config access, URL building from subfolders, HTTPS detection, browser fingerprint, session wipe, ending a request with redirect/JSON/403. |

## includes/helpers.php

Constants: `APP_NAME = 'ICQ1.0'`, `ERR_GENERIC` (the generic error text).

| Function | Description |
|---|---|
| `e(string $s): string` | `htmlspecialchars($s, ENT_QUOTES, 'UTF-8')`. Use for every printed value. |
| `page_start(string $title, string $base = '', ?array $user = null): void` | Sends security headers and opens the window. `$base` is `'../'` for pages in `admin/`; `$user` shows the UIN in the title bar. |
| `page_end(): void` | Closes the window and the document. |
| `avatar_img(string $photo, string $base = '', int $size = 32): string` | Small `<img>` of a profile photo for lists. |
| `csrf_field(): void` | Prints the hidden CSRF input. Needs `auth.php`. |
| `field_error(array $errors, string $key): void` | Prints the error of one form field, if any. |

## includes/users.php

| Function | Description |
|---|---|
| `password_error(string $pw): ?string` | Error text, or `null` when the password is 8–128 characters. |
| `validate_user_input(array $in, bool $isNew): array` | Returns `[$clean, $errors]`. Normalizes and checks names, e-mail (lowercased), phone (spaces and hyphens removed), gender; with `$isNew` also login and password. |
| `find_conflicts(?string $login, string $email, int $exceptId = 0): array` | Errors for a login or e-mail already used by another user. `null` login skips that check; `$exceptId` excludes the user being edited. |
| `user_create(array $c, string $photoName): int` | Inserts a user with role `user` and a random UIN (retried on collision). Returns the id. |
| `user_update(int $id, array $c, ?string $photoName = null, ?string $newPassword = null): void` | Updates personal data; `null` keeps the current photo or password. |
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
| `mail_nav(int $userId, string $active, string $base = ''): void` | The menu with the unread badge, the logout button and the notification script. Shows **Users** to admins only. |
| `msg_time(?string $dt): string` | Formats a database time in the display time zone (`d.m.Y H:i`). Used by pages. |
| `is_online(?string $lastActivity): bool` | Active within the online window. Used by pages. |
| `msg_utc(?string $dt): ?DateTimeImmutable` | Parses a database time as UTC. |
| `msg_decrypt_safe`, `msg_list_row` | *Internal.* Decrypt for display without breaking a list; turn a list row's encrypted subject into text. |

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
| `index.php` | anyone | GET; redirects to `inbox.php` or `login.php` |
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
| `contacts.php` | user | GET |
| `admin/users.php` | admin | GET (`?saved=1`); POST `id`, `role` |
| `admin/edit.php` | admin | GET `?id=`; POST user data, role, optional new password |
| `api/unread.php` | user | GET, JSON |
| `install_admin.php` | command line only | interactive; answers 404 over HTTP |

"Guests" pages send a logged-in user to the profile.

## API: `GET api/unread.php`

Used by `assets/notify.js` every 20 seconds. Same-origin, session cookie. Does not extend the
session.

| Status | Body | When |
|---|---|---|
| 200 | `{"unread": 3}` | logged in |
| 401 | `{"error": "unauthorized"}` | not logged in or the session expired; the script redirects to `login.php?timeout=1` |
| 500 | `{"error": "internal"}` | server problem |

Response headers: `Content-Type: application/json; charset=utf-8`, `Cache-Control: no-store`.

## Command-line scripts

| Script | Purpose |
|---|---|
| `install_admin.php` | Sets password, e-mail and phone of the locked default `admin` (asks interactively; the password is not echoed). Does nothing once the account is active. |

## SQL files

| File | Purpose |
|---|---|
| `schema.sql` | Database, all four tables and the locked `admin` row. Used for a new installation. |
| `migrate_stage7.sql` | Adds `login_attempts` to a database created earlier. |
| `migrate_password_reset.sql` | Adds `password_resets` to a database created earlier. |
