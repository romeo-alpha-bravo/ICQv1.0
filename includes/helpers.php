<?php
declare(strict_types=1);

// Small output helpers shared by all pages. Include with require_once.
// csrf_field() needs includes/auth.php to be loaded as well.

const APP_NAME    = 'ICQ1.0';
const ERR_GENERIC = 'Something went wrong. Please try again later.';

// Escapes a value for safe output in HTML.
function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// Sends headers and opens the page window. $base is the path prefix to the
// project root ('' for root pages, '../' for pages in admin/).
// $user (from require_login) shows the UIN in the title bar.
function page_start(string $title, string $base = '', ?array $user = null): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store'); // do not show private pages after logout (Back button)

    echo '<!DOCTYPE html>' . "\n"
       . '<html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . e($title) . ' - ' . APP_NAME . '</title>'
       . '<link rel="stylesheet" href="' . e($base) . 'assets/style.css">'
       . '</head><body>' . "\n"
       . '<main class="window">' . "\n"
       . '<div class="titlebar"><span class="flower" aria-hidden="true"></span>'
       . '<span>' . APP_NAME . ' - ' . e($title) . '</span>';

    if ($user !== null && isset($user['uin'])) {
        echo '<span class="uin">UIN ' . e((string)$user['uin']) . '</span>';
    }

    echo '</div>' . "\n" . '<div class="content">' . "\n";
}

function page_end(): void
{
    echo "\n</div></main></body></html>";
}

// Small avatar <img> for lists. $photo is users.photo_path (file name inside uploads/),
// $base is '' for root pages and '../' in admin/. alt is empty: the name is next to it.
function avatar_img(string $photo, string $base = '', int $size = 32): string
{
    return '<img class="avatar" src="' . e($base) . 'uploads/' . e($photo) . '" alt=""'
         . ' width="' . $size . '" height="' . $size . '" loading="lazy" decoding="async">';
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
