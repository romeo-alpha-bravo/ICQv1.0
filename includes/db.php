<?php
declare(strict_types=1);

// Returns a shared PDO connection (created on first call).
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = (require __DIR__ . '/../config.php')['db'];

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'], $cfg['port'], $cfg['name'], $cfg['charset']
    );

    try {
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // real prepared statements
        ]);
        // Store and read every DATETIME in UTC, whatever the server is set to;
        // display is converted to the app timezone in msg_time().
        $pdo->exec("SET time_zone = '+00:00'");
    } catch (PDOException $ex) {
        // Log details server-side; do not chain $ex, its trace holds the DB password.
        error_log('UIN-Mail DB connection failed: ' . $ex->getMessage());
        throw new RuntimeException('Service temporarily unavailable');
    }

    return $pdo;
}
