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

// Internal: remembers which closing tags page_end() has to print.
function page_state(?string $set = null): string
{
    static $state = 'guest';
    if ($set !== null) {
        $state = $set;
    }
    return $state;
}

// Logo mark: a speech bubble with a status dot (original drawing, inline SVG).
function brand_mark(int $size = 26): string
{
    return '<svg class="brand-mark" width="' . $size . '" height="' . $size . '" viewBox="0 0 32 32" aria-hidden="true">'
         . '<path d="M6 4h20a4 4 0 0 1 4 4v12a4 4 0 0 1-4 4H14l-6 5v-5H6a4 4 0 0 1-4-4V8a4 4 0 0 1 4-4z" fill="currentColor"/>'
         . '<circle cx="23" cy="10" r="4.5" fill="#2bd17e" stroke="#141e33" stroke-width="2"/></svg>';
}

// Sends headers and opens the document. $base is the path prefix to the project root
// ('' for root pages, '../' for pages in admin/).
// Without $user: a centered card for login, registration and password pages.
// With $user: the page continues with mail_nav(), which prints the top bar and <main>.
function page_start(string $title, string $base = '', ?array $user = null): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store'); // do not show private pages after logout (Back button)

    echo '<!DOCTYPE html>' . "\n"
       . '<html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="theme-color" content="#141e33">'
       . '<title>' . e($title) . ' - ' . APP_NAME . '</title>'
       . '<link rel="icon" href="' . e($base) . 'assets/icon.svg" type="image/svg+xml">'
       . '<link rel="stylesheet" href="' . e($base) . 'assets/style.css">'
       . '</head>' . "\n";

    if ($user === null) {
        page_state('guest');
        echo '<body class="guest"><main class="guest-main"><div class="guest-card">'
           . '<a class="brand brand--big" href="' . e($base) . 'index.php">' . brand_mark(34)
           . '<span>' . APP_NAME . '</span></a>' . "\n";
    } else {
        page_state('app');
        echo '<body class="app">' . "\n";
    }
}

function page_end(): void
{
    echo match (page_state()) {
        'guest'    => "\n</div></main></body></html>",
        'app-main' => "\n</main></body></html>",
        default    => "\n</body></html>",
    };
}

// UIN in groups of three for reading: 331376673 -> "331 376 673".
function uin_fmt(int $uin): string
{
    return trim(strrev(chunk_split(strrev((string)$uin), 3, ' ')));
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
