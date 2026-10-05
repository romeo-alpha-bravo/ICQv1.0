# Installation and configuration

This guide sets up ICQ1.0 on a development machine (macOS or Linux) with the database in
Docker and the PHP built-in web server. Notes for a real server are at the end.

## 1. Requirements

| Software | Version | Check |
|---|---|---|
| PHP | 8.1 or newer | `php -v` |
| PHP extensions | `pdo_mysql`, `openssl`, `mbstring`, `gd` | `php -m` |
| Optional extensions | `imagick` (needed for TIFF photos), `exif` (rotates phone photos) | `php -m` |
| Argon2 support in PHP | – | `php -r "var_dump(defined('PASSWORD_ARGON2ID'));"` must print `true` |
| Docker | any recent | `docker --version` |

Without `imagick` everything works, but TIFF uploads are rejected with a message.
On macOS with Homebrew: `brew install imagemagick pkg-config && pecl install imagick`.

### php.ini

Phone photos are often larger than PHP's defaults. Find the file with `php --ini` and set:

```ini
upload_max_filesize = 10M
post_max_size = 12M
memory_limit = 256M
```

## 2. Database

All commands are run from the project folder (`ICQ1.0/`).

```bash
docker run -d --name uin-mariadb \
  -e MARIADB_ROOT_PASSWORD=rootpass \
  -e MARIADB_DATABASE=uin_mail \
  -e MARIADB_USER=uin_app \
  -e MARIADB_PASSWORD=YOUR_DB_PASSWORD \
  -p 127.0.0.1:3306:3306 \
  -v uin_mariadb_data:/var/lib/mysql \
  -v "$(pwd)/schema.sql:/docker-entrypoint-initdb.d/schema.sql:ro" \
  mariadb:11
```

What this does:

- creates the database `uin_mail` and the application user `uin_app`;
- runs `schema.sql` on the very first start: all tables plus a locked `admin` row;
- keeps data in the Docker volume `uin_mariadb_data`, so it survives restarts;
- opens port 3306 only on `127.0.0.1`, so the database is not reachable from the network.

Check after 15–20 seconds:

```bash
docker ps                                  # status must be "Up"
docker exec -it uin-mariadb mariadb -u uin_app -p uin_mail -e "SHOW TABLES;"
```

Expected tables: `login_attempts`, `messages`, `password_resets`, `users`.

> **Run the command from the project folder.** If `schema.sql` does not exist at
> `$(pwd)/schema.sql`, Docker creates an empty *directory* with that name and the container
> stops with `Can't read from a directory`. See Troubleshooting.

### Without Docker

With a locally installed MariaDB or MySQL:

```bash
mysql -u root -p < schema.sql
mysql -u root -p -e "CREATE USER 'uin_app'@'localhost' IDENTIFIED BY 'YOUR_DB_PASSWORD';
  GRANT SELECT, INSERT, UPDATE, DELETE ON uin_mail.* TO 'uin_app'@'localhost';"
```

The application needs only these four privileges.

### Upgrading an older database

A database created from an older `schema.sql` lacks the newer tables. Apply the migrations
once (they are safe to run again):

```bash
docker exec -i uin-mariadb mariadb -u root -prootpass uin_mail < migrate_stage7.sql
docker exec -i uin-mariadb mariadb -u root -prootpass uin_mail < migrate_password_reset.sql
```

## 3. Local settings and secrets

`config.php` is committed to Git and contains **no secrets**. Real values come from
environment variables or from a local PHP file that is never committed.

### Where the local file goes

By default `config.php` looks for **`uin-mail.local.php` one level above the project
folder**:

```
03_Assignments/
├── uin-mail.local.php     ← here
└── ICQ1.0/                ← the project
```

To keep it elsewhere, set the environment variable `UIN_CONFIG_FILE` to its full path.
If you keep it inside the project, make sure `.gitignore` contains `*.local.php` and check
with `git check-ignore -v uin-mail.local.php`.

### Generating the two keys

```bash
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"   # run twice: two different keys
```

### The file

```php
<?php
return [
    'DB_HOST'      => '127.0.0.1',
    'DB_NAME'      => 'uin_mail',
    'DB_USER'      => 'uin_app',
    'DB_PASS'      => 'YOUR_DB_PASSWORD',
    'UIN_ENC_KEY'  => 'first key',
    'UIN_HMAC_KEY' => 'second key',
];
```

Use `127.0.0.1`, not `localhost`: with `localhost` PHP tries a Unix socket file that does not
exist for a database running in Docker (`[2002] No such file or directory`).

> **Back up both keys.** If `UIN_ENC_KEY` is lost or changed, every encrypted e-mail, phone
> number and message in the database becomes unreadable. If `UIN_HMAC_KEY` changes, existing
> users can no longer be found by e-mail.

### All settings

Every setting can be an environment variable or a key in the local file (environment wins).

