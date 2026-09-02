<?php
/**
 * PrimePrint Razorpay API & Cryptographic Signature Verification
 */

require_once __DIR__ . '/config.php';

/**
 * Log payment events securely without exposing secrets
 */
function log_payment_event(string $event, array $data = [], string $level = 'INFO'): void {
    // Sanitize any sensitive tokens or secrets before logging
    $sanitized = $data;
    unset($sanitized['key_secret'], $sanitized['webhook_secret'], $sanitized['password']);

    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    $logFile = $logDir . '/payment.log';
    $logLine = sprintf(
        "[%s] [%s] %s: %s\n",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        $event,
        json_encode($sanitized, JSON_UNESCAPED_SLASHES)
    );

    @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
}

/**
 * Low-level cURL call to Razorpay Orders API
 */
function _execute_razorpay_order_call(int $amountPaise, string $receipt, array $notes, string $keyId, string $keySecret): array {
    $payload = [
        'amount'   => $amountPaise,
        'currency' => 'INR',
        'receipt'  => $receipt,
        'notes'    => $notes
    ];

    $ch = curl_init('https://api.razorpay.com/v1/orders');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, "{$keyId}:{$keySecret}");
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300 && !empty($response)) {
        $resData = json_decode($response, true);
        if (isset($resData['id'])) {
            log_payment_event('razorpay_order_created_api', [
                'order_id' => $resData['id'],
                'amount'   => $amountPaise,
                'receipt'  => $receipt
            ]);
            return [
                'success'  => true,
                'order_id' => $resData['id'],
                'data'     => $resData
            ];
        }
    }

    $errorMsg = 'Failed to create order on Razorpay.';
    if (!empty($response)) {
        $errData = json_decode($response, true);
        if (isset($errData['error']['description'])) {
            $errorMsg = $errData['error']['description'];
        }
    } elseif (!empty($curlErr)) {
        $errorMsg = 'Razorpay connection error: ' . $curlErr;
    }

    log_payment_event('razorpay_order_creation_failed', [
        'http_code' => $httpCode,
        'error'     => $errorMsg,
        'receipt'   => $receipt
    ], 'ERROR');

    return [
        'success'   => false,
        'http_code' => $httpCode,
        'error'     => $errorMsg
    ];
}

/**
 * Create an Order on Razorpay
 *
 * @param int $amountPaise Amount in paise (e.g. ₹48.00 = 4800 paise)
 * @param string $receipt Internal receipt identifier
 * @param array $notes Optional key-value notes
 * @param string|null $customKeyId Optional shop-specific Key ID
 * @param string|null $customKeySecret Optional shop-specific Key Secret
 * @return array Result array with order_id and success status
 */
function razorpay_create_order(int $amountPaise, string $receipt, array $notes = [], ?string $customKeyId = null, ?string $customKeySecret = null): array {
    // Only attempt shop-specific gateway if BOTH Key ID AND Key Secret are non-empty
    $hasCustomKeys = (!empty($customKeyId) && !empty($customKeySecret) && trim($customKeyId) !== '' && trim($customKeySecret) !== '');

    if ($hasCustomKeys) {
        $cKeyId = trim($customKeyId);
        $cKeySecret = trim($customKeySecret);
        $res = _execute_razorpay_order_call($amountPaise, $receipt, $notes, $cKeyId, $cKeySecret);

        if ($res['success']) {
            $res['active_key_id'] = $cKeyId;
            return $res;
        }

        // If shop's custom keys failed (e.g. 401 Authentication failed), log and fallback to platform credentials
        log_payment_event('razorpay_shop_keys_failed_fallback_platform', [
            'shop_key_id' => $cKeyId,
            'error'       => $res['error'] ?? 'Authentication failed',
            'receipt'     => $receipt
        ], 'WARNING');
    }

    // Fallback to platform default credentials
    $keyId = defined('RAZORPAY_KEY_ID') ? RAZORPAY_KEY_ID : '';
    $keySecret = defined('RAZORPAY_KEY_SECRET') ? RAZORPAY_KEY_SECRET : '';

    if (empty($keyId) || empty($keySecret) || str_contains($keyId, 'sampleKey')) {
        log_payment_event('razorpay_credentials_missing', [
            'key_id_set' => !empty($keyId)
        ], 'ERROR');
        return [
            'success' => false,
            'error'   => 'Razorpay API credentials are not configured or invalid.'
        ];
    }

    $res = _execute_razorpay_order_call($amountPaise, $receipt, $notes, $keyId, $keySecret);
    if ($res['success']) {
        $res['active_key_id'] = $keyId;
    }
    return $res;
}

