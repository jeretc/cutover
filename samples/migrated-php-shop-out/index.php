<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Cutover Demo Shop</title>
<script src="https://www.sandbox.paypal.com/web-sdk/v6/core"></script>
</head><body>
<input type="number" data-sku="tee" value="1">
<input type="number" data-sku="mug" value="0">
<input type="number" data-sku="poster" value="0">
<paypal-button id="paypal-button" type="pay"></paypal-button>
<div id="result"></div>
<script>
async function createOrder() {
  const cart = {};
  document.querySelectorAll('input[data-sku]').forEach(i => cart[i.dataset.sku] = i.value);
  const res = await fetch('/api/create-payment.php', { method: 'POST', body: JSON.stringify(cart) });
  const data = await res.json();
  if (!data.id) throw new Error(data.error || 'Failed');
  return { orderId: data.id };
}
(async () => {
  const tokenRes = await fetch('/api/client-token.php');
  const { clientToken } = await tokenRes.json();
  const sdk = await window.paypal.createInstance({ clientToken, components: ['paypal-payments'], pageType: 'checkout' });
  const session = sdk.createPayPalOneTimePaymentSession({
    async onApprove(data) {
      const res = await fetch('/api/execute-payment.php', { method: 'POST', body: JSON.stringify({ orderId: data.orderId }) });
      const json = await res.json();
      document.getElementById('result').style.display = 'block';
      document.getElementById('result').textContent = json.ok ? `Payment complete. Transaction ${json.transactionId} for $${json.amount}` : `Payment failed: ${json.error}`;
    },
    onCancel() { document.getElementById('result').style.display = 'block'; document.getElementById('result').textContent = 'Payment cancelled.'; },
    onError(err) { document.getElementById('result').style.display = 'block'; document.getElementById('result').textContent = 'Payment failed: ' + err; }
  });
  document.getElementById('paypal-button').addEventListener('click', () => session.start({ presentationMode: 'popup' }, createOrder()));
})();
</script></body></html>