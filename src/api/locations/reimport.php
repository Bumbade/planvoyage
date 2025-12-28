<?php
// src/api/locations/reimport.php
// Reimport an OSM feature and update an existing locations row (by osm_id)
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/mysql.php';
require_once __DIR__ . '/../../helpers/session.php';
if (file_exists(__DIR__ . '/../../helpers/import_helpers.php')) {
    require_once __DIR__ . '/../../helpers/import_helpers.php';
}
if (function_exists('start_secure_session')) start_secure_session();

$sessionUserId = $_SESSION['user_id'] ?? null;
if (empty($sessionUserId)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'auth_required']);
    exit;
}

$raw = $_REQUEST['osm_id'] ?? null;
if (empty($raw) || !is_numeric($raw)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_osm_id']);
    exit;
}
$osmId = (int)$raw;

$overpass = env('OVERPASS_ENDPOINT', 'https://overpass.openstreetmap.org/api/interpreter');
$timeout = max(5, intval(env('OVERPASS_TIMEOUT', 25)));
$q = sprintf("[out:json][timeout:%d];(node(%d);way(%d);relation(%d););out center tags;", $timeout, $osmId, $osmId, $osmId);
$endpoints = [$overpass, 'https://overpass.kumi.systems/api/interpreter', 'https://lz4.overpass-api.de/api/interpreter'];
$resp = null; $dec = null; $chosen = null;
foreach ($endpoints as $ep) {
    try {
        $ch = curl_init($ep);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'data=' . $q);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, max(5, $timeout));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, 'PlanVoyage/1.0 (+https://planvoyage.local)');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        $resp = @curl_exec($ch);
        $http = @curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp && $http >= 200 && $http < 300) {
            $dec = @json_decode($resp, true);
            if (is_array($dec) && !empty($dec['elements'])) break;
        }
    } catch (Exception $e) { }
}

if (!is_array($dec) || empty($dec['elements'])) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit;
}

// prefer node element when available
foreach ($dec['elements'] as $el) {
    if (($el['type'] ?? '') === 'node') { $chosen = $el; break; }
    if ($chosen === null) $chosen = $el;
}
if (!$chosen) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit;
}

$tags = $chosen['tags'] ?? [];
$lat = ($chosen['type'] ?? '') === 'node' ? ($chosen['lat'] ?? null) : ($chosen['center']['lat'] ?? null);
$lon = ($chosen['type'] ?? '') === 'node' ? ($chosen['lon'] ?? null) : ($chosen['center']['lon'] ?? null);

$db = get_db();
try {
    $chk = $db->prepare('SELECT id FROM locations WHERE osm_id = :osm LIMIT 1');
    $chk->execute([':osm' => (string)$osmId]);
    $found = $chk->fetchColumn();
} catch (Exception $e) { $found = null; }

// mapping similar to LocationController import
$map = [
    'addr:street' => 'addr_street', 'street' => 'addr_street',
    'addr:housenumber' => 'addr_housenumber', 'housenumber' => 'addr_housenumber',
    'addr:city' => 'addr_city', 'city' => 'addr_city',
    'addr:postcode' => 'addr_postcode', 'postcode' => 'addr_postcode',
    'addr:province' => 'addr_province', 'addr:state' => 'addr_province',
    'name' => 'name', 'alt_name' => 'alt_name', 'short_name' => 'short_name',
    'contact:phone' => 'phone', 'phone' => 'phone', 'telephone' => 'phone',
    'contact:email' => 'email', 'email' => 'email',
    'website' => 'website', 'url' => 'website',
    'opening_hours' => 'opening_hours',
    'brand' => 'brand', 'operator' => 'operator',
    'wikidata' => 'wikidata', 'wikipedia' => 'wikipedia'
];

$colsList = $db->query("SHOW COLUMNS FROM locations")->fetchAll(PDO::FETCH_ASSOC);
$colNames = array_column($colsList, 'Field');

// prepare updates
$updateParts = [];
$binds = [];

// always update lat/lon when present
if ($lat !== null && $lon !== null) {
    $updateParts[] = 'latitude = :latitude'; $binds[':latitude'] = (float)$lat;
    $updateParts[] = 'longitude = :longitude'; $binds[':longitude'] = (float)$lon;
}

// name and type
$name = $tags['name'] ?? null;
if ($name !== null && in_array('name', $colNames, true)) { $updateParts[] = 'name = :name'; $binds[':name'] = $name; }

