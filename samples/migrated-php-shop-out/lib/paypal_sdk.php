<?php
require_once __DIR__ . '/../config.php';
require __DIR__ . '/../vendor/autoload.php';

use PaypalServerSdkLib\PaypalServerSdkClientBuilder;
use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;
use PaypalServerSdkLib\Environment;
use PaypalServerSdkLib\ApiException;

function getPaypalClient() {
    $clientId = getenv('PAYPAL_CLIENT_ID');
    $clientSecret = getenv('PAYPAL_CLIENT_SECRET');

    if (!$clientId || !$clientSecret) {
        error_log("PayPal client ID or secret not set in environment.");
        // This function is called by API endpoints, so it should throw an exception
        // that can be caught and translated to an HTTP 500/502.
        throw new Exception("PayPal credentials missing.");
    }

    return PaypalServerSdkClientBuilder::init()
        ->clientCredentialsAuthCredentials(ClientCredentialsAuthCredentialsBuilder::init($clientId, $clientSecret))
        ->environment(Environment::SANDBOX)->build();
}

function getPaypalOrdersController() {
    return getPaypalClient()->getOrdersController();
}

function getPaypalPaymentsController() {
    return getPaypalClient()->getPaymentsController();
}

// Helper for consistent error responses
function handlePaypalApiException(ApiException $e, $defaultMessage = 'PayPal API error') {
    $statusCode = $e->getStatusCode();
    $errorBody = json_decode($e->getResponse(), true);
    $errorMessage = $errorBody['details'][0]['description'] ?? $errorBody['message'] ?? $defaultMessage;
    error_log("PayPal API Error (Status: $statusCode): " . $errorMessage);
    http_response_code(502); // Use 502 for upstream API errors
    echo json_encode(['error' => $errorMessage]);
    exit;
}
