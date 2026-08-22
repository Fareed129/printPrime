<?php
/**
 * PrimePrint Logout Handler
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/helpers.php';

logout_user();
flash_set('info', 'You have been successfully logged out.');
header("Location: " . APP_URL . "/login.php");
exit;
