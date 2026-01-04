<?php
// Debug script: Show which mirrors are currently loaded
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load the same endpoints as search_overpass_quick.php
$endpoints = [
    'https://overpass.private.coffee/api/interpreter',
    'https://maps.mail.ru/osm/tools/overpass/api/interpreter',
    'https://overpass.osm.jp/api/interpreter',
    'https://overpass-api.de/api/interpreter',
    'https://overpass.kumi.systems/api/interpreter'
];

$response = [
    'mirror_count' => count($endpoints),
    'mirrors' => $endpoints,
    'file_mtime' => date('Y-m-d H:i:s', filemtime(__DIR__ . '/search_overpass_quick.php')),
    'test_query' => '[out:json][timeout:25];(node["name"~".*Mercedes.*Benz.*Museum.*",i];way["name"~".*Mercedes.*Benz.*Museum.*",i];);out center 5;'
];

echo json_encode($response, JSON_PRETTY_PRINT);
