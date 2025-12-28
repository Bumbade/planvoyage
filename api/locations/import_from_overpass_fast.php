<?php
// Proxy to src/api/locations/import_from_overpass_fast.php so direct `/api/locations/import_from_overpass_fast.php` works
$target = __DIR__ . '/../../src/api/locations/import_from_overpass_fast.php';
if (file_exists($target)) {
    require_once $target;
    exit;
}
http_response_code(404);
echo "Not Found";
