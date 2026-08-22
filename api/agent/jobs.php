<?php
/**
 * PrimePrint API — GET /api/agent/jobs
 * Poll Queued Jobs for the Authenticated Shop Agent
 */

require_once __DIR__ . '/auth-agent.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed. Use GET.']);
    exit;
}

$agent = authenticate_agent();
$db = getDBConnection();

$stmt = $db->prepare("
    SELECT 
        id, shop_id, printer_id, file_name, file_type, 
        page_count, copies, paper_size, color_mode, side_mode, 
        amount, status, payment_status, created_at 
    FROM print_jobs 
    WHERE shop_id = :shop_id 
      AND status IN ('PAID', 'QUEUED')
    ORDER BY created_at ASC
    LIMIT 20
");
$stmt->execute([':shop_id' => $agent['shop_id']]);
$jobs = $stmt->fetchAll();

// Add download URL to each job record
$formattedJobs = array_map(function($job) {
    $job['download_url'] = APP_URL . "/api/agent/job-file.php?id=" . $job['id'];
    return $job;
}, $jobs);

echo json_encode([
    'success' => true,
    'count'   => count($formattedJobs),
    'data'    => $formattedJobs
], JSON_PRETTY_PRINT);
