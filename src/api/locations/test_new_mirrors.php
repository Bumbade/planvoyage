<?php
/**
 * Test Overpass search with direct mirror loading (bypasses opcache issues)
 * This is a temporary test endpoint to verify new mirrors work
 */
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Force disable opcache for this request
if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__DIR__ . '/search_overpass_quick.php', true);
}

$search = $_GET['search'] ?? 'Mercedes Benz Museum';
$limit = intval($_GET['limit'] ?? 5);

// These are the NEW mirrors (same as in search_overpass_quick.php)
$endpoints = [
    'https://overpass.private.coffee/api/interpreter',
    'https://maps.mail.ru/osm/tools/overpass/api/interpreter',  // NEW!
    'https://overpass.osm.jp/api/interpreter',                   // NEW!
    'https://overpass-api.de/api/interpreter',
    'https://overpass.kumi.systems/api/interpreter'
];

// Escape for Overpass
$safeSearch = str_replace(['\\','"'], ['\\\\','\\"'], $search);
$flexibleSearch = preg_replace('/[\s\-_]+/', '[\\s\\-_]+', $safeSearch);
$searchPattern = '.*' . $flexibleSearch . '.*';

// Build query (without bbox for simplicity)
$ql = '[out:json][timeout:25];(';
$ql .= 'node["name"~"' . $searchPattern . '",i];';
$ql .= 'way["name"~"' . $searchPattern . '",i];';
$ql .= 'relation["name"~"' . $searchPattern . '",i];';
$ql .= ');out center ' . $limit . ';';

$result = [
    'search' => $search,
    'query' => $ql,
    'mirrors_available' => $endpoints,
    'attempts' => []
];

// Try each mirror
foreach ($endpoints as $endpoint) {
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'data=' . urlencode($ql));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    
    $start = microtime(true);
    $response = curl_exec($ch);
    $duration = round((microtime(true) - $start) * 1000, 2);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    $attempt = [
        'mirror' => $endpoint,
        'http_code' => $httpCode,
        'duration_ms' => $duration,
        'error' => $error ?: null
    ];
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        $elementCount = count($data['elements'] ?? []);
        $attempt['elements_count'] = $elementCount;
        
        if ($elementCount > 0) {
            $result['success'] = true;
            $result['used_mirror'] = $endpoint;
            $result['results'] = array_slice($data['elements'], 0, $limit);
            $result['attempts'][] = $attempt;
            echo json_encode($result, JSON_PRETTY_PRINT);
            exit(0);
        } else {
            $attempt['note'] = 'Empty response - trying next mirror';
        }
    }
    
    $result['attempts'][] = $attempt;
}

$result['success'] = false;
$result['message'] = 'All mirrors failed or returned empty results';
echo json_encode($result, JSON_PRETTY_PRINT);
