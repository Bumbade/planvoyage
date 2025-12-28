<?php
// tools/poi_import_test.php
// Run automated import tests: 2 imports per category, then report DB rows.
// Usage: php tools/poi_import_test.php
// WARNING: This script will call the import endpoint and write to your DB. Use on dev only.

require_once __DIR__ . '/../src/helpers/url.php';
require_once __DIR__ . '/../src/config/mysql.php';

// Categories to test (keys matching server POI_FILTERS / select_pois)
$categories = [
    'hotels','attraction','tourist_info','food','nightlife','fuel','supermarket','banks','healthcare','laundry','fitness','tobacco_vape','cannabis','transport','dump_station','campgrounds','attractions'
];

// Minimal sample OSM-like payloads per category (two samples each)
$samples = [
    'hotels' => [
        ['name'=>'Test Hotel Alpha','lat'=>52.52,'lon'=>13.405,'tags'=>['tourism'=>'hotel','name'=>'Test Hotel Alpha']],
        ['name'=>'Test Motel Beta','lat'=>52.53,'lon'=>13.406,'tags'=>['tourism'=>'motel','name'=>'Test Motel Beta']]
    ],
    'attraction' => [
        ['name'=>'Test Museum One','lat'=>48.8566,'lon'=>2.3522,'tags'=>['tourism'=>'museum','name'=>'Test Museum One']],
        ['name'=>'Test Waterfall','lat'=>47.0,'lon'=>9.0,'tags'=>['natural'=>'waterfall','name'=>'Test Waterfall']]
    ],
    'tourist_info' => [
        ['name'=>'Test Tourist Info','lat'=>50.0,'lon'=>8.0,'tags'=>['tourism'=>'information','name'=>'Test Tourist Info']],
        ['name'=>'Info Center Two','lat'=>50.1,'lon'=>8.1,'tags'=>['tourism'=>'information','name'=>'Info Center Two']]
    ],
    'food' => [
        ['name'=>'Test Restaurant A','lat'=>51.0,'lon'=>7.0,'tags'=>['amenity'=>'restaurant','name'=>'Test Restaurant A']],
        ['name'=>'Test Cafe B','lat'=>51.1,'lon'=>7.1,'tags'=>['amenity'=>'cafe','name'=>'Test Cafe B']]
    ],
    'nightlife' => [
        ['name'=>'Test Bar One','lat'=>51.5,'lon'=>7.5,'tags'=>['amenity'=>'bar','name'=>'Test Bar One']],
        ['name'=>'Test Club Two','lat'=>51.6,'lon'=>7.6,'tags'=>['amenity'=>'nightclub','name'=>'Test Club Two']]
    ],
    'fuel' => [
        ['name'=>'Test Fuel Station','lat'=>52.0,'lon'=>13.0,'tags'=>['amenity'=>'fuel','name'=>'Test Fuel Station']],
        ['name'=>'Test Charging Point','lat'=>52.01,'lon'=>13.01,'tags'=>['amenity'=>'charging_station','name'=>'Test Charging Point']]
    ],
    'supermarket' => [
        ['name'=>'Test Supermarket','lat'=>49.0,'lon'=>8.0,'tags'=>['shop'=>'supermarket','name'=>'Test Supermarket']],
        ['name'=>'Test Mall','lat'=>49.1,'lon'=>8.1,'tags'=>['shop'=>'mall','name'=>'Test Mall']]
    ],
    'banks' => [
        ['name'=>'Test Bank','lat'=>48.0,'lon'=>11.0,'tags'=>['amenity'=>'bank','name'=>'Test Bank']],
        ['name'=>'Test ATM','lat'=>48.1,'lon'=>11.1,'tags'=>['amenity'=>'atm','name'=>'Test ATM']]
    ],
    'healthcare' => [
        ['name'=>'Test Pharmacy','lat'=>47.0,'lon'=>11.0,'tags'=>['amenity'=>'pharmacy','name'=>'Test Pharmacy']],
        ['name'=>'Test Hospital','lat'=>47.1,'lon'=>11.1,'tags'=>['amenity'=>'hospital','name'=>'Test Hospital']]
    ],
    'laundry' => [
        ['name'=>'Test Laundry','lat'=>46.0,'lon'=>9.0,'tags'=>['shop'=>'laundry','name'=>'Test Laundry']],
        ['name'=>'Test Laundry 2','lat'=>46.1,'lon'=>9.1,'tags'=>['amenity'=>'laundry','name'=>'Test Laundry 2']]
    ],
    'fitness' => [
        ['name'=>'Test Gym','lat'=>45.0,'lon'=>9.0,'tags'=>['leisure'=>'fitness_centre','name'=>'Test Gym']],
        ['name'=>'Test Sports Hall','lat'=>45.1,'lon'=>9.1,'tags'=>['leisure'=>'sports_hall','name'=>'Test Sports Hall']]
    ],
    'tobacco_vape' => [
        ['name'=>'Test Tobacco','lat'=>44.0,'lon'=>10.0,'tags'=>['shop'=>'tobacco','name'=>'Test Tobacco']],
        ['name'=>'Test Vape','lat'=>44.1,'lon'=>10.1,'tags'=>['shop'=>'e-cigarette','name'=>'Test Vape']]
    ],
    'cannabis' => [
        ['name'=>'Test Cannabis Shop','lat'=>43.0,'lon'=>10.0,'tags'=>['shop'=>'cannabis','name'=>'Test Cannabis Shop']],
        ['name'=>'Test Cannabis 2','lat'=>43.1,'lon'=>10.1,'tags'=>['shop'=>'cannabis','name'=>'Test Cannabis 2']]
    ],
    'transport' => [
        ['name'=>'Test Bus Stop','lat'=>42.0,'lon'=>12.0,'tags'=>['public_transport'=>'stop_position','name'=>'Test Bus Stop']],
        ['name'=>'Test Station','lat'=>42.1,'lon'=>12.1,'tags'=>['public_transport'=>'station','name'=>'Test Station']]
    ],
    'dump_station' => [
        ['name'=>'Test Dump','lat'=>41.0,'lon'=>12.0,'tags'=>['amenity'=>'sanitary_dump_station','name'=>'Test Dump']],
        ['name'=>'Test Dump 2','lat'=>41.1,'lon'=>12.1,'tags'=>['amenity'=>'waste_disposal','name'=>'Test Dump 2']]
    ],
    'campgrounds' => [
        ['name'=>'Test Camp','lat'=>40.0,'lon'=>12.0,'tags'=>['tourism'=>'camp_site','name'=>'Test Camp']],
        ['name'=>'Test Caravan','lat'=>40.1,'lon'=>12.1,'tags'=>['tourism'=>'caravan_site','name'=>'Test Caravan']]
    ],
];

