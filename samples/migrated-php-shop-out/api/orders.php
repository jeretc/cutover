<?php
// Leftover "admin" helper from an old project: lists orders as JSON. (Note: no authentication.)
// MIGRATED: Now requires admin authentication.
require_once __DIR__ . '/../lib/orders_store.php';
require_once __DIR__ . '/../lib/paypal_sdk.php'; // For authenticateAdmin
header('Content-Type: application/json');

// Security fix: Require admin authentication
authenticateAdmin();

echo json_encode(array_values(orders_all()));
