<?php
// Log keluar control panel.
declare(strict_types=1);
require __DIR__ . '/../api/config.php';
session_start();
if (!empty($_SESSION['panel_admin'])) audit_log('LOGOUT', 'Log keluar panel.');
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: index.php');
exit;
