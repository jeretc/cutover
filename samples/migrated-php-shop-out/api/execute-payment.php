<?php
require_once __DIR__ . '/../lib/paypal_sdk.php';
require_once __DIR__ . '/../lib/orders_store.php';
header('Content-Type: application/json');

use PaypalServerSdkLib\ApiException;

$in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$orderId = $in['orderId'] ?? ''; // Frontend now sends orderId, not token

$order = orders_get($orderId);
if (!$order) { http_response_code(404); echo json_encode(['error' => 'unknown order ID']); exit; }

// Security fix: Payment confirmation has replay guard.
// If the order is already paid, return the existing transaction details idempotently.
if ($order['status'] === 'PAID') {
    echo json_encode(['ok' => true, 'transactionId' => $order['transaction_id'], 'amount' => $order['amount']]);
    exit;
}
// If the order is already refunded, it's an error to try to capture again.
if ($order['status'] === 'REFUNDED') {
    http_response_code(400);
    echo json_encode(['error' => 'Order already refunded, cannot capture.']);
    exit;
}

$ordersController = getPaypalOrdersController();

try {
    // Get order details (replaces GetExpressCheckoutDetails)
    $paypalOrder = $ordersController->getOrder(['id' => $orderId])->getResult();
    if ($paypalOrder->getStatus() !== 'APPROVED') {
        http_response_code(400);
        echo json_encode(['error' => 'Order not approved by buyer. Current status: ' . $paypalOrder->getStatus()]);
        exit;
    }

    // Capture the order (replaces DoExpressCheckoutPayment)
    $captureResponse = $ordersController->captureOrder(['id' => $orderId, 'prefer' => 'return=representation']);
    $captureResult = $captureResponse->getResult();

    $captureId = $captureResult->getPurchaseUnits()[0]->getPayments()->getCaptures()[0]->getId();
    $payerEmail = $paypalOrder->getPayer()->getEmailAddress() ?? '';
    $payerId = $paypalOrder->getPayer()->getPayerId() ?? ''; // Store payer ID if available

    $order['status'] = 'PAID';
    $order['payer_id'] = $payerId;
    $order['transaction_id'] = $captureId;
    $order['payer_email'] = $payerEmail;
    orders_save($order);

    echo json_encode(['ok' => true, 'transactionId' => $order['transaction_id'], 'amount' => $order['amount']]);

} catch (ApiException $e) {
    // Handle specific PayPal errors like ORDER_ALREADY_CAPTURED if the replay guard above somehow missed it
    $errorBody = json_decode($e->getResponse(), true);
    if (($errorBody['name'] ?? '') === 'ORDER_ALREADY_CAPTURED') {
        error_log("Attempted to capture an already captured order: " . $orderId . ". This should have been caught by the replay guard.");
        http_response_code(400);
        echo json_encode(['error' => 'Order already captured.']);
        exit;
    }
    handlePaypalApiException($e, 'Failed to execute PayPal payment');
} catch (Exception $e) {
    error_log("General error in execute-payment: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
