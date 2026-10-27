<?php
declare(strict_types=1);

// Entry point: logged-in users go to the messenger, everyone else to the login page.

require_once __DIR__ . '/includes/auth.php';

start_secure_session();
header('Location: ' . (empty($_SESSION['uid']) ? 'login.php' : 'chat.php'));
exit;
