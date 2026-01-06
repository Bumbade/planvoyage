<?php
// Proxy to src/api/locations/search_overpass_quick.php so direct `/api/locations/search_overpass_quick.php` works
$target = __DIR__ . '/../../src/api/locations/search_overpass_quick.php';
// Emit a minimal INVOKE line to project logs (best-effort) so we see requests that hit the wrapper
$wrapperLog = __DIR__ . '/../../logs/overpass_quick.log';
$wrapperTmp = __DIR__ . '/../../tmp/overpass_quick_cache/overpass_quick.log';
$inv = date('c') . " WRAPPER_INVOKE: " . ($_SERVER['REQUEST_METHOD'] ?? 'GET') . " " . ($_SERVER['REQUEST_URI'] ?? '') . " REMOTE=" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n";
@file_put_contents($wrapperLog, $inv, FILE_APPEND | LOCK_EX);
@file_put_contents($wrapperTmp, $inv, FILE_APPEND | LOCK_EX);

if (file_exists($target)) {
    require_once $target;
    exit;
}
http_response_code(404);
echo "Not Found";
