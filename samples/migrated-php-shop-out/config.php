<?php
$clientId = getenv('PAYPAL_CLIENT_ID');
$clientSecret = getenv('PAYPAL_CLIENT_SECRET');
if (!$clientId || !$clientSecret) { /* Fail loudly if needed */ }
define('SHOP_CURRENCY', 'USD');
define('SHOP_BASE_URL', getenv('SHOP_BASE_URL') ?: 'http://localhost:8081');
$PRODUCTS = ['tee' => ['name' => 'Cutover Tee', 'price' => '25.00'], 'mug' => ['name' => 'Rollback Mug', 'price' => '12.50'], 'poster' => ['name' => 'Legacy Poster', 'price' => '18.00']];