// Import endpoint - use the app front controller path if available
// Build absolute API base. Prefer configured app.base, then environment override, else assume localhost
$configuredBase = null;
if (function_exists('config')) {
    $configuredBase = config('app.base') ?: null;
}
$envBase = getenv('API_BASE_URL') ?: null;
$base = $envBase ?: ($configuredBase ?: null);
if ($base) {
    // ensure trailing slash
    $base = rtrim($base, '/') . '/';
} else {
    // fallback to localhost; adjust if your app serves on different host/port
    $base = 'http://localhost/';
}

// Candidate import endpoints
$apiCandidates = [
    // Prefer front-controller without .php (common on this project)
    $base . 'src/index.php/api/locations/import',
    // fallbacks that may exist on other installs
    $base . 'src/index.php/api/locations/import.php',
    $base . 'src/api/locations/import',
    $base . 'src/api/locations/import.php',
    $base . 'api/locations/import',
    $base . 'api/locations/import.php'
];

function tryPost($url, $payload) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_USERAGENT, 'PlanVoyage-ImportTest/1.0');
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code'=>$code,'res'=>$res,'err'=>$err];
}

$report = [];

foreach ($categories as $cat) {
    if (!isset($samples[$cat])) continue;
    $report[$cat] = [];
    foreach ($samples[$cat] as $i => $s) {
        // Build payload similar to importer expectations
        $payload = [
            'osm_id' => rand(90000000, 99999999),
            'osm_type' => 'node',
            'lat' => $s['lat'],
            'lon' => $s['lon'],
            'tags' => json_encode($s['tags']),
            'name' => $s['name'],
            'source' => 'import_test'
        ];
        $ok = false; $last = null;
        foreach ($apiCandidates as $u) {
            if (empty($u)) continue;
            $r = tryPost($u, $payload);
            $last = ['url'=>$u,'resp'=>$r];
            if ($r['code'] >= 200 && $r['code'] < 300) { $ok = true; break; }
        }
        $report[$cat][] = $last;
        // small sleep to avoid race
        usleep(200000);
    }
}

// After imports, query DB for recent rows matching our marker `source = 'import_test'` via tags_text
$db = get_db();
$stmt = $db->prepare("SELECT id, name, type, logo, tags_text, created_at FROM locations WHERE tags_text LIKE :marker OR name LIKE :name_marker ORDER BY id DESC LIMIT 500");
$mk = '%import_test%'; $nm = '%Test %';
$stmt->bindValue(':marker', $mk);
$stmt->bindValue(':name_marker', $nm);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Print report
echo "Import HTTP results:\n";
foreach ($report as $cat => $entries) {
    echo "Category: $cat\n";
    foreach ($entries as $e) {
        if (!$e) { echo "  no attempt\n"; continue; }
        echo "  -> tried " . ($e['url'] ?? 'n/a') . " status=" . ($e['resp']['code'] ?? 'n/a') . " err=" . ($e['resp']['err'] ?? '') . "\n";
    }
}

echo "\nDB rows matching test imports:\n";
foreach ($rows as $r) {
    echo sprintf("%d | %s | type=%s | logo=%s | created=%s\n", $r['id'], $r['name'], $r['type'] ?? '', $r['logo'] ?? '', $r['created_at'] ?? '');
}

echo "\nDone. Review above output.\n";

?>