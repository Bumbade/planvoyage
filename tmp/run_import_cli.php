<?php
$root = realpath(__DIR__ . '/..');
require_once $root . '/src/config/env.php';
require_once $root . '/src/config/mysql.php';
require_once $root . '/src/helpers/import_helpers.php';
require_once $root . '/src/controllers/LocationController.php';

if ($argc < 2) {
    echo "Usage: php run_import_cli.php <osm_id>\n";
    exit(1);
}
$osmId = intval($argv[1]);
if ($osmId <= 0) {
    echo "invalid osm id\n";
    exit(1);
}
$overpass = getenv('OVERPASS_ENDPOINT') ?: 'https://overpass.openstreetmap.org/api/interpreter';
$timeout = intval(getenv('OVERPASS_TIMEOUT') ?: 25);
$q = sprintf("[out:json][timeout:%d];(node(%d);way(%d);relation(%d););out center tags;", $timeout, $osmId, $osmId, $osmId);
$ch = curl_init($overpass);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, 'data=' . $q);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, max(10, $timeout));
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_USERAGENT, 'PlanVoyage/CLI');
$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$pref = null;
if ($resp && $http >= 200 && $http < 300) {
    $dec = @json_decode($resp, true);
    if (is_array($dec) && isset($dec['elements'])) {
        // choose best element per same-id logic
        $chosen = null;
        foreach ($dec['elements'] as $el) {
            if ($chosen === null) { $chosen = $el; continue; }
            $cur = $chosen;
            $curTags = is_array($cur['tags'] ?? null) ? count($cur['tags']) : (empty($cur['tags']) ? 0 : 1);
            $newTags = is_array($el['tags'] ?? null) ? count($el['tags']) : (empty($el['tags']) ? 0 : 1);
            $curName = is_array($cur['tags'] ?? null) ? ($cur['tags']['name'] ?? ($cur['name'] ?? null)) : ($cur['name'] ?? null);
            $newName = is_array($el['tags'] ?? null) ? ($el['tags']['name'] ?? ($el['name'] ?? null)) : ($el['name'] ?? null);
            $replace = false;
            // Prefer elements that include a name when the current does not
            if (!empty($newName) && empty($curName)) {
                $replace = true;
            // If both have names prefer the one with more tags
            } elseif (!empty($newName) && !empty($curName) && $newTags > $curTags) {
                $replace = true;
            // Prefer node element when available (avoid overwriting node with way/relation)
            } elseif ((($cur['type'] ?? '') !== 'node') && (($el['type'] ?? '') === 'node')) {
                $replace = true;
            // fallback: prefer element with more tags
            } elseif ($newTags > $curTags) {
                $replace = true;
            }
            if ($replace) $chosen = $el;
        }
        $pref = $chosen;
    }
}
$lc = new LocationController();
$res = $lc->import($pref, true);
echo json_encode($res, JSON_PRETTY_PRINT) . "\n";
