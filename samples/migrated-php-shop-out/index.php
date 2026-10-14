<?php require_once __DIR__ . '/config.php'; ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cutover Demo Shop (modern PayPal)</title>
<link rel="stylesheet" href="assets/shop.css">
</head>
<body>
<div class="top">Sandbox store: fake buyer, fake money</div>
<header class="site"><a class="logo" href="./"><i></i>Cutover Supply Co.</a><span>Sandbox demo store</span></header>
<section class="hero"><h1>Merch for people who ship on Fridays.</h1><p>Pick what you like and pay with PayPal.</p></section>

<div class="layout">
  <div class="products" id="items">
    <div class="card"><img src="assets/tee.svg" alt="Cutover Tee"><div class="body"><h3>Cutover Tee</h3><span class="price">$25.00</span>
      <label class="qty">Qty <input type="number" min="0" value="1" data-sku="tee" data-price="25.00"></label></div></div>
    <div class="card"><img src="assets/mug.svg" alt="Rollback Mug"><div class="body"><h3>Rollback Mug</h3><span class="price">$12.50</span>
      <label class="qty">Qty <input type="number" min="0" value="0" data-sku="mug" data-price="12.50"></label></div></div>
    <div class="card"><img src="assets/poster.svg" alt="Legacy Poster"><div class="body"><h3>Legacy Poster</h3><span class="price">$18.00</span>
      <label class="qty">Qty <input type="number" min="0" value="0" data-sku="poster" data-price="18.00"></label></div></div>
  </div>
  <aside class="summary">
    <h2>Your order</h2>
    <div id="total"><span>Total</span><span>$<span id="amt">0.00</span></span></div>
    <paypal-button id="paypal-button" type="pay"></paypal-button>
    <div id="result"></div>
    <p class="fine">You will pay in the PayPal sandbox with the test buyer.</p>
  </aside>
</div>
<footer class="site">Cutover Supply Co. is a demo store. Not affiliated with or endorsed by PayPal.</footer>

<script src="https://www.sandbox.paypal.com/web-sdk/v6/core"></script>
<script>
function cart() { var c = {}; document.querySelectorAll('input[data-sku]').forEach(function (i) { if (+i.value > 0) c[i.dataset.sku] = +i.value; }); return c; }
function total() { var t = 0; document.querySelectorAll('input[data-sku]').forEach(function (i) { t += i.value * i.dataset.price; }); return t; }
function refresh() { document.getElementById('amt').textContent = total().toFixed(2); }
document.querySelectorAll('input[data-sku]').forEach(function (i) { i.addEventListener('input', refresh); }); refresh();

// Fix: Define createOrder as a standalone function as per JS SDK v6 grounding.
// The session.start() method expects a Promise returned by calling this function.
async function createOrderForSession() {
  const cartData = cart();
  if (Object.keys(cartData).length === 0) {
    throw new Error('Empty cart'); // Prevent creating an empty order
  }
  const response = await fetch('/api/create-payment.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(cartData)
  });
  if (!response.ok) {
    const errorData = await response.json();
    throw new Error(errorData.error || 'Failed to create order');
  }
  const data = await response.json();
  return { orderId: data.token }; // Server still returns 'token', map to 'orderId' for SDK
}

async function initPayPalButton() {
  let clientToken;
  try {
    const response = await fetch('/api/client-token.php');
    if (!response.ok) {
      const errorData = await response.json();
      throw new Error(errorData.error || 'Failed to fetch client token');
    }
    const data = await response.json();
    clientToken = data.clientToken;
  } catch (error) {
    console.error('Error fetching client token:', error);
    document.getElementById('result').style.display = 'block';
    document.getElementById('result').textContent = 'Payment failed: Could not initialize PayPal (client token error).';
    return;
  }

  const sdk = await window.paypal.createInstance({ clientToken, components: ['paypal-payments'], pageType: 'checkout' });
  const session = sdk.createPayPalOneTimePaymentSession({
    // Fix: createOrder callback is no longer defined here. It's handled by session.start's second argument.
    async onApprove(data) {
      const response = await fetch('/api/execute-payment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ orderId: data.orderId }) // Send orderId, not token/payerID
      });
      const res = await response.json();
      var el = document.getElementById('result'); el.style.display = 'block';
      el.textContent = res.ok ? 'Payment complete. Transaction ' + res.transactionId + ' for $' + res.amount : 'Payment failed: ' + (res.error || 'unknown');
    },
    onCancel() {
      var el = document.getElementById('result'); el.style.display = 'block'; el.textContent = 'Payment cancelled.';
    },
    onError(err) {
      console.error('PayPal error:', err);
      var el = document.getElementById('result'); el.style.display = 'block'; el.textContent = 'Payment failed: ' + (err.message || 'unknown error');
    }
  });

  const button = document.getElementById('paypal-button');
  button.addEventListener('click', () => {
    // createOrder() is an async function that is CALLED here and its promise passed in.
    // NO await before start(): the popup must open synchronously in the click.
    // Fix: Call the standalone createOrderForSession function and pass its returned Promise.
    session.start({ presentationMode: 'popup' }, createOrderForSession());
  });
}

initPayPalButton();
</script>
</body></html>
