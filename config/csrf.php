<?php
/**
 * PrimePrint CSRF Protection Module
 */

require_once __DIR__ . '/config.php';

function generate_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_token(): string {
    return generate_csrf_token();
}

function csrf_field(): string {
    $token = generate_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

function verify_csrf_token(?string $token = null): bool {
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    }

    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

function get_csrf_token(): string {
    return generate_csrf_token();
}

function validate_csrf_token(?string $token = null): bool {
    return verify_csrf_token($token);
}

function require_csrf_token(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf_token()) {
            http_response_code(403);
            die("Security Error: Invalid or expired CSRF token. Please refresh and try again.");
        }
    }
}

