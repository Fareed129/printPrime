<?php
/**
 * PrimePrint API — POST /api/agent/heartbeat
 * Periodic Heartbeat & Status Ping
 */

require_once __DIR__ . '/auth-agent.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed. Use POST.']);
    exit;
}

$agent = authenticate_agent();
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$version = trim($input['version'] ?? $agent['version']);

$db = getDBConnection();
$stmt = $db->prepare("
    UPDATE print_agents 
    SET last_seen = NOW(), status = 'online', version = :version 
    WHERE id = :id
");
$stmt->execute([':version' => $version, ':id' => $agent['id']]);

// Count pending jobs
$stmt = $db->prepare("SELECT COUNT(*) AS pending FROM print_jobs WHERE shop_id = :shop_id AND status IN ('PAID', 'QUEUED')");
$stmt->execute([':shop_id' => $agent['shop_id']]);
$pendingCount = (int)($stmt->fetch()['pending'] ?? 0);

echo json_encode([
    'success' => true,
    'message' => 'Heartbeat acknowledged.',
    'data'    => [
        'agent_id'     => $agent['id'],
        'shop_id'      => $agent['shop_id'],
        'shop_name'    => $agent['shop_name'],
        'server_time'  => date('Y-m-d H:i:s'),
        'pending_jobs' => $pendingCount
    ]
], JSON_PRETTY_PRINT);
