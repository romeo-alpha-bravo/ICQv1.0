# Security

This document lists every protection in ICQ1.0, why it is there and where it is implemented,
followed by the known limitations.

## Summary

| Threat | Protection | Where |
|---|---|---|
| Stolen database reveals passwords | Argon2id hashes | `users.php`, `login.php` |
| Stolen database reveals personal data and mail | AES-256-GCM encryption | `crypto.php` |
| Probing who is registered by e-mail | keyed HMAC instead of a plain hash | `crypto.php` |
| SQL injection | prepared statements only | all modules |
| Cross-site scripting (XSS) | every output escaped with `e()` | all pages |
| Cross-site request forgery (CSRF) | token in every POST form, `SameSite=Strict` cookie | `auth.php` |
| Session hijacking and fixation | cookie flags, strict mode, new id at login, fingerprint | `auth.php` |
| Forgotten open session | logout after 20 minutes of inactivity | `auth.php` |
| Access to admin pages or other people's data | server-side role and ownership checks | `auth.php`, `messages.php`, pages |
| Malicious uploads | content check, size limits, re-encoding, random names | `image.php`, `uploads/.htaccess` |
| Password guessing | 10 failures per 15 minutes per login or IP | `throttle.php` |
| Password reset abuse | hashed single-use tokens, limits, no account probing | `password_reset.php` |
| Information leaks in errors | generic messages, details only in the log | everywhere |
| Secrets in Git | settings outside the repository | `config.php` |

## Passwords

- Hashed with `password_hash(..., PASSWORD_ARGON2ID)`, a slow, memory-hard algorithm. The
  hash cannot be reversed; login uses `password_verify()`.
- 8 to 128 characters. The upper limit stops very long inputs from being used to load the
  server.
- `password_needs_rehash()` upgrades old hashes at the next login when PHP's defaults change.
- For an unknown login the server still verifies a dummy hash, so the response time does not
  reveal which logins exist. The error text is the same for a wrong login and a wrong password.
- Changing your own password requires the current one. An administrator can set a password
  without it, which is the purpose of an admin reset.

## Encryption of personal data

E-mail, phone, message subject and message body must be readable later, so they are
**encrypted**, not hashed.

- Algorithm: **AES-256-GCM** (`openssl_encrypt`). GCM is authenticated: a modified or
  corrupted value fails to decrypt instead of producing garbage.
- A new random 12-byte IV for every value; stored as `IV ‖ tag ‖ ciphertext`.
- The key (`UIN_ENC_KEY`, 32 random bytes) is never in the database or in Git, so a copy of
  the database alone is unreadable.
- A damaged message is shown as "(unreadable message)" instead of breaking the whole folder.

### E-mail uniqueness without plain e-mails

An encrypted e-mail cannot be compared, because the random IV makes every encryption
different. A second column `email_hash = HMAC-SHA256(normalized e-mail, UIN_HMAC_KEY)` carries
a `UNIQUE` index and serves lookups ("is this address registered", password recovery).

A plain SHA-256 would not be enough: anyone with the database could hash lists of common
addresses and find matches. With a secret key that is impossible. The HMAC key is separate
from the encryption key, so one leaked key does not expose the other function.

## SQL injection

Every query is a PDO prepared statement with `?` placeholders. No variable is ever
concatenated into SQL. `PDO::ATTR_EMULATE_PREPARES` is off, so the database server receives
the query and the values separately. Binary values are bound as `PDO::PARAM_LOB`.

## Cross-site scripting

- Every value printed into HTML goes through `e()` = `htmlspecialchars($s, ENT_QUOTES, 'UTF-8')`,
  including values printed inside attributes and the page title.
- Message bodies are escaped first and only then get line breaks (`nl2br(e($body))`).
- Input is validated as well: names allow only letters, spaces, apostrophe and hyphen;
  subjects cannot contain control characters; invalid UTF-8 is rejected.

## Cross-site request forgery

- Every POST form contains a hidden `csrf` field with a 256-bit token from the session.
- `csrf_check()` compares it with `hash_equals()` (constant time) and stops the request with
  HTTP 403 if it is missing or wrong. JavaScript can send it as the `X-CSRF-Token` header.
- Logout is a POST form too, so another site cannot log users out with a link or an image.
- The session cookie is `SameSite=Strict`, so the browser does not even send it with requests
  started from other sites. This is a second, independent layer.

## Sessions

| Measure | Against |
|---|---|
| Cookie `HttpOnly` | JavaScript (and thus XSS) cannot read the session id |
| Cookie `Secure` on HTTPS | the id cannot be sniffed on the network |
| Cookie `SameSite=Strict` | CSRF |
| `session.use_strict_mode`, `use_only_cookies`, no `trans_sid` | session fixation: the server ignores ids it did not create and never takes one from the URL |
| `session_regenerate_id(true)` at login and after a password change | session fixation |
| Session bound to a fingerprint of the browser (User-Agent) | a stolen cookie used from a different browser is rejected |
| Logout after 1200 s without activity | sessions left open on shared computers |
| Background polling (`AUTH_API`) does not refresh the timer | an open but unused tab still times out |
| User row and role read from the database on every request | role changes and deleted accounts take effect immediately |

