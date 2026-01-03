<?php
/**
 * GET /src/api/countries.php
 * Returns localized country list with coordinates and area for zoom calculation.
 * Response is cacheable (ETags, 1-hour cache).
 * Automatically gzip-compressed if client supports it.
 */

// Load env and helpers
if (file_exists(__DIR__ . '/../config/env.php')) {
    require_once __DIR__ . '/../config/env.php';
}
if (file_exists(__DIR__ . '/../helpers/url.php')) {
    require_once __DIR__ . '/../helpers/url.php';
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600'); // 1 hour cache
header('Access-Control-Allow-Origin: *');
// Enable gzip compression if client supports it
header('Vary: Accept-Encoding');

// Check if client supports gzip
$gzip_supported = strpos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false;

// Curated country list (fallback + extended)
$countries = [
    ['name' => 'Germany', 'cca2' => 'DE', 'latlng' => [51, 9], 'area' => 357022],
    ['name' => 'United States', 'cca2' => 'US', 'latlng' => [38, -97], 'area' => 9525067],
    ['name' => 'United Kingdom', 'cca2' => 'GB', 'latlng' => [54, -2], 'area' => 244376],
    ['name' => 'France', 'cca2' => 'FR', 'latlng' => [46, 2], 'area' => 643801],
    ['name' => 'Canada', 'cca2' => 'CA', 'latlng' => [60, -95], 'area' => 9984670],
    ['name' => 'Spain', 'cca2' => 'ES', 'latlng' => [40, -3], 'area' => 505992],
    ['name' => 'Italy', 'cca2' => 'IT', 'latlng' => [42, 12], 'area' => 301340],
    ['name' => 'Netherlands', 'cca2' => 'NL', 'latlng' => [52.3, 5.3], 'area' => 33720],
    ['name' => 'Belgium', 'cca2' => 'BE', 'latlng' => [50.5, 4.5], 'area' => 30528],
    ['name' => 'Switzerland', 'cca2' => 'CH', 'latlng' => [46.8, 8.2], 'area' => 41290],
    ['name' => 'Austria', 'cca2' => 'AT', 'latlng' => [47.5, 13.5], 'area' => 83860],
    ['name' => 'Denmark', 'cca2' => 'DK', 'latlng' => [56, 9], 'area' => 43094],
    ['name' => 'Sweden', 'cca2' => 'SE', 'latlng' => [62, 15], 'area' => 450295],
    ['name' => 'Norway', 'cca2' => 'NO', 'latlng' => [62, 10], 'area' => 385207],
    ['name' => 'Poland', 'cca2' => 'PL', 'latlng' => [51.9, 19.1], 'area' => 312696],
    ['name' => 'Czech Republic', 'cca2' => 'CZ', 'latlng' => [49.8, 15.5], 'area' => 78867],
    ['name' => 'Greece', 'cca2' => 'GR', 'latlng' => [38.9, 22], 'area' => 131990],
    ['name' => 'Portugal', 'cca2' => 'PT', 'latlng' => [39.3, -8.2], 'area' => 92090],
    ['name' => 'Ireland', 'cca2' => 'IE', 'latlng' => [53, -8], 'area' => 70280],
    ['name' => 'Japan', 'cca2' => 'JP', 'latlng' => [36, 138], 'area' => 377975],
    ['name' => 'Australia', 'cca2' => 'AU', 'latlng' => [-27, 133], 'area' => 7692024],
    ['name' => 'New Zealand', 'cca2' => 'NZ', 'latlng' => [-40.9, 174.9], 'area' => 268838],
];

// Sort by name
usort($countries, fn($a, $b) => strcasecmp($a['name'], $b['name']));

// Generate ETag for cache validation
$json_data = json_encode($countries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$etag = '"' . md5($json_data) . '"';
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
    http_response_code(304); // Not Modified
    exit;
}
header('ETag: ' . $etag);

// Output with optional gzip
if ($gzip_supported && extension_loaded('zlib')) {
    // Use PHP's built-in output buffering with gzip
    ob_start('ob_gzhandler');
    header('Content-Encoding: gzip');
}

echo $json_data;

if ($gzip_supported && extension_loaded('zlib')) {
    ob_end_flush();
}
