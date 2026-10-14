# Target stack facts (verified in the sandbox, PayPal PHP Server SDK paypal/paypal-server-sdk ^2.4)

## PHP client setup
```php
require __DIR__ . '/vendor/autoload.php';
use PaypalServerSdkLib\PaypalServerSdkClientBuilder;
use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;
use PaypalServerSdkLib\Environment;
$client = PaypalServerSdkClientBuilder::init()
  ->clientCredentialsAuthCredentials(ClientCredentialsAuthCredentialsBuilder::init(getenv('PAYPAL_CLIENT_ID'), getenv('PAYPAL_CLIENT_SECRET')))
  ->environment(Environment::SANDBOX)->build();
$orders = $client->getOrdersController();
$payments = $client->getPaymentsController();
```
Credentials come ONLY from the environment (no fallbacks). Fail with an error if missing.

## Create order (replaces SetExpressCheckout). Request bodies MUST be builder objects, not arrays.
```php
use PaypalServerSdkLib\Models\Builders\{OrderRequestBuilder, PurchaseUnitRequestBuilder, AmountWithBreakdownBuilder, AmountBreakdownBuilder, ItemBuilder, MoneyBuilder};
$r = $orders->createOrder(['body' => OrderRequestBuilder::init('CAPTURE', [
  PurchaseUnitRequestBuilder::init(
    AmountWithBreakdownBuilder::init('USD', '50.00')
      ->breakdown(AmountBreakdownBuilder::init()->itemTotal(MoneyBuilder::init('USD', '50.00')->build())->build())->build()
  )->items([ItemBuilder::init('Tee', MoneyBuilder::init('USD', '25.00')->build(), '2')->build()])->build()
])->build(), 'prefer' => 'return=representation']);
$orderId = $r->getResult()->getId();   // HTTP 201, status CREATED
```
Quantity is a STRING. Sale -> intent CAPTURE.

## Other calls
- `$orders->getOrder(['id' => $orderId])` -> ->getResult()->getStatus() / getPayer()
- `$orders->captureOrder(['id' => $orderId, 'prefer' => 'return=representation'])` -> capture id at `->getResult()->getPurchaseUnits()[0]->getPayments()->getCaptures()[0]->getId()`. Capturing an already captured order is an ERROR (ORDER_ALREADY_CAPTURED, HTTP 422): the old NVP API returned the same transaction instead, so the migrated code must check the stored order status first and return the stored capture (idempotent).
- Refund by CAPTURE id: `$payments->refundCapturedPayment(['captureId' => $captureId, 'prefer' => 'return=representation'])` (empty body = full refund) -> `->getResult()->getId()`.
- SDK failures throw exceptions (ApiException subclasses, getStatusCode()); catch them and answer with HTTP 502 JSON `{error}` like the legacy shop.

## Front end: JS SDK v6 (replaces checkout.js v4)
- The server mints a client token: POST https://api-m.sandbox.paypal.com/v1/oauth2/token with Basic auth(client id:secret) and body `grant_type=client_credentials&response_type=client_token&intent=sdk_init` -> `access_token`. Expose it from `api/client-token.php` as `{clientToken}`. (Never put the secret in the browser.)
- Load `<script src="https://www.sandbox.paypal.com/web-sdk/v6/core"></script>` (sandbox host), then:
```js
const sdk = await window.paypal.createInstance({ clientToken, components: ['paypal-payments'], pageType: 'checkout' });
const session = sdk.createPayPalOneTimePaymentSession({
  async onApprove(data) { /* data.orderId -> POST /api/execute-payment.php {orderId} */ },
  onCancel() { },
  onError(err) { }
});
// a <paypal-button id="paypal-button"> web component click handler:
button.addEventListener('click', () => {
  // NO await before start(): the popup must open synchronously in the click. createOrder() is an async function that is CALLED here and its promise passed in.
  session.start({ presentationMode: 'popup' }, createOrder()); // createOrder() returns Promise<{ orderId }>. IMPORTANT: pass the PROMISE (call it: createOrder()), never the function reference
});
```
- `createOrder()` POSTs the cart to `/api/create-payment.php` and resolves `{ orderId: data.id }`.

## Webhooks (replace IPN)
The PHP SDK has NO webhook controller (its only controllers are Orders, Payments, Vault, TransactionSearch, Subscriptions). Verify with plain curl: first get a bearer token (POST https://api-m.sandbox.paypal.com/v1/oauth2/token, Basic auth client id:secret, body grant_type=client_credentials -> access_token), then POST https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature with Authorization: Bearer <token>, JSON body {auth_algo, cert_url, transmission_id, transmission_sig, transmission_time, webhook_id, webhook_event} taken from the PAYPAL-* request headers (PAYPAL-AUTH-ALGO, PAYPAL-CERT-URL, PAYPAL-TRANSMISSION-ID, PAYPAL-TRANSMISSION-SIG, PAYPAL-TRANSMISSION-TIME) and the raw decoded body; the answer is {"verification_status":"SUCCESS"} or FAILURE. Needs the PAYPAL_WEBHOOK_ID env var. Write this in a function inside the webhook handler (the string verify-webhook-signature must appear in the code). handle PAYMENT.CAPTURE.COMPLETED by matching the capture to a stored order, amount and currency; ignore duplicates by event id.

## Configuration rules
- config.php must NOT throw or exit at load time for missing optional variables. PAYPAL_CLIENT_ID and PAYPAL_CLIENT_SECRET are needed by the payment endpoints; PAYPAL_WEBHOOK_ID only by the webhook handler (return HTTP 500 {error} there if it is missing); ADMIN_TOKEN only by the admin endpoints (refuse with 401 when it is missing or does not match).
- lib/paypal_sdk.php must `require_once __DIR__ . '/../config.php'` so SHOP_CURRENCY and $PRODUCTS are always defined.
- Keep the JSON shapes: create-payment returns {"id": orderId} and 400 {"error":"empty cart"} for an empty cart; execute-payment returns {"ok":true,"transactionId","amount"}; refund returns 400 {"error":"order is not paid"} for an unpaid or unknown order, 200 {"ok":true,"refundId"} otherwise.
- The page uses the `<paypal-button id="paypal-button" type="pay">` web component of JS SDK v6 (not a plain div).
