<?php
declare(strict_types=1);
header('Cache-Control: no-store');
header('Content-Type: application/json');
ini_set('display_errors', '0');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit('{"error":"POST required"}'); }
$id = $_GET['connection'] ?? '';
if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) { http_response_code(400); exit('{"error":"Invalid connection"}'); }
require_once __DIR__ . '/Square.php';
try {
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    require_once __DIR__ . '/Settings.php';
    require_once __DIR__ . '/Pricing.php';
    require_once __DIR__ . '/Customer.php';
    require_once dirname(__DIR__) . '/includes/functions.php';
    require_once dirname(__DIR__) . '/includes/auth.php';
    $config = require __DIR__ . '/settings-config.php';
    $api = new \AmAccount\Square();
    $customers = new \AmAccount\CustomerService($config,static fn($path,$body)=>call_auth_worker($path,$body),$api);
    $store = new \AmAccount\SquareStore($config,$api,static fn($reseller,$connection,$order)=>$customers->notification($reseller,$connection,$order));
    $store->webhook($id, $raw, $_SERVER['HTTP_X_SQUARE_HMACSHA256_SIGNATURE'] ?? '');
    echo '{"accepted":true}';
} catch (InvalidArgumentException | JsonException $error) {
    http_response_code(400); echo '{"error":"Invalid notification"}';
} catch (Throwable $error) {
    http_response_code(503); echo '{"error":"Verification unavailable; retry notification"}';
}
