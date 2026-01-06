<?php
// Proxy to src/api/locations/search_overpass_v2.php so direct `/api/locations/search_overpass_v2.php` works
$target = __DIR__ . '/../../src/api/locations/search_overpass_v2.php';
if (file_exists($target)) {
    require_once $target;
    exit;
}
http_response_code(404);
echo "Not Found";
