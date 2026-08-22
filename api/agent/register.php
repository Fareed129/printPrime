<?php
/**
 * PrimePrint API — POST /api/agent/register
 * Register / Pair Desktop Print Agent with a Printing Shop
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed. Use POST.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$shopSlug  = trim($input['shop_slug'] ?? '');
$agentName = trim($input['agent_name'] ?? 'Windows-Desktop-Agent');
$version   = trim($input['version'] ?? '1.0.0-poc');

if (empty($shopSlug)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required field: shop_slug.']);
    exit;
}

$db = getDBConnection();
$stmt = $db->prepare("SELECT id, name, status FROM shops WHERE slug = :slug LIMIT 1");
$stmt->execute([':slug' => $shopSlug]);
$shop = $stmt->fetch();

if (!$shop) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => "Shop with slug '{$shopSlug}' not found."]);
    exit;
}

if ($shop['status'] !== 'active') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => "Shop '{$shop['name']}' is currently inactive."]);
    exit;
}

// Generate secure agent token
$rawToken = 'ppa_' . bin2hex(random_bytes(24));
$tokenHash = hash('sha256', $rawToken);

$stmt = $db->prepare("
    INSERT INTO print_agents (shop_id, agent_name, agent_token_hash, status, last_seen, version)
    VALUES (:shop_id, :agent_name, :token_hash, 'online', NOW(), :version)
");
$stmt->execute([
    ':shop_id'    => $shop['id'],
    ':agent_name' => $agentName,
    ':token_hash' => $tokenHash,
    ':version'    => $version
]);
$agentId = (int)$db->lastInsertId();

echo json_encode([
    'success' => true,
    'message' => "Print Agent successfully registered for {$shop['name']}.",
    'data'    => [
        'agent_id'    => $agentId,
        'agent_name'  => $agentName,
        'shop_id'     => $shop['id'],
        'shop_name'   => $shop['name'],
        'agent_token' => $rawToken // Sent once during registration
    ]
], JSON_PRETTY_PRINT);
