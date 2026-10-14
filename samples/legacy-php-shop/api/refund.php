<?php
// Admin-style refund using the classic RefundTransaction call.
require_once __DIR__ . '/../lib/paypal_nvp.php';
require_once __DIR__ . '/../lib/orders_store.php';
header('Content-Type: application/json');

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$order = orders_get($in['token'] ?? '');
if (!$order || $order['status'] !== 'PAID') { http_response_code(400); echo json_encode(['error' => 'order is not paid']); exit; }

$resp = paypal_nvp('RefundTransaction', ['TRANSACTIONID' => $order['transaction_id'], 'REFUNDTYPE' => 'Full']);
if (!paypal_ok($resp)) { http_response_code(502); echo json_encode(['error' => $resp['L_LONGMESSAGE0'] ?? 'refund failed']); exit; }
$order['status'] = 'REFUNDED'; $order['refund_id'] = $resp['REFUNDTRANSACTIONID'];
orders_save($order);
echo json_encode(['ok' => true, 'refundId' => $order['refund_id']]);
