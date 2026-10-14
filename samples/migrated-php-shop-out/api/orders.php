<?php
require_once __DIR__ . '/../lib/orders_store.php';
header('Content-Type: application/json');

// Security fix: Order listing requires admin authentication.
$adminToken = getenv('ADMIN_TOKEN');
if (!$adminToken) {
    error_log("ADMIN_TOKEN environment variable not set for /api/orders.php");
    http_response_code(500);
    echo json_encode(['error' => 'Server configuration error: ADMIN_TOKEN missing.']);
    exit;
}

$requestAdminToken = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
if ($requestAdminToken !== $adminToken) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

echo json_encode(array_values(orders_all()));
