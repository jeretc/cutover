<?php
// Step 1 of Express Checkout: SetExpressCheckout, returns the EC token to checkout.js.
// MIGRATED: Now creates a PayPal Order using Orders v2 API.
require_once __DIR__ . '/../lib/paypal_sdk.php';
require_once __DIR__ . '/../lib/orders_store.php';
header('Content-Type: application/json');

use PaypalServerSdkLib\Models\Builders\{OrderRequestBuilder, PurchaseUnitRequestBuilder, AmountWithBreakdownBuilder, AmountBreakdownBuilder, ItemBuilder, MoneyBuilder};
use PaypalServerSdkLib\ApiException;

if (!$orders) {
    http_response_code(500);
    echo json_encode(['error' => 'PayPal SDK not initialized.']);
    exit;
}

// checkout.js v4 posts either JSON or a classic form body depending on the call; accept both.
$cart = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$total = 0.0; $items = [];
foreach ($cart as $sku => $qty) {
    if (!isset($PRODUCTS[$sku])) continue;
    $p = $PRODUCTS[$sku];
    $itemPrice = number_format((float)$p['price'], 2, '.', '');
    $itemQty = (string)(int)$qty; // Quantity must be a string
    if ((int)$itemQty > 0) {
        $items[] = ItemBuilder::init($p['name'], MoneyBuilder::init(SHOP_CURRENCY, $itemPrice)->build(), $itemQty)->build();
        $total += (float)$itemPrice * (int)$itemQty;
    }
}
$total = number_format($total, 2, '.', '');

if ((float)$total <= 0) { http_response_code(400); echo json_encode(['error' => 'empty cart']); exit; }

try {
    $purchaseUnit = PurchaseUnitRequestBuilder::init(
        AmountWithBreakdownBuilder::init(SHOP_CURRENCY, $total)
            ->breakdown(AmountBreakdownBuilder::init()->itemTotal(MoneyBuilder::init(SHOP_CURRENCY, $total)->build())->build())
            ->build()
    )->items($items)->build();

    $orderRequest = OrderRequestBuilder::init('CAPTURE', [$purchaseUnit])->build();

    $r = $orders->createOrder(['body' => $orderRequest, 'prefer' => 'return=representation']);
    $orderId = $r->getResult()->getId();

    orders_save(['token' => $orderId, 'amount' => $total, 'status' => 'CREATED', 'created' => date('c'), 'currency' => SHOP_CURRENCY]);
    echo json_encode(['id' => $orderId]);

} catch (ApiException $e) {
    handlePayPalApiException($e);
} catch (Exception $e) {
    error_log("Unexpected error in create-payment: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An unexpected error occurred.']);
    exit;
}
