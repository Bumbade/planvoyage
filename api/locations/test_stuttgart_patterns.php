<?php
// Test: Search in Stuttgart area with different name patterns
header('Content-Type: application/json');

// Stuttgart bbox: roughly 48.7-48.85, 9.1-9.25
$bbox = '48.7,9.1,48.85,9.25';

$searchPatterns = [
    'Mercedes-Benz Museum' => 'Mercedes[\s\-_]*Benz[\s\-_]*Museum',
    'Mercedes Museum' => 'Mercedes.*Museum',
    'Benz Museum' => 'Benz.*Museum',
    'Museum (tourism)' => 'tourism.*museum.*mercedes',
    'Any Mercedes building' => 'Mercedes'
];

$endpoint = 'https://overpass.private.coffee/api/interpreter';

$results = [];
foreach ($searchPatterns as $label => $pattern) {
    $query = '[out:json][timeout:15];(';
    $query .= 'node["name"~"' . $pattern . '",i](' . $bbox . ');';
    $query .= 'way["name"~"' . $pattern . '",i](' . $bbox . ');';
    $query .= 'relation["name"~"' . $pattern . '",i](' . $bbox . ');';
    $query .= ');out center 3;';
    
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'data=' . urlencode($query));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    
    $start = microtime(true);
    $response = curl_exec($ch);
    $duration = round((microtime(true) - $start) * 1000, 2);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $data = $response ? json_decode($response, true) : null;
    $count = isset($data['elements']) ? count($data['elements']) : 0;
    
    $found = [];
    if ($count > 0) {
        foreach ($data['elements'] as $el) {
            $found[] = [
                'type' => $el['type'],
                'id' => $el['id'],
                'name' => $el['tags']['name'] ?? 'no name',
                'lat' => $el['lat'] ?? $el['center']['lat'] ?? null,
                'lon' => $el['lon'] ?? $el['center']['lon'] ?? null
            ];
        }
    }
    
    $results[] = [
        'pattern' => $label,
        'regex' => $pattern,
        'http_code' => $httpCode,
        'duration_ms' => $duration,
        'found_count' => $count,
        'results' => $found
    ];
    
    if ($count > 0 && strpos($label, 'Museum') !== false) {
        break; // Found it!
    }
}

echo json_encode(['bbox' => $bbox, 'tests' => $results], JSON_PRETTY_PRINT);
