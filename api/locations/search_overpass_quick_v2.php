<?php
// Lightweight Overpass proxy for quick-search UI.
// Returns a safe JSON shape and short timeouts so quickSearch doesn't block the main filter flow.
// Version: 2026-01-04 v2 - New mirrors: VK Russia, Japan, Kumi (5 total)

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('X-Overpass-Version: 2026-01-04-v2');

function safe_json($arr) { echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit(0); }

// Robust log writer: attempt to write into project logs, fallback to error_log() and tmp
function write_qlog($msg) {
    // paths relative to project root (api/locations -> ../../logs)
    $logPath = __DIR__ . '/../../logs/overpass_quick.log';
    $tmpLog  = __DIR__ . '/../../tmp/overpass_quick_cache/overpass_quick.log';

    $dir = dirname($logPath);
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $res = @file_put_contents($logPath, $msg, FILE_APPEND | LOCK_EX);
    if ($res === false) {
        error_log('overpass_quick: ' . trim($msg));
        $tmpDir = dirname($tmpLog);
        if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0755, true); }
        @file_put_contents($tmpLog, $msg, FILE_APPEND | LOCK_EX);
    }
}

// Log the incoming request early for visibility (use the robust writer)
$reqLine = date('c') . " INVOKE: " . ($_SERVER['REQUEST_METHOD'] ?? 'GET') . " " . ($_SERVER['REQUEST_URI'] ?? '') . " REMOTE=" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n";
write_qlog($reqLine);

// Script start time for RESPONSE timing
$scriptStart = microtime(true);

// --- Mirror stats (auto-rank) ---------------------------------------------
$statsKey = 'overpass_quick_mirror_stats_v1';
$statsFile = __DIR__ . '/../../tmp/overpass_quick_cache/mirror_stats.json';

function load_mirror_stats() {
    global $statsKey, $statsFile;
    $stats = [];
    if (function_exists('apcu_fetch')) {
        $s = @apcu_fetch($statsKey, $ok);
        if ($ok && is_array($s)) return $s;
    }
    if (is_file($statsFile)) {
        $c = @file_get_contents($statsFile);
        $j = $c ? @json_decode($c, true) : null;
        if (is_array($j)) return $j;
    }
    return $stats;
}

function save_mirror_stats($stats) {
    global $statsKey, $statsFile;
    if (function_exists('apcu_store')) {
        @apcu_store($statsKey, $stats, 3600);
    }
    $dir = dirname($statsFile);
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    @file_put_contents($statsFile, json_encode($stats));
}

function update_mirror_stats($ep, $success, $latMs = null) {
    if (!$ep) return;
    $stats = load_mirror_stats();
    if (!isset($stats[$ep])) {
        $stats[$ep] = ['attempts'=>0,'successes'=>0,'avg_ms'=>null];
    }
    $entry = $stats[$ep];
    $entry['attempts'] = ($entry['attempts'] ?? 0) + 1;
    if ($success) {
        $entry['successes'] = ($entry['successes'] ?? 0) + 1;
    }
    if (!is_null($latMs) && $latMs > 0) {
        if (empty($entry['avg_ms'])) {
            $entry['avg_ms'] = $latMs;
        } else {
            // exponential moving average, alpha=0.3
            $entry['avg_ms'] = ($entry['avg_ms'] * 0.7) + ($latMs * 0.3);
        }
    }
    $stats[$ep] = $entry;
    save_mirror_stats($stats);
}

function score_endpoint($ep, $stats) {
    $default_success = 0.5; $default_ms = 2000;
    $e = $stats[$ep] ?? null;
    $attempts = $e['attempts'] ?? 0;
    $successes = $e['successes'] ?? 0;
    $avg = $e['avg_ms'] ?? $default_ms;
    $sr = $attempts ? ($successes / $attempts) : $default_success;
    // Higher score = better: weight success rate heavily, penalize latency
    return ($sr * 1000) - ($avg / 1000);
}


