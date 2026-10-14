<?php
require_once __DIR__ . '/../lib/paypal_sdk.php';
require_once __DIR__ . '/../lib/orders_store.php';
header('Content-Type: application/json');
$cart = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$total = 0.0; $items = [];
foreach ($cart as $sku => $qty) {
    if (!isset($PRODUCTS[$sku]) || (int)$qty <= 0) continue;
    $p = $PRODUCTS[$sku];
    $items[] = \PaypalServerSdkLib\Models\Builders\ItemBuilder::init($p['name'], \PaypalServerSdkLib\Models\Builders\MoneyBuilder::init(SHOP_CURRENCY, $p['price'])->build(), (string)$qty)->build();
    $total += $p['price'] * (int)$qty;
}
if ($total <= 0) { http_response_code(400); echo json_encode(['error' => 'empty cart']); exit; }
try {
    $r = $orders->createOrder(['body' => \PaypalServerSdkLib\Models\Builders\OrderRequestBuilder::init('CAPTURE', [
        \PaypalServerSdkLib\Models\Builders\PurchaseUnitRequestBuilder::init(
            \PaypalServerSdkLib\Models\Builders\AmountWithBreakdownBuilder::init(SHOP_CURRENCY, number_format($total, 2, '.', ''))
                ->breakdown(\PaypalServerSdkLib\Models\Builders\AmountBreakdownBuilder::init()->itemTotal(\PaypalServerSdkLib\Models\Builders\MoneyBuilder::init(SHOP_CURRENCY, number_format($total, 2, '.', ''))->build())->build())->build()
        )->items($items)->build()
    ])->build(), 'prefer' => 'return=representation']);
    $orderId = $r->getResult()->getId();
    orders_save(['token' => $orderId, 'amount' => number_format($total, 2, '.', ''), 'status' => 'CREATED', 'created' => date('c')]);
    echo json_encode(['id' => $orderId]);
} catch (Exception $e) { http_response_code(502); echo json_encode(['error' => 'order creation failed']); }