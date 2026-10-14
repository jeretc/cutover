<?php
// Legacy shop configuration (circa 2016). Deliberately typical of old PayPal integrations:
// credentials live in code with fallback values, and the API is the classic NVP endpoint.
// Modern credentials (client ID/secret, webhook ID, admin token) are read from environment variables directly in the files that use them.
define('SHOP_CURRENCY', 'USD');
define('SHOP_BASE_URL', getenv('SHOP_BASE_URL') ?: 'http://localhost:8081');

$PRODUCTS = [
    'tee'    => ['name' => 'Cutover Tee',   'price' => '25.00'],
    'mug'    => ['name' => 'Rollback Mug',  'price' => '12.50'],
    'poster' => ['name' => 'Legacy Poster', 'price' => '18.00'],
];
