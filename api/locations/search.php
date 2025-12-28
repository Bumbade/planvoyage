<?php
// Proxy to src/api/locations/search.php so direct `/api/locations/search.php` works
$target = __DIR__ . '/../../src/api/locations/search.php';
if (file_exists($target)) {
    require_once $target;
    exit;
}
http_response_code(404);
echo "Not Found";