$search = isset($_GET['search']) ? trim($_GET['search']) : (isset($_REQUEST['search']) ? trim($_REQUEST['search']) : '');
$bbox = isset($_GET['bbox']) ? trim($_GET['bbox']) : (isset($_REQUEST['bbox']) ? trim($_REQUEST['bbox']) : '');
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
if ($limit <= 0) $limit = 10;

// Minimal validation
if ($search === '') {
    $payload = ['page'=>1,'per_page'=>0,'data'=>[],'error'=>'empty_search'];
    $dur = round((microtime(true)-$scriptStart)*1000);
    write_qlog(date('c') . " RESPONSE: empty_search size=" . strlen(json_encode($payload)) . " dur_ms={$dur}\n");
    safe_json($payload);
}

// Build Overpass QL: search name~term case-insensitive within bbox for node/way/relation
// bbox should be south,west,north,east
$bboxParts = preg_split('/\s*,\s*/', $bbox);
// If bbox is missing or invalid, treat as global search (no bbox clause).
if (count($bboxParts) !== 4) {
    $bbox = null;
} else {
    // Check if bbox is too large (>0.5° lat span or >0.7° lon span)
    // Even with tag-constrained search, regex on large areas is slow
    // Stuttgart area ~0.2° × 0.3° works well, so 0.5° × 0.7° is reasonable maximum
    $latSpan = abs(floatval($bboxParts[2]) - floatval($bboxParts[0]));
    $lonSpan = abs(floatval($bboxParts[3]) - floatval($bboxParts[1]));
    if ($latSpan > 0.5 || $lonSpan > 0.7) {
        write_qlog(date('c') . " BBOX TOO LARGE: lat={$latSpan}° lon={$lonSpan}° - rejecting search\n");
        // Return error asking user to zoom in
        $payload = [
            'page' => 1,
            'per_page' => 0,
            'data' => [],
            'error' => 'bbox_too_large',
            'message' => 'Bitte zoomen Sie näher heran. Die aktuelle Ansicht ist zu groß für die Namenssuche.',
            'diagnostic' => ['lat_span' => round($latSpan, 2), 'lon_span' => round($lonSpan, 2), 'max_lat' => 0.5, 'max_lon' => 0.7]
        ];
        safe_json($payload);
    }
}

// Escape double quotes and backslashes for Overpass
$safeSearch = str_replace(['\\','"'], ['\\\\','\\"'], $search);

// Build bbox clause for queries that use it
$bboxClause = '';
if (!is_null($bbox)) {
    $bboxClause = '(' . $bbox . ')';
}

// Build flexible search pattern: replace spaces/hyphens/underscores with .* (any chars)
// This matches any combination of characters between words
// Example: "Mercedes Benz Museum" → ".*Mercedes.*Benz.*Museum.*"
// This finds: "Mercedes-Benz Museum", "Mercedes Benz Museum", "Mercedes_Benz_Museum", etc.
$flexibleSearch = preg_replace('/[\s\-_]+/', '.*', $safeSearch);
$searchPattern = '.*' . $flexibleSearch . '.*';

// PERFORMANCE OPTIMIZATION: Tag-constrained search is MUCH faster than name-only
// Instead of searching all nodes/ways/relations by name, we search within POI categories
// Common POI tags: tourism, amenity, shop, leisure, historic, natural, office
$timeout = 25;
write_qlog(date('c') . " TAG-CONSTRAINED SEARCH: pattern='{$searchPattern}' bbox=" . ($bbox ? "yes" : "no") . "\n");

