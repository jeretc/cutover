<?php
require_once __DIR__ . '/../lib/paypal_sdk.php';
header('Content-Type: application/json');
$ch = curl_init('https://api-m.sandbox.paypal.com/v1/oauth2/token');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERPWD, getenv('PAYPAL_CLIENT_ID') . ':' . getenv('PAYPAL_CLIENT_SECRET'));
curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials&response_type=client_token&intent=sdk_init');
$res = json_decode(curl_exec($ch), true);
curl_close($ch);
echo json_encode(['clientToken' => $res['access_token']]);