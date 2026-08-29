<?php
/**
 * PrimePrint API — POST /api/agent/jobs/{id}/status
 * Update Live Print Job Status from Desktop Agent Spooler
 */

require_once __DIR__ . '/auth-agent.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed. Use POST.']);
    exit;
}

$agent = authenticate_agent();
$jobId = (int)($_GET['id'] ?? 0);

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
if ($jobId <= 0 && isset($input['job_id'])) {
    $jobId = (int)$input['job_id'];
}

$newStatus   = trim($input['status'] ?? '');
$printerId   = isset($input['printer_id']) ? (int)$input['printer_id'] : null;
$agentJobId  = trim($input['agent_job_id'] ?? '');

$allowedStatuses = ['DOWNLOADING', 'PRINTING', 'PRINTED', 'FAILED', 'CANCELLED'];
if ($jobId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Invalid job ID or unrecognized status. Allowed: ' . implode(', ', $allowedStatuses)
    ]);
    exit;
}

$db = getDBConnection();
$stmt = $db->prepare("SELECT * FROM print_jobs WHERE id = :id AND shop_id = :shop_id LIMIT 1");
$stmt->execute([':id' => $jobId, ':shop_id' => $agent['shop_id']]);
$job = $stmt->fetch();

if (!$job) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Job not found for this shop.']);
    exit;
}

$printedAt = ($newStatus === 'PRINTED') ? date('Y-m-d H:i:s') : null;

$stmt = $db->prepare("
    UPDATE print_jobs 
    SET status = :status, 
        printer_id = COALESCE(:printer_id, printer_id),
        agent_job_id = COALESCE(:agent_job_id, agent_job_id),
        printed_at = COALESCE(:printed_at, printed_at)
    WHERE id = :id
");
$stmt->execute([
    ':status'       => $newStatus,
    ':printer_id'   => $printerId,
    ':agent_job_id' => !empty($agentJobId) ? $agentJobId : null,
    ':printed_at'   => $printedAt,
    ':id'           => $jobId
]);

// If printed, automatically create Invoice record and clean up customer uploaded document for privacy
if ($newStatus === 'PRINTED') {
    $invoiceNumber = 'INV-' . date('Y') . '-' . str_pad($jobId, 6, '0', STR_PAD_LEFT);
    $stmt = $db->prepare("
        INSERT INTO invoices (job_id, shop_id, invoice_number, amount)
        VALUES (:job_id, :shop_id, :inv_num, :amount)
        ON DUPLICATE KEY UPDATE amount = VALUES(amount)
    ");
    $stmt->execute([
        ':job_id'   => $jobId,
        ':shop_id'  => $agent['shop_id'],
        ':inv_num'  => $invoiceNumber,
        ':amount'   => $job['amount']
    ]);

    // Customer Document Privacy: Permanently unlink uploaded file once printed
    if (!empty($job['file_path']) && file_exists($job['file_path'])) {
        @unlink($job['file_path']);
    }
}


echo json_encode([
    'success' => true,
    'message' => "Job #{$jobId} status successfully updated to '{$newStatus}'.",
    'data'    => [
        'job_id'     => $jobId,
        'status'     => $newStatus,
        'printed_at' => $printedAt
    ]
], JSON_PRETTY_PRINT);