$ql = '[out:json][timeout:' . $timeout . '];(';
// Tourism POIs (hotels, museums, attractions, viewpoints, etc.)
$ql .= 'node["tourism"]["name"~"' . $searchPattern . '",i]' . $bboxClause . ';';
$ql .= 'way["tourism"]["name"~"' . $searchPattern . '",i]' . $bboxClause . ';';
$ql .= 'relation["tourism"]["name"~"' . $searchPattern . '",i]' . $bboxClause . ';';
// Amenities (restaurants, cafes, parking, fuel, etc.)
$ql .= 'node["amenity"]["name"~"' . $searchPattern . '",i]' . $bboxClause . ';';
$ql .= 'way["amenity"]["name"~"' . $searchPattern . '",i]' . $bboxClause . ';';
$ql .= 'relation["amenity"]["name"~"' . $searchPattern . '",i]' . $bboxClause . ';';
// Shops
$ql .= 'node["shop"]["name"~"' . $searchPattern . '",i]' . $bboxClause . ';';
$ql .= 'way["shop"]["name"~"' . $searchPattern . '",i]' . $bboxClause . ';';
// Leisure (parks, sports centers, etc.)
$ql .= 'node["leisure"]["name"~"' . $searchPattern . '",i]' . $bboxClause . ';';
$ql .= 'way["leisure"]["name"~"' . $searchPattern . '",i]' . $bboxClause . ';';
// Historic sites
$ql .= 'node["historic"]["name"~"' . $searchPattern . '",i]' . $bboxClause . ';';
$ql .= 'way["historic"]["name"~"' . $searchPattern . '",i]' . $bboxClause . ';';
$ql .= ');out center ' . intval($limit) . ';';

// Simple file cache to reduce repeated Overpass calls for identical queries
$cacheTtl = 3600; // seconds
$cacheDir = __DIR__ . '/../../tmp/overpass_quick_cache';
@mkdir($cacheDir, 0755, true);
$cacheKey = 'oq_' . md5($ql);
$cacheFile = $cacheDir . '/' . $cacheKey . '.json';
// Try APCu first (if available) for faster in-memory cache
if (function_exists('apcu_fetch')) {
    $apc = @apcu_fetch($cacheKey, $apcSuccess);
    if ($apcSuccess && $apc !== false) {
        write_qlog(date('c') . " APCU HIT: {$cacheKey}\n");
        echo $apc; exit(0);
    }
}

if (file_exists($cacheFile) && (time() - filemtime($cacheFile) <= $cacheTtl)) {
    // Serve cached response (file)
    $cached = @file_get_contents($cacheFile);
        if ($cached !== false) {
            // log cache hit
            write_qlog(date('c') . " CACHE HIT: {$cacheKey}\n");
            echo $cached; exit(0);
        }
}

// Preferred mirror order (user-supplied list).
// Overpass API endpoints from official wiki: https://wiki.openstreetmap.org/wiki/Overpass_API#Public_Overpass_API_instances
// Priority based on: hardware specs, rate limits, and observed reliability from logs
$endpoints = [
    // initial list; will be re-ordered by stats below
    'https://overpass.private.coffee/api/interpreter',         // Private.coffee: 4 servers, 20 cores, 256GB RAM each, no rate limit
    'https://maps.mail.ru/osm/tools/overpass/api/interpreter', // VK Maps Russia: 2 servers, 56 cores, 384GB RAM, no limits  
    'https://overpass.osm.jp/api/interpreter',                 // Japan: 24 cores, 64GB RAM, global data
    'https://overpass-api.de/api/interpreter',                 // Official: often 504/overloaded, but complete
    'https://overpass.kumi.systems/api/interpreter'            // Kumi old domain: additional fallback
];

// Auto-rank endpoints by historical success/latency
$mirrorStats = load_mirror_stats();
usort($endpoints, function($a,$b) use ($mirrorStats){
    $sa = score_endpoint($a,$mirrorStats);
    $sb = score_endpoint($b,$mirrorStats);
    if ($sa == $sb) return 0; return ($sa > $sb) ? -1 : 1;
});
write_qlog(date('c') . " MIRROR_ORDER: " . implode(',', $endpoints) . "\n");

