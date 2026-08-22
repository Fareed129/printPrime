<?php
/**
 * PrimePrint Razorpay Webhook Receiver
 * Endpoint: POST /api/razorpay/webhook.php
 * Handles asynchronous payment reconciliation: payment.captured, order.paid, payment.failed
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helpers.php';
require_once __DIR__ . '/../../config/razorpay.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed. POST required.']);
    exit;
}

// 1. Read Raw Body and Signature Header
$rawBody = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

if (empty($rawBody) || empty($signature)) {
    log_payment_event('webhook_missing_signature_or_body', [], 'WARNING');
    http_response_code(400);
    echo json_encode(['error' => 'Missing webhook payload or signature header.']);
    exit;
}

// 2. Cryptographic Webhook Signature Verification
$isValid = razorpay_verify_webhook_signature($rawBody, $signature);
if (!$isValid) {
    log_payment_event('webhook_invalid_signature', [
        'received_sig' => substr($signature, 0, 10) . '...'
    ], 'ERROR');
    http_response_code(400);
    echo json_encode(['error' => 'Invalid webhook signature.']);
    exit;
}

// 3. Parse Verified Event Payload
$eventData = json_decode($rawBody, true);
if (!is_array($eventData) || !isset($eventData['event'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Malformed JSON webhook payload.']);
    exit;
}

$event = $eventData['event'];
log_payment_event('webhook_event_received', ['event' => $event]);

try {
    $db = getDBConnection();

    switch ($event) {
        case 'payment.captured':
            $paymentEntity = $eventData['payload']['payment']['entity'] ?? [];
            $paymentId = $paymentEntity['id'] ?? '';
            $orderId   = $paymentEntity['order_id'] ?? '';
            $amountP   = (int)($paymentEntity['amount'] ?? 0);
            $method    = $paymentEntity['method'] ?? null;
            $token     = $paymentEntity['notes']['public_token'] ?? null;

            if (empty($orderId) && empty($token)) {
                log_payment_event('webhook_captured_missing_order_ref', $paymentEntity, 'WARNING');
                break;
            }

            // Locate Payment & Print Job
            if (!empty($orderId)) {
                $stmt = $db->prepare("
                    SELECT p.*, j.id AS print_job_id, j.amount AS expected_amount, j.payment_status AS job_pay_status, j.status AS job_status, j.public_token 
                    FROM payments p 
                    INNER JOIN print_jobs j ON p.job_id = j.id 
                    WHERE p.razorpay_order_id = :order_id 
                    LIMIT 1
                ");
                $stmt->execute([':order_id' => $orderId]);
                $record = $stmt->fetch();
            } else {
                $stmt = $db->prepare("
                    SELECT p.*, j.id AS print_job_id, j.amount AS expected_amount, j.payment_status AS job_pay_status, j.status AS job_status, j.public_token 
                    FROM print_jobs j 
                    LEFT JOIN payments p ON p.job_id = j.id 
                    WHERE j.public_token = :token 
                    ORDER BY p.id DESC LIMIT 1
                ");
                $stmt->execute([':token' => $token]);
                $record = $stmt->fetch();
            }

            if (!$record || empty($record['print_job_id'])) {
                log_payment_event('webhook_captured_job_not_found', ['order_id' => $orderId, 'token' => $token], 'WARNING');
                break;
            }

            // Amount Integrity Check
            $expectedPaise = (int)round((float)$record['expected_amount'] * 100);
            if ($amountP > 0 && $amountP !== $expectedPaise) {
                log_payment_event('webhook_amount_mismatch_detected', [
                    'job_id'         => $record['print_job_id'],
                    'expected_paise' => $expectedPaise,
                    'paid_paise'     => $amountP
                ], 'ERROR');
                // Do not mark job as paid if amount differs from expected
                break;
            }

            // Idempotency: If already paid, safely return 200 OK without re-processing
            if ($record['job_pay_status'] === 'paid' && $record['job_status'] === 'QUEUED') {
                log_payment_event('webhook_captured_already_processed_idempotent', ['job_id' => $record['print_job_id']]);
                break;
            }

            $db->beginTransaction();

            // Update Payment Record
            $stmt = $db->prepare("
                UPDATE payments 
                SET razorpay_payment_id = :payment_id, 
                    status = 'captured', 
                    method = :method, 
                    captured_at = NOW(), 
                    updated_at = NOW() 
                WHERE id = :id
            ");
            $stmt->execute([
                ':payment_id' => $paymentId,
                ':method'     => $method,
                ':id'         => $record['id']
            ]);

            // Transition Job to PAID and QUEUED
            $stmt = $db->prepare("
                UPDATE print_jobs 
                SET payment_status = 'paid', 
                    status = 'QUEUED' 
                WHERE id = :id
            ");
            $stmt->execute([':id' => $record['print_job_id']]);

            // Ensure Invoice Exists
            $stmt = $db->prepare("SELECT id FROM invoices WHERE job_id = :job_id LIMIT 1");
            $stmt->execute([':job_id' => $record['print_job_id']]);
            if (!$stmt->fetch()) {
                $invNumber = 'INV-' . date('Ymd') . '-' . sprintf('%04d', $record['print_job_id']);
                $stmt = $db->prepare("
                    INSERT INTO invoices (job_id, shop_id, invoice_number, amount, created_at)
                    VALUES (:job_id, :shop_id, :inv_num, :amt, NOW())
                ");
                $stmt->execute([
                    ':job_id'   => $record['print_job_id'],
                    ':shop_id'  => $record['shop_id'],
                    ':inv_num'  => $invNumber,
                    ':amt'      => $record['expected_amount']
                ]);
            }

            $db->commit();

            log_payment_event('webhook_payment_captured_success', [
                'job_id'     => $record['print_job_id'],
                'order_id'   => $orderId,
                'payment_id' => $paymentId
            ]);
            break;

        case 'order.paid':
            $orderEntity = $eventData['payload']['order']['entity'] ?? [];
            $orderId     = $orderEntity['id'] ?? '';
            $token       = $orderEntity['notes']['public_token'] ?? null;

            if (!empty($orderId)) {
                $stmt = $db->prepare("SELECT job_id FROM payments WHERE razorpay_order_id = :order_id LIMIT 1");
                $stmt->execute([':order_id' => $orderId]);
                $jobId = $stmt->fetchColumn();
                if ($jobId) {
                    $stmt = $db->prepare("UPDATE print_jobs SET payment_status = 'paid', status = 'QUEUED' WHERE id = :id AND payment_status != 'paid'");
                    $stmt->execute([':id' => $jobId]);
                    log_payment_event('webhook_order_paid_reconciled', ['job_id' => $jobId, 'order_id' => $orderId]);
                }
            }
            break;

        case 'payment.failed':
            $paymentEntity = $eventData['payload']['payment']['entity'] ?? [];
            $paymentId     = $paymentEntity['id'] ?? '';
            $orderId       = $paymentEntity['order_id'] ?? '';
            $failureReason = $paymentEntity['error_description'] ?? 'Payment failed';

            if (!empty($orderId)) {
                $stmt = $db->prepare("
                    UPDATE payments 
                    SET status = 'failed', 
                        razorpay_payment_id = :payment_id, 
                        failure_reason = :reason, 
                        updated_at = NOW() 
                    WHERE razorpay_order_id = :order_id
                ");
                $stmt->execute([
                    ':payment_id' => $paymentId,
                    ':reason'     => $failureReason,
                    ':order_id'   => $orderId
                ]);
                log_payment_event('webhook_payment_failed_recorded', [
                    'order_id'       => $orderId,
                    'failure_reason' => $failureReason
                ]);
            }
            break;

        default:
            log_payment_event('webhook_unhandled_event', ['event' => $event]);
            break;
    }

    echo json_encode(['status' => 'ok', 'event' => $event]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    log_payment_event('webhook_exception', ['error' => $e->getMessage()], 'ERROR');
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error while processing webhook.']);
}
