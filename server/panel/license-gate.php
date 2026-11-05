<?php
// Included after reseller identity verification, before any AM management action.
if (!isset($resellerId)) { http_response_code(404); exit; }
require_once __DIR__.'/Licenses.php';
$licenseError=null; $licenseRow=null;
try {
    $licenseConfig=require __DIR__.'/settings-config.php';
    $licensing=new \AmAccount\Licenses($licenseConfig['database']);
    $licenseRow=$licensing->current($resellerId);
    if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST' && ($_POST['action'] ?? '')==='license_activate') {
        csrf_check();
        if (!is_string($_POST['license_key'] ?? null)) throw new InvalidArgumentException('Enter your license key.');
        $licensing->redeem($resellerId,$_POST['license_key']);
        header('Location: '.url('am-app/'),true,303); exit;
    }
    if (\AmAccount\Licenses::state($licenseRow)==='active') return;
} catch (InvalidArgumentException $e) { $licenseError=$e->getMessage(); }
catch (Throwable $e) { http_response_code(503); $licenseError='License verification is unavailable. Please try again later.'; }
if (http_response_code()!==503) http_response_code(403);
$licenseState=http_response_code()===503?'unavailable':\AmAccount\Licenses::state($licenseRow);
require __DIR__.'/license-showcase.php';
exit;
