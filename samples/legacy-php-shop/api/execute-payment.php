<?php
// Steps 2 and 3: GetExpressCheckoutDetails then DoExpressCheckoutPayment, called after the buyer approves.
require_once __DIR__ . '/../lib/paypal_nvp.php';
require_once __DIR__ . '/../lib/orders_store.php';
header('Content-Type: application/json');

$in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$token = $in['token'] ?? ''; $payer = $in['payerID'] ?? '';
$order = orders_get($token);
if (!$order) { http_response_code(404); echo json_encode(['error' => 'unknown token']); exit; }

$details = paypal_nvp('GetExpressCheckoutDetails', ['TOKEN' => $token]);
if (!paypal_ok($details)) { http_response_code(502); echo json_encode(['error' => 'details failed']); exit; }

$pay = paypal_nvp('DoExpressCheckoutPayment', [
    'TOKEN' => $token, 'PAYERID' => $payer,
    'PAYMENTREQUEST_0_PAYMENTACTION' => 'Sale',
    'PAYMENTREQUEST_0_AMT'           => $order['amount'],
    'PAYMENTREQUEST_0_CURRENCYCODE'  => SHOP_CURRENCY,
]);
if (!paypal_ok($pay)) { http_response_code(502); echo json_encode(['error' => $pay['L_LONGMESSAGE0'] ?? 'payment failed']); exit; }

$order['status'] = 'PAID';
$order['payer_id'] = $payer;
$order['transaction_id'] = $pay['PAYMENTINFO_0_TRANSACTIONID'];
$order['payer_email'] = $details['EMAIL'] ?? '';
orders_save($order);
echo json_encode(['ok' => true, 'transactionId' => $order['transaction_id'], 'amount' => $order['amount']]);
