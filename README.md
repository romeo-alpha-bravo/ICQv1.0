# ICQ1.0

A retro web messenger with e-mail-like internal mail, styled after late-1990s desktop
messengers. Every user gets a numeric **UIN**, can write messages to other users, sees who
is online and gets notified about new mail. An administrator manages all accounts.

University assignment for *BVWA2 – Vývoj webových aplikací II*. Plain PHP, no framework.

## Features

- Registration with first and last name, e-mail, phone, gender, profile photo, login and password
- Validation in the browser (instant) and again on the server
- Profile photos converted to JPEG (quality 90, 800 px wide); PNG, GIF, BMP, TIFF and JPEG accepted
- Personal data protected under GDPR: passwords hashed (Argon2id), e-mail and phone encrypted (AES-256-GCM)
- Session login with automatic logout after 20 minutes of inactivity and session hijacking protection
- Internal mail: Inbox, Sent (with read status), Write, Reply; messages stored encrypted
- New-mail notification: badge, count in the browser tab, short sound
- Contact list split into Online and Offline
- Admin panel: view and edit any user, grant or revoke the admin role, reset passwords
- Password recovery by e-mail ("Forgot your password?")
- Brute-force protection for login and password recovery
- Responsive retro interface (desktop and mobile)

## Tech stack

| Part | Technology |
|---|---|
| Server | PHP 8.1 or newer (developed on 8.5), built-in server or Apache |
| Database | MariaDB 11 (MySQL 8 also works), accessed only through PDO |
| Front end | HTML5, one hand-written CSS file, a little vanilla JavaScript (`fetch`) |
| PHP extensions | `pdo_mysql`, `openssl`, `mbstring`, `gd` (or `imagick` for TIFF), optionally `exif` |

## Quick start

Full instructions, including every setting, are in [docs/INSTALL.md](docs/INSTALL.md).

```bash
# 1. Database in Docker (run from the project folder)
docker run -d --name uin-mariadb \
  -e MARIADB_ROOT_PASSWORD=rootpass -e MARIADB_DATABASE=uin_mail \
  -e MARIADB_USER=uin_app -e MARIADB_PASSWORD=YOUR_DB_PASSWORD \
  -p 127.0.0.1:3306:3306 -v uin_mariadb_data:/var/lib/mysql \
  -v "$(pwd)/schema.sql:/docker-entrypoint-initdb.d/schema.sql:ro" mariadb:11

# 2. Local settings with the DB password and two encryption keys
#    (see docs/INSTALL.md, step 3), saved as ../uin-mail.local.php

# 3. Finish the default admin account and start the site
php install_admin.php
php -S localhost:8000
```

Open <http://localhost:8000> and log in as `admin`.

## Project structure

```
ICQ1.0/
├── index.php              entry point: inbox when logged in, otherwise login
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
├── contacts.php           contact list, online / offline
├── admin/
│   ├── users.php          all users, role switch (admin only)
│   └── edit.php           edit any user (admin only)
├── api/
│   └── unread.php         JSON unread counter for notifications
├── includes/              shared modules (no direct HTTP access needed)
│   ├── db.php             PDO connection
│   ├── crypto.php         AES-256-GCM encryption, HMAC of e-mail
│   ├── auth.php           sessions, login state, roles, CSRF
│   ├── image.php          photo validation and conversion
│   ├── users.php          user validation and data access
│   ├── messages.php       mail data access, navigation bar
│   ├── throttle.php       failed-login counter
│   ├── password_reset.php reset tokens
│   ├── mailer.php         e-mail delivery (log / SMTP / mail())
│   └── helpers.php        HTML escaping, page frame, form helpers
├── assets/
│   ├── style.css          retro interface
│   ├── notify.js          polls for new mail
│   └── notify.wav         notification sound
├── uploads/               profile photos (random file names)
│   ├── .htaccess          no script execution in this folder (Apache)
│   └── default.jpg        placeholder photo of the default admin
├── config.php             settings; contains no secrets
├── schema.sql             database schema and the default admin row
├── migrate_stage7.sql     adds login_attempts to an older database
├── migrate_password_reset.sql  adds password_resets to an older database
└── install_admin.php      one-time CLI script that activates the admin account
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
| Notification about new messages | `api/unread.php`, `assets/notify.js` |
| Messages stored encrypted | `message_send()` in `includes/messages.php` |

## Known limitations

These are deliberate simplifications for a course project; [docs/SECURITY.md](docs/SECURITY.md)
explains each one.

- E-mail is printed to the server log by default; real delivery needs an SMTP server.
- Changing a password does not end other open sessions (they still expire after 20 minutes).
- Profile photos are reachable by anyone who knows their random URL.
- Local development runs over HTTP, so the `Secure` cookie flag is off.
