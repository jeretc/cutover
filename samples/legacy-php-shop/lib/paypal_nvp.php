<?php
require_once __DIR__ . '/../config.php';

// Classic PayPal NVP (name-value pair) API client.
function paypal_nvp($method, array $params = []) {
    $post = array_merge([
        'USER'      => PAYPAL_API_USERNAME,
        'PWD'       => PAYPAL_API_PASSWORD,
        'SIGNATURE' => PAYPAL_API_SIGNATURE,
        'VERSION'   => PAYPAL_NVP_VERSION,
        'METHOD'    => $method,
    ], $params);

    $ch = curl_init(PAYPAL_NVP_ENDPOINT);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $raw = curl_exec($ch);
    curl_close($ch);

    parse_str((string)$raw, $out);
    return $out;   // e.g. ['ACK' => 'Success', 'TOKEN' => 'EC-...']
}

function paypal_ok($r) {
    return isset($r['ACK']) && ($r['ACK'] === 'Success' || $r['ACK'] === 'SuccessWithWarning');
}
