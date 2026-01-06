<?php
// Quick test: Search Mercedes Benz Museum WITHOUT bbox to see if it exists in OSM
header('Content-Type: application/json');

$query = '[out:json][timeout:10];(node["name"~"Mercedes.*Benz.*Museum",i];way["name"~"Mercedes.*Benz.*Museum",i];relation["name"~"Mercedes.*Benz.*Museum",i];);out center 5;';

$endpoints = [
    'https://overpass.private.coffee/api/interpreter',
    'https://maps.mail.ru/osm/tools/overpass/api/interpreter',
    'https://overpass-api.de/api/interpreter'
];

$results = [];
foreach ($endpoints as $ep) {
    $ch = curl_init($ep);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'data=' . urlencode($query));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    
    $start = microtime(true);
    $response = curl_exec($ch);
    $duration = round((microtime(true) - $start) * 1000, 2);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $data = $response ? json_decode($response, true) : null;
    $count = isset($data['elements']) ? count($data['elements']) : 0;
    
    $results[] = [
        'mirror' => basename(parse_url($ep, PHP_URL_HOST)),
        'http_code' => $httpCode,
        'duration_ms' => $duration,
        'found_count' => $count,
        'first_result' => $count > 0 ? ($data['elements'][0]['tags']['name'] ?? 'no name') : null
    ];
    
    if ($count > 0) break; // Stop at first success
}

echo json_encode(['query' => $query, 'results' => $results], JSON_PRETTY_PRINT);
