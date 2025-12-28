<?php
// CLI runner to test src/api/locations/search.php
$_GET = ['q' => 'test', 'limit' => '1'];
// Ensure SESSION is not required to avoid warnings
if (php_sapi_name() === 'cli') {
    if (!isset($_SERVER['REQUEST_METHOD'])) $_SERVER['REQUEST_METHOD'] = 'GET';
}
require __DIR__ . '/api/locations/search.php';
