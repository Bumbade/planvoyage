<?php
// src/api/locations/import_from_overpass_fast.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/mysql.php';
require_once __DIR__ . '/../../helpers/session.php';
if (file_exists(__DIR__ . '/../../helpers/import_helpers.php')) {
    require_once __DIR__ . '/../../helpers/import_helpers.php';
}

// start session for CSRF and auth checks
if (function_exists('start_secure_session')) start_secure_session();

$sessionUserId = $_SESSION['user_id'] ?? null;
// Allow any logged-in user to import POIs. Previously imports were restricted to admins.
if (empty($sessionUserId)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'auth_required', 'msg' => 'Please log in to import POIs']);
    exit;
}

$raw_single = $_POST['osm_id'] ?? null;
$raw_multi = $_POST['osm_ids'] ?? null;
$ids = [];
if ($raw_multi) {
    $decoded = @json_decode($raw_multi, true);
    if (is_array($decoded)) $ids = $decoded;
    else {
        $parts = array_map('trim', explode(',', $raw_multi));
        $ids = array_values(array_filter($parts, function ($v) { return $v !== ''; }));
    }
}
if ($raw_single && $raw_single !== '') $ids[] = $raw_single;
$osmIds = array_values(array_unique(array_map('intval', $ids)));

$maxBatch = intval(env('IMPORT_MAX_BATCH', 200));
if (count($osmIds) > $maxBatch) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'batch_too_large', 'max' => $maxBatch, 'provided' => count($osmIds)]);
    exit;
}

// Bulk fetch elements from Overpass
$prefetched = [];
if (!empty($osmIds)) {
    $overpass = env('OVERPASS_ENDPOINT', 'https://overpass.openstreetmap.org/api/interpreter');
    $timeout = max(5, intval(env('OVERPASS_TIMEOUT', 25)));
    $parts = [];
    foreach ($osmIds as $id) {
        $id = intval($id);
        if ($id <= 0) continue;
        $parts[] = "node($id);";
        $parts[] = "way($id);";
        $parts[] = "relation($id);";
    }
    if (!empty($parts)) {
        $q = "[out:json][timeout:" . $timeout . "];(" . implode('', $parts) . ");out center tags;";
        $endpoints = [
            $overpass,
            'https://overpass.kumi.systems/api/interpreter',
            'https://overpass-api.de/api/interpreter'
        ];
        $resp = null;
        foreach ($endpoints as $ep) {
            try {
                $ch = curl_init($ep);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $q);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, max(5, $timeout));
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                curl_setopt($ch, CURLOPT_USERAGENT, 'PlanVoyage/1.0 (+https://planvoyage.local)');
                $resp = @curl_exec($ch);
                $http = @curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($resp && $http >= 200 && $http < 300) break;
            } catch (Exception $e) {
                // continue
            }
            usleep(100000);
        }
        if ($resp) {
            $dec = @json_decode($resp, true);
            if (is_array($dec) && isset($dec['elements'])) {
                foreach ($dec['elements'] as $el) {
                    // store by explicit type+id so we never lose element type information
                    $type = isset($el['type']) ? $el['type'] : 'node';
                    $typedKey = $type . '_' . $el['id'];
                    $prefetched[$typedKey] = $el;

                    // Also maintain a numeric-key entry for convenience, but prefer a node
                    // element when both node and way/relation share the same numeric id.
                    $numKey = (string)$el['id'];
                    if (!isset($prefetched[$numKey])) {
                        $prefetched[$numKey] = $el;
                    } else {
                        $existing = $prefetched[$numKey];
                        if ((($existing['type'] ?? '') !== 'node') && ($type === 'node')) {
                            // prefer the node when available
                            $prefetched[$numKey] = $el;
                        }
                    }
                }
            }
        }
    }
}

// For each requested id, call LocationController::import internally and collect results
require_once __DIR__ . '/../../controllers/LocationController.php';
$controller = new LocationController();
$results = [];
foreach ($osmIds as $oid) {
    // Respect optional requested element type when available (e.g. frontend can send 'way'/'node'/'relation')
    $requestedType = null;
    if (isset($_POST['osm_type']) && is_string($_POST['osm_type'])) {
        $rt = strtolower(trim($_POST['osm_type']));
        if (in_array($rt, ['node','way','relation'], true)) $requestedType = $rt;
    }
    // Prefer requested type when present, otherwise prefer an explicit node, then numeric-key fallback
    if ($requestedType) {
        $pref = $prefetched[$requestedType . '_' . $oid] ?? ($prefetched['node_' . $oid] ?? ($prefetched[(string)$oid] ?? null));
    } else {
        $pref = $prefetched['node_' . $oid] ?? ($prefetched[(string)$oid] ?? null);
    }
    try {
        if ($pref) {
            $r = $controller->import($pref, true, null);
        } else {
            $r = $controller->import(null, true, (int)$oid);
        }
    } catch (Throwable $e) {
        $r = ['ok' => false, 'error' => 'exception', 'msg' => $e->getMessage(), 'osm_id' => $oid];
    }
    $results[] = $r;
}

// If a single id was requested, return its result directly for compatibility, else return results array
if (count($results) === 1) {
    $out = $results[0];
    // normalize into { ok: true, results: [...] } shape expected by frontend
    if (isset($out['ok'])) {
        echo json_encode(['ok' => (bool)$out['ok'], 'results' => [$out]]);
    } else {
        echo json_encode(['ok' => false, 'results' => [$out]]);
    }
    exit;
}

echo json_encode(['ok' => true, 'results' => $results]);
exit;
