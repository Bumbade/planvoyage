<?php
// Minimal Overpass-backed search endpoint used by frontend POI lookups.
// Attempts to query Overpass API; on failure returns an empty standardized shape
// so the frontend can continue operating without a 501.
header('Content-Type: application/json; charset=utf-8');

$bbox = trim($_GET['bbox'] ?? ''); // expected: lat1,lon1,lat2,lon2
$types = trim($_GET['types'] ?? ''); // CSV of POI types (e.g. fuel,restaurant)
$limit = isset($_GET['limit']) ? max(1, min(5000, (int)$_GET['limit'])) : 200;

// Helper: write to overpass_errors.log for debugging
function ov_log($obj) {
	$f = __DIR__ . '/../../logs/overpass_errors.log';
	@file_put_contents($f, json_encode($obj) . "\n", FILE_APPEND | LOCK_EX);
}

if ($bbox === '') {
	echo json_encode(['page' => 1, 'per_page' => $limit, 'data' => []]);
	exit;
}

$parts = array_map('trim', explode(',', $bbox));
if (count($parts) !== 4) {
	echo json_encode(['page' => 1, 'per_page' => $limit, 'data' => []]);
	exit;
}

list($lat1, $lon1, $lat2, $lon2) = array_map('floatval', $parts);
$minLat = min($lat1, $lat2);
$maxLat = max($lat1, $lat2);
$minLon = min($lon1, $lon2);
$maxLon = max($lon1, $lon2);

$typeList = array_values(array_filter(array_map('trim', explode(',', $types))));

// Map simple type names to likely OSM tags
$maps = [
	// category key => array of tag k/v to search
	'gas_stations' => [['k'=>'amenity','v'=>'fuel']],
	'charging_station' => [['k'=>'amenity','v'=>'charging_station']],
	'restaurant' => [['k'=>'amenity','v'=>'restaurant']],
	'cafe' => [['k'=>'amenity','v'=>'cafe']],
	'hotel' => [['k'=>'tourism','v'=>'hotel']],
	'hotels' => [['k'=>'tourism','v'=>'hotel'], ['k'=>'amenity','v'=>'hotel']],
	'food' => [['k'=>'amenity','v'=>'restaurant'], ['k'=>'amenity','v'=>'biergarten'], ['k'=>'amenity','v'=>'fast_food'], ['k'=>'amenity','v'=>'food_court'], ['k'=>'shop','v'=>'bakery'], ['k'=>'amenity','v'=>'cafe'], ['k'=>'amenity','v'=>'pub'], ['k'=>'amenity','v'=>'bar']],
	'shopping' => [['k'=>'shop','v'=>'shoes'],['k'=>'shop','v'=>'supermarket'],['k'=>'shop','v'=>'gift'],['k'=>'shop','v'=>'clothes'],['k'=>'shop','v'=>'electronics'],['k'=>'shop','v'=>'books'],['k'=>'shop','v'=>'department_store'],['k'=>'shop','v'=>'variety_store'],['k'=>'shop','v'=>'convenience'],['k'=>'shop','v'=>'art'],['k'=>'shop','v'=>'computer'],['k'=>'shop','v'=>'mall']],
	'supermarket' => [['k'=>'shop','v'=>'supermarket'],['k'=>'shop','v'=>'mall'],['k'=>'shop','v'=>'department_store']],
	'banks' => [['k'=>'amenity','v'=>'bank'],['k'=>'amenity','v'=>'atm']],
	'campgrounds' => [['k'=>'tourism','v'=>'camp_site'],['k'=>'tourism','v'=>'caravan_site']],
	'provincial_parks' => [['k'=>'leisure','v'=>'park'],['k'=>'natural','v'=>'nature_reserve'],['k'=>'leisure','v'=>'nature_reserve']],
	'dump_station' => [['k'=>'amenity','v'=>'sanitary_dump_station'],['k'=>'amenity','v'=>'waste_disposal']],
	'tourist_info' => [['k'=>'tourism','v'=>'information']],
	'transport' => [['k'=>'amenity','v'=>'ferry_terminal'],['k'=>'amenity','v'=>'bus_station'],['k'=>'highway','v'=>'bus_stop'],['k'=>'aeroway','v'=>'aerodrome'],['k'=>'public_transport','v'=>'stop_position'],['k'=>'public_transport','v'=>'station']],
	'laundry' => [['k'=>'amenity','v'=>'laundry'],['k'=>'shop','v'=>'laundry']],
	'pharmacy' => [['k'=>'amenity','v'=>'pharmacy'],['k'=>'amenity','v'=>'hospital']],
	'parking' => [['k'=>'amenity','v'=>'parking'],['k'=>'amenity','v'=>'parking_entrance'],['k'=>'amenity','v'=>'parking_space']],
	'fitness' => [['k'=>'leisure','v'=>'fitness_centre'],['k'=>'leisure','v'=>'sports_hall'],['k'=>'leisure','v'=>'sports_centre'],['k'=>'leisure','v'=>'fitness_station']],
	'attractions' => [['k'=>'tourism','v'=>'museum'],['k'=>'tourism','v'=>'theme_park'],['k'=>'natural','v'=>'waterfall'],['k'=>'waterway','v'=>'waterfall'],['k'=>'tourism','v'=>'attraction'],['k'=>'tourism','v'=>'viewpoint'],['k'=>'tourism','v'=>'zoo'],['k'=>'leisure','v'=>'nature_reserve'],['k'=>'tourism','v'=>'museum']],
	'nightlife' => [['k'=>'amenity','v'=>'bar'],['k'=>'amenity','v'=>'pub'],['k'=>'amenity','v'=>'nightclub']],
	'tobacco_vape' => [['k'=>'shop','v'=>'tobacco'],['k'=>'shop','v'=>'e-cigarette']],
	'cannabis' => [['k'=>'shop','v'=>'cannabis']]
];

