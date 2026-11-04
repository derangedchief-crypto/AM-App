<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Pricing.php';
require_once __DIR__ . '/Square.php';
require_once __DIR__ . '/Customer.php';
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new \AmAccount\CustomerError(405,'POST required.');
    $raw = file_get_contents('php://input',false,null,0,16385);
    if (strlen($raw) > 16384) throw new \AmAccount\CustomerError(413,'Request too large.');
    $input = json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    if (!is_array($input) || !is_string($input['action'] ?? null)) throw new \AmAccount\CustomerError(422,'Invalid request.');
    $action = $input['action']; unset($input['action']);
    $allowed = match ($action) {'login'=>['username','password','activation_code'],'account','orders','logout'=>[], 'bouquets'=>['bouquet_ids','revision'], 'quote'=>['package_id','currency'], 'checkout'=>['quote_id'],'order'=>['order_id'], default=>throw new \AmAccount\CustomerError(404,'Unknown operation.')};
    if (array_diff(array_keys($input),$allowed)) throw new \AmAccount\CustomerError(422,'Unexpected request fields.');
    // Load server-only worker configuration, without starting a reseller session.
    require_once dirname(__DIR__) . '/includes/functions.php';
    require_once dirname(__DIR__) . '/includes/auth.php';
    $service = new \AmAccount\CustomerService(require __DIR__.'/settings-config.php',static fn($path,$body)=>call_auth_worker($path,$body),new \AmAccount\Square());
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    $token = str_starts_with($header,'Bearer ') ? substr($header,7) : '';
    if ($action === 'login') {
        $result = $service->login($input,$_SERVER['REMOTE_ADDR'] ?? 'unknown');
    } elseif ($action === 'logout') {
        $service->logout($token); $result = [];
    } else {
        $ctx = $service->session($token);
        if ($action === 'quote' && (!is_int($input['package_id'] ?? null) || !is_string($input['currency'] ?? null))) throw new \AmAccount\CustomerError(422,'Invalid renewal option.');
        foreach (['quote_id','order_id'] as $id) if (isset($input[$id]) && (!is_string($input[$id]) || !preg_match('/^[a-f0-9]{32}$/D',$input[$id]))) throw new \AmAccount\CustomerError(422,'Invalid reference.');
        $result = match($action) {
            'account'=>['account'=>$service->account($ctx)], 'orders'=>['orders'=>$service->orders($ctx)],
            'quote'=>['quote'=>$service->quote($ctx,$input['package_id'],$input['currency'])],
            'checkout'=>['order'=>$service->checkout($ctx,$input['quote_id'] ?? '')],
            'order'=>['order'=>$service->status($ctx,$input['order_id'] ?? '')],
            'bouquets'=>$service->bouquets($ctx,$input),
        };
    }
    echo json_encode(['ok'=>true]+$result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
} catch (\AmAccount\CustomerError $error) {
    http_response_code($error->status); echo json_encode(['ok'=>false,'error'=>$error->getMessage()]);
} catch (JsonException | InvalidArgumentException $error) {
    http_response_code(422); echo '{"ok":false,"error":"Request or renewal option is invalid. Refresh and retry."}';
} catch (Throwable $error) {
    http_response_code(503); echo '{"ok":false,"error":"AM is temporarily unavailable. Keep any existing order and retry; do not pay again."}';
}
