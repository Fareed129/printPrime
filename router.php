<?php
/**
 * PrimePrint Built-in PHP Development Server Router
 * Emulates Apache mod_rewrite for /p/{shop-slug} and /api/agent/* endpoints
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve static assets directly if file exists
if ($uri !== '/' && file_exists(__DIR__ . $uri)) {
    return false;
}

// 1. Rewrite /p/{shop-slug} to p.php?slug={shop-slug}
if (preg_match('#^/p/([a-zA-Z0-9_-]+)/?$#', $uri, $matches)) {
    $_GET['slug'] = $matches[1];
    require_once __DIR__ . '/p.php';
    return true;
}

// 2. Rewrite /api/agent/printers/sync to api/agent/printers-sync.php
if (preg_match('#^/api/agent/printers/sync/?$#', $uri)) {
    require_once __DIR__ . '/api/agent/printers-sync.php';
    return true;
}

// 3. Rewrite /api/agent/jobs/(\d+)/file to api/agent/job-file.php?id=$1
if (preg_match('#^/api/agent/jobs/(\d+)/file/?$#', $uri, $matches)) {
    $_GET['id'] = $matches[1];
    require_once __DIR__ . '/api/agent/job-file.php';
    return true;
}

// 4. Rewrite /api/agent/jobs/(\d+)/status to api/agent/job-status.php?id=$1
if (preg_match('#^/api/agent/jobs/(\d+)/status/?$#', $uri, $matches)) {
    $_GET['id'] = $matches[1];
    require_once __DIR__ . '/api/agent/job-status.php';
    return true;
}

// 5. Support direct extensionless API routing (/api/agent/register -> /api/agent/register.php)
if (file_exists(__DIR__ . $uri . '.php')) {
    require_once __DIR__ . $uri . '.php';
    return true;
}

// 6. Default index routing
if ($uri === '/' || $uri === '') {
    require_once __DIR__ . '/index.php';
    return true;
}

return false;
