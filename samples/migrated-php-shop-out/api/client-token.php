<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

$clientId = getenv('PAYPAL_CLIENT_ID');
$clientSecret = getenv('PAYPAL_CLIENT_SECRET');

if (!$clientId || !$clientSecret) {
    error_log("PayPal client ID or secret not set in environment for client-token endpoint.");
    http_response_code(500);
    echo json_encode(['error' => 'PayPal credentials missing.']);
    exit;
}

$ch = curl_init('https://api-m.sandbox.paypal.com/v1/oauth2/token');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERPWD, $clientId . ':' . $clientSecret);
curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials&response_type=client_token&intent=sdk_init');
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Accept-Language: en_US']);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    error_log("Failed to get client token from PayPal. HTTP Status: $httpCode, Response: $response");
    http_response_code(502);
    echo json_encode(['error' => 'Failed to get client token from PayPal.']);
    exit;
}

$data = json_decode($response, true);
if (!isset($data['access_token'])) {
    error_log("PayPal client token response missing access_token: " . $response);
    http_response_code(502);
    echo json_encode(['error' => 'Invalid client token response from PayPal.']);
    exit;
}

echo json_encode(['clientToken' => $data['access_token']]);
