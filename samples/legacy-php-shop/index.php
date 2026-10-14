<?php require_once __DIR__ . '/config.php'; ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Cutover Demo Shop (legacy PayPal)</title>
<style>
 body{font-family:Georgia,serif;max-width:640px;margin:2rem auto;padding:0 1rem;color:#222}
 .item{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #ccc;padding:.6rem 0}
 input[type=number]{width:3.5rem}
 #total{font-size:1.3rem;margin:1rem 0}
 #result{margin-top:1rem;padding:.7rem;background:#eef;display:none}
</style></head>
<body>
<h1>Cutover Demo Shop</h1>
<p><em>A deliberately old PayPal integration: checkout.js v4 plus the NVP API.</em></p>
<div id="items">
<?php foreach ($PRODUCTS as $sku => $p): ?>
  <div class="item"><span><?= htmlspecialchars($p['name']) ?> &mdash; $<?= $p['price'] ?></span>
  <span>Qty <input type="number" min="0" value="<?= $sku === 'tee' ? 1 : 0 ?>" data-sku="<?= $sku ?>" data-price="<?= $p['price'] ?>"></span></div>
<?php endforeach; ?>
</div>
<div id="total">Total: $<span id="amt">0.00</span></div>
<div id="paypal-button"></div>
<div id="result"></div>

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
