<?php
// Admin-style refund using the classic RefundTransaction call.
// MIGRATED: Now uses Payments v2 API for refunds.
require_once __DIR__ . '/../lib/paypal_sdk.php';
require_once __DIR__ . '/../lib/orders_store.php';
header('Content-Type: application/json');

use PaypalServerSdkLib\ApiException;

if (!$payments) {
    http_response_code(500);
    echo json_encode(['error' => 'PayPal SDK not initialized.']);
    exit;
}

// Security fix: Require admin authentication
authenticateAdmin();

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$order = orders_get($in['token'] ?? ''); // 'token' here is the PayPal Order ID
if (!$order || $order['status'] !== 'PAID') { http_response_code(400); echo json_encode(['error' => 'order is not paid']); exit; }

$captureId = $order['transaction_id'] ?? null;
if (!$captureId) { http_response_code(400); echo json_encode(['error' => 'Order has no capture ID.']); exit; }

try {
    // Refund by CAPTURE id (the NVP transaction id). Empty body = full refund.
    $refundResponse = $payments->refundCapturedPayment(['captureId' => $captureId, 'prefer' => 'return=representation']);
    $refundId = $refundResponse->getResult()->getId();

    $order['status'] = 'REFUNDED';
    $order['refund_id'] = $refundId;
    orders_save($order);
    echo json_encode(['ok' => true, 'refundId' => $order['refund_id']]);

} catch (ApiException $e) {
    handlePayPalApiException($e);
} catch (Exception $e) {
    error_log("Unexpected error in refund: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An unexpected error occurred.']);
    exit;
}