// derive type from amenity/tourism/shop
$type = null;
if (!empty($tags['amenity'])) $type = ucfirst($tags['amenity']);
elseif (!empty($tags['tourism'])) $type = ucfirst($tags['tourism']);
elseif (!empty($tags['shop'])) $type = ucfirst($tags['shop']);
if ($type !== null && in_array('type', $colNames, true)) { $updateParts[] = 'type = :type'; $binds[':type'] = $type; }

$matchedAny = false;
foreach ($map as $tagKey => $colName) {
    if (isset($tags[$tagKey]) && in_array($colName, $colNames, true)) {
        $val = (string)$tags[$tagKey];
        $updateParts[] = "$colName = :$colName";
        $binds[':' . $colName] = $val;
        $matchedAny = true;
    }
}

// Ensure addr:city also populates the primary `city` column when available
if (isset($tags['addr:city']) && in_array('city', $colNames, true) && !array_key_exists(':city', $binds)) {
    $updateParts[] = 'city = :city';
    $binds[':city'] = (string)$tags['addr:city'];
    $matchedAny = true;
}
// If tag 'city' exists, prefer it for the `city` column as well
if (isset($tags['city']) && in_array('city', $colNames, true) && !array_key_exists(':city', $binds)) {
    $updateParts[] = 'city = :city';
    $binds[':city'] = (string)$tags['city'];
    $matchedAny = true;
}

// phone special mapping for contact:phone
if (isset($tags['contact:phone']) && in_array('phone', $colNames, true) && !array_key_exists(':phone', $binds)) {
    $binds[':phone'] = $tags['contact:phone']; if (!in_array('phone = :phone', $updateParts, true)) $updateParts[] = 'phone = :phone';
    $matchedAny = true;
}

// If we didn't map any individual columns, write compact tags into `tags` if column exists
// If no mapped columns were populated, attempt reverse-geocode to fill city/state/country
if (!$matchedAny) {
    if ((($lat !== null && $lon !== null) && function_exists('reverse_geocode_location'))) {
        try {
            $rev = reverse_geocode_location((float)$lat, (float)$lon, null);
            if (is_array($rev)) {
                if (!empty($rev['city']) && in_array('city', $colNames, true) && !array_key_exists(':city', $binds)) { $updateParts[] = 'city = :city'; $binds[':city'] = $rev['city']; $matchedAny = true; }
                if (!empty($rev['state']) && in_array('state', $colNames, true) && !array_key_exists(':state', $binds)) { $updateParts[] = 'state = :state'; $binds[':state'] = $rev['state']; $matchedAny = true; }
                if (!empty($rev['country']) && in_array('country', $colNames, true) && !array_key_exists(':country', $binds)) { $updateParts[] = 'country = :country'; $binds[':country'] = $rev['country']; $matchedAny = true; }
            }
        } catch (Exception $e) { /* ignore reverse geocode failures */ }
    }
}

if (!$matchedAny && in_array('tags', $colNames, true)) {
    $tagsText = json_encode($tags, JSON_UNESCAPED_UNICODE);
    $tagsShort = mb_substr($tagsText, 0, 255);
    $updateParts[] = 'tags = :tags'; $binds[':tags'] = $tagsShort;
}

// always set updated_at if present
if (in_array('updated_at', $colNames, true)) { $updateParts[] = 'updated_at = :updated_at'; $binds[':updated_at'] = date('Y-m-d H:i:s'); }

if ($found) {
    if (empty($updateParts)) {
        echo json_encode(['ok' => true, 'id' => (int)$found, 'updated' => false, 'note' => 'nothing_to_update']);
        exit;
    }
    $sql = 'UPDATE locations SET ' . implode(', ', $updateParts) . ' WHERE osm_id = :osm LIMIT 1';
    $upd = $db->prepare($sql);
    foreach ($binds as $k => $v) {
        if ($v === null) $upd->bindValue($k, null, PDO::PARAM_NULL);
        else $upd->bindValue($k, $v);
    }
    $upd->bindValue(':osm', (string)$osmId);
    try {
        $upd->execute();
        echo json_encode(['ok' => true, 'id' => (int)$found, 'updated' => true]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'update_failed', 'msg' => $e->getMessage()]);
        exit;
    }
} else {
    // No existing row -> delegate to LocationController import to create a new one
    require_once __DIR__ . '/../../controllers/LocationController.php';
    $c = new LocationController();
    $res = $c->import($chosen, true, null);
    if (isset($res['ok']) && $res['ok']) {
        echo json_encode(['ok' => true, 'id' => $res['id'], 'created' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'import_failed', 'detail' => $res]);
    }
    exit;
}
