<?php
// Wrapper to expose src/api/locations/test_overpass_log.php at /api/locations/ for webroot compatibility
$src = __DIR__ . '/../../src/api/locations/test_overpass_log.php';
if (file_exists($src)) {
    require $src;
} else {
    http_response_code(404);
    echo "Missing src test file";
}
