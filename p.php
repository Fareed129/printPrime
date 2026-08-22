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

// Pass shop to the customer interface view
require_once __DIR__ . '/customer/index.php';
