<?php
// Step 1 of Express Checkout: SetExpressCheckout, returns the EC token to checkout.js.
require_once __DIR__ . '/../lib/paypal_nvp.php';
require_once __DIR__ . '/../lib/orders_store.php';
header('Content-Type: application/json');

// checkout.js v4 posts either JSON or a classic form body depending on the call; accept both.
$cart = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$total = 0.0; $i = 0; $items = [];
foreach ($cart as $sku => $qty) {
    if (!isset($PRODUCTS[$sku])) continue;
    $p = $PRODUCTS[$sku];
    $items["L_PAYMENTREQUEST_0_NAME$i"] = $p['name'];
    $items["L_PAYMENTREQUEST_0_AMT$i"]  = $p['price'];
    $items["L_PAYMENTREQUEST_0_QTY$i"]  = (int)$qty;
    $total += $p['price'] * (int)$qty; $i++;
}
if ($total <= 0) { http_response_code(400); echo json_encode(['error' => 'empty cart']); exit; }

$resp = paypal_nvp('SetExpressCheckout', array_merge([
    'PAYMENTREQUEST_0_PAYMENTACTION' => 'Sale',
    'PAYMENTREQUEST_0_AMT'           => number_format($total, 2, '.', ''),
    'PAYMENTREQUEST_0_ITEMAMT'       => number_format($total, 2, '.', ''),
    'PAYMENTREQUEST_0_CURRENCYCODE'  => SHOP_CURRENCY,
    'RETURNURL'                      => SHOP_BASE_URL . '/success.php',
    'CANCELURL'                      => SHOP_BASE_URL . '/cancel.php',
    'NOSHIPPING'                     => '1',
    'LOCALECODE'                     => 'en_US',
], $items));

if (!paypal_ok($resp)) { http_response_code(502); echo json_encode(['error' => $resp['L_LONGMESSAGE0'] ?? 'PayPal error']); exit; }
orders_save(['token' => $resp['TOKEN'], 'amount' => number_format($total, 2, '.', ''), 'status' => 'CREATED', 'created' => date('c')]);
echo json_encode(['token' => $resp['TOKEN']]);
