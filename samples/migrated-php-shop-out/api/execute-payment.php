<?php
require_once __DIR__ . '/../lib/paypal_sdk.php';
require_once __DIR__ . '/../lib/orders_store.php';
header('Content-Type: application/json');
$in = json_decode(file_get_contents('php://input'), true);
$orderId = $in['orderId'] ?? '';
$order = orders_get($orderId);
if (!$order) { http_response_code(404); echo json_encode(['error' => 'unknown order']); exit; }
if ($order['status'] === 'PAID') { echo json_encode(['ok' => true, 'transactionId' => $order['transaction_id'], 'amount' => $order['amount']]); exit; }
try {
    $res = $orders->captureOrder(['id' => $orderId, 'prefer' => 'return=representation']);
    $capture = $res->getResult()->getPurchaseUnits()[0]->getPayments()->getCaptures()[0];
    $order['status'] = 'PAID';
    $order['transaction_id'] = $capture->getId();
    orders_save($order);
    echo json_encode(['ok' => true, 'transactionId' => $order['transaction_id'], 'amount' => $order['amount']]);
} catch (Exception $e) { http_response_code(502); echo json_encode(['error' => 'capture failed']); }