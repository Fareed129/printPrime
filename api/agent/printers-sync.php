<?php
/**
 * PrimePrint API — POST /api/agent/printers/sync
 * Synchronize Detected Windows Local Printers with Shop Cloud Portal
 */

require_once __DIR__ . '/auth-agent.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed. Use POST.']);
    exit;
}

$agent = authenticate_agent();
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$printers = $input['printers'] ?? [];

if (!is_array($printers)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Field "printers" must be an array.']);
    exit;
}

$db = getDBConnection();
$upsertCount = 0;

$stmt = $db->prepare("
    INSERT INTO printers (shop_id, printer_name, printer_identifier, status, last_seen)
    VALUES (:shop_id, :name, :identifier, :status, NOW())
    ON DUPLICATE KEY UPDATE 
        status = VALUES(status), 
        last_seen = NOW()
");

// Or look up by (shop_id, printer_name)
$lookupStmt = $db->prepare("SELECT id FROM printers WHERE shop_id = :shop_id AND printer_name = :name LIMIT 1");
$updateStmt = $db->prepare("UPDATE printers SET printer_identifier = :identifier, status = :status, last_seen = NOW() WHERE id = :id");
$insertStmt = $db->prepare("INSERT INTO printers (shop_id, printer_name, printer_identifier, status, last_seen) VALUES (:shop_id, :name, :identifier, :status, NOW())");

foreach ($printers as $p) {
    $name = trim(is_string($p) ? $p : ($p['name'] ?? ''));
    if (empty($name)) continue;

    $identifier = is_array($p) ? ($p['identifier'] ?? $p['driver'] ?? null) : null;
    $status     = is_array($p) ? ($p['status'] ?? 'online') : 'online';
    if (!in_array($status, ['online', 'offline', 'idle', 'printing'])) {
        $status = 'online';
    }

    $lookupStmt->execute([':shop_id' => $agent['shop_id'], ':name' => $name]);
    $existing = $lookupStmt->fetch();

    if ($existing) {
        $updateStmt->execute([
            ':identifier' => $identifier,
            ':status'     => $status,
            ':id'         => $existing['id']
        ]);
    } else {
        $insertStmt->execute([
            ':shop_id'    => $agent['shop_id'],
            ':name'       => $name,
            ':identifier' => $identifier,
            ':status'     => $status
        ]);
    }
    $upsertCount++;
}

echo json_encode([
    'success' => true,
    'message' => "Synced {$upsertCount} printer(s) for {$agent['shop_name']}.",
    'synced_count' => $upsertCount
], JSON_PRETTY_PRINT);
