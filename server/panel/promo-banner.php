<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: no-store');
// Random banner IDs are public marketing assets; no merchant or customer data is returned.
try {
    $id=$_GET['id'] ?? '';
    if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id)) throw new RuntimeException();
    require_once __DIR__.'/Promotions.php';
    $config=require __DIR__.'/settings-config.php';
    $image=(new \AmAccount\Promotions($config['database']))->banner($id);
    if (!$image) throw new RuntimeException();
    header('Content-Type: '.$image['image_type']); echo base64_decode($image['image'],true);
} catch (Throwable $e) { http_response_code(404); }
