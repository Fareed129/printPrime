<?php
/**
 * PrimePrint API — GET /api/agent/jobs/{id}/file
 * Stream Print Job Document File to Authenticated Desktop Agent
 */

require_once __DIR__ . '/auth-agent.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed. Use GET.']);
    exit;
}

$agent = authenticate_agent();
$jobId = (int)($_GET['id'] ?? 0);

if ($jobId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid or missing job ID.']);
    exit;
}

$db = getDBConnection();
$stmt = $db->prepare("SELECT * FROM print_jobs WHERE id = :id AND shop_id = :shop_id LIMIT 1");
$stmt->execute([':id' => $jobId, ':shop_id' => $agent['shop_id']]);
$job = $stmt->fetch();

if (!$job) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Print job not found for this shop.']);
    exit;
}

$filePath = $job['file_path'];
if (!file_exists($filePath)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Underlying document file not found on server storage.']);
    exit;
}

// Update job status to DOWNLOADING
$stmt = $db->prepare("UPDATE print_jobs SET status = 'DOWNLOADING' WHERE id = :id");
$stmt->execute([':id' => $jobId]);

// Stream file
header('Content-Description: File Transfer');
header('Content-Type: ' . ($job['file_type'] ?: 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . basename($job['file_name']) . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
exit;
