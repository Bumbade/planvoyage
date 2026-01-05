<?php
// Printer-friendly route printout with interactive Leaflet maps and options

if (file_exists(__DIR__ . '/helpers/session.php')) {
    require_once __DIR__ . '/helpers/session.php';
    start_secure_session();
}

require_once __DIR__ . '/config/mysql.php';
require_once __DIR__ . '/controllers/RouteController.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo 'Invalid route id';
    exit;
}

// Get user options from query parameters
$showDetailsOnly = isset($_GET['details_only']) && $_GET['details_only'] === '1';
$useA3 = isset($_GET['a3']) && $_GET['a3'] === '1';
$generate = isset($_GET['generate']) && $_GET['generate'] === '1';

function haversine_km($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earthRadius * $c;
}

// Fetch full route geometry from OSRM public server for given waypoint list
function fetch_osrm_geometry(array $waypoints)
{
    if (count($waypoints) < 2) return null;
    $parts = [];
    foreach ($waypoints as $p) {
        if (!isset($p['latitude']) || !isset($p['longitude'])) continue;
        $parts[] = sprintf('%.6F,%.6F', (float)$p['longitude'], (float)$p['latitude']);
    }
    if (count($parts) < 2) return null;
    $coordStr = implode(';', $parts);
    $url = 'https://router.project-osrm.org/route/v1/driving/' . $coordStr . '?overview=full&geometries=geojson&steps=true';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = curl_exec($ch);
    curl_close($ch);
    if ($resp === false) return null;
    $decoded = json_decode($resp, true);
    if (!$decoded || empty($decoded['routes'][0]['geometry']['coordinates'])) return null;
    
    $coords = [];
    foreach ($decoded['routes'][0]['geometry']['coordinates'] as $c) {
        $coords[] = ['latitude' => (float)$c[1], 'longitude' => (float)$c[0]];
    }
    
    $duration = $decoded['routes'][0]['duration'] ?? 0; // in seconds
    $distance = $decoded['routes'][0]['distance'] ?? 0; // in meters
    $legs = $decoded['routes'][0]['legs'] ?? [];
    
    return [
        'coords' => $coords,
        'duration' => $duration,
        'distance' => $distance,
        'legs' => $legs
    ];
}

function bearing_deg($lat1, $lon1, $lat2, $lon2)
{
    $lat1 = deg2rad($lat1); $lat2 = deg2rad($lat2);
    $dLon = deg2rad($lon2 - $lon1);
    $y = sin($dLon) * cos($lat2);
    $x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($dLon);
    $brng = rad2deg(atan2($y, $x));
    return fmod(($brng + 360), 360);
}

// detect indices of significant direction changes (turns). Returns array of indices in coords
function detect_turn_indices(array $coords, $angleThreshold = 30)
{
    $n = count($coords);
    $indices = [];
    for ($i = 1; $i < $n - 1; $i++) {
        $b1 = bearing_deg($coords[$i-1]['latitude'], $coords[$i-1]['longitude'], $coords[$i]['latitude'], $coords[$i]['longitude']);
        $b2 = bearing_deg($coords[$i]['latitude'], $coords[$i]['longitude'], $coords[$i+1]['latitude'], $coords[$i+1]['longitude']);
        $diff = abs($b2 - $b1);
        if ($diff > 180) $diff = 360 - $diff;
        if ($diff >= $angleThreshold) {
            $indices[] = $i;
        }
    }
    return $indices;
}

function format_duration($seconds) {
    $seconds = (float)$seconds;
    $hours = (int)floor($seconds / 3600);
    $minutes = (int)floor(fmod($seconds, 3600) / 60);
    if ($hours > 0) {
        return sprintf('%dh %dmin', $hours, $minutes);
    }
    return sprintf('%dmin', $minutes);
}

$rc = new RouteController();
$route = $rc->viewRoute($id);
if (!$route) { http_response_code(404); echo 'Route not found'; exit; }

$items = $route->items ?? [];

