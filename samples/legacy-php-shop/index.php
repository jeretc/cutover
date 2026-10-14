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
    <div id="paypal-button"></div>
    <div id="result"></div>
    <p class="fine">You will pay in the PayPal sandbox with the test buyer.</p>
  </aside>
</div>
<footer class="site">Cutover Supply Co. is a demo store. Not affiliated with or endorsed by PayPal.</footer>

<script src="https://www.paypalobjects.com/api/checkout.js"></script>
<script>
function cart() { var c = {}; document.querySelectorAll('input[data-sku]').forEach(function (i) { if (+i.value > 0) c[i.dataset.sku] = +i.value; }); return c; }
function total() { var t = 0; document.querySelectorAll('input[data-sku]').forEach(function (i) { t += i.value * i.dataset.price; }); return t; }
function refresh() { document.getElementById('amt').textContent = total().toFixed(2); }
document.querySelectorAll('input[data-sku]').forEach(function (i) { i.addEventListener('input', refresh); }); refresh();

paypal.Button.render({
  env: 'sandbox',
  commit: true,
  payment: function () {
    return paypal.request.post('/api/create-payment.php', cart()).then(function (data) { return data.token; });
  },
  onAuthorize: function (data) {
    return paypal.request.post('/api/execute-payment.php', { token: data.paymentToken || data.token, payerID: data.payerID }).then(function (res) {
      var el = document.getElementById('result'); el.style.display = 'block';
      el.textContent = res.ok ? 'Payment complete. Transaction ' + res.transactionId + ' for $' + res.amount : 'Payment failed: ' + (res.error || 'unknown');
    });
  },
  onCancel: function () { var el = document.getElementById('result'); el.style.display = 'block'; el.textContent = 'Payment cancelled.'; }
}, '#paypal-button');
</script>
</body></html>
