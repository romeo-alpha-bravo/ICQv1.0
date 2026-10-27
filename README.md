# ICQ1.0

A web messenger in the spirit of the classic instant messengers, with an e-mail-like
internal mail next to it. Every user gets a numeric **UIN**, adds people to a contact list
with authorization, chats with them in real time, sets a status and gets notified about new
messages. An administrator manages all accounts.

University assignment for *BVWA2 – Vývoj webových aplikací II*. Plain PHP, no framework.

## Features

- Registration with first and last name, e-mail, phone, gender, profile photo, login and password
- Validation in the browser (instant) and again on the server
- Profile photos converted to JPEG (quality 90, 800 px wide); PNG, GIF, BMP, TIFF and JPEG accepted
- Personal data protected under GDPR: passwords hashed (Argon2id), e-mail and phone encrypted (AES-256-GCM)
- Session login with automatic logout after 20 minutes of inactivity and session hijacking protection
- **Messenger**: one-to-one chats with history; new messages arrive within 2–3 seconds
  without reloading; messages stored encrypted
- **Statuses**: Online, Away, Not available, Do not disturb, Invisible, plus a status text;
  Away is set automatically after 10 minutes without activity
- **"is typing…"** indicator and **ticks**: ✓ sent, ✓✓ delivered, blue ✓✓ read
- **Contact list with groups and authorization**: add people by UIN; they must accept before
  you can chat or see their status; unread counter per contact
- **Search** for people by UIN, login or name
- **Notifications**: sound, desktop notification, blinking tab title (silent in Do not disturb)
- **Smileys** panel and an **info card** for every user (city, about me, last seen)
- Internal mail: Inbox, Sent (with read status), Write, Reply; letters stored encrypted
- Admin panel: view and edit any user, grant or revoke the admin role, reset passwords
- Password recovery by e-mail ("Forgot your password?")
- Brute-force protection for login and password recovery
- Responsive interface (desktop and mobile)

## Tech stack

| Part | Technology |
|---|---|
| Server | nginx + PHP 8.3 FPM in Docker; also runs on PHP 8.1+ with the built-in server or Apache |
| Database | MariaDB 11 (MySQL 8 also works), accessed only through PDO |
| Front end | HTML5, one hand-written CSS file, a little vanilla JavaScript (`fetch`) |
| PHP extensions | `pdo_mysql`, `openssl`, `mbstring`, `gd` (or `imagick` for TIFF), optionally `exif` |

## Quick start

Requires Docker with Compose (Docker Desktop or OrbStack on macOS). Full instructions,
including the variant without Docker, are in [docs/INSTALL.md](docs/INSTALL.md).

```bash
make env      # creates .env with random DB passwords and encryption keys
make up       # builds and starts nginx, php-fpm, MariaDB and Mailpit
make admin    # activates the default admin account
```

| Address | What |
|---|---|
| <http://localhost:8080> | the application (log in as `admin`) |
| <http://localhost:8025> | Mailpit, the local inbox for password-reset e-mails |

`make` alone lists all shortcuts (logs, database console, reset, ...).

## Project structure