// If not generating yet, show options form
if (!$generate) {
    if (file_exists(__DIR__ . '/includes/header.php')) { require_once __DIR__ . '/includes/header.php'; }
    ?>
    <div class="container" style="max-width: 800px; margin: 40px auto; padding: 20px;">
        <h1>Druckoptionen für Route: <?php echo htmlspecialchars($route->name); ?></h1>
        <form method="GET" action="">
            <input type="hidden" name="id" value="<?php echo $id; ?>">
            <input type="hidden" name="generate" value="1">
            
            <div class="form-group" style="margin: 20px 0;">
                <label style="display: block; margin-bottom: 10px;">
                    <input type="checkbox" name="details_only" value="1">
                    <strong>Nur Übersichtskarten</strong> (keine Detail-Karten für Abbiegungen)
                </label>
            </div>
            
            <div class="form-group" style="margin: 20px 0;">
                <label style="display: block; margin-bottom: 10px;">
                    <input type="checkbox" name="a3" value="1">
                    <strong>A3-Format verwenden</strong> (sonst A4)
                </label>
            </div>
            
            <button type="submit" class="btn btn-primary" style="padding: 10px 20px; font-size: 16px;">
                Druckansicht generieren
            </button>
            <a href="<?php echo htmlspecialchars(function_exists('app_url') ? app_url('/src/index.php/routes/view/' . $id) : '/src/index.php/routes/view/' . $id); ?>" 
               class="btn btn-secondary" style="padding: 10px 20px; font-size: 16px; margin-left: 10px;">
                Zurück
            </a>
        </form>
    </div>
    <?php
    if (file_exists(__DIR__ . '/includes/footer.php')) { require_once __DIR__ . '/includes/footer.php'; }
    exit;
}

// Calculate total distance and collect all coordinates
$totalKm = 0.0; 
$prev = null;
$allPoints = [];
foreach ($items as $it) {
    if (!empty($it['latitude']) && !empty($it['longitude'])) {
        $allPoints[] = ['latitude' => $it['latitude'], 'longitude' => $it['longitude']];
        if ($prev !== null) {
            $totalKm += haversine_km($prev['latitude'], $prev['longitude'], $it['latitude'], $it['longitude']);
        }
        $prev = $it;
    }
}

// Fetch complete route data from OSRM for total duration
$fullRouteData = count($allPoints) >= 2 ? fetch_osrm_geometry($allPoints) : null;
$totalDuration = $fullRouteData ? $fullRouteData['duration'] : 0;
$totalDistance = $fullRouteData ? ($fullRouteData['distance'] / 1000) : $totalKm;

// Determine page size class
$pageClass = $useA3 ? 'a3page' : 'a4page';
$mapHeight = $useA3 ? '800px' : '600px';

// Start HTML output
if (file_exists(__DIR__ . '/includes/header.php')) { require_once __DIR__ . '/includes/header.php'; }
if (function_exists('app_url')) { 
    echo '<link rel="stylesheet" href="' . htmlspecialchars(app_url('/src/assets/css/printout.css')) . '">'; 
}
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
/* Force landscape orientation for all print pages */
@page {
    size: A4 landscape;
    margin: 15mm;
}

/* Additional styles for A3 support */
.a3page {
    width: 297mm;
    height: 420mm;
    padding: 18mm;
    box-sizing: border-box;
    page-break-after: always;
    overflow: hidden;
}
.a3page .pv-leaflet {
    height: <?php echo $mapHeight; ?> !important;
}
.print-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid #333;
}
.print-header img {
    max-height: 60px;
}
.print-date {
    font-size: 0.9em;
    color: #000000;
}
</style>

<div class="print-button" style="margin: 20px; text-align: center;">
    <button class="btn btn-primary" onclick="window.print()">Drucken / Als PDF speichern</button>
    <a href="?id=<?php echo $id; ?>" class="btn btn-secondary" style="margin-left: 10px;">Optionen ändern</a>
</div>

<?php
// Page 1: Overview with logo, trip info, and total map
echo '<div class="' . $pageClass . ' a4-overview">';
echo '<div class="printout-container">';

// Logo and print date
echo '<div class="print-header">';
echo '<div class="logo">';
// Check if logo exists before trying to display
$logoExists = false;
if (function_exists('app_url')) {
    $logoPath = app_url('/src/assets/images/logo.png');
    $logoFilePath = __DIR__ . '/assets/images/logo.png';
    if (file_exists($logoFilePath)) {
        echo '<img src="' . htmlspecialchars($logoPath) . '" alt="PlanVoyage.de">';
        $logoExists = true;
    }
}
if (!$logoExists) {
    echo '<h2 style="margin: 0; color: #333;">PlanVoyage.de</h2>';
}
echo '</div>';
echo '<div class="print-date">Druckdatum: ' . date('d.m.Y H:i') . '</div>';
echo '</div>';

