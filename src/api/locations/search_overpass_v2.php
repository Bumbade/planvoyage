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
// optional free-text search (split into tokens)
$searchRaw = trim($_GET['search'] ?? $_GET['q'] ?? '');
$searchTokens = [];
if ($searchRaw !== '') {
	$parts = preg_split('/\s+/', $searchRaw);
	foreach ($parts as $p) {
		$t = trim($p);
		if ($t !== '') {
			// sanitize token for Overpass regex (remove quotes/slashes)
			$t = str_replace(["\"", "\\"], ['',''], $t);
			$searchTokens[] = $t;
		}
	}
}

// Map simple type names to likely OSM tags
$maps = [

	/* =====================
	   MOBILITÄT
	===================== */

	'gas_stations' => [
		['k'=>'amenity','v'=>'fuel']
	],

	'charging_station' => [
		['k'=>'amenity','v'=>'charging_station']
	],

	'transport' => [
		['k'=>'amenity','v'=>'bus_station'],
		['k'=>'highway','v'=>'bus_stop'],
		['k'=>'railway','v'=>'station'],
		['k'=>'amenity','v'=>'ferry_terminal'],
		['k'=>'aeroway','v'=>'aerodrome']
	],

	'parking' => [
		['k'=>'amenity','v'=>'parking'],
		['k'=>'amenity','v'=>'parking_entrance']
	],

	/* =====================
	   UNTERKÜNFTE & TOURISMUS
	===================== */

	'hotel' => [
		['k'=>'tourism','v'=>'hotel'],
		['k'=>'tourism','v'=>'guest_house'],
		['k'=>'tourism','v'=>'hostel'],
		['k'=>'tourism','v'=>'bed_and_breakfast'],
		['k'=>'tourism','v'=>'alpine_hut'],
		['k'=>'tourism','v'=>'apartment'],
		['k'=>'tourism','v'=>'guesthouse']
	],

	'tourist_info' => [
		['k'=>'tourism','v'=>'information']
	],

	'attraction' => [
		['k'=>'tourism','v'=>'attraction'],
		['k'=>'tourism','v'=>'museum'],
		['k'=>'tourism','v'=>'viewpoint'],
		['k'=>'tourism','v'=>'zoo'],
		['k'=>'tourism','v'=>'theme_park'],
		['k'=>'natural','v'=>'waterfall'],
		['k'=>'waterway','v'=>'waterfall']
	],

	/* =====================
	   ESSEN & VERSORGUNG
	===================== */

	'food' => [
		['k'=>'amenity','v'=>'restaurant'],
		['k'=>'amenity','v'=>'fast_food'],
		['k'=>'amenity','v'=>'cafe'],
		['k'=>'amenity','v'=>'food_court'],
		['k'=>'amenity','v'=>'biergarten'],
		['k'=>'amenity','v'=>'pub'],
		['k'=>'amenity','v'=>'bar'],
		['k'=>'shop','v'=>'bakery']
	],

	'supermarket' => [
		['k'=>'shop','v'=>'supermarket'],
		['k'=>'shop','v'=>'department_store'],
		['k'=>'shop','v'=>'mall']
	],

	/* =====================
	   SERVICE
	===================== */

	'bank' => [
		['k'=>'amenity','v'=>'bank'],
		['k'=>'amenity','v'=>'atm']
	],

	'laundry' => [
		['k'=>'amenity','v'=>'laundry'],
		['k'=>'shop','v'=>'laundry']
	],

	'healthcare' => [
		['k'=>'amenity','v'=>'pharmacy'],
		['k'=>'amenity','v'=>'clinic'],
		['k'=>'amenity','v'=>'hospital']
	],

	'dump_station' => [
		['k'=>'amenity','v'=>'sanitary_dump_station'],
	],

	/* =====================
	   FREIZEIT
	===================== */

	'fitness' => [
		['k'=>'leisure','v'=>'fitness_centre'],
		['k'=>'leisure','v'=>'sports_centre'],
		['k'=>'leisure','v'=>'sports_hall'],
		['k'=>'leisure','v'=>'fitness_station']
	],

	'nightlife' => [
		['k'=>'amenity','v'=>'bar'],
		['k'=>'amenity','v'=>'pub'],
		['k'=>'amenity','v'=>'nightclub']
	],

	'campgrounds' => [
		['k'=>'tourism','v'=>'camp_site'],
		['k'=>'tourism','v'=>'caravan_site']
	],

	'natureparks' => [
		['k'=>'leisure','v'=>'nature_reserve'],
		['k'=>'natural','v'=>'nature_reserve']
	],

	/* =====================
	   REGULIERTE WAREN
	===================== */

	'tobacco' => [
		['k'=>'vending','v'=>'cigarettes'],
		['k'=>'shop','v'=>'tobacco'],
		['k'=>'shop','v'=>'vape'],
		['k'=>'shop','v'=>'vape_shop'],
		// Additional common variants/aliases to improve recall
		['k'=>'shop','v'=>'ecigarette'],
		['k'=>'shop','v'=>'e-cigarette'],
		['k'=>'shop','v'=>'ecig'],
		['k'=>'shop','v'=>'cigar'],
		['k'=>'shop','v'=>'cigars'],
		['k'=>'shop','v'=>'tobacconist'],
		['k'=>'shop','v'=>'smoke_shop'],
	],

	'cannabis' => [
		['k'=>'shop','v'=>'cannabis'],
		['k'=>'shop','v'=>'hemp']
	]

];

