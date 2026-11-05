<?php
declare(strict_types=1);
ini_set('display_errors','0'); header('Content-Type: application/json'); header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { http_response_code(405); exit('{"error":"POST required"}'); }
$id=$_GET['connection'] ?? '';
if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id)) { http_response_code(400); exit('{"error":"Invalid connection"}'); }
try {
    foreach (['Settings','Pricing','Square','Customer'] as $file) require_once __DIR__.'/'.$file.'.php';
    require_once dirname(__DIR__).'/includes/functions.php'; require_once dirname(__DIR__).'/includes/auth.php';
    $config=require __DIR__.'/settings-config.php';
    $service=new \AmAccount\CustomerService($config,static fn($path,$body)=>call_auth_worker($path,$body),new \AmAccount\Square());
    $headers=[]; foreach ($_SERVER as $key=>$value) if (str_starts_with($key,'HTTP_')) $headers[strtolower(str_replace('_','-',substr($key,5)))]=$value;
    (new \AmAccount\GatewayStore($config,new \AmAccount\Gateways()))->webhook($id,file_get_contents('php://input',false,null,0,65537),$headers,static function($reseller,$order) use ($service) {
        $r=$service->retryOrder($reseller,$order);
        if (in_array($r['state'],['review','applying','refresh_pending'],true)) throw new RuntimeException('Retry reconciliation.');
    });
    echo '{"accepted":true}';
} catch (InvalidArgumentException | JsonException $e) { http_response_code(400); echo '{"error":"Invalid notification"}'; }
catch (Throwable $e) { http_response_code(503); echo '{"error":"Verification unavailable; retry notification"}'; }
