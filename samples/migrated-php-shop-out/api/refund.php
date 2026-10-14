<?php
require_once __DIR__ . '/../lib/paypal_sdk.php';
require_once __DIR__ . '/../lib/orders_store.php';
if (getenv('ADMIN_TOKEN') === false || ($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '') !== getenv('ADMIN_TOKEN')) { http_response_code(401); exit; }
header('Content-Type: application/json');
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$order = orders_get($in['token'] ?? '');
if (!$order || $order['status'] !== 'PAID') { http_response_code(400); echo json_encode(['error' => 'order is not paid']); exit; }
try {
    $resp = $payments->refundCapturedPayment(['captureId' => $order['transaction_id'], 'prefer' => 'return=representation']);
    $order['status'] = 'REFUNDED'; $order['refund_id'] = $resp->getResult()->getId();
    orders_save($order);
    echo json_encode(['ok' => true, 'refundId' => $order['refund_id']]);
} catch (Exception $e) { http_response_code(502); echo json_encode(['error' => 'refund failed']); }