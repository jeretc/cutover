<?php
// Tiny flat-file "database" for orders (legacy style).
function orders_path() { return __DIR__ . '/../data/orders.json'; }
function orders_all() {
    $p = orders_path();
    return file_exists($p) ? (json_decode(file_get_contents($p), true) ?: []) : [];
}
function orders_save(array $order) {
    $all = orders_all();
    $all[$order['token']] = $order;
    file_put_contents(orders_path(), json_encode($all, JSON_PRETTY_PRINT));
}
function orders_get($token) { $a = orders_all(); return $a[$token] ?? null; }
