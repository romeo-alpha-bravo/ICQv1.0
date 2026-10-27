<?php
declare(strict_types=1);

// Helpers for the JSON API in api/. Every API script starts with:
//   define('AUTH_API', true);  require_once __DIR__ . '/../includes/api.php';
// GET = reading (background polling, does not extend the session),
// POST = an action: needs the X-CSRF-Token header and a JSON body.

require_once __DIR__ . '/auth.php';

// Sends a JSON response and ends the request.
function api_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Error with a message that is safe to show to the user.
function api_error(string $message, int $status = 400): never
{
    api_json(['error' => $message], $status);
}

// Runs the endpoint: expected problems become 400 with their message,
// anything else is logged and answered with a generic 500.
function api_run(callable $handler): never
{
    try {
        api_json($handler());
    } catch (InvalidArgumentException $ex) {
        api_error($ex->getMessage());
    } catch (Throwable $ex) {
        error_log('ICQ1.0 API ' . ($_SERVER['SCRIPT_NAME'] ?? '') . ': ' . $ex->getMessage());
        api_error('Something went wrong. Please try again.', 500);
    }
}

// For actions: requires POST and a valid CSRF token, returns the decoded JSON body.
function api_post_body(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        api_error('Method not allowed.', 405);
    }
    csrf_check(); // header X-CSRF-Token; answers 403 when wrong

    $raw = file_get_contents('php://input', false, null, 0, 65536);
    $data = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($data)) {
        api_error('Invalid request.');
    }
    return $data;
}

// Positive integer from input (UIN, message id), or 0 when missing or invalid.
function api_int(array $in, string $key): int
{
    $value = $in[$key] ?? null;
    if (is_int($value)) {
        return max(0, $value);
    }
    return is_string($value) && ctype_digit($value) && strlen($value) <= 10 ? (int)$value : 0;
}

// String from input, '' when missing or not a string.
function api_str(array $in, string $key): string
{
    $value = $in[$key] ?? '';
    return is_string($value) ? $value : '';
}
