<?php require_once __DIR__ . '/config.php'; ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cutover Demo Shop (legacy PayPal)</title>
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

(async () => {
  let clientToken;
  try {
    const response = await fetch('/api/client-token.php');
    if (!response.ok) throw new Error('Failed to fetch client token: ' + response.statusText);
    const data = await response.json();
    clientToken = data.clientToken;
  } catch (error) {
    console.error('Error fetching client token:', error);
    document.getElementById('result').style.display = 'block';
    document.getElementById('result').textContent = 'Payment failed: Could not initialize PayPal (client token missing).';
    return;
  }

  const sdk = await window.paypal.createInstance({ clientToken, components: ['paypal-payments'], pageType: 'checkout' });
  const session = sdk.createPayPalOneTimePaymentSession({
    async onApprove(data) {
      try {
        const res = await fetch('/api/execute-payment.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ orderId: data.orderId })
        });
        const jsonRes = await res.json();
        var el = document.getElementById('result'); el.style.display = 'block';
        el.textContent = jsonRes.ok ? 'Payment complete. Transaction ' + jsonRes.transactionId + ' for $' + jsonRes.amount : 'Payment failed: ' + (jsonRes.error || 'unknown');
      } catch (error) {
        console.error('Error executing payment:', error);
        var el = document.getElementById('result'); el.style.display = 'block';
        el.textContent = 'Payment failed: ' + (error.message || 'unknown error during execution.');
      }
    },
    onCancel() { var el = document.getElementById('result'); el.style.display = 'block'; el.textContent = 'Payment cancelled.'; },
    onError(err) {
      console.error('PayPal SDK error:', err);
      var el = document.getElementById('result'); el.style.display = 'block';
      el.textContent = 'Payment failed: ' + (err.message || 'unknown PayPal error.');
    }
  });

  const paypalButton = document.getElementById('paypal-button');
  paypalButton.addEventListener('click', () => {
    const cartData = cart();
    if (Object.keys(cartData).length === 0) {
        var el = document.getElementById('result'); el.style.display = 'block';
        el.textContent = 'Payment failed: your cart is empty';
        return;
    }
    session.start({ presentationMode: 'popup' }, createOrder());
  });

  async function createOrder() {
    const response = await fetch('/api/create-payment.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(cart())
    });
    if (!response.ok) {
      const errorData = await response.json();
      throw new Error(errorData.error || 'Failed to create order.');
    }
    const data = await response.json();
    return { orderId: data.id };
  }
})();
</script>
</body></html>