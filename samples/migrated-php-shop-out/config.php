<?php
// Legacy shop configuration (circa 2016). Deliberately typical of old PayPal integrations:
// credentials live in code with fallback values, and the API is the classic NVP endpoint.
// MIGRATED: Credentials now come ONLY from environment variables.
// MIGRATED: NVP endpoints removed.
define('PAYPAL_CLIENT_ID',     getenv('PAYPAL_CLIENT_ID')     ?: null); // No fallback, will cause SDK init to fail
define('PAYPAL_CLIENT_SECRET', getenv('PAYPAL_CLIENT_SECRET') ?: null); // No fallback, will cause SDK init to fail
define('PAYPAL_WEBHOOK_ID',    getenv('PAYPAL_WEBHOOK_ID')    ?: null); // No fallback, will cause webhook handler to fail
define('ADMIN_TOKEN',          getenv('ADMIN_TOKEN')          ?: null); // No fallback, will cause admin endpoints to fail

define('SHOP_CURRENCY', 'USD');
define('SHOP_BASE_URL', getenv('SHOP_BASE_URL') ?: 'http://localhost:8081');
define('SHOP_RECEIVER_EMAIL', getenv('SHOP_RECEIVER_EMAIL') ?: 'sb-business@business.example.com'); // Not used in modern flow, but kept verbatim.

$PRODUCTS = [
    'tee'    => ['name' => 'Cutover Tee',   'price' => '25.00'],
    'mug'    => ['name' => 'Rollback Mug',  'price' => '12.50'],
    'poster' => ['name' => 'Legacy Poster', 'price' => '18.00'],
];
