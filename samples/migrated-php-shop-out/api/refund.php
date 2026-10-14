<?php
require_once __DIR__ . '/../lib/paypal_sdk.php';
require_once __DIR__ . '/../lib/orders_store.php';
header('Content-Type: application/json');

use PaypalServerSdkLib\ApiException;

// Security fix: Refund endpoint requires admin authentication.
$adminToken = getenv('ADMIN_TOKEN');
if (!$adminToken) {
    error_log("ADMIN_TOKEN environment variable not set for /api/refund.php");
    http_response_code(500);
    echo json_encode(['error' => 'Server configuration error: ADMIN_TOKEN missing.']);
    exit;
}

$requestAdminToken = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
if ($requestAdminToken !== $adminToken) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$order = orders_get($in['token'] ?? ''); // 'token' is now orderId

if (!$order || $order['status'] !== 'PAID') {
    http_response_code(400);
    echo json_encode(['error' => 'order is not paid or does not exist']);
    exit;
}

$captureId = $order['transaction_id']; // Stored transaction_id is now the capture ID
if (!$captureId) {
    http_response_code(400);
    echo json_encode(['error' => 'Order has no capture ID to refund.']);
    exit;
}

$paymentsController = getPaypalPaymentsController();

try {
    // Refund by CAPTURE id (empty body = full refund)
    $refundResponse = $paymentsController->refundCapturedPayment(['captureId' => $captureId, 'prefer' => 'return=representation']);
    $refundId = $refundResponse->getResult()->getId();

    $order['status'] = 'REFUNDED';
    $order['refund_id'] = $refundId;
    orders_save($order);

    echo json_encode(['ok' => true, 'refundId' => $refundId]);

} catch (ApiException $e) {
    handlePaypalApiException($e, 'Failed to process PayPal refund');
} catch (Exception $e) {
    error_log("General error in refund: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
