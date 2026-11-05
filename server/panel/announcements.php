<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Content-Type: application/json'); header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff'); header('X-Robots-Tag: noindex, nofollow');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '',['GET','HEAD'],true)) { http_response_code(405); header('Allow: GET, HEAD'); echo '{"error":"GET required"}'; exit; }
try {
    require_once __DIR__.'/Announcements.php';
    $config=require __DIR__.'/settings-config.php';
    echo json_encode(['schema'=>1,'announcements'=>(new \AmAccount\Announcements($config['database']))->active()],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) { http_response_code(503); echo '{"error":"Announcements unavailable"}'; }
