<?php
// MIGRATED: Replaces legacy IPN listener with a modern Webhook handler.
require_once __DIR__ . '/lib/orders_store.php';
require_once __DIR__ . '/lib/paypal_sdk.php'; // For error handling and logging
header('Content-Type: application/json');

$webhookId = PAYPAL_WEBHOOK_ID;
if (!$webhookId) {
    error_log("PAYPAL_WEBHOOK_ID environment variable is not set.");
    http_response_code(500);
    echo json_encode(['error' => 'Webhook ID not configured.']);
    exit;
}

$rawBody = file_get_contents('php://input');
// CUTOVER-HUMAN-FIX: HTTP header names are case-insensitive and a proxy may lowercase them; match them case-insensitively.
$headers = array_change_key_case(getallheaders(), CASE_LOWER);

// Extract PayPal webhook headers
$paypalHeaders = [
    'PAYPAL-AUTH-ALGO'      => $headers['paypal-auth-algo'] ?? '',
    'PAYPAL-CERT-URL'       => $headers['paypal-cert-url'] ?? '',
    'PAYPAL-TRANSMISSION-ID'=> $headers['paypal-transmission-id'] ?? '',
    'PAYPAL-TRANSMISSION-SIG'=> $headers['paypal-transmission-sig'] ?? '',
    'PAYPAL-TRANSMISSION-TIME'=> $headers['paypal-transmission-time'] ?? '',
];

// Check if all required headers are present
foreach ($paypalHeaders as $key => $value) {
    if (empty($value)) {
        error_log("Missing PayPal webhook header: $key");
        http_response_code(400);
        echo json_encode(['error' => "Missing PayPal webhook header: $key"]);
        exit;
    }
}

$event = json_decode($rawBody, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("Invalid JSON payload: " . json_last_error_msg());
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload.']);
    exit;
}

// Verify webhook signature
function verifyWebhookSignature($webhookId, $paypalHeaders, $rawBody) {
    $clientId = PAYPAL_CLIENT_ID;
    $clientSecret = PAYPAL_CLIENT_SECRET;

    if (!$clientId || !$clientSecret) {
        error_log("PayPal API credentials not configured for webhook verification.");
        return false;
    }

    // First, get an access token
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
        error_log("Failed to get access token for webhook verification: HTTP $tokenHttpCode - $tokenResponse");
        return false;
    }
    $tokenData = json_decode($tokenResponse, true);
    $accessToken = $tokenData['access_token'] ?? null;

    if (!$accessToken) {
        error_log("Access token not found in response for webhook verification.");
        return false;
    }

    // Then, verify the webhook signature
    $verifyBody = [
        'auth_algo'         => $paypalHeaders['PAYPAL-AUTH-ALGO'],
        'cert_url'          => $paypalHeaders['PAYPAL-CERT-URL'],
        'transmission_id'   => $paypalHeaders['PAYPAL-TRANSMISSION-ID'],
        'transmission_sig'  => $paypalHeaders['PAYPAL-TRANSMISSION-SIG'],
        'transmission_time' => $paypalHeaders['PAYPAL-TRANSMISSION-TIME'],
        'webhook_id'        => $webhookId,
        'webhook_event'     => json_decode($rawBody) // Must be a JSON object, not string
    ];

    $ch = curl_init('https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($verifyBody));

    $verifyResponse = curl_exec($ch);
    $verifyHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($verifyHttpCode !== 200) {
        error_log("Webhook verification failed: HTTP $verifyHttpCode - $verifyResponse");
        return false;
    }

    $verifyData = json_decode($verifyResponse, true);
    return ($verifyData['verification_status'] ?? '') === 'SUCCESS';
}

if (!verifyWebhookSignature($webhookId, $paypalHeaders, $rawBody)) {
    error_log("Webhook signature verification failed.");
    http_response_code(403); // Forbidden
    echo json_encode(['error' => 'Webhook signature verification failed.']);
    exit;
}

// Process the event
$eventType = $event['event_type'] ?? '';
$resource = $event['resource'] ?? [];
$eventId = $event['id'] ?? ''; // For duplicate checking

// Log the event for debugging (optional, but good for webhooks)
file_put_contents(__DIR__ . '/data/webhook.log', date('c') . ' ' . $eventType . ' ' . $eventId . "\n", FILE_APPEND);

switch ($eventType) {
    case 'PAYMENT.CAPTURE.COMPLETED':
        $captureId = $resource['id'] ?? null;
        $orderId = $resource['supplementary_data']['related_ids']['order_id'] ?? null;
        $amountValue = $resource['amount']['value'] ?? null;
        $currencyCode = $resource['amount']['currency_code'] ?? null;

        if (!$captureId || !$orderId || !$amountValue || !$currencyCode) {
            error_log("Missing data in PAYMENT.CAPTURE.COMPLETED event: " . json_encode($event));
            http_response_code(400);
            echo json_encode(['error' => 'Missing data in webhook event.']);
            exit;
        }

        $order = orders_get($orderId);

        if (!$order) {
            error_log("Order $orderId not found for capture $captureId. Event ID: $eventId");
            // Respond 200 to PayPal to avoid retries, but log the issue.
            http_response_code(200);
            echo json_encode(['status' => 'Order not found, logged for review.']);
            exit;
        }

        // Idempotency check: ignore if already processed or refunded
        if ($order['status'] === 'PAID' && $order['transaction_id'] === $captureId) {
            error_log("Duplicate PAYMENT.CAPTURE.COMPLETED event for order $orderId, capture $captureId. Event ID: $eventId");
            http_response_code(200);
            echo json_encode(['status' => 'Already processed.']);
            exit;
        }
        if ($order['status'] === 'REFUNDED') {
            error_log("PAYMENT.CAPTURE.COMPLETED event for already refunded order $orderId, capture $captureId. Event ID: $eventId");
            http_response_code(200);
            echo json_encode(['status' => 'Order already refunded.']);
            exit;
        }

        // Verify amount and currency (security check)
        if (number_format((float)$order['amount'], 2, '.', '') !== number_format((float)$amountValue, 2, '.', '') || $order['currency'] !== $currencyCode) {
            error_log("Amount/currency mismatch for order $orderId. Expected {$order['amount']} {$order['currency']}, got $amountValue $currencyCode. Event ID: $eventId");
            // This is a critical error, might indicate fraud or misconfiguration.
            // Respond 200 to PayPal to avoid retries, but alert human.
            http_response_code(200);
            echo json_encode(['status' => 'Amount/currency mismatch, logged for review.']);
            exit;
        }

        // Update order status
        $order['status'] = 'PAID';
        $order['transaction_id'] = $captureId;
        // Payer details are not directly in PAYMENT.CAPTURE.COMPLETED, but can be fetched from the order if needed.
        // For now, keep existing or fetch from orders->getOrder() if needed.
        orders_save($order);

        http_response_code(200);
        echo json_encode(['status' => 'success']);
        break;

    // Add other event types if needed (e.g., PAYMENT.CAPTURE.REFUNDED)
    default:
        error_log("Unhandled webhook event type: $eventType. Event ID: $eventId");
        http_response_code(200); // Acknowledge to avoid retries for unhandled events
        echo json_encode(['status' => 'Unhandled event type.']);
        break;
}
