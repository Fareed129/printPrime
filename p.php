<?php
/**
 * PrimePrint Customer Portal Route Handler
 * Handles /p/{shop-slug} or p.php?slug={shop-slug}
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/helpers.php';

$slug = trim($_GET['slug'] ?? '');

// If slug not provided in query param, check REQUEST_URI
if (empty($slug)) {
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (preg_match('#/p/([a-zA-Z0-9_-]+)#', $uri, $matches)) {
        $slug = $matches[1];
    }
}

if (empty($slug)) {
    http_response_code(404);
    die("Error 404: No printing shop identifier specified.");
}

$db = getDBConnection();
$stmt = $db->prepare("SELECT * FROM shops WHERE slug = :slug LIMIT 1");
$stmt->execute([':slug' => $slug]);
$shop = $stmt->fetch();

if (!$shop) {
    http_response_code(404);
    die("Error 404: Printing Shop '{$slug}' was not found or is no longer registered.");
}

if ($shop['status'] !== 'active') {
    http_response_code(403);
    die("Shop Notice: '" . htmlspecialchars($shop['name']) . "' is temporarily offline and not accepting print jobs.");
}

// Check Subscription Validity
$isSubExpired = ($shop['subscription_status'] === 'expired') || (!empty($shop['subscription_expires_at']) && strtotime($shop['subscription_expires_at']) < time());
if ($isSubExpired) {
    http_response_code(403);
    die("<!DOCTYPE html><html><head><title>Counter Maintenance</title><meta name='viewport' content='width=device-width, initial-scale=1'><link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css' rel='stylesheet'><link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css'></head><body class='bg-light d-flex align-items-center min-vh-100'><div class='container text-center py-5'><div class='card shadow-sm border-0 mx-auto p-4' style='max-width: 460px; border-radius: 12px;'><i class='bi bi-clock-history text-warning' style='font-size: 3rem;'></i><h4 class='fw-bold text-dark mt-3 mb-2'>" . htmlspecialchars($shop['name']) . "</h4><p class='text-muted small mb-0'>This counter's online self-service printing is currently undergoing maintenance. Please visit the counter staff directly for printing assistance.</p></div></div></body></html>");
}

// Pass shop to the customer interface view
require_once __DIR__ . '/customer/index.php';

