<?php
// Legacy Instant Payment Notification (IPN) listener.
require_once __DIR__ . '/lib/paypal_nvp.php';
require_once __DIR__ . '/lib/orders_store.php';

$raw = file_get_contents('php://input');
$ch = curl_init(PAYPAL_IPN_VERIFY_URL);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, 'cmd=_notify-validate&' . $raw);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$verdict = curl_exec($ch);
curl_close($ch);

parse_str($raw, $ipn);
if ($verdict === 'VERIFIED' && ($ipn['payment_status'] ?? '') === 'Completed') {
    file_put_contents(__DIR__ . '/data/ipn.log', date('c') . ' ' . ($ipn['txn_id'] ?? '?') . ' ' . ($ipn['mc_gross'] ?? '?') . "\n", FILE_APPEND);
}
http_response_code(200);
