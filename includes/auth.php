<?php
declare(strict_types=1);

// Sessions, login state, roles and CSRF protection. Include with require_once.
// API scripts (e.g. api/unread.php) must call define('AUTH_API', true) before
// require_login(): background polling then does not count as user activity
// (so the 20-minute timeout still works) and errors are returned as JSON.

require_once __DIR__ . '/db.php';

// ---------- Internal helpers (not part of the module contract) ----------

// Setting from config.php (loaded once per request).
function auth_config(string $name): mixed
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/../config.php';
    }
    return $cfg[$name];
}

// URL of a page in the project root; works from subfolders (admin/, api/).
function auth_url(string $page): string
{
    $root    = realpath(dirname(__DIR__));
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $base    = '';
    if ($root !== false && $docRoot !== false && str_starts_with($root, $docRoot)) {
        $base = str_replace(DIRECTORY_SEPARATOR, '/', substr($root, strlen($docRoot)));
    }
    return rtrim($base, '/') . '/' . $page;
}

function auth_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

// Browser fingerprint bound to the session (hijacking check).
function auth_fingerprint(): string
{
    return hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
}

// Drops all session data and issues a new session id.
function auth_reset_session(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

// Ends the request: JSON for API calls, redirect or plain text for pages.
function auth_stop(int $status, ?string $redirect = null): never
{
    if (defined('AUTH_API')) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $status === 401 ? 'unauthorized' : 'forbidden']);
    } elseif ($redirect !== null) {
        header('Location: ' . $redirect);
    } else {
        http_response_code($status);
        echo 'Access denied.';
    }
    exit;
}

// ---------- Public functions ----------

// Starts the session with secure settings; applies inactivity timeout
// and the hijacking check. Safe to call more than once.
function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $timeout = (int)auth_config('session_timeout');

    ini_set('session.use_strict_mode', '1');  // reject unknown ids (session fixation)
    ini_set('session.use_only_cookies', '1'); // never accept the id from the URL
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', (string)$timeout);

    session_name('UINSESSID');
    session_set_cookie_params([
        'lifetime' => 0,               // cookie dies with the browser
        'path'     => '/',
        'secure'   => auth_is_https(), // sent only over HTTPS when available
        'httponly' => true,            // not readable from JavaScript
        'samesite' => 'Strict',        // not sent with cross-site requests
    ]);
    session_start();

    if (isset($_SESSION['uid'])) {
        $expired = time() - (int)($_SESSION['last'] ?? 0) > $timeout;
        $foreign = !hash_equals((string)($_SESSION['fp'] ?? ''), auth_fingerprint());

        if ($expired || $foreign) {
            auth_reset_session();
            $_SESSION['timed_out'] = $expired;
            return;
        }
        if (!defined('AUTH_API')) {
            $_SESSION['last'] = time(); // only real user actions extend the session
        }
    }
}

// Call right after a successful password_verify() in login.php.
function login_user(int $userId): void
{
    start_secure_session();
    session_regenerate_id(true); // new id after login: prevents session fixation

    $_SESSION = [
        'uid'  => $userId,
        'last' => time(),
        'fp'   => auth_fingerprint(),
        'csrf' => bin2hex(random_bytes(32)),
    ];

    $st = db()->prepare('UPDATE users SET last_activity = NOW() WHERE id = ?');
    $st->execute([$userId]);
}

// Destroys the session and its cookie.
function logout_user(): void
{
    start_secure_session();
    $_SESSION = [];

    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 3600,
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => $p['samesite'],
    ]);
    session_destroy();
}

// Returns the logged-in user (without encrypted fields) or redirects to login.php.
function require_login(): array
{
    start_secure_session();

    if (empty($_SESSION['uid'])) {
        $query = !empty($_SESSION['timed_out']) ? '?timeout=1' : '';
        unset($_SESSION['timed_out']);
        auth_stop(401, auth_url('login.php' . $query));
    }

    // Read the user from the DB on every request, so a changed role
    // or a deleted account takes effect immediately.
    $st = db()->prepare(
        'SELECT id, uin, login, first_name, last_name, gender, photo_path,
                role, last_activity, created_at
           FROM users WHERE id = ?'
    );
    $st->execute([$_SESSION['uid']]);
    $user = $st->fetch();

    if ($user === false) {
        logout_user();
        auth_stop(401, auth_url('login.php'));
    }

    if (!defined('AUTH_API')) {
        $st = db()->prepare('UPDATE users SET last_activity = NOW() WHERE id = ?');
        $st->execute([$user['id']]);
    }

    return $user;
}

// Like require_login(), but only for role 'admin'; others get HTTP 403.
function require_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'admin') {
        auth_stop(403);
    }
    return $user;
}

// CSRF token of the current session; put it in every POST form as field "csrf".
function csrf_token(): string
{
    start_secure_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

// Checks the token from POST field "csrf" (or header X-CSRF-Token for fetch);
// stops the request with HTTP 403 when it is missing or wrong.
function csrf_check(): void
{
    start_secure_session();
    $sent = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (!is_string($sent) || empty($_SESSION['csrf'])
        || !hash_equals($_SESSION['csrf'], $sent)) {
        auth_stop(403);
    }
}
