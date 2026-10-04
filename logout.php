<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

start_secure_session();

// Already logged out (e.g. session expired): just go to the login page.
if (empty($_SESSION['uid'])) {
    header('Location: login.php');
    exit;
}

// Logout only via POST with a CSRF token, so another site cannot log the user out.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: profile.php');
    exit;
}
csrf_check();

logout_user();
header('Location: login.php');
exit;
