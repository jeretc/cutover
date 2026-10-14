<?php
require_once __DIR__ . '/../lib/orders_store.php';
if (getenv('ADMIN_TOKEN') === false || ($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '') !== getenv('ADMIN_TOKEN')) { http_response_code(401); exit; }
header('Content-Type: application/json');
echo json_encode(array_values(orders_all()));