/**
 * Fetch Payment details from Razorpay API
 */
function razorpay_fetch_payment(string $paymentId, ?string $customKeyId = null, ?string $customKeySecret = null): array|false {
    $keyId = !empty($customKeyId) ? $customKeyId : RAZORPAY_KEY_ID;
    $keySecret = !empty($customKeySecret) ? $customKeySecret : RAZORPAY_KEY_SECRET;

    if (empty($paymentId) || empty($keyId) || empty($keySecret) || str_contains($keyId, 'sampleKey')) {
        return false;
    }

    $ch = curl_init("https://api.razorpay.com/v1/payments/" . urlencode($paymentId));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, "{$keyId}:{$keySecret}");
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300 && !empty($response)) {
        return json_decode($response, true) ?: false;
    }

    return false;
}

/**
 * Verify Razorpay Checkout Payment Signature
 * Algorithm: HMAC-SHA256(order_id + "|" + payment_id, key_secret) == signature
 */
function razorpay_verify_payment_signature(string $orderId, string $paymentId, string $signature, ?string $customKeySecret = null): bool {
    $keySecret = !empty($customKeySecret) ? $customKeySecret : RAZORPAY_KEY_SECRET;
    if (empty($orderId) || empty($paymentId) || empty($signature) || empty($keySecret)) {
        return false;
    }

    $expectedSignature = hash_hmac('sha256', $orderId . '|' . $paymentId, $keySecret);
    $isValid = hash_equals($expectedSignature, $signature);

    log_payment_event('payment_signature_verification', [
        'order_id'   => $orderId,
        'payment_id' => $paymentId,
        'is_valid'   => $isValid
    ], $isValid ? 'INFO' : 'WARNING');

    return $isValid;
}


/**
 * Verify Razorpay Webhook Signature
 * Algorithm: HMAC-SHA256(raw_request_body, webhook_secret) == signature
 */
function razorpay_verify_webhook_signature(string $rawBody, string $signature): bool {
    $webhookSecret = RAZORPAY_WEBHOOK_SECRET;
    $isConfigured = !empty($webhookSecret) && $webhookSecret !== 'sampleWebhookSecret123456';

    if (empty($rawBody) || empty($signature) || empty($webhookSecret)) {
        log_payment_event('webhook_signature_verification', [
            'is_valid'            => false,
            'secret_configured'   => $isConfigured ? 'YES' : 'NO',
            'secret_length'       => strlen($webhookSecret),
            'received_sig_length' => strlen($signature),
            'body_len'            => strlen($rawBody)
        ], 'WARNING');
        return false;
    }

    $expectedSignature = hash_hmac('sha256', $rawBody, $webhookSecret);
    $isValid = hash_equals($expectedSignature, $signature);

    log_payment_event('webhook_signature_verification', [
        'is_valid'            => $isValid,
        'secret_configured'   => $isConfigured ? 'YES' : 'NO',
        'secret_length'       => strlen($webhookSecret),
        'received_sig_length' => strlen($signature),
        'body_len'            => strlen($rawBody)
    ], $isValid ? 'INFO' : 'WARNING');

    return $isValid;
}
