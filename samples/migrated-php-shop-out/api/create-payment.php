<?php
require_once __DIR__ . '/../lib/paypal_sdk.php';
require_once __DIR__ . '/../lib/orders_store.php';
header('Content-Type: application/json');

use PaypalServerSdkLib\Models\Builders\{OrderRequestBuilder, PurchaseUnitRequestBuilder, AmountWithBreakdownBuilder, AmountBreakdownBuilder, ItemBuilder, MoneyBuilder};
use PaypalServerSdkLib\ApiException;

$cart = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$total = 0.0;
$purchaseUnitItems = [];

foreach ($cart as $sku => $qty) {
    if (!isset($PRODUCTS[$sku])) continue;
    $p = $PRODUCTS[$sku];
    $itemPrice = (float)$p['price'];
    $itemQty = (int)$qty;

    // Fix: Skip items with quantity 0 as PayPal rejects them.
    if ($itemQty <= 0) {
        continue;
    }

    $total += $itemPrice * $itemQty;

    $purchaseUnitItems[] = ItemBuilder::init(
        $p['name'],
        MoneyBuilder::init(SHOP_CURRENCY, number_format($itemPrice, 2, '.', ''))->build(),
        (string)$itemQty // Quantity must be a string
    )->build();
}

if ($total <= 0) { http_response_code(400); echo json_encode(['error' => 'empty cart']); exit; }

$ordersController = getPaypalOrdersController();

try {
    $purchaseUnit = PurchaseUnitRequestBuilder::init(
        AmountWithBreakdownBuilder::init(SHOP_CURRENCY, number_format($total, 2, '.', ''))
            ->breakdown(AmountBreakdownBuilder::init()->itemTotal(MoneyBuilder::init(SHOP_CURRENCY, number_format($total, 2, '.', ''))->build())->build())
            ->build()
    )->items($purchaseUnitItems)->build();

    $orderRequest = OrderRequestBuilder::init('CAPTURE', [$purchaseUnit])->build();

    // CUTOVER-REVIEW: The NVP parameters RETURNURL, CANCELURL, NOSHIPPING, LOCALECODE are not explicitly set
    // in the server-side createOrder call for JS SDK v6. The JS SDK typically handles the experience context.
    // If the shop relies on these being explicitly set on the server for specific behaviors (e.g., custom return/cancel pages
    // after the PayPal flow, or specific shipping preferences), this might need review.
    // If needed, they would be added via payment_source.paypal.experience_context in the OrderRequest.

    $r = $ordersController->createOrder(['body' => $orderRequest, 'prefer' => 'return=representation']);
    $orderId = $r->getResult()->getId();

    orders_save([
        'token' => $orderId,
        'amount' => number_format($total, 2, '.', ''),
        'currency' => SHOP_CURRENCY, // Store currency with the order for webhook verification
        'status' => 'CREATED',
        'created' => date('c')
    ]);
    echo json_encode(['token' => $orderId]); // Preserve 'token' key for frontend compatibility

} catch (ApiException $e) {
    handlePaypalApiException($e, 'Failed to create PayPal order');
} catch (Exception $e) {
    error_log("General error in create-payment: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
