<?php

// src/api/route/osrm_route.php
// Proxy to an OSRM server to compute a route for given coordinates.
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../helpers/session.php';
start_secure_session();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false,'error' => 'method_not_allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input') ?: '{}', true);
$coords = isset($body['coordinates']) && is_array($body['coordinates']) ? $body['coordinates'] : [];

// Debug logging
@file_put_contents(
    __DIR__ . '/../../_development/logs/osrm_debug.log',
    'OSRM called - coords count: ' . count($coords) . ', data: ' . json_encode($coords, JSON_UNESCAPED_UNICODE) . PHP_EOL,
    FILE_APPEND | LOCK_EX
);

if (count($coords) < 2) {
    http_response_code(400);
    echo json_encode(['ok' => false,'error' => 'need_at_least_two_points']);
    exit;
}

// OSRM base URL configurable via environment variable OSRM_URL, default to public demo
$osrmBase = getenv('OSRM_URL') ?: 'https://router.project-osrm.org';

// Build coordinate string lon,lat;lon,lat
$parts = [];
foreach ($coords as $c) {
    if (!isset($c['lat']) || !isset($c['lon'])) {
        continue;
    }
    $parts[] = sprintf('%.6F,%.6F', (float)$c['lon'], (float)$c['lat']);
}
if (count($parts) < 2) {
    http_response_code(400);
    echo json_encode(['ok' => false,'error' => 'invalid_coordinates']);
    exit;
}
$coordStr = implode(';', $parts);

$url = rtrim($osrmBase, '/') . '/route/v1/driving/' . $coordStr . '?overview=full&geometries=geojson&steps=true&annotations=duration,distance';

// Debug: log the URL
@file_put_contents(
    __DIR__ . '/../../_development/logs/osrm_debug.log',
    'OSRM URL: ' . $url . PHP_EOL,
    FILE_APPEND | LOCK_EX
);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
// If OSRM over https with self-signed cert in local setups, user may adjust env.
$resp = curl_exec($ch);
$err = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($resp === false) {
    http_response_code(502);
    echo json_encode(['ok' => false,'error' => 'osrm_error','msg' => $err]);
    exit;
}

// forward OSRM response (as JSON) and include ok flag
$decoded = json_decode($resp, true);
if ($httpCode >= 400 || !$decoded) {
    http_response_code(502);
    echo json_encode(['ok' => false,'error' => 'osrm_failed','status_code' => $httpCode,'body' => $decoded]);
    exit;
}

echo json_encode(['ok' => true,'osrm' => $decoded]);
exit;
