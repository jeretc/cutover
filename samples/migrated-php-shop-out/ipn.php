<?php
require_once __DIR__ . '/lib/paypal_sdk.php';
require_once __DIR__ . '/lib/orders_store.php';
function verifyWebhook($raw, $headers) {
    $token = base64_encode(getenv('PAYPAL_CLIENT_ID') . ':' . getenv('PAYPAL_CLIENT_SECRET'));
    $ch = curl_init('https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Basic ' . $token, 'Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['auth_algo' => $headers['HTTP_PAYPAL_AUTH_ALGO'], 'cert_url' => $headers['HTTP_PAYPAL_CERT_URL'], 'transmission_id' => $headers['HTTP_PAYPAL_TRANSMISSION_ID'], 'transmission_sig' => $headers['HTTP_PAYPAL_TRANSMISSION_SIG'], 'transmission_time' => $headers['HTTP_PAYPAL_TRANSMISSION_TIME'], 'webhook_id' => getenv('PAYPAL_WEBHOOK_ID'), 'webhook_event' => json_decode($raw)]));
    $res = json_decode(curl_exec($ch), true);
    return ($res['verification_status'] ?? '') === 'SUCCESS';
}
$raw = file_get_contents('php://input');
if (verifyWebhook($raw, $_SERVER)) {
    $event = json_decode($raw, true);
    if ($event['event_type'] === 'PAYMENT.CAPTURE.COMPLETED') {
        // Logic to update order status if not already paid
    }
}
http_response_code(200);