// Initialize cURL common options (we'll set URL per-endpoint inside the loop).
$ch = curl_init();
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'PlanVoyage-QuickSearch/1.0');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $ql);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/plain']);
// Connection and overall timeouts: fail-fast connection, but allow enough time for query execution
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
curl_setopt($ch, CURLOPT_TIMEOUT, 25);

// Try each endpoint and retry once on transient failures to avoid brief network hiccups.
$used = null; $lastErr = null; $respBody = null; $httpInfo = null;

// Log the Overpass query (shortened) for debugging
$shortQl = (strlen($ql) > 500) ? substr($ql,0,500) . '... [truncated]' : $ql;
write_qlog(date('c') . " OVERPASS_QUERY: " . trim(preg_replace('/\s+/', ' ', $shortQl)) . "\n");

// Try each endpoint and retry a few times on transient failures
foreach ($endpoints as $ep) {
    curl_setopt($ch, CURLOPT_URL, $ep);
    // Try up to 2 attempts per endpoint (first attempt + one retry) to fail-fast
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $start = microtime(true);
        $body = curl_exec($ch);
        $duration = microtime(true) - $start;
        $info = curl_getinfo($ch);
        $http_code = $info['http_code'] ?? 0;
        $err = curl_error($ch);

        // Log this attempt's outcome for observability
        $log = date('c') . " ATTEMPT: ep={$ep} attempt={$attempt} http_code={$http_code} total_time=" . round(($info['total_time'] ?? $duration), 3) . " err=" . json_encode($err) . "\n";
        write_qlog($log);

        if ($body !== false && $http_code >= 200 && $http_code < 300 && strlen($body) > 0) {
            // Check if response actually contains data (not just empty elements array)
            $testJson = json_decode($body, true);
            if ($testJson && isset($testJson['elements']) && is_array($testJson['elements']) && count($testJson['elements']) > 0) {
                // Found valid response with data - use this endpoint
                $used = $ep;
                $respBody = $body;
                $httpInfo = $info;
                break 2;
            }
            // Else: valid HTTP response but no data - try next endpoint
            write_qlog(date('c') . " EMPTY_RESPONSE: ep={$ep} returned valid JSON but 0 elements, trying next mirror\n");
            break; // Break inner loop to try next endpoint
        }

        $lastErr = $err ?: ('http_code:' . $http_code);
        $httpInfo = $info;

        // Small backoff before retrying (only for attempt 1)
        if ($attempt < 2) {
            usleep(150000 * $attempt); // 150ms
        }
    }
}

curl_close($ch);

if (!$used || !$respBody) {
    // Log failure diagnostic
    $logLine = date('c') . " OVERPASS_FAIL: used=" . var_export($used, true) . " err=" . var_export($lastErr, true) . " info=" . json_encode($httpInfo) . "\n";
    write_qlog($logLine);
    // update stats: mark used endpoint as failure
    if ($used) {
        $latMs = isset($httpInfo['total_time']) ? round($httpInfo['total_time'] * 1000) : null;
        update_mirror_stats($used, false, $latMs);
    }
    $payload = ['page'=>1,'per_page'=>0,'data'=>[], 'error'=>'overpass_unreachable', 'diagnostic'=>['used_endpoint'=>$used,'http_info'=>$httpInfo,'err'=>$lastErr]];
    $dur = round((microtime(true)-$scriptStart)*1000);
    write_qlog(date('c') . " RESPONSE: overpass_unreachable size=" . strlen(json_encode($payload)) . " dur_ms={$dur} used=" . var_export($used, true) . "\n");
    safe_json($payload);
}

