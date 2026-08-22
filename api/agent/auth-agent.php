<?php
/**
 * PrimePrint Agent API - Token Authentication Middleware
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

function authenticate_agent(): array {
    $token = $_SERVER['HTTP_X_AGENT_TOKEN'] ?? '';
    
    if (empty($token) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/Bearer\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
            $token = trim($matches[1]);
        }
    }

    if (empty($token)) {
        // Fallback for body/param in scaffolding
        $input = json_decode(file_get_contents('php://input'), true);
        $token = $input['token'] ?? $_GET['token'] ?? '';
    }

    if (empty($token)) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error'   => 'Unauthorized: Missing X-Agent-Token header or Bearer authorization.'
        ]);
        exit;
    }

    $tokenHash = hash('sha256', $token);
    $db = getDBConnection();
    
    $stmt = $db->prepare("
        SELECT a.*, s.name AS shop_name, s.status AS shop_status 
        FROM print_agents a 
        INNER JOIN shops s ON a.shop_id = s.id 
        WHERE a.agent_token_hash = :hash 
        LIMIT 1
    ");
    $stmt->execute([':hash' => $tokenHash]);
    $agent = $stmt->fetch();

    if (!$agent) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error'   => 'Unauthorized: Invalid print agent token.'
        ]);
        exit;
    }

    if ($agent['shop_status'] !== 'active') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'Shop is currently deactivated.'
        ]);
        exit;
    }

    // Update last seen
    $stmt = $db->prepare("UPDATE print_agents SET last_seen = NOW(), status = 'online' WHERE id = :id");
    $stmt->execute([':id' => $agent['id']]);

    return $agent;
}
