<?php
// Legacy shop configuration (circa 2016). Deliberately typical of old PayPal integrations:
// credentials live in code with fallback values, and the API is the classic NVP endpoint.
define('PAYPAL_API_USERNAME',  getenv('PAYPAL_NVP_USER')      ?: 'sb-demo_api1.example.com');
define('PAYPAL_API_PASSWORD',  getenv('PAYPAL_NVP_PWD')       ?: 'DEMOPASSWORD');
define('PAYPAL_API_SIGNATURE', getenv('PAYPAL_NVP_SIGNATURE') ?: 'DEMOSIGNATURE');
define('PAYPAL_NVP_ENDPOINT',  'https://api-3t.sandbox.paypal.com/nvp');
define('PAYPAL_NVP_VERSION',   '204.0');
define('PAYPAL_IPN_VERIFY_URL','https://ipnpb.sandbox.paypal.com/cgi-bin/webscr');
define('SHOP_CURRENCY', 'USD');
define('SHOP_BASE_URL', getenv('SHOP_BASE_URL') ?: 'http://localhost:8081');
define('SHOP_RECEIVER_EMAIL', getenv('SHOP_RECEIVER_EMAIL') ?: 'sb-business@business.example.com');

$PRODUCTS = [
    'tee'    => ['name' => 'Cutover Tee',   'price' => '25.00'],
    'mug'    => ['name' => 'Rollback Mug',  'price' => '12.50'],
    'poster' => ['name' => 'Legacy Poster', 'price' => '18.00'],
];