// Build Overpass QL parts
$conds = [];
if (!empty($typeList)) {
	foreach ($typeList as $t) {
		if (isset($maps[$t])) {
				foreach ($maps[$t] as $m) {
					// build base filter like [k=v] and append any extra attributes
					$baseFilter = sprintf('[%s=%s]', $m['k'], $m['v']);
					if (!empty($m['extra']) && is_array($m['extra'])) {
						foreach ($m['extra'] as $ek => $ev) {
							$baseFilter .= sprintf('[%s=%s]', $ek, $ev);
						}
					}
					$conds[] = sprintf('node%s(%F,%F,%F,%F);way%s(%F,%F,%F,%F);rel%s(%F,%F,%F,%F);',
						$baseFilter, $minLat, $minLon, $maxLat, $maxLon,
						$baseFilter, $minLat, $minLon, $maxLat, $maxLon,
						$baseFilter, $minLat, $minLon, $maxLat, $maxLon
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

// If free-text search tokens provided, add name-based filters (OR across tokens)
if (!empty($searchTokens)) {
	foreach ($searchTokens as $tok) {
		// use case-insensitive regex match for name/brand/operator fields
		$tokEsc = preg_replace('/[^\w\-\s]/u', '', $tok);
		if ($tokEsc === '') continue;
		$conds[] = sprintf('node[name~"%s",i](%F,%F,%F,%F);way[name~"%s",i](%F,%F,%F,%F);rel[name~"%s",i](%F,%F,%F,%F);', $tokEsc, $minLat, $minLon, $maxLat, $maxLon, $tokEsc, $minLat, $minLon, $maxLat, $maxLon, $tokEsc, $minLat, $minLon, $maxLat, $maxLon);
	}
}

$overpassQL = '[out:json][timeout:25];(' . implode('', $conds) . ');out center;';

// Try multiple Overpass endpoints (fallbacks) to improve resilience
// Try multiple Overpass endpoints (fallbacks) to improve resilience
$endpoints = [
	// prefer public mirrors that are often reachable from filtered networks
	'https://overpass.kumi.systems/api/interpreter',
	'https://overpass-api.de/api/interpreter',
	'https://overpass.openstreetmap.org/api/interpreter'
];
$resp = null; $errMsg = null; $used = null; $lastInfo = null;
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
	$info = curl_getinfo($ch);
	$errNo = curl_errno($ch);
	$errMsg = curl_error($ch);
	curl_close($ch);
	if ($errNo === 0 && $resp) { $used = $ep; $lastInfo = $info; break; }
	usleep(150000);
}

if (!$resp) {
	ov_log(['ts'=>date('c'),'error'=>'curl_error','msg'=>$errMsg ?: 'empty_response','uri'=>$_SERVER['REQUEST_URI'],'tried'=>$endpoints]);
	echo json_encode(['page' => 1, 'per_page' => $limit, 'data' => [], 'error' => 'overpass_unreachable']);
	exit;
}

$data = json_decode($resp, true);
if (!$data || !isset($data['elements'])) {
	// Dump raw Overpass response to temp for debugging (do not expose publicly)
	try {
		$dumpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'planvoyage_overpass_raw_' . time() . '.txt';
		@file_put_contents($dumpPath, "endpoint: " . ($used ?? 'unknown') . PHP_EOL . "request_uri: " . ($_SERVER['REQUEST_URI'] ?? '') . PHP_EOL . "\n---RAW-RESPONSE---\n" . (string)$resp);
	} catch (\Exception $e) { /* ignore */ }
	ov_log(['ts'=>date('c'),'error'=>'parse_error','msg'=>'invalid_json','uri'=>$_SERVER['REQUEST_URI'],'dump'=>$dumpPath ?? null,'used'=>$used ?? null,'http_info'=>$lastInfo ?? null]);
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
	
	// Extract key OSM tag fields to top level for category detection
	// (Frontend _getCategoryForPoi looks for poi.highway, poi.railway, etc.)
	$row = [
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
	
	// Extract critical OSM tags to top level for proper categorization
	if (isset($tags['amenity'])) $row['amenity'] = $tags['amenity'];
	if (isset($tags['tourism'])) $row['tourism'] = $tags['tourism'];
	if (isset($tags['shop'])) $row['shop'] = $tags['shop'];
	if (isset($tags['leisure'])) $row['leisure'] = $tags['leisure'];
	if (isset($tags['highway'])) $row['highway'] = $tags['highway'];
	if (isset($tags['railway'])) $row['railway'] = $tags['railway'];
	if (isset($tags['aeroway'])) $row['aeroway'] = $tags['aeroway'];
	if (isset($tags['natural'])) $row['natural'] = $tags['natural'];
	if (isset($tags['waterway'])) $row['waterway'] = $tags['waterway'];
	if (isset($tags['public_transport'])) $row['public_transport'] = $tags['public_transport'];
	if (isset($tags['brand'])) $row['brand'] = $tags['brand'];
	if (isset($tags['operator'])) $row['operator'] = $tags['operator'];
	
	$rows[] = $row;
	if (count($rows) >= $limit) break;
}

echo json_encode(['page' => 1, 'per_page' => $limit, 'data' => $rows]);
exit;
