<?php
// Proxy -> src/api/locations/search_overpass_quick_v2.php
$target = __DIR__ . "/../../src/api/locations/search_overpass_quick_v2.php";
if (file_exists($target)) { require_once $target; exit; }
http_response_code(404);
echo "Not Found";
