<?php
/** Resolve the price for a product / customer / quantity (base units). */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
header('Content-Type: application/json');
try {
    echo json_encode(resolve_price(input_int($_GET, 'product_id'), input_int($_GET, 'customer_id') ?: null, max(1, input_int($_GET, 'qty', 1))));
} catch (Throwable $e) { http_response_code(400); echo json_encode(['error' => $e->getMessage()]); }
