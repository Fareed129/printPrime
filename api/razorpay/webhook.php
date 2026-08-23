<?php
/**
 * PrimePrint Razorpay Webhook Receiver
 * Endpoint: POST /api/razorpay/webhook.php
 * Handles authoritative asynchronous payment reconciliation: payment.captured, order.paid, payment.failed
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
$eventId = $_SERVER['HTTP_X_RAZORPAY_EVENT_ID'] ?? '';

if (empty($rawBody) || empty($signature)) {
    log_payment_event('webhook_missing_signature_or_body', [
        'event_id'         => $eventId,
        'has_body'         => !empty($rawBody),
        'has_sig'          => !empty($signature)
    ], 'WARNING');
    http_response_code(400);
    echo json_encode(['error' => 'Missing webhook payload or signature header.']);
    exit;
}

// 2. Cryptographic Webhook Signature Verification
$isValid = razorpay_verify_webhook_signature($rawBody, $signature);
if (!$isValid) {
    $parsedPreview = json_decode($rawBody, true);
    $eventName = is_array($parsedPreview) ? ($parsedPreview['event'] ?? 'unknown') : 'unparseable';
    $isSecretSet = !empty(RAZORPAY_WEBHOOK_SECRET) && RAZORPAY_WEBHOOK_SECRET !== 'sampleWebhookSecret123456';

    log_payment_event('webhook_invalid_signature', [
        'event_id'          => $eventId,
        'event_name'        => $eventName,
        'body_len'          => strlen($rawBody),
        'sig_len'           => strlen($signature),
        'secret_configured' => $isSecretSet ? 'YES' : 'NO',
        'secret_length'     => strlen(RAZORPAY_WEBHOOK_SECRET)
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
            $currency  = $paymentEntity['currency'] ?? 'INR';
            $method    = $paymentEntity['method'] ?? null;

            if (empty($orderId)) {
                log_payment_event('webhook_captured_missing_order_id', $paymentEntity, 'WARNING');
                break;
            }

            // Currency check
            if ($currency !== 'INR') {
                log_payment_event('webhook_captured_currency_mismatch', [
                    'order_id' => $orderId,
                    'currency' => $currency
                ], 'ERROR');
                break;
            }

            // Strict Location: Match exact payment record and print job by razorpay_order_id
            $stmt = $db->prepare("
                SELECT p.*, j.id AS print_job_id, j.shop_id AS job_shop_id, j.amount AS expected_amount, j.payment_status AS job_pay_status, j.status AS job_status, j.public_token 
                FROM payments p 
                INNER JOIN print_jobs j ON p.job_id = j.id 
                WHERE p.razorpay_order_id = :order_id 
                LIMIT 1
            ");
            $stmt->execute([':order_id' => $orderId]);
            $record = $stmt->fetch();

            if (!$record || empty($record['print_job_id'])) {
                log_payment_event('webhook_captured_order_not_found', ['order_id' => $orderId], 'WARNING');
                break;
            }

            // Strict Amount Integrity Check (Paise)
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

            // Strict Idempotency: If already confirmed as paid, safely return 200 OK without re-processing
            if ($record['job_pay_status'] === 'paid' && $record['job_status'] === 'QUEUED') {
                log_payment_event('webhook_captured_already_processed_idempotent', ['job_id' => $record['print_job_id']]);
                break;
            }

            $db->beginTransaction();

            // Update Payment Record to Captured
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

            // Authoritative Transition: Move Print Job to PAID and QUEUED
            $stmt = $db->prepare("
                UPDATE print_jobs 
                SET payment_status = 'paid', 
                    status = 'QUEUED' 
                WHERE id = :id
            ");
            $stmt->execute([':id' => $record['print_job_id']]);

            // Generate Official Invoice Record if not already generated
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
                    ':shop_id'  => $record['job_shop_id'],
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

            if (!empty($orderId)) {
                $stmt = $db->prepare("SELECT job_id, shop_id, amount FROM payments WHERE razorpay_order_id = :order_id LIMIT 1");
                $stmt->execute([':order_id' => $orderId]);
                $payRow = $stmt->fetch();
                if ($payRow) {
                    $db->beginTransaction();
                    $stmt = $db->prepare("UPDATE print_jobs SET payment_status = 'paid', status = 'QUEUED' WHERE id = :id");
                    $stmt->execute([':id' => $payRow['job_id']]);

                    $stmt = $db->prepare("UPDATE payments SET status = 'captured', captured_at = NOW() WHERE razorpay_order_id = :order_id");
                    $stmt->execute([':order_id' => $orderId]);

                    $stmt = $db->prepare("SELECT id FROM invoices WHERE job_id = :job_id LIMIT 1");
                    $stmt->execute([':job_id' => $payRow['job_id']]);
                    if (!$stmt->fetch()) {
                        $invNumber = 'INV-' . date('Ymd') . '-' . sprintf('%04d', $payRow['job_id']);
                        $stmt = $db->prepare("INSERT INTO invoices (job_id, shop_id, invoice_number, amount, created_at) VALUES (:job_id, :shop_id, :inv_num, :amt, NOW())");
                        $stmt->execute([':job_id' => $payRow['job_id'], ':shop_id' => $payRow['shop_id'], ':inv_num' => $invNumber, ':amt' => $payRow['amount']]);
                    }
                    $db->commit();

                    log_payment_event('webhook_order_paid_reconciled', ['job_id' => $payRow['job_id'], 'order_id' => $orderId]);
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