// Build Overpass QL parts
$conds = [];
if (!empty($typeList)) {
	foreach ($typeList as $t) {
		if (isset($maps[$t])) {
			foreach ($maps[$t] as $m) {
				$conds[] = sprintf('node[%s=%s](%F,%F,%F,%F);way[%s=%s](%F,%F,%F,%F);rel[%s=%s](%F,%F,%F,%F);',
					$m['k'], $m['v'], $minLat, $minLon, $maxLat, $maxLon,
					$m['k'], $m['v'], $minLat, $minLon, $maxLat, $maxLon,
					$m['k'], $m['v'], $minLat, $minLon, $maxLat, $maxLon
				);
			}
		} else {
			// fallback: search amenity and shop keys
			$conds[] = sprintf('node[amenity=%s](%F,%F,%F,%F);way[amenity=%s](%F,%F,%F,%F);rel[amenity=%s](%F,%F,%F,%F);',
				$t, $minLat, $minLon, $maxLat, $maxLon,
				$t, $minLat, $minLon, $maxLat, $maxLon,
				$t, $minLat, $minLon, $maxLat, $maxLon
			);
			$conds[] = sprintf('node[shop=%s](%F,%F,%F,%F);way[shop=%s](%F,%F,%F,%F);rel[shop=%s](%F,%F,%F,%F);',
				$t, $minLat, $minLon, $maxLat, $maxLon,
				$t, $minLat, $minLon, $maxLat, $maxLon,
				$t, $minLat, $minLon, $maxLat, $maxLon
			);
		}
	}
} else {
	// No types provided -> fetch common POI types (amenity/shop) but keep lightweight
	$conds[] = sprintf('node[amenity](%F,%F,%F,%F);way[amenity](%F,%F,%F,%F);rel[amenity](%F,%F,%F,%F);', $minLat, $minLon, $maxLat, $maxLon, $minLat, $minLon, $maxLat, $maxLon, $minLat, $minLon, $maxLat, $maxLon);
}

$overpassQL = '[out:json][timeout:25];(' . implode('', $conds) . ');out center qt;';

// Try multiple Overpass endpoints (fallbacks) to improve resilience
$endpoints = [
	'https://overpass.openstreetmap.org/api/interpreter',
	'https://overpass.kumi.systems/api/interpreter',
	'https://overpass-api.de/api/interpreter'
];
$resp = null; $errMsg = null; $used = null;
foreach ($endpoints as $ep) {
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $ep);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $overpassQL);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 20);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
	curl_setopt($ch, CURLOPT_USERAGENT, 'PlanVoyage/2.0 (+https://example.invalid)');
	$resp = curl_exec($ch);
	$errNo = curl_errno($ch);
	$errMsg = curl_error($ch);
	curl_close($ch);
	if ($errNo === 0 && $resp) { $used = $ep; break; }
	usleep(150000);
}

if (!$resp) {
	ov_log(['ts'=>date('c'),'error'=>'curl_error','msg'=>$errMsg ?: 'empty_response','uri'=>$_SERVER['REQUEST_URI'],'tried'=>$endpoints]);
	echo json_encode(['page' => 1, 'per_page' => $limit, 'data' => [], 'error' => 'overpass_unreachable']);
	exit;
}

$data = json_decode($resp, true);
if (!$data || !isset($data['elements'])) {
	ov_log(['ts'=>date('c'),'error'=>'parse_error','msg'=>'invalid_json','uri'=>$_SERVER['REQUEST_URI']]);
	echo json_encode(['page' => 1, 'per_page' => $limit, 'data' => [], 'error' => 'invalid_overpass_response']);
	exit;
}

$rows = [];
foreach ($data['elements'] as $el) {
	$tags = $el['tags'] ?? [];
	$lat = $el['lat'] ?? null;
	$lon = $el['lon'] ?? null;
	if ($lat === null && isset($el['center'])) {
		$lat = $el['center']['lat'] ?? null;
		$lon = $el['center']['lon'] ?? null;
	}
	$rows[] = [
		'id' => ($el['type'] ?? '') . '/' . ($el['id'] ?? 0),
		'osm_id' => $el['id'] ?? null,
		'osm_type' => $el['type'] ?? null,
		'name' => $tags['name'] ?? ($tags['ref'] ?? ''),
		'type' => $tags['amenity'] ?? ($tags['shop'] ?? ''),
		'latitude' => $lat,
		'longitude' => $lon,
		'tags' => $tags,
		'source' => 'overpass',
	];
	if (count($rows) >= $limit) break;
}

echo json_encode(['page' => 1, 'per_page' => $limit, 'data' => $rows]);
exit;
