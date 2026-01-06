<?php
// Backup of api/index.php
// Original content preserved below
// -------------------------------
// API router: forward requests from /api/... to ../src/api/...
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$base = '/api/';
$pos = strpos($uri, $base);
if ($pos !== false) {
    $relative = substr($uri, $pos + strlen($base));
} else {
    $relative = ltrim($_SERVER['PATH_INFO'] ?? '', '/');
}
$relative = preg_replace('#[\\/]+#', '/', $relative);
$relative = preg_replace('#\.\.#', '', $relative);
$relative = ltrim($relative, '/');
$target = realpath(__DIR__ . '/../../src/api');
if ($target === false) { http_response_code(500); echo 'Server misconfiguration'; exit; }
$full = $target . DIRECTORY_SEPARATOR . $relative;
if (is_dir($full)) {
    $full = rtrim($full, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'index.php';
}
if (file_exists($full)) {
    require $full; exit;
}
http_response_code(404);
echo 'Not Found';