$json = json_decode($respBody, true);
if (!$json || !isset($json['elements']) || !is_array($json['elements'])) {
    $payload = ['page'=>1,'per_page'=>0,'data'=>[], 'error'=>'invalid_overpass_response', 'diagnostic'=>['used_endpoint'=>$used,'http_info'=>$httpInfo]];
    $dur = round((microtime(true)-$scriptStart)*1000);
    write_qlog(date('c') . " RESPONSE: invalid_overpass_response size=" . strlen(json_encode($payload)) . " dur_ms={$dur} used=" . var_export($used,true) . "\n");
    // mark used endpoint as failed
    if ($used) { $latMs = isset($httpInfo['total_time']) ? round($httpInfo['total_time']*1000) : null; update_mirror_stats($used, false, $latMs); }
    safe_json($payload);
}

$out = [];
foreach ($json['elements'] as $el) {
    $type = $el['type'] ?? null; // node/way/relation
    $id = $el['id'] ?? null;
    $tags = $el['tags'] ?? [];
    $name = $tags['name'] ?? ($el['display_name'] ?? null) ?? '';
    $lat = null; $lon = null;
    if ($type === 'node') {
        $lat = isset($el['lat']) ? floatval($el['lat']) : null;
        $lon = isset($el['lon']) ? floatval($el['lon']) : null;
    } else {
        if (isset($el['center'])) {
            $lat = floatval($el['center']['lat'] ?? 0);
            $lon = floatval($el['center']['lon'] ?? 0);
        }
    }
    
    // Extract POI type from tags for display in popup
    // Check common POI tags in order of priority
    $poiType = null;
    if (isset($tags['tourism'])) $poiType = $tags['tourism'];
    elseif (isset($tags['amenity'])) $poiType = $tags['amenity'];
    elseif (isset($tags['shop'])) $poiType = $tags['shop'];
    elseif (isset($tags['leisure'])) $poiType = $tags['leisure'];
    elseif (isset($tags['historic'])) $poiType = $tags['historic'];
    
    $out[] = [
        'id' => $id,
        'osm_id' => $id,
        'osm_type' => ($type ? strtoupper(substr($type,0,1)) : null),
        'name' => $name,
        'type' => $poiType, // Add type field for popup template
        'source' => 'overpass', // Mark as external source for popup template
        'lat' => $lat,
        'lon' => $lon,
        'tags' => $tags
    ];
}

// Deduplicate results: same name + very close coordinates (< 10m) = likely duplicate
// This removes cases where node, way, and relation all represent the same POI
$deduped = [];
$seen = [];
foreach ($out as $item) {
    $nameKey = strtolower(trim($item['name']));
    $latRounded = round($item['lat'], 4); // ~11m precision
    $lonRounded = round($item['lon'], 4);
    $key = $nameKey . '|' . $latRounded . '|' . $lonRounded;
    if (!isset($seen[$key])) {
        $seen[$key] = true;
        $deduped[] = $item;
    }
}
$out = $deduped;

// Prepare and log response
$responsePayload = ['page'=>1,'per_page'=>count($out),'data'=>array_slice($out,0,$limit),'diagnostic'=>['used_endpoint'=>$used,'http_info'=>$httpInfo]];
$dur = round((microtime(true)-$scriptStart)*1000);
write_qlog(date('c') . " RESPONSE: success size=" . strlen(json_encode($responsePayload)) . " dur_ms={$dur} used=" . var_export($used,true) . "\n");

// update mirror stats for success
if ($used) {
    $latMs = isset($httpInfo['total_time']) ? round($httpInfo['total_time'] * 1000) : null;
    update_mirror_stats($used, true, $latMs);
}

safe_json($responsePayload);

// Cache successful response for future identical queries
try {
    $payload = json_encode(['page'=>1,'per_page'=>count($out),'data'=>array_slice($out,0,$limit),'diagnostic'=>['used_endpoint'=>$used,'http_info'=>$httpInfo]]);
    @file_put_contents($cacheFile, $payload, LOCK_EX);
    if (function_exists('apcu_store')) {
        @apcu_store($cacheKey, $payload, $cacheTtl);
    }
} catch (Exception $e) {}

?>
