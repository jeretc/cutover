<?php
// Steps 2 and 3: GetExpressCheckoutDetails then DoExpressCheckoutPayment, called after the buyer approves.
// MIGRATED: Now captures the PayPal Order using Orders v2 API.
require_once __DIR__ . '/../lib/paypal_sdk.php';
require_once __DIR__ . '/../lib/orders_store.php';
header('Content-Type: application/json');

use PaypalServerSdkLib\ApiException;

if (!$orders) {
    http_response_code(500);
    echo json_encode(['error' => 'PayPal SDK not initialized.']);
    exit;
}

$in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$orderId = $in['orderId'] ?? ''; // JS SDK v6 sends orderId

$order = orders_get($orderId);
if (!$order) { http_response_code(404); echo json_encode(['error' => 'unknown order ID']); exit; }

// Security fix: Payment confirmation has replay guard (idempotency).
// If the order is already paid, return the stored transaction ID.
if ($order['status'] === 'PAID') {
    echo json_encode(['ok' => true, 'transactionId' => $order['transaction_id'], 'amount' => $order['amount']]);
    exit;
}

try {
    // Get order details to retrieve payer email (replaces GetExpressCheckoutDetails)
    $paypalOrder = $orders->getOrder(['id' => $orderId]);
    $payerEmail = $paypalOrder->getResult()->getPayer()->getEmailAddress() ?? '';
    $payerId = $paypalOrder->getResult()->getPayer()->getPayerId() ?? '';

    // Capture the order (replaces DoExpressCheckoutPayment)
    $captureResponse = $orders->captureOrder(['id' => $orderId, 'prefer' => 'return=representation']);
    $captureId = $captureResponse->getResult()->getPurchaseUnits()[0]->getPayments()->getCaptures()[0]->getId();

    $order['status'] = 'PAID';
    $order['payer_id'] = $payerId;
    $order['transaction_id'] = $captureId;
    $order['payer_email'] = $payerEmail;
    orders_save($order);

    echo json_encode(['ok' => true, 'transactionId' => $order['transaction_id'], 'amount' => $order['amount']]);

} catch (ApiException $e) {
    // Handle ORDER_ALREADY_CAPTURED specifically for idempotency
    if ($e->getStatusCode() === 422) {
        $errorData = json_decode($e->getResponseBody(), true);
        if (($errorData['details'][0]['issue'] ?? '') === 'ORDER_ALREADY_CAPTURED') {
            // Attempt to retrieve the capture ID from the order if it was already captured by PayPal
            try {
                $paypalOrder = $orders->getOrder(['id' => $orderId]);
                $captures = $paypalOrder->getResult()->getPurchaseUnits()[0]->getPayments()->getCaptures();
                if (!empty($captures)) {
                    $captureId = $captures[0]->getId();
                    $order['status'] = 'PAID';
                    $order['transaction_id'] = $captureId;
                    orders_save($order);
                    echo json_encode(['ok' => true, 'transactionId' => $order['transaction_id'], 'amount' => $order['amount']]);
                    exit;
                }
            } catch (Exception $innerE) {
                error_log("Failed to retrieve capture details for already captured order $orderId: " . $innerE->getMessage());
            }
        }
    }
    handlePayPalApiException($e);
} catch (Exception $e) {
    error_log("Unexpected error in execute-payment: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An unexpected error occurred.']);
    exit;
}