// Trip name and period
echo '<h1>' . htmlspecialchars($route->name) . '</h1>';
echo '<p><strong>Zeitraum:</strong> ' . htmlspecialchars($route->start_date) . ' bis ' . htmlspecialchars($route->end_date) . '</p>';
echo '<p><strong>Stationen:</strong> ' . count($items) . ' &nbsp; | &nbsp; <strong>Gesamtstrecke:</strong> ' . number_format($totalDistance, 1) . ' km';
if ($totalDuration > 0) {
    echo ' &nbsp; | &nbsp; <strong>Gesamtfahrzeit:</strong> ' . format_duration($totalDuration);
}
echo '</p>';

// Total overview map
if (count($allPoints) >= 2) {
    $mapId = 'pvmap-total-overview';
    $geoData = $fullRouteData && !empty($fullRouteData['coords']) ? $fullRouteData['coords'] : $allPoints;
    $dataGeo = htmlspecialchars(json_encode($geoData), ENT_QUOTES);
    echo '<div class="map-wrapper">';
    echo '<h3>Gesamtübersicht</h3>';
    echo '<div id="' . $mapId . '" class="pv-leaflet" style="height: ' . $mapHeight . ';" data-geo=\'' . $dataGeo . '\'></div>';
    echo '</div>';
}

echo '</div></div>'; // .printout-container, .page

// Generate segment pages
$count = count($items);
if ($count > 1) {
    for ($s = 0; $s < $count - 1; $s++) {
        $a = $items[$s]; 
        $b = $items[$s+1];
        $haveCoords = (!empty($a['latitude']) && !empty($a['longitude']) && !empty($b['latitude']) && !empty($b['longitude']));
        
        if (!$haveCoords) continue;
        
        $segmentTitle = htmlspecialchars(($a['location_name'] ?? ('#' . ($a['location_id'] ?? ''))) . ' → ' . ($b['location_name'] ?? ('#' . ($b['location_id'] ?? ''))));
        
        // Fetch segment route data
        $pts = [
            ['latitude' => $a['latitude'], 'longitude' => $a['longitude']], 
            ['latitude' => $b['latitude'], 'longitude' => $b['longitude']]
        ];
        $segmentData = fetch_osrm_geometry($pts);
        $segKm = $segmentData ? ($segmentData['distance'] / 1000) : haversine_km($a['latitude'], $a['longitude'], $b['latitude'], $b['longitude']);
        $segDuration = $segmentData ? $segmentData['duration'] : 0;
        
        // Segment overview page
        echo '<div class="' . $pageClass . ' a4-overview">';
        echo '<div class="printout-container">';
        echo '<div class="segment">';
        echo '<div class="segment-title" style="font-size: 1.5em; margin-bottom: 10px;">' . $segmentTitle . '</div>';
        
        $segmentInfo = [];
        if (!empty($a['departure'])) $segmentInfo[] = 'Abfahrt: ' . htmlspecialchars($a['departure']);
        if (!empty($b['arrival'])) $segmentInfo[] = 'Ankunft: ' . htmlspecialchars($b['arrival']);
        $segmentInfo[] = 'Distanz: ' . number_format($segKm, 1) . ' km';
        if ($segDuration > 0) $segmentInfo[] = 'Fahrzeit: ' . format_duration($segDuration);
        
        echo '<div class="segment-info" style="font-size: 1.1em; margin-bottom: 20px;">' . implode(' • ', $segmentInfo) . '</div>';
        
        $mapId = 'pvmap-segment-' . $s;
        $geoForClient = ($segmentData && !empty($segmentData['coords'])) ? $segmentData['coords'] : $pts;
        $dataGeo = htmlspecialchars(json_encode($geoForClient), ENT_QUOTES);
        echo '<div class="map-wrapper">';
        echo '<div id="' . $mapId . '" class="pv-leaflet" style="height: ' . $mapHeight . ';" data-geo=\'' . $dataGeo . '\'></div>';
        echo '</div>';
        
        echo '</div></div></div>'; // .segment, .printout-container, .page
        
        // Detail pages (if not "only overview" mode)
        if (!$showDetailsOnly && $segmentData && !empty($segmentData['coords']) && count($segmentData['coords']) >= 3) {
            $fullGeo = $segmentData['coords'];
            $indices = detect_turn_indices($fullGeo, 35); // Higher threshold for significant turns
            
            // Limit to max 6 detail pages per segment
            $detailCount = 0;
            $maxDetails = 6;
            
            if (count($indices) > 0) {
                // Pick spaced indices
                $centers = [];
                $last = -999;
                foreach ($indices as $idx) {
                    if ($detailCount >= $maxDetails) break;
                    if ($idx - $last < 15) continue; // Space them out
                    $centers[] = $idx;
                    $last = $idx;
                    $detailCount++;
                }
                
                foreach ($centers as $ci) {
                    $window = 40; // Show 40 points before and after turn
                    $start = max(0, $ci - $window);
                    $end = min(count($fullGeo)-1, $ci + $window);
                    $subset = array_slice($fullGeo, $start, $end - $start + 1);
                    if (count($subset) === 0) continue;
                    
                    $dataGeo = htmlspecialchars(json_encode($subset), ENT_QUOTES);
                    $mapId = 'pvdetail-' . $s . '-' . $ci;
                    
                    echo '<div class="' . $pageClass . ' a4-overview">';
                    echo '<div class="printout-container">';
                    echo '<h3>Detail: ' . $segmentTitle . '</h3>';
                    echo '<div id="' . $mapId . '" class="pv-leaflet" style="height: ' . $mapHeight . ';" data-geo=\'' . $dataGeo . '\'></div>';
                    echo '</div></div>';
                }
            }
        }
    }
}
?>

