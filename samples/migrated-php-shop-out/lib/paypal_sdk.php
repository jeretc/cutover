<?php
require_once __DIR__ . '/../config.php';
require __DIR__ . '/../vendor/autoload.php';

use PaypalServerSdkLib\PaypalServerSdkClientBuilder;
use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;
use PaypalServerSdkLib\Environment;
use PaypalServerSdkLib\ApiException;

$clientId = PAYPAL_CLIENT_ID;
$clientSecret = PAYPAL_CLIENT_SECRET;

$client = null;
$orders = null;
$payments = null;

if ($clientId && $clientSecret) {
    try {
        $client = PaypalServerSdkClientBuilder::init()
            ->clientCredentialsAuthCredentials(ClientCredentialsAuthCredentialsBuilder::init($clientId, $clientSecret))
            ->environment(Environment::SANDBOX)->build();
        $orders = $client->getOrdersController();
        $payments = $client->getPaymentsController();
    } catch (Exception $e) {
        error_log("PayPal SDK client initialization failed: " . $e->getMessage());
        // Client will remain null, calling scripts must check for it.
    }
} else {
    error_log("PayPal API credentials (PAYPAL_CLIENT_ID or PAYPAL_CLIENT_SECRET) are missing.");
}

// Helper function for API calls to handle exceptions consistently
function handlePayPalApiException($e) {
    if ($e instanceof ApiException) {
        $statusCode = $e->getStatusCode();
        $responseBody = $e->getResponseBody();
        error_log("PayPal API Error (Status: $statusCode): $responseBody");
        $errorData = json_decode($responseBody, true);
        $errorMessage = $errorData['message'] ?? ($errorData['details'][0]['description'] ?? 'PayPal API error');
        http_response_code(502);
        echo json_encode(['error' => $errorMessage]);
    } else {
        error_log("Unexpected error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'An unexpected error occurred.']);
    }
    exit;
}

// Helper function for admin authentication
function authenticateAdmin() {
    $adminToken = ADMIN_TOKEN;
    $requestToken = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';

    if (!$adminToken) {
        error_log("ADMIN_TOKEN environment variable is not set.");
        http_response_code(500);
        echo json_encode(['error' => 'Admin token not configured on server.']);
        exit;
    }

    if ($requestToken !== $adminToken) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized.']);
        exit;
    }
}
