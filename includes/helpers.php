<?php
declare(strict_types=1);

// Small output helpers shared by all pages. Include with require_once.
// csrf_field() needs includes/auth.php to be loaded as well.

const ERR_GENERIC = 'Something went wrong. Please try again later.';

// Escapes a value for safe output in HTML.
function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// Sends headers and opens the page. $base is the path prefix to the project root
// ('' for root pages, '../' for pages in admin/).
function page_start(string $title, string $base = ''): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store'); // do not show private pages after logout (Back button)

    echo '<!DOCTYPE html>' . "\n"
       . '<html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . e($title) . ' - UIN-Mail</title>'
       . '<link rel="stylesheet" href="' . e($base) . 'assets/style.css">'
       . '</head><body><main class="window">' . "\n";
}

function page_end(): void
{
    echo "\n</main></body></html>";
}

// Hidden CSRF input for POST forms.
function csrf_field(): void
{
    echo '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

// Prints the error message of one form field (if any).
function field_error(array $errors, string $key): void
{
    if (isset($errors[$key])) {
        echo '<p class="error">' . e($errors[$key]) . '</p>';
    }
}