<script>
document.addEventListener("DOMContentLoaded", function(){
    var elems = document.querySelectorAll(".pv-leaflet");
    elems.forEach(function(el){
        try {
            var rawGeo = el.getAttribute('data-geo');
            var latlngs = [];
            
            if (rawGeo) {
                try {
                    var parsed = JSON.parse(rawGeo);
                    latlngs = parsed.map(function(p){ 
                        return [parseFloat(p.latitude), parseFloat(p.longitude)]; 
                    });
                } catch(e) { 
                    console.error('Failed to parse geometry:', e);
                    latlngs = [];
                }
            }

            if (!latlngs || latlngs.length < 2) {
                console.warn('No valid coordinates for map element', el.id);
                return;
            }

            var map = L.map(el, { 
                zoomControl: false, 
                attributionControl: false, 
                dragging: false, 
                scrollWheelZoom: false, 
                doubleClickZoom: false, 
                boxZoom: false, 
                keyboard: false, 
                touchZoom: false 
            });
            
            var tile = L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", { 
                maxZoom: 19 
            });
            tile.addTo(map);

            var poly = L.polyline(latlngs, { 
                color: '#00b7ff', 
                weight: 4, 
                interactive: false 
            }).addTo(map);
            
            // Add markers for start and end
            if (latlngs.length >= 2) {
                L.marker(latlngs[0], {
                    icon: L.divIcon({
                        className: 'start-marker',
                        html: '<div style="background: #22c55e; width: 12px; height: 12px; border-radius: 50%; border: 2px solid white;"></div>',
                        iconSize: [12, 12]
                    })
                }).addTo(map);
                
                L.marker(latlngs[latlngs.length - 1], {
                    icon: L.divIcon({
                        className: 'end-marker',
                        html: '<div style="background: #ef4444; width: 12px; height: 12px; border-radius: 50%; border: 2px solid white;"></div>',
                        iconSize: [12, 12]
                    })
                }).addTo(map);
            }
            
            // Force map to recalculate size before fitting bounds
            setTimeout(function() {
                map.invalidateSize();
                try { 
                    var bounds = poly.getBounds();
                    map.fitBounds(bounds, { 
                        padding: [50, 50],
                        maxZoom: 14
                    }); 
                } catch(e) { 
                    console.error('Failed to fit bounds:', e);
                    if (latlngs.length > 0) {
                        map.setView(latlngs[Math.floor(latlngs.length / 2)], 10); 
                    }
                }
            }, 100);
        } catch(e) { 
            console.error('Map initialization error:', e); 
        }
    });
});
</script>

<?php if (file_exists(__DIR__ . '/includes/footer.php')) { require_once __DIR__ . '/includes/footer.php'; } ?>