| Setting | Required | Default | Meaning |
|---|---|---|---|
| `DB_HOST` | no | `localhost` | database host; use `127.0.0.1` for Docker |
| `DB_PORT` | no | `3306` | database port |
| `DB_NAME` | yes | – | database name |
| `DB_USER` | yes | – | database user |
| `DB_PASS` | no | empty | database password |
| `UIN_ENC_KEY` | yes | – | base64 of 32 random bytes, AES-256-GCM key |
| `UIN_HMAC_KEY` | yes | – | base64 of 32 random bytes, must differ from `UIN_ENC_KEY` |
| `UIN_TIMEZONE` | no | `Europe/Prague` | time zone for displayed dates (database stays in UTC) |
| `UIN_UPLOAD_DIR` | no | `uploads/` in the project | where profile photos are stored |
| `UIN_BASE_URL` | no | `http://localhost:8000` | site address used in e-mailed links |
| `UIN_MAIL_MODE` | no | `log` | `log`, `smtp` or `mail` (see step 6) |
| `UIN_MAIL_HOST` | no | `127.0.0.1` | SMTP host (smtp mode) |
| `UIN_MAIL_PORT` | no | `1025` | SMTP port (smtp mode) |
| `UIN_MAIL_FROM` | no | `no-reply@icq1.local` | sender address |
| `UIN_CONFIG_FILE` | no | `../uin-mail.local.php` | path to the local file (environment variable only) |

Fixed values in `config.php`: inactivity timeout 1200 s, online window 300 s, photo width
800 px, JPEG quality 90, reset link lifetime 1800 s.

Check that the file is found and the keys are valid:

```bash
php -r "var_dump(array_keys(require 'config.php'));"
```

## 4. Activate the admin account

`schema.sql` creates a locked `admin` row (UIN 10000): its encrypted fields need the key,
which must never appear in SQL. This script sets the password, e-mail and phone:

```bash
php install_admin.php
```

It works only from the command line, and only while the account is still locked. Expected
output: `Admin account is ready. You can log in as 'admin'.`

## 5. Run

```bash
php -S localhost:8000
```

Start it from the project folder and keep the terminal open; it also shows the error log.
Open <http://localhost:8000>.

## 6. E-mail (password recovery)

| `UIN_MAIL_MODE` | Behaviour | Use |
|---|---|---|
| `log` (default) | nothing is sent; the whole message, including the reset link, is printed in the `php -S` terminal | quick local testing |
| `smtp` | sent over plain SMTP without login or TLS | a local catcher such as Mailpit |
| `mail` | PHP `mail()` | a server with a configured mail system |

### Mailpit: a local inbox in the browser

```bash
docker run -d --name icq-mailpit -p 127.0.0.1:1025:1025 -p 127.0.0.1:8025:8025 axllent/mailpit
```

Add `'UIN_MAIL_MODE' => 'smtp',` to the local file and open <http://localhost:8025>.
Mailpit keeps every message locally and delivers nothing to the outside world, so test
addresses of strangers are safe.

Real providers (Gmail and others) require authentication and TLS, which the built-in SMTP
client does not support; use `mail` mode on a configured server or a library such as
PHPMailer.

## 7. Everyday commands

```bash
docker start uin-mariadb       # after a reboot
docker stop uin-mariadb        # stop the database (data is kept)
docker logs --tail 20 uin-mariadb
```

Start over with an empty database (**deletes all data**):

```bash
docker rm -f uin-mariadb && docker volume rm uin_mariadb_data
# then run the command from step 2 again and repeat step 4
```

## 8. Troubleshooting

The real cause of a server error is always in the `php -S` terminal, in a line that starts
with `UIN-Mail` or `ICQ1.0`. Users only see a generic message.

| Message | Cause and fix |
|---|---|
| `config error: missing UIN_ENC_KEY` (or another setting) | the local file is not found or lacks that key; check its location (step 3) |
| `... must be base64 of 32 random bytes` | key pasted wrongly; generate it again and paste only the string |
| `UIN_ENC_KEY and UIN_HMAC_KEY must differ` | the same key was used twice |
| `DB connection failed: [2002] No such file or directory` | `DB_HOST` is `localhost`; set `127.0.0.1` |
| `DB connection failed: [2002] Connection refused` | the container is not running: `docker ps -a`, `docker start uin-mariadb` |
| `DB connection failed: Access denied` | `DB_PASS` differs from `MARIADB_PASSWORD` |
| `Table '...login_attempts' doesn't exist` | old database; apply the migrations (step 2) |
| container `Exited`, log says `Can't read from a directory 'stdin'` | started outside the project folder; remove the stray `schema.sql` directory, the container and the volume, then start again from `ICQ1.0/` |
| `Not Found` for `/` | the server was started outside the project folder |
| page without styling | `assets/style.css` missing |
| photo rejected as too large although under 10 MB | raise `upload_max_filesize` and `post_max_size` (step 1) |
| "TIFF is not supported on this server" | install `imagick` |
| "Too many failed attempts" | 10 failed logins within 15 minutes; wait, or clear the table: `DELETE FROM login_attempts;` |

## 9. Running on a real server

- Serve the site over **HTTPS**; the session cookie then gets the `Secure` flag automatically.
- Keep `uin-mail.local.php` **outside the web root**, or use real environment variables.
- Turn off `display_errors` and log errors to a file.
- On Apache, `uploads/.htaccess` stops script execution in the photo folder. On nginx, add a
  rule that serves `uploads/` as static files only.
- Point `UIN_BASE_URL` at the public `https://` address.
- Use `mail` mode or a real SMTP relay instead of `log`.
- Grant the database user only `SELECT, INSERT, UPDATE, DELETE`.
