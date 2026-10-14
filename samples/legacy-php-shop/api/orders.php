<?php
// Leftover "admin" helper from an old project: lists orders as JSON. (Note: no authentication.)
require_once __DIR__ . '/../lib/orders_store.php';
header('Content-Type: application/json');
echo json_encode(array_values(orders_all()));
