<?php
/**
 * PrimePrint Authentication & Role Authorization Middleware
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/helpers.php';

function is_logged_in(): bool {
    return isset($_SESSION['user']) && !empty($_SESSION['user']['id']);
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function login_user(array $user): void {
    // Prevent session fixation attack
    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id'        => (int)$user['id'],
        'name'      => $user['name'],
        'email'     => $user['email'],
        'role'      => $user['role'],
        'shop_id'   => !empty($user['shop_id']) ? (int)$user['shop_id'] : null,
        'status'    => $user['status']
    ];
}

function logout_user(): void {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

function require_auth(?string $redirectUrl = null): void {
    if (!is_logged_in()) {
        $target = $redirectUrl ?? (APP_URL . '/login.php');
        flash_set('danger', 'Please login to access this page.');
        header("Location: " . $target);
        exit;
    }
}

function require_role(array|string $roles): void {
    require_auth();

    $roles = (array)$roles;
    $user = current_user();

    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        flash_set('danger', 'Access Denied: You do not have permission to view this page.');
        
        if ($user['role'] === 'shop') {
            header("Location: " . APP_URL . "/shop/dashboard.php");
        } else {
            header("Location: " . APP_URL . "/admin/dashboard.php");
        }
        exit;
    }
}

/**
 * Strict Multi-Tenant Shop Isolation Guard
 * Prevents Shop A from accessing Shop B records even if ID in URL is tampered.
 */
function verify_shop_access(int|string|null $requestedShopId): int {
    require_auth();
    $user = current_user();

    // Super Admin has global access to all shops
    if ($user['role'] === 'admin') {
        return (int)$requestedShopId;
    }

    // Shop Users can ONLY access their own assigned shop_id
    if ($user['role'] === 'shop') {
        $userShopId = (int)$user['shop_id'];
        if ($requestedShopId !== null && (int)$requestedShopId !== $userShopId) {
            http_response_code(403);
            die("Access Denied: Unauthorized access to another shop's tenant data.");
        }
        return $userShopId;
    }

    http_response_code(403);
    die("Access Denied: Invalid role.");
}