```
ICQ1.0/
├── index.php              entry point: messenger when logged in, otherwise login
├── chat.php               the messenger (contact list + conversation)
├── register.php           registration form
├── login.php              login form (with brute-force protection)
├── logout.php             logout (POST + CSRF only)
├── forgot.php             "Forgot your password?" – request a reset link
├── reset.php              set a new password from the e-mailed link
├── profile.php            own data: view and edit, change photo and password
├── inbox.php              incoming messages
├── sent.php               sent messages with read status
├── compose.php            write a message or a reply
├── message.php            read one message
├── admin/
│   ├── users.php          all users, role switch (admin only)
│   └── edit.php           edit any user (admin only)
├── api/                   JSON endpoints used by the browser
│   ├── poll.php           everything the messenger needs, every few seconds
│   ├── chat.php           conversation history (GET), send a message (POST)
│   ├── typing.php         "is typing…" signal (POST)
│   ├── status.php         change my status and status text (POST)
│   ├── contacts.php       request / accept / decline / remove / move to group (POST)
│   ├── search.php         find people
│   ├── user.php           info card of a user
│   └── unread.php         unread counters for the menu badges
├── includes/              shared modules (no direct HTTP access needed)
│   ├── db.php             PDO connection
│   ├── crypto.php         AES-256-GCM encryption, HMAC of e-mail
│   ├── auth.php           sessions, login state, roles, CSRF
│   ├── image.php          photo validation and conversion
│   ├── users.php          user validation and data access
│   ├── messages.php       mail data access, navigation bar
│   ├── chat.php           instant messages, ticks, "is typing"
│   ├── contacts.php       contact list with authorization, groups, search
│   ├── presence.php       statuses: what others see, auto Away, Invisible
│   ├── api.php            JSON helpers for api/*
│   ├── throttle.php       failed-login counter
│   ├── password_reset.php reset tokens
│   ├── mailer.php         e-mail delivery (log / SMTP / mail())
│   └── helpers.php        HTML escaping, page frame, form helpers
├── assets/
│   ├── style.css          the whole interface
│   ├── messenger.js       messenger client (polling, chat, dialogs, notifications)
│   ├── notify.js          menu badges on the other pages
│   ├── message.wav        new chat message sound
│   ├── notify.wav         new letter sound
│   └── icon.svg           browser tab icon
├── uploads/               profile photos (random file names)
│   ├── .htaccess          no script execution in this folder (Apache)
│   └── default.jpg        placeholder photo of the default admin
├── config.php             settings; contains no secrets
├── schema.sql             database schema and the default admin row
├── migrate_stage7.sql     adds login_attempts to an older database
├── migrate_password_reset.sql  adds password_resets to an older database
├── migrate_messenger.sql  adds statuses, contacts and chat to an older database
├── install_admin.php      one-time CLI script that activates the admin account
├── docker-compose.yml     the whole stack: web, php, db, mailpit
├── docker/                nginx config, PHP image, DB init script
├── .env.example           template for secrets (real .env is never committed)
└── Makefile               shortcuts: make env / up / admin / logs / reset
```

## Documentation

| Document | For whom | Contents |
|---|---|---|
| [docs/INSTALL.md](docs/INSTALL.md) | whoever runs the project | requirements, setup step by step, all settings, mail, troubleshooting |
| [docs/USER_GUIDE.md](docs/USER_GUIDE.md) | users and admins | how to use every screen (basic manual) |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | developers | modules, request flow, database schema, main data flows |
| [docs/SECURITY.md](docs/SECURITY.md) | developers, reviewers | every protection, where it is implemented, known limits |
| [docs/REFERENCE.md](docs/REFERENCE.md) | developers | every module, constant and function, pages and the API |

## Assignment requirements and where they are met

| Requirement | Implementation |
|---|---|
| Registration form with name, surname, e-mail, phone, gender, photo, login, password | `register.php` |
| Suitable HTML input types, instant client validation | `type=email/tel/file/password`, `pattern`, `required`, small JS checks |
| Responsive interface | `assets/style.css` (window fills the screen on mobile, tables scroll) |
| Password stored as a one-way hash | Argon2id, `includes/users.php` |
| E-mail and phone encrypted | AES-256-GCM, `includes/crypto.php` |
| Server-side validation, unique login | `validate_user_input()`, `find_conflicts()`, `UNIQUE` index |
| SQL injection protection | PDO prepared statements only |
| Photo as JPEG q90, 800 px wide, from PNG/GIF/BMP/TIFF; other files rejected | `includes/image.php` |
| Default admin who can view and edit everyone and grant the admin role | `schema.sql`, `install_admin.php`, `admin/` |
| Others edit only themselves | `profile.php` always works with the logged-in user's id |
| Session login, logout after 20 min inactivity, hijacking protection | `includes/auth.php` |
| After login: own data, own role, internal mail | `profile.php`, `inbox.php`, `sent.php`, `compose.php` |
| Sent messages kept in "Sent" | `sent.php` |
| Notification about new messages | `api/poll.php` + `assets/messenger.js`, `api/unread.php` + `assets/notify.js` |
| Messages stored encrypted | `message_send()` in `includes/messages.php`, `chat_send()` in `includes/chat.php` |

## Known limitations

These are deliberate simplifications for a course project; [docs/SECURITY.md](docs/SECURITY.md)
explains each one.

- E-mail is printed to the server log by default; real delivery needs an SMTP server.
- Changing a password does not end other open sessions (they still expire after 20 minutes).
- Profile photos are reachable by anyone who knows their random URL.
- Local development runs over HTTP, so the `Secure` cookie flag is off.
- "Real time" is short polling (every 3 s), not WebSockets: simple and reliable on php-fpm,
  but it costs one small request per open tab every few seconds.
