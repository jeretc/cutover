<?php
require_once __DIR__ . '/lib/paypal_sdk.php';
require_once __DIR__ . '/lib/orders_store.php';
require_once __DIR__ . '/config.php'; // For SHOP_CURRENCY

// Webhook handler
header('Content-Type: application/json');

$webhookId = getenv('PAYPAL_WEBHOOK_ID');
if (!$webhookId) {
    error_log("PAYPAL_WEBHOOK_ID environment variable not set for webhook handler.");
    http_response_code(500);
    echo json_encode(['error' => 'Server configuration error: PAYPAL_WEBHOOK_ID missing.']);
    exit;
}

$rawBody = file_get_contents('php://input');
$webhookEvent = json_decode($rawBody, true);

if (!$webhookEvent) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload.']);
    exit;
}

// Extract headers for verification
$headers = getallheaders();
$transmissionId = $headers['PAYPAL-TRANSMISSION-ID'] ?? $headers['paypal-transmission-id'] ?? '';
$transmissionTime = $headers['PAYPAL-TRANSMISSION-TIME'] ?? $headers['paypal-transmission-time'] ?? '';
$transmissionSig = $headers['PAYPAL-TRANSMISSION-SIG'] ?? $headers['paypal-transmission-sig'] ?? '';
$certUrl = $headers['PAYPAL-CERT-URL'] ?? $headers['paypal-cert-url'] ?? '';
$authAlgo = $headers['PAYPAL-AUTH-ALGO'] ?? $headers['paypal-auth-algo'] ?? '';

if (!$transmissionId || !$transmissionTime || !$transmissionSig || !$certUrl || !$authAlgo) {
    error_log("Missing PayPal webhook verification headers.");
    http_response_code(400);
    echo json_encode(['error' => 'Missing PayPal webhook verification headers.']);
    exit;
}

try {
    // Get an access token for webhook verification
    $clientId = getenv('PAYPAL_CLIENT_ID');
    $clientSecret = getenv('PAYPAL_CLIENT_SECRET');

    if (!$clientId || !$clientSecret) {
        error_log("PayPal client ID or secret not set for webhook verification.");
        http_response_code(500);
        echo json_encode(['error' => 'PayPal credentials missing for webhook verification.']);
        exit;
    }

    $ch = curl_init('https://api-m.sandbox.paypal.com/v1/oauth2/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, $clientId . ':' . $clientSecret);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Accept-Language: en_US']);
    $tokenResponse = curl_exec($ch);
    $tokenHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($tokenHttpCode !== 200) {
        error_log("Failed to get access token for webhook verification. HTTP Status: $tokenHttpCode, Response: $tokenResponse");
        http_response_code(502);
        echo json_encode(['error' => 'Failed to get access token for webhook verification.']);
        exit;
    }
    $tokenData = json_decode($tokenResponse, true);
    $accessToken = $tokenData['access_token'] ?? null;

    if (!$accessToken) {
        error_log("Access token missing from PayPal response for webhook verification.");
        http_response_code(502);
        echo json_encode(['error' => 'Access token missing for webhook verification.']);
        exit;
    }

    // Verify webhook signature
    $verifyBody = json_encode([
        'auth_algo' => $authAlgo,
        'cert_url' => $certUrl,
        'transmission_id' => $transmissionId,
        'transmission_sig' => $transmissionSig,
        'transmission_time' => $transmissionTime,
        'webhook_id' => $webhookId,
        'webhook_event' => $webhookEvent,
    ]);

    $ch = curl_init('https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $verifyBody);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken,
    ]);
    $verifyResponse = curl_exec($ch);
    $verifyHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($verifyHttpCode !== 200) {
        error_log("Webhook verification failed. HTTP Status: $verifyHttpCode, Response: $verifyResponse");
        http_response_code(502);
        echo json_encode(['error' => 'Webhook verification failed.']);
        exit;
    }

    $verifyData = json_decode($verifyResponse, true);
    if (($verifyData['verification_status'] ?? '') !== 'SUCCESS') {
        error_log("Webhook signature verification status: " . ($verifyData['verification_status'] ?? 'UNKNOWN'));
        http_response_code(403);
        echo json_encode(['error' => 'Webhook signature verification failed.']);
        exit;
    }

    // Process event
    $eventType = $webhookEvent['event_type'] ?? '';
    $resource = $webhookEvent['resource'] ?? [];
    $eventId = $webhookEvent['id'] ?? '';

    // Security fix: Replay guard for webhooks by event ID.
    $processedEventsPath = __DIR__ . '/data/processed_webhook_events.json';
    $processedEvents = file_exists($processedEventsPath) ? json_decode(file_get_contents($processedEventsPath), true) : [];
    if (isset($processedEvents[$eventId])) {
        error_log("Duplicate webhook event received: $eventId");
        http_response_code(200); // Acknowledge and ignore
        echo json_encode(['status' => 'duplicate event, ignored']);
        exit;
    }

    switch ($eventType) {
        case 'PAYMENT.CAPTURE.COMPLETED':
            $captureId = $resource['id'] ?? null;
            $orderId = $resource['supplementary_data']['related_resources'][0]['order']['id'] ?? null;
            $amountValue = $resource['amount']['value'] ?? null;
            $currencyCode = $resource['amount']['currency_code'] ?? null;
            $status = $resource['status'] ?? null;

            if (!$captureId || !$orderId || !$amountValue || !$currencyCode || $status !== 'COMPLETED') {
                error_log("Invalid PAYMENT.CAPTURE.COMPLETED event data for event ID: $eventId");
                http_response_code(400);
                echo json_encode(['error' => 'Invalid capture event data.']);
                exit;
            }

            $order = orders_get($orderId);
            if (!$order) {
                error_log("Webhook: Order ID $orderId not found in local store for capture $captureId.");
                http_response_code(404); // Or 200 if we just want to acknowledge and move on
                echo json_encode(['error' => 'Order not found.']);
                exit;
            }

            // Security fix: Verify amount and currency against stored order.
            if (number_format((float)$order['amount'], 2, '.', '') !== number_format((float)$amountValue, 2, '.', '') || $order['currency'] !== $currencyCode) {
                error_log("Webhook: Amount/currency mismatch for order $orderId. Expected {$order['amount']} {$order['currency']}, got $amountValue $currencyCode.");
                http_response_code(400);
                echo json_encode(['error' => 'Amount or currency mismatch.']);
                exit;
            }

            // Update order status if not already paid
            if ($order['status'] !== 'PAID') {
                $order['status'] = 'PAID';
                $order['transaction_id'] = $captureId; // Store capture ID as transaction_id
                orders_save($order);
                error_log("Webhook: Order $orderId updated to PAID with capture $captureId.");
            } else {
                error_log("Webhook: Order $orderId already PAID, ignoring capture $captureId.");
            }
            break;

        // Add other event types if needed, e.g., PAYMENT.CAPTURE.REFUNDED
        // case 'PAYMENT.CAPTURE.REFUNDED':
        //     // Handle refund events
        //     break;

        default:
            error_log("Unhandled webhook event type: $eventType for event ID: $eventId");
            // Acknowledge unhandled events to prevent retries
            break;
    }

    // Record event ID to prevent replay
    $processedEvents[$eventId] = date('c');
    file_put_contents($processedEventsPath, json_encode($processedEvents, JSON_PRETTY_PRINT));

    http_response_code(200);
    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    error_log("Error processing webhook: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error processing webhook.']);
}
