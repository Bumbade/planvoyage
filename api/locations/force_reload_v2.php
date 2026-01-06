<?php
// Proxy -> src/api/locations/clear_opcache.php (clear opcache functionality in src)
$target = __DIR__ . '/../../src/api/locations/clear_opcache.php';
if (file_exists($target)) { require_once $target; exit; }
http_response_code(404);
echo "Not Found";