The session id is not rotated periodically during a session: parallel `fetch` requests can
race with a rotation and log the user out. It changes at login and after a password change.

## Access control

- Each page starts with `require_login()` or `require_admin()`. Hiding a link is never the
  protection; a regular user who types `/admin/users.php` gets HTTP 403.
- `profile.php` always works with the id from the session, never with an id from the request,
  so users can edit only themselves.
- `message_get()` returns a message only to its sender or recipient. Any other id answers
  "Message not found", exactly like a non-existent one, so ids reveal nothing.
- Admins cannot change their own role, and the last admin cannot lose the role, so the system
  cannot lock itself out.

## File uploads

| Measure | Against |
|---|---|
| Type detected from the file content (`getimagesize`), not from the name or MIME type | fake extensions |
| Allow list: JPEG, PNG, GIF, BMP, TIFF | other formats |
| Max 10 MB, 10,000 px per side, 20 megapixels, checked before decoding | decompression bombs and memory exhaustion |
| Imagick is told the detected format explicitly (`png:/tmp/...`) | ImageMagick guessing a dangerous format |
| Every image decoded and **re-encoded** to JPEG | polyglot files and code hidden in images |
| Metadata stripped (EXIF, GPS) | leaking location data (GDPR) |
| Random 128-bit file names | guessing other users' photo URLs, overwriting files |
| `is_uploaded_file()` | processing arbitrary server files |
| `delete_photo()` accepts only generated names | path traversal such as `../config.php` |
| `uploads/.htaccess` disables script execution (Apache) | running uploaded code |

## Brute-force protection

- Login: every failure is stored in `login_attempts` with the login name and the client IP.
  At 10 failures within 15 minutes for the same login **or** the same IP, further attempts are
  refused without checking the password. A successful login clears the counters.
- Password recovery: at most 5 requests per IP per 15 minutes and 3 per account per hour.
  Requests above the limit are silently ignored.

## Password recovery

- The e-mailed token is 32 random bytes. Only its SHA-256 hash is stored, so a copy of the
  database does not contain usable links.
- Valid for 30 minutes and for one use. A new request cancels older links; using a link
  cancels all open links of the account.
- `reset_complete()` runs in a transaction with `SELECT ... FOR UPDATE`, so the same link cannot
  succeed twice in parallel.
- The page answers identically whether or not the e-mail is registered, so the form cannot be
  used to discover accounts.
- The link's address comes from `UIN_BASE_URL` in the configuration, never from the request's
  `Host` header, which an attacker could forge to receive the token.
- Line breaks are refused in mail recipients and subjects (mail header injection).

## Error handling

- Users see only short generic messages; details go to the server log with `error_log()`.
- `db.php` does not chain the original `PDOException`, whose stack trace would contain the
  database password.
- Validation messages ("photo must be at least 800 px wide") are safe and specific by design.

## HTTP headers

Every page sends:

| Header | Purpose |
|---|---|
| `X-Frame-Options: DENY` | the site cannot be embedded in another page (clickjacking) |
| `X-Content-Type-Options: nosniff` | browsers do not reinterpret file types |
| `Referrer-Policy: same-origin` | the reset token in the URL does not leak to other sites |
| `Cache-Control: no-store` | private pages are not shown from cache after logout |

## Secrets and configuration

- `config.php` holds no secrets and is committed. The database password and both keys come
  from environment variables or `uin-mail.local.php`, which is outside the repository.
- `config.php` validates the keys: exactly 32 bytes each and different from each other.
- `install_admin.php` runs only from the command line and only while the admin account is
  still locked.
- The database is reachable only on `127.0.0.1`, and the application user needs only
  `SELECT, INSERT, UPDATE, DELETE`.

## Known limitations

These are conscious trade-offs for a course project.

| Limitation | Effect | How it would be solved |
|---|---|---|
| The browser fingerprint is only the User-Agent | an attacker who copies the cookie and the User-Agent passes the check | HTTPS everywhere is the real protection; binding to the IP breaks mobile users |
| Changing the password does not end other sessions | a hijacked session lives until its 20-minute timeout | store sessions in the database, or a per-user "session version" checked on each request |
| Ciphertext is not bound to its row | someone with write access to the database could copy one user's `email_enc` to another | pass the row id as GCM additional data (AAD) |
| Photos are public to anyone who knows the URL | random names make guessing impractical, but links can be shared | serve photos through a PHP script that checks the login |
| Local development runs over HTTP | the `Secure` flag is off locally | HTTPS on the real server |
| `log` mail mode prints reset links into the log | anyone who can read the log can reset passwords | development only; use `smtp` or `mail` on a server |
| Per-IP limits use `REMOTE_ADDR` | behind a reverse proxy, all users share one IP | read the client IP from a trusted proxy header |
| All logged-in users can see every user's name and UIN | intended: it is a messenger with a contact list | – |
