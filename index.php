<?php
/**
 * PrimePrint Root Portal Router
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/auth.php';

if (is_logged_in()) {
    $user = current_user();
    if ($user['role'] === 'admin') {
        header("Location: " . APP_URL . "/admin/dashboard.php");
    } else {
        header("Location: " . APP_URL . "/shop/dashboard.php");
    }
    exit;
}

header("Location: " . APP_URL . "/login.php");
exit;
