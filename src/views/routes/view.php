<?php
// This file displays the details of a specific route.

// Include necessary files (use __DIR__ to avoid broken relative includes when executed from different CWDs)
require_once __DIR__ . '/../../helpers/url.php';
require_once __DIR__ . '/../../helpers/i18n.php';
require_once __DIR__ . '/../../helpers/utils.php';
require_once __DIR__ . '/../../models/Route.php';
require_once __DIR__ . '/../../controllers/RouteController.php';

// Inject Leaflet CSS and MarkerCluster CSS into the head
$HEAD_EXTRA = '';
$HEAD_EXTRA .= '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">';
$HEAD_EXTRA .= '<link rel="stylesheet" href="' . asset_url('assets/vendor/leaflet.markercluster/MarkerCluster.css') . '">';
$HEAD_EXTRA .= '<link rel="stylesheet" href="' . asset_url('assets/vendor/leaflet.markercluster/MarkerCluster.Default.css') . '">';
$HEAD_EXTRA .= '<link rel="stylesheet" href="' . asset_url('assets/vendor/flatpickr/flatpickr.min.css') . '" crossorigin="">';

// Create an instance of RouteController
$routeController = new RouteController();

// Get the route ID from the URL
$routeId = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Fetch the route details
$route = $routeController->viewRoute($routeId);

// Check if the route exists
if (!$route) {
    echo '<h1>' . htmlspecialchars(t('route_not_found', 'Route not found')) . '</h1>';
    exit;
}

// Display route details
?>
<?php
// Set appBase for frontend assets - MUST be global so header.php can use it
global $appBase;
if (!function_exists('config')) {
    require_once __DIR__ . '/../../config/app.php';
}
$appBase = config('app.base') ?? '/Allgemein/planvoyage/src';

// Load required helpers
if (file_exists(__DIR__ . '/../../config/env.php')) {
    require_once __DIR__ . '/../../config/env.php';
}
if (file_exists(__DIR__ . '/../../helpers/i18n.php')) {
    require_once __DIR__ . '/../../helpers/i18n.php';
}
if (file_exists(__DIR__ . '/../../helpers/session.php')) {
    require_once __DIR__ . '/../../helpers/session.php';
    start_secure_session();
}
if (file_exists(__DIR__ . '/../../helpers/auth.php')) {
    require_once __DIR__ . '/../../helpers/auth.php';
}
include __DIR__ . '/../../includes/header.php';
?>
<?php
// header.php already renders the navigation template; do not include it again here.
?>
<?php $flashOk = flash_get('success');
if ($flashOk): ?>
    <div class="flash-success"><?php echo htmlspecialchars($flashOk); ?></div>
<?php endif; ?>
<?php $flashErr = flash_get('error');
if ($flashErr): ?>
    <div class="error"><?php echo htmlspecialchars($flashErr); ?></div>
<?php endif; ?>

<main>
    <section class="trip-header">
        <h1><?php echo htmlspecialchars($route->name); ?></h1>
        <div class="trip-metadata">
            <strong><?php echo htmlspecialchars(t('route_start_date', 'Start Date:')); ?></strong> <?php echo htmlspecialchars($route->start_date); ?> &nbsp; • &nbsp;
            <strong><?php echo htmlspecialchars(t('route_end_date', 'End Date:')); ?></strong> <?php echo htmlspecialchars($route->end_date); ?>
            <div class="margin-top-medium"><?php echo nl2br(htmlspecialchars($route->description)); ?></div>
        </div>
    </section>
    <!-- Leaflet Map & Filter UI -->
    <div class="margin-bottom-large">
        <div class="route-controls">
            <div class="flex-row gap-large">
                <div>
                    <label for="country-filter"><strong><?php echo htmlspecialchars(t('country_label', 'Country:')); ?></strong></label>
                    <select id="country-filter"><option value=""><?php echo htmlspecialchars(t('filter_all', 'All')); ?></option></select>
                </div>
                <div>
                    <label for="state-filter"><strong><?php echo htmlspecialchars(t('state_label', 'State:')); ?></strong></label>
                    <select id="state-filter"><option value=""><?php echo htmlspecialchars(t('filter_all', 'All')); ?></option></select>
                </div>
            </div>
        </div>
        <div id="route-map" class="route-map"></div>
    </div>
    
        <!-- Quick Map Planner -->
        <section id="quick-planner">
            <h3><?php echo htmlspecialchars(t('quick_map_planner', 'Quick map planner')); ?></h3>
            <p><?php echo htmlspecialchars(t('planner_description', 'The quick planner follows the order of trip items. Move items to reorder. When ready, press Calc Route to compute the route; Export GPX downloads the route.')); ?></p>
            
            <div>
                <!-- Controls Panel -->
                <div class="planner-controls">
                    <div>
                        <label for="planner-speed"><?php echo htmlspecialchars(t('avg_speed_label', 'Avg Speed:')); ?></label>
                        <select id="planner-speed">
                            <option value="80"><?php echo htmlspecialchars(t('speed_80', '80 km/h')); ?></option>
                            <option value="100" selected><?php echo htmlspecialchars(t('speed_100', '100 km/h')); ?></option>
                            <option value="120"><?php echo htmlspecialchars(t('speed_120', '120 km/h')); ?></option>
                        </select>
                    </div>
                    <button id="planner-calc" class="btn">
                        <?php echo htmlspecialchars(t('planner_calc_route', 'Calc Route')); ?>
                    </button>
                    <button id="planner-export" class="btn">
                        <?php echo htmlspecialchars(t('planner_export_gpx', 'Export GPX')); ?>
                    </button>
                    <div class="planner-stats">
                        <div id="poi-count">POIs: <strong>0</strong></div>
                        <div id="leg-count">Legs: <strong>0</strong></div>
                    </div>
                </div>
                
                <!-- Summary Panel -->
                <div class="planner-summary-container">
                    <div class="planner-summary-header">
                        <?php echo htmlspecialchars(t('planner_summary', 'Route Summary')); ?>
                    </div>
                    <div id="planner-summary">
                        <div id="planner-distance">
                            <?php echo htmlspecialchars(t('planner_total', 'Total Distance:')); ?> <span>—</span>
                        </div>
                        <div id="planner-duration">
                            <?php echo htmlspecialchars(t('planner_eta', 'Est. Duration:')); ?> <span>—</span>
                        </div>
                        <div id="planner-legs"></div>
                    </div>
                </div>
            </div>
        </section>
    <script src="<?php echo asset_url('assets/vendor/leaflet/leaflet.js'); ?>" crossorigin=""></script>
    <script src="<?php echo asset_url('assets/vendor/leaflet.markercluster/leaflet.markercluster.js'); ?>" crossorigin=""></script>
    <link rel="stylesheet" href="<?php echo asset_url('assets/vendor/leaflet/leaflet.css'); ?>" crossorigin="">
    <?php
    // POIs for the map: use route items; build global country/state lists from MySQL so filters show available values
    $mapItems = [];
$countries = [];
$states = [];
if (!empty($route->items)) {
    foreach ($route->items as $it) {
        $lat = isset($it['latitude']) ? (float)$it['latitude'] : null;
        $lon = isset($it['longitude']) ? (float)$it['longitude'] : null;
        // Always include a map item entry if we have at least an id; coordinates may be null.
        $mapItems[] = [
            'id' => (int)($it['item_id'] ?? 0),
            'location_id' => isset($it['location_id']) ? (int)$it['location_id'] : null,
            'name' => $it['location_name'] ?? ('Location #' . (isset($it['location_id']) ? $it['location_id'] : '')),
            'type' => $it['location_type'] ?? '',
            'country' => $it['country'] ?? '',
            'state' => $it['state'] ?? '',
            'logo' => $it['logo'] ?? '',
            'lat' => $lat,
            'lon' => $lon
        ];
            // Collect unique countries and states from route items
            if (!empty($it['country']) && !in_array($it['country'], $countries, true)) {
                $countries[] = $it['country'];
            }
            if (!empty($it['state']) && !in_array($it['state'], $states, true)) {
                $states[] = $it['state'];
            }
        }
    }
// Load distinct countries and states from locations favorited by the current user
try {
    require_once __DIR__ . '/../../config/mysql.php';
    $db = get_db();
    $userId = $_SESSION['user_id'] ?? null;
    if ($userId) {
        // Prefer values only from favorites mapping for this user
        $cStmt = $db->prepare("SELECT DISTINCT l.country FROM favorites f JOIN locations l ON l.id = f.location_id WHERE l.country IS NOT NULL AND l.country != '' AND f.user_id = :uid ORDER BY l.country ASC");
        $cStmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $cStmt->execute();
        $cRows = $cStmt->fetchAll(PDO::FETCH_COLUMN);

        $sStmt = $db->prepare("SELECT DISTINCT l.state FROM favorites f JOIN locations l ON l.id = f.location_id WHERE l.state IS NOT NULL AND l.state != '' AND f.user_id = :uid ORDER BY l.state ASC");
        $sStmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $sStmt->execute();
        $sRows = $sStmt->fetchAll(PDO::FETCH_COLUMN);

        // Merge with route items countries/states (route items remain first)
        foreach ($cRows as $c) {
            if (!in_array($c, $countries, true)) {
                $countries[] = $c;
            }
        }
        foreach ($sRows as $s) {
            if (!in_array($s, $states, true)) {
                $states[] = $s;
            }
        }
    }
    sort($countries);
    sort($states);
} catch (Exception $e) {
    // If DB fails, at least we have the route items countries/states
    sort($countries);
    sort($states);
}
?>
    <script>
    var routePOIs = <?php echo json_encode($mapItems, JSON_UNESCAPED_UNICODE); ?>;
    var countries = <?php echo json_encode($countries, JSON_UNESCAPED_UNICODE); ?>;
    var states = <?php echo json_encode($states, JSON_UNESCAPED_UNICODE); ?>;
    var filterAllLabel = <?php echo json_encode(t('filter_all', 'All')); ?>;
    var locTypeLabel = <?php echo json_encode(t('type_label', 'Type:')); ?>;
    var locLogoLabel = <?php echo json_encode(t('logo_label', 'Logo:')); ?>;
    var locCountryLabel = <?php echo json_encode(t('country_label', 'Country:')); ?>;
    var locStateLabel = <?php echo json_encode(t('state_label', 'State:')); ?>;
    var locNotEnoughWaypoints = <?php echo json_encode(t('not_enough_waypoints', 'Not enough waypoints for routing')); ?>;
    var locRoutingFailed = <?php echo json_encode(t('routing_failed', 'Routing failed')); ?>;
    var locNoRouteReturned = <?php echo json_encode(t('no_route_returned', 'No route returned')); ?>;
    var locSaveFailed = <?php echo json_encode(t('save_failed', 'Save failed')); ?>;
    var locSaveError = <?php echo json_encode(t('save_error', 'Save error')); ?>;
    var locOsrmRequestFailed = <?php echo json_encode(t('osrm_request_failed', 'OSRM request failed')); ?>;
    var locFailedToAddItem = <?php echo json_encode(t('failed_to_add_item', 'Failed to add item')); ?>;
    var locFailedToRemoveItem = <?php echo json_encode(t('failed_to_remove_item', 'Failed to remove item')); ?>;
    var locFailedToSaveOrder = <?php echo json_encode(t('failed_to_save_order', 'Failed to save new order')); ?>;
    var locNetworkErrorSavingOrder = <?php echo json_encode(t('network_error_saving_order', 'Network error saving order')); ?>;
    var locGenericError = <?php echo json_encode(t('generic_error', 'Error')); ?>;
    var locLoading = <?php echo json_encode(t('loading', 'Loading...')); ?>;
    var locPleaseSelectTypeFirst = <?php echo json_encode(t('please_select_type_first', 'Please select a type first')); ?>;
    var locConfirmRemove = <?php echo json_encode(t('confirm_remove_item', 'Remove this item from the trip?')); ?>;
    var locGotoLabel = <?php echo json_encode(t('goto', 'Goto')); ?>;
    var locNoPoisAddedTrip = <?php echo json_encode(t('no_pois_added_trip', 'No POIs have been added to this trip yet.')); ?>;
    var locRemoveLabel = <?php echo json_encode(t('remove', 'Remove')); ?>;
    
    /* Country code translations */
    var countryLabels = {
        'CA': <?php echo json_encode(t('country_CA', 'Canada')); ?>,
        'US': <?php echo json_encode(t('country_US', 'United States')); ?>,
        'MX': <?php echo json_encode(t('country_MX', 'Mexico')); ?>,
        'GB': <?php echo json_encode(t('country_GB', 'United Kingdom')); ?>,
        'FR': <?php echo json_encode(t('country_FR', 'France')); ?>,
        'DE': <?php echo json_encode(t('country_DE', 'Germany')); ?>,
        'IT': <?php echo json_encode(t('country_IT', 'Italy')); ?>,
        'ES': <?php echo json_encode(t('country_ES', 'Spain')); ?>,
        'AT': <?php echo json_encode(t('country_AT', 'Austria')); ?>,
        'CH': <?php echo json_encode(t('country_CH', 'Switzerland')); ?>,
        'NL': <?php echo json_encode(t('country_NL', 'Netherlands')); ?>,
        'BE': <?php echo json_encode(t('country_BE', 'Belgium')); ?>,
        'LU': <?php echo json_encode(t('country_LU', 'Luxembourg')); ?>,
        'PT': <?php echo json_encode(t('country_PT', 'Portugal')); ?>,
        'GR': <?php echo json_encode(t('country_GR', 'Greece')); ?>,
        'SE': <?php echo json_encode(t('country_SE', 'Sweden')); ?>,
        'NO': <?php echo json_encode(t('country_NO', 'Norway')); ?>,
        'DK': <?php echo json_encode(t('country_DK', 'Denmark')); ?>,
        'FI': <?php echo json_encode(t('country_FI', 'Finland')); ?>,
        'PL': <?php echo json_encode(t('country_PL', 'Poland')); ?>,
        'CZ': <?php echo json_encode(t('country_CZ', 'Czech Republic')); ?>,
        'SK': <?php echo json_encode(t('country_SK', 'Slovakia')); ?>,
        'HU': <?php echo json_encode(t('country_HU', 'Hungary')); ?>,
        'RO': <?php echo json_encode(t('country_RO', 'Romania')); ?>,
        'BG': <?php echo json_encode(t('country_BG', 'Bulgaria')); ?>,
        'GE': <?php echo json_encode(t('country_GE', 'Georgia')); ?>,
        'RU': <?php echo json_encode(t('country_RU', 'Russia')); ?>,
        'UA': <?php echo json_encode(t('country_UA', 'Ukraine')); ?>,
        'JP': <?php echo json_encode(t('country_JP', 'Japan')); ?>,
        'CN': <?php echo json_encode(t('country_CN', 'China')); ?>,
        'IN': <?php echo json_encode(t('country_IN', 'India')); ?>,
        'AU': <?php echo json_encode(t('country_AU', 'Australia')); ?>,
        'NZ': <?php echo json_encode(t('country_NZ', 'New Zealand')); ?>,
        'BR': <?php echo json_encode(t('country_BR', 'Brazil')); ?>,
        'AR': <?php echo json_encode(t('country_AR', 'Argentina')); ?>,
        'ZA': <?php echo json_encode(t('country_ZA', 'South Africa')); ?>
    };
    
    // Update stats display with current POI and leg counts
    function updateStats() {
        var poiCountEl = document.getElementById('poi-count');
        var legCountEl = document.getElementById('leg-count');
        if (poiCountEl && window.routePOIs && Array.isArray(window.routePOIs)) {
            poiCountEl.querySelector('strong').textContent = window.routePOIs.length;
        }
        if (legCountEl) {
            var legCount = 0;
            var list = document.querySelector('.route-items-list');
            if (list) {
                legCount = list.querySelectorAll('li').length;
            }
            legCountEl.querySelector('strong').textContent = legCount;
        }
    }
    
    window.addEventListener('DOMContentLoaded', function(){
        // Update stats on page load
        updateStats();
        
        var countrySel = document.getElementById('country-filter');
            countries.forEach(function(c){
                var opt = document.createElement('option'); 
                opt.value = c; 
                opt.textContent = countryLabels[c] || c;  /* Use translation or fallback to code */
                countrySel.appendChild(opt);
            });
        var stateSel = document.getElementById('state-filter');
        states.forEach(function(s){
            var opt = document.createElement('option'); opt.value = s; opt.textContent = s; stateSel.appendChild(opt);
        });

        // When country changes: fetch states for that country, fit map to country bbox, and reload location dropdown
        function fetchStatesForCountry(country) {
            if (!country) {
                // restore all states
                stateSel.innerHTML = '<option value="">'+filterAllLabel+'<\/option>';
                states.forEach(function(s){ var o = document.createElement('option'); o.value = s; o.textContent = s; stateSel.appendChild(o); });
                return;
            }
            fetch('<?php echo htmlspecialchars(api_base_url() . '/locations/distinct.php'); ?>?field=state&country='+encodeURIComponent(country))
                .then(function(r){ if (!r.ok) throw new Error('network'); return r.json(); })
                .then(function(js){
                    stateSel.innerHTML = '<option value="">'+filterAllLabel+'<\/option>';
                    if (js && Array.isArray(js.data)) js.data.forEach(function(s){ var o = document.createElement('option'); o.value = s; o.textContent = s; stateSel.appendChild(o); });
                }).catch(function(){ stateSel.innerHTML = '<option value="">'+filterAllLabel+'<\/option>'; });
        }

        function zoomTo(country, state) {
            var q = '';
            if (country) q += 'country=' + encodeURIComponent(country);
            if (state) q += (q ? '&' : '') + 'state=' + encodeURIComponent(state);
            fetch('<?php echo htmlspecialchars(api_base_url() . '/locations/bbox.php'); ?>?' + q)
                .then(function(r){ if (!r.ok) throw new Error('network'); return r.json(); })
                .then(function(js){
                    if (js && js.ok) {
                        var sw = [js.minLat, js.minLon];
                        var ne = [js.maxLat, js.maxLon];
                        map.fitBounds([sw, ne], {padding:[20,20]});
                    }
                }).catch(function(err){ console.warn('bbox fetch failed', err); });
        }
        var iconsBase = '<?php echo htmlspecialchars(asset_url('assets/icons/')); ?>';
        // make available to other scripts
        window.iconsBase = iconsBase;
        var map = L.map('route-map').setView([51,10], 5);
        // expose for planner code
        window.routeMap = map;
        // Ensure map tiles render correctly after initialization
        setTimeout(function() { if (map) map.invalidateSize(); }, 100);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OSM contributors' }).addTo(map);
        var markers = [];
        function updateMarkers() {
            markers.forEach(function(m){ map.removeLayer(m); });
            markers = [];
            // If the planner UI is present, planner code will create numbered markers for trip items.
            // Avoid adding duplicate simple POI markers in that case.
            if (document.getElementById('quick-planner')) {
                console.log('Planner present — skipping simple routePOIs markers to avoid duplicate icons');
                return;
            }
            // Always show markers for trip items (routePOIs) regardless of active country/state filters
            var bounds = [];
            routePOIs.forEach(function(p){
                var logoFile = (p.logo && p.logo !== '') ? p.logo : 'poi.png';
                var icon = L.icon({
                    iconUrl: iconsBase + logoFile,
                    iconSize: [28,28],
                    iconAnchor: [14,28],
                    popupAnchor: [0,-28]
                });
                var marker = L.marker([p.lat, p.lon], {icon: icon}).addTo(map);
                marker.bindPopup('<strong>'+p.name+'</strong><br>'+locTypeLabel+' '+p.type+'<br>'+locCountryLabel+' '+p.country+'<br>'+locStateLabel+' '+p.state + '<br><img src="' + iconsBase + logoFile + '" class="marker-popup-image" />');
                markers.push(marker);
                bounds.push([p.lat, p.lon]);
            });
            if (bounds.length) map.fitBounds(bounds, {padding:[30,30]});
        }
        countrySel.addEventListener('change', function(){
            var c = countrySel.value;
            fetchStatesForCountry(c);
            // reload locations dropdown when country changed
            var evt = new Event('change');
            document.getElementById('poi_type_filter') && document.getElementById('poi_type_filter').dispatchEvent(evt);
            zoomTo(c, stateSel.value || '');
            updateMarkers();
        });
        stateSel.addEventListener('change', function(){
            var s = stateSel.value;
            // reload locations with new state filter
            var evt = new Event('change');
            document.getElementById('poi_type_filter') && document.getElementById('poi_type_filter').dispatchEvent(evt);
            zoomTo(countrySel.value || '', s);
            updateMarkers();
        });
        // Persist filters so adding a POI doesn't reset them on reload
        var filterStorageKey = 'tpv_filters_<?php echo (int)$route->id; ?>';
        function saveFilters() {
            try {
                var obj = { country: countrySel.value || '', state: stateSel.value || '' };
                localStorage.setItem(filterStorageKey, JSON.stringify(obj));
            } catch (e) { /* ignore */ }
        }
        function loadFilters() {
            try {
                var raw = localStorage.getItem(filterStorageKey);
                if (!raw) return;
                var obj = JSON.parse(raw || '{}');
                if (obj.country) {
                    countrySel.value = obj.country;
                    fetchStatesForCountry(obj.country);
                }
                // wait a tick to ensure states loaded
                setTimeout(function(){ if (obj.state) stateSel.value = obj.state; updateMarkers(); }, 250);
            } catch (e) { /* ignore */ }
        }
        countrySel.addEventListener('change', saveFilters);
        stateSel.addEventListener('change', saveFilters);
        // restore any saved filters
        loadFilters();
        updateMarkers();
    });
    </script>


    <script>
    // Quick Map Planner client-side implementation
    (function(){
        var routeId = <?php echo (int)$route->id; ?>;
        var storageKey = 'tpv_planner_' + routeId;
        var map = window.routeMap || null;
        // helper: wait until `window.routeMap` is available, then call cb
        function waitForMap(cb){
            if (window.routeMap) { map = window.routeMap; try{ cb(); }catch(e){} return; }
            var tries = 0;
            var iv = setInterval(function(){
                if (window.routeMap) { map = window.routeMap; clearInterval(iv); try{ cb(); }catch(e){} }
                if (++tries > 50) { clearInterval(iv); console.warn('waitForMap: giving up after retries'); try{ cb(); }catch(e){} }
            }, 100);
        }
        var wpListEl = document.getElementById('planner-waypoints');
        // If the planner waypoint list is removed from the UI, guard usage (planner follows Trip Items)
        var summaryDist = document.getElementById('planner-distance');
        var summaryDur = document.getElementById('planner-duration');
        var waypoints = []; // {lat,lon,label,marker}
        var plannerLine = null;
        var routeLayer = null; // OSRM returned route layer

        function haversine(a,b){
            var R = 6371; // km
            var dLat = (b.lat-a.lat) * Math.PI/180;
            var dLon = (b.lon-a.lon) * Math.PI/180;
            var lat1 = a.lat * Math.PI/180;
            var lat2 = b.lat * Math.PI/180;
            var sinDlat = Math.sin(dLat/2), sinDlon = Math.sin(dLon/2);
            var c = 2 * Math.atan2(Math.sqrt(sinDlat*sinDlat + Math.cos(lat1)*Math.cos(lat2)*sinDlon*sinDlon), Math.sqrt(1 - (sinDlat*sinDlat + Math.cos(lat1)*Math.cos(lat2)*sinDlon*sinDlon)));
            return R * c; // km
        }

        function renderWaypoints(){
            // Planner no longer shows an editable waypoint list in the Quick Planner UI.
            // The planner respects the Trip Items list and order; this function is a no-op when the UI list is removed.
            if (!wpListEl) return;
            try{ wpListEl.innerHTML = ''; }catch(e){}
        }

        function getThemeColor(varName, fallback) {
            try {
                var value = getComputedStyle(document.documentElement).getPropertyValue('--' + varName).trim();
                return value || fallback;
            } catch (e) {
                return fallback;
            }
        }

        function updateLine(){
            if (plannerLine && map) { try{ map.removeLayer(plannerLine); }catch(e){} plannerLine = null; }
            var coords = waypoints.map(function(w){ return [w.lat,w.lon]; });
            if (coords.length>0 && map){ plannerLine = L.polyline(coords,{color: getThemeColor('map-primary-line', '#4da6ff')}).addTo(map); try{ map.fitBounds(plannerLine.getBounds(),{padding:[20,20]}); }catch(e){} }
        }

        function savePlanner(){
            try{ var payload = waypoints.map(function(w){ return {lat:w.lat,lon:w.lon,label:w.label||'', location_id: w.location_id || null}; }); localStorage.setItem(storageKey, JSON.stringify(payload)); }catch(e){}
        }
        // Build planner waypoints from Trip Items DOM list in the current order
        function rebuildPlannerFromTripItems(){
            try{
                // clear existing markers and waypoints
                waypoints.forEach(function(w){ if (w.marker) { try{ map.removeLayer(w.marker); }catch(e){} } });
                waypoints = [];
                var list = document.getElementById('route-items');
                if (!list) return;
                // map of routePOIs keyed by item id
                var poiMap = {};
                if (window.routePOIs && Array.isArray(window.routePOIs)){
                    window.routePOIs.forEach(function(p){
                        try {
                            var pid = Number(p.id || 0);
                            var lid = Number(p.location_id || 0);
                            if (pid > 0) poiMap[String(pid)] = p;
                            if (lid > 0) poiMap[String(lid)] = p;
                        } catch (e) { /* ignore invalid entries */ }
                    });
                }
                // Track seen location_ids and coordinate pairs to avoid duplicate planner markers
                var seenLocationIds = new Set();
                var seenCoords = new Set();
                Array.from(list.querySelectorAll('li')).forEach(function(li){
                    var itemId = parseInt(li.getAttribute('data-item-id') || '0', 10);
                    var locId = parseInt(li.getAttribute('data-location-id') || '0', 10);
                    var p = null;
                    // Prefer matching by location_id when available and valid to avoid using placeholder item ids (0)
                    if (locId > 0 && poiMap.hasOwnProperty(String(locId))) p = poiMap[String(locId)];
                    // fallback: try matching by item id
                    if (!p && itemId > 0 && poiMap.hasOwnProperty(String(itemId))) p = poiMap[String(itemId)];
                    // final fallback: try to match by visible POI name
                    if (!p) {
                        var nameEl = li.querySelector('.poi-name');
                        var nm = nameEl ? nameEl.textContent.trim() : '';
                        if (nm) {
                            for (var k in poiMap) {
                                if (poiMap.hasOwnProperty(k) && poiMap[k].name && poiMap[k].name.toString().trim() === nm) { p = poiMap[k]; break; }
                            }
                        }
                    }
                    if (p && p.lat && p.lon) {
                        // dedupe by explicit location_id when present
                        var lidKey = (p.location_id && Number(p.location_id) > 0) ? String(p.location_id) : null;
                        var coordKey = String(Number(p.lat).toFixed(6)) + ':' + String(Number(p.lon).toFixed(6));
                        if (lidKey && seenLocationIds.has(lidKey)) {
                            return; // skip duplicate location
                        }
                        if (seenCoords.has(coordKey)) {
                            return; // skip duplicate coordinate
                        }
                        if (lidKey) seenLocationIds.add(lidKey);
                        seenCoords.add(coordKey);
                        addWaypoint(p.lat, p.lon, p.name || '', false, { location_id: p.location_id || null, logo: p.logo || '' });
                    }
                });
                try {
                    var ids = Array.from(list.querySelectorAll('li')).map(function(li){ return li.getAttribute('data-item-id'); });
                    var lids = Array.from(list.querySelectorAll('li')).map(function(li){ return li.getAttribute('data-location-id'); });
                    console.debug('rebuildPlannerFromTripItems built waypoints for itemIds:', ids, 'locationIds:', lids, 'waypoints:', waypoints.map(function(w){return {location_id:w.location_id, label:w.label};}));
                } catch(e){}
                saveAndRedraw();
            }catch(e){ console.warn('rebuildPlannerFromTripItems failed', e); }
        }

        function createPlannerMarker(w, index){
            var logoFile = (w.logo && w.logo !== '') ? w.logo : 'poi.png';
            var html = '<div class="planner-marker"><img src="'+ (window.iconsBase || '') + logoFile +'" alt="marker"/>' +
                       '<div class="planner-num">'+ (index+1) +'</div></div>';
            var icon = L.divIcon({ className: '', html: html, iconSize: [36,36], iconAnchor: [18,36], popupAnchor: [0,-34] });
            var m = L.marker([w.lat,w.lon], { icon: icon });
            m.bindPopup('<strong>'+(w.label || (w.lat.toFixed(5)+', '+w.lon.toFixed(5)))+'</strong>');
            return m;
        }

        function saveAndRedraw(){
            // remove markers, re-add
            waypoints.forEach(function(w){ if (w.marker && map) { try{ map.removeLayer(w.marker); }catch(e){} } delete w.marker; });
            waypoints.forEach(function(w,i){ var m = createPlannerMarker(w, i); if (map && m && typeof m.addTo === 'function') { w.marker = m.addTo(map); } else { w.marker = null; } });
            renderWaypoints(); updateLine(); savePlanner(); updateSummary();
        }

        function removeWaypoint(idx){
            var w = waypoints.splice(idx,1)[0]; if (w && w.marker) { try{ map.removeLayer(w.marker); }catch(e){} }
            saveAndRedraw();
        }

        function addWaypoint(lat,lon,label,save,opts){
            opts = opts || {};
            var w = {lat:parseFloat(lat), lon:parseFloat(lon), label: label || '', location_id: opts.location_id || null, logo: opts.logo || ''};
            var m = createPlannerMarker(w, waypoints.length);
            if (map && m && typeof m.addTo === 'function') { w.marker = m.addTo(map); } else { w.marker = null; }
            waypoints.push(w);
            renderWaypoints(); updateLine(); if (save !== false) savePlanner(); updateSummary();
        }

        // planner derives waypoints from Trip Items list; disable adding arbitrary waypoints by map click
        // map.on('click', function(ev){ addWaypoint(ev.latlng.lat, ev.latlng.lng, '', true); });

        // calc route: compute straight-line distances; calculating optimized order is separate
        // calcRoute(options): options = { save: true|false } — when save is false, do not POST back to save_planner
        function calcRoute(options){
            options = options || {};
            var doSave = (typeof options.save === 'undefined') ? true : !!options.save;
            // Ensure waypoints reflect the current Trip Items order before routing
            try { if (typeof rebuildPlannerFromTripItems === 'function') rebuildPlannerFromTripItems(); } catch(e) { console.warn('rebuildPlannerFromTripItems failed at calcRoute start', e); }
            console.log('calcRoute invoked', {waypointsCount: waypoints.length, waypoints: waypoints, options: options});
            if (waypoints.length<2) { console.warn('Not enough waypoints:', locNotEnoughWaypoints, 'waypoints.length=', waypoints.length); return; }
            var coords = waypoints.map(function(w){ return {lat: w.lat, lon: w.lon}; });
            console.log('Sending coords to OSRM:', JSON.stringify(coords));
            var osrmUrl = '<?php echo htmlspecialchars(api_base_url() . '/route/osrm_route.php'); ?>';
            // Call OSRM proxy
            fetch(osrmUrl, { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({coordinates: coords}) })
                .then(function(r) {
                    if (!r.ok) throw new Error('OSRM proxy returned HTTP ' + r.status);
                    return r.text().then(function(text){
                        try { return JSON.parse(text); }
                        catch(e){ console.error('Failed to parse OSRM JSON response', text); throw e; }
                    });
                })
                .then(function(js){
                    console.log('OSRM proxy response', js);
                    if (!js || !js.ok || !js.osrm) { try{ alert(locRoutingFailed + (js && js.err ? (': '+js.err) : '')); }catch(e){ alert(locRoutingFailed); } return; }
                    var os = js.osrm;
                    if (!os.routes || !os.routes.length) { alert(locNoRouteReturned); return; }
                    var route = os.routes[0];
                    console.log('Route from OSRM:', {distance: route.distance, duration: route.duration, legsCount: route.legs ? route.legs.length : 0});
                    // remove old route layer(s)
                    if (routeLayer) { try{ if (map) map.removeLayer(routeLayer); } catch(e){} routeLayer = null; }
                    try {
                        // Prefer drawing each OSRM leg separately so each POI->POI leg can have its own color
                        var legLayers = [];
                        // helper: offset a LineString (array of [lon,lat]) by a small meters amount to draw parallel lines
                        // Two strategies:
                        // 1) If `map` (Leaflet) is available use pixel-space perpendicular offsets (visual-consistent across zooms).
                        // 2) Otherwise fall back to a per-point geographic offset approximation.
                        function offsetCoordinatesMap(coords, offsetM) {
                            if (!coords || coords.length === 0) return coords;
                            if (!window.routeMap || !window.routeMap.project) return coords;
                            var out = [];
                            var mapLocal = window.routeMap;
                            var zoom = mapLocal.getZoom();
                            // compute a stable global perpendicular direction using overall route bearing (first -> last)
                            var useGlobal = false;
                            var pxg = 0, pyg = 0;
                            try {
                                if (route && route.geometry && route.geometry.coordinates && route.geometry.coordinates.length >= 2) {
                                    var gf = route.geometry.coordinates[0];
                                    var gl = route.geometry.coordinates[route.geometry.coordinates.length - 1];
                                    var pF = mapLocal.latLngToLayerPoint(L.latLng(gf[1], gf[0]));
                                    var pL = mapLocal.latLngToLayerPoint(L.latLng(gl[1], gl[0]));
                                    var gx = pL.x - pF.x, gy = pL.y - pF.y;
                                    var gLen = Math.sqrt(gx * gx + gy * gy);
                                    if (gLen > 1e-6) { pxg = -gy / gLen; pyg = gx / gLen; useGlobal = true; }
                                }
                            } catch (e) {}
                            // compute pixel offset
                            var meanLat = 0, countLat = 0;
                            if (route && route.geometry && route.geometry.coordinates) { route.geometry.coordinates.forEach(function(c){ meanLat += c[1]; countLat++; }); meanLat = countLat?meanLat/countLat:0; }
                            var meanLatRad = meanLat * Math.PI/180.0;
                            var metersPerPixel = 40075016.686 * Math.cos(meanLatRad) / (256 * Math.pow(2, zoom));
                            var pixelOffset = offsetM / metersPerPixel;
                            for (var i = 0; i < coords.length; i++) {
                                try {
                                    var lon = coords[i][0], lat = coords[i][1];
                                    var p = mapLocal.latLngToLayerPoint(L.latLng(lat, lon));
                                    var px = pxg, py = pyg;
                                    if (!useGlobal) {
                                        var prev = coords[Math.max(0, i - 1)];
                                        var next = coords[Math.min(coords.length - 1, i + 1)];
                                        var pPrev = mapLocal.latLngToLayerPoint(L.latLng(prev[1], prev[0]));
                                        var pNext = mapLocal.latLngToLayerPoint(L.latLng(next[1], next[0]));
                                        var dx = pNext.x - pPrev.x, dy = pNext.y - pPrev.y;
                                        var len = Math.sqrt(dx*dx + dy*dy);
                                        if (len === 0) { out.push([lon, lat]); continue; }
                                        px = -dy / len; py = dx / len;
                                    }
                                    var newP = L.point(p.x + px * pixelOffset, p.y + py * pixelOffset);
                                    var nn = mapLocal.layerPointToLatLng(newP);
                                    out.push([nn.lng, nn.lat]);
                                } catch (e) { out.push(coords[i]); }
                            }
                            return out;
                        }

                        // fallback variable-offset in geographic degrees (older method)
                        function offsetCoordinatesVariable(coords, offsetM) {
                            if (!coords || coords.length === 0) return coords;
                            if (coords.length === 1) return coords.slice();
                            var out = [];
                            // try to compute a global perpendicular in geographic meters using route first->last
                            var useGlobal = false;
                            var gpx = 0, gpy = 0;
                            try {
                                if (route && route.geometry && route.geometry.coordinates && route.geometry.coordinates.length >= 2) {
                                    var gf = route.geometry.coordinates[0];
                                    var gl = route.geometry.coordinates[route.geometry.coordinates.length - 1];
                                    var meanLatRad = ((gf[1] + gl[1]) / 2) * Math.PI/180.0;
                                    var metersPerDegLat = 111320;
                                    var metersPerDegLon = 111320 * Math.cos(meanLatRad);
                                    var dxg = (gl[0] - gf[0]) * metersPerDegLon;
                                    var dyg = (gl[1] - gf[1]) * metersPerDegLat;
                                    var glen = Math.sqrt(dxg*dxg + dyg*dyg);
                                    if (glen > 1e-6) { gpx = -dyg / glen; gpy = dxg / glen; useGlobal = true; }
                                }
                            } catch (e) {}
                            for (var i = 0; i < coords.length; i++) {
                                var prev = coords[Math.max(0, i - 1)];
                                var next = coords[Math.min(coords.length - 1, i + 1)];
                                var lon = coords[i][0], lat = coords[i][1];
                                if (useGlobal) {
                                    var meanLatRad = ((prev[1] + next[1]) / 2) * Math.PI/180.0;
                                    var metersPerDegLat = 111320;
                                    var metersPerDegLon = 111320 * Math.cos(meanLatRad);
                                    var ex = gpx * offsetM; var ey = gpy * offsetM;
                                    var dLon = ex / metersPerDegLon; var dLat = ey / metersPerDegLat;
                                    out.push([lon + dLon, lat + dLat]);
                                    continue;
                                }
                                var meanLatRad = ((prev[1] + next[1]) / 2) * Math.PI/180.0;
                                var metersPerDegLat = 111320;
                                var metersPerDegLon = 111320 * Math.cos(meanLatRad);
                                var dx = (next[0] - prev[0]) * metersPerDegLon;
                                var dy = (next[1] - prev[1]) * metersPerDegLat;
                                var len = Math.sqrt(dx*dx + dy*dy);
                                if (len === 0) { out.push([lon,lat]); continue; }
                                var px = -dy / len; var py = dx / len; // perpendicular unit vector
                                var ex = px * offsetM; var ey = py * offsetM;
                                var dLon = ex / metersPerDegLon; var dLat = ey / metersPerDegLat;
                                out.push([lon + dLon, lat + dLat]);
                            }
                            return out;
                        }

                        if (route.legs && route.legs.length) {
                            var outlineArr = [];
                            var coloredArr = [];
                            var mainLegLayerByIdx = [];
                            route.legs.forEach(function(leg, idx){
                                try {
                                    console.log('Processing leg', idx, ':', {distance: leg.distance, duration: leg.duration, stepsCount: leg.steps ? leg.steps.length : 0});
                                    // build a GeoJSON LineString for this leg by concatenating step geometries
                                    var coords = [];
                                    if (Array.isArray(leg.steps) && leg.steps.length) {
                                        leg.steps.forEach(function(step){
                                            if (step.geometry && step.geometry.coordinates && Array.isArray(step.geometry.coordinates)) {
                                                coords = coords.concat(step.geometry.coordinates);
                                            }
                                        });
                                    }
                                    // fallback: if no step geometries, try to slice from full route geometry using leg indices
                                    if (coords.length === 0 && route.geometry && route.geometry.coordinates && Array.isArray(route.geometry.coordinates)) {
                                        // approximate: split full geometry evenly by leg count
                                        var all = route.geometry.coordinates;
                                        var from = Math.floor(idx * all.length / route.legs.length);
                                        var to = Math.floor((idx+1) * all.length / route.legs.length);
                                        coords = all.slice(from, Math.max(from+1,to));
                                    }
                                    // separation removed: draw legs on original geometry without lateral offsets
                                    var centerIndex = (route.legs.length - 1) / 2.0;
                                    var offsetMeters = 0;
                                    var shifted = coords;
                                    var legGeo = { type: 'Feature', geometry: { type: 'LineString', coordinates: shifted } };
                                    var hue = (idx * 47) % 360; // deterministic varied hues
                                    var color = 'hsl('+hue+',70%,40%)';
                                    // draw an outline behind the colored line so closely overlapping lines remain visible
                                    var outlineColor = getThemeColor('map-outline', '#ffffff');
                                    var outline = L.geoJSON(legGeo, { style: { color: outlineColor, weight: 14, opacity: 0.95, lineCap: 'round', lineJoin: 'round' } });
                                    var colored = L.geoJSON(legGeo, { style: { color: color, weight: 8, opacity: 0.98, lineCap: 'round', lineJoin: 'round' } });
                                    // collect instead of pushing immediately to control draw order
                                    outlineArr.push(outline);
                                    coloredArr.push({ layer: colored, idx: idx, offset: Math.abs(offsetMeters) });
                                    mainLegLayerByIdx[idx] = colored;
                                } catch(e) { console.warn('failed to build leg', idx, e); }
                            });
                            // push outlines first (so they form white gutters), then colored lines ordered by offset so inner lines draw last
                            // builds legLayers as combined array for L.layerGroup
                            var ordered = [];
                            outlineArr.forEach(function(o){ ordered.push(o); });
                            // sort colored by descending offset (outermost first) so center lines appear on top
                            coloredArr.sort(function(a,b){ return b.offset - a.offset; });
                            coloredArr.forEach(function(c){ ordered.push(c.layer); });
                            legLayers = ordered;
                        }
                        // If we didn't build per-leg layers, fall back to single full route geometry
                        if (legLayers.length === 0 && route.geometry) {
                            var fallbackColor = getThemeColor('map-fallback-line', '#ff6600');
                            var single = L.geoJSON(route.geometry, { style: {color: fallbackColor, weight: 5, opacity: 0.8} });
                            legLayers.push(single);
                        }
                        // group legs into one layer for easy removal/fitBounds
                        routeLayer = L.layerGroup(legLayers);
                        if (map && typeof routeLayer.addTo === 'function') routeLayer.addTo(map);
                        // build per-leg legend (distance/duration) if UI container exists
                        try {
                            var legsContainer = document.getElementById('planner-legs');
                            console.log('Rendering legs. legsContainer:', !!legsContainer, 'route.legs:', route.legs ? route.legs.length : 'none');
                            if (legsContainer) {
                                legsContainer.innerHTML = '';
                                if (route.legs && route.legs.length) {
                                    route.legs.forEach(function(leg, idx){
                                        try {
                                            console.log('Displaying leg', idx, 'with distance:', leg.distance, 'waypoints[', idx, ']:', waypoints[idx], 'waypoints[', (idx+1), ']:', waypoints[idx+1]);
                                            var hue = (idx * 47) % 360;
                                            var color = 'hsl('+hue+',70%,40%)';
                                            var fromLabel = (waypoints[idx] && (waypoints[idx].label || (waypoints[idx].lat.toFixed(5)+', '+waypoints[idx].lon.toFixed(5)))) || ('#'+(idx+1));
                                            var toLabel = (waypoints[idx+1] && (waypoints[idx+1].label || (waypoints[idx+1].lat.toFixed(5)+', '+waypoints[idx+1].lon.toFixed(5)))) || ('#'+(idx+2));
                                            var distKm = ((leg.distance || 0) / 1000.0).toFixed(2);
                                            var durSec = leg.duration || 0;
                                            var h = Math.floor(durSec / 3600);
                                            var m = Math.round((durSec % 3600) / 60);
                                            var entry = document.createElement('div'); entry.className = 'planner-leg';
                                            var info = document.createElement('div');
                                            info.innerHTML = '<strong>'+fromLabel+' → '+toLabel+'</strong><br/>'+distKm+' km, '+h+'h '+(m<10?('0'+m):m)+'m';
                                            var sw = document.createElement('span'); sw.className = 'leg-swatch'; sw.style.background = color;
                                            entry.appendChild(sw);
                                            entry.appendChild(info);
                                            // goto button
                                            var gotoBtn = document.createElement('button'); gotoBtn.type = 'button'; gotoBtn.style.marginLeft = '8px'; gotoBtn.style.fontSize = '90%'; gotoBtn.textContent = locGotoLabel || 'Goto';
                                            (function(localIdx){ gotoBtn.addEventListener('click', function(){
                                                try {
                                                    var layer = (typeof mainLegLayerByIdx !== 'undefined' && mainLegLayerByIdx[localIdx]) ? mainLegLayerByIdx[localIdx] : null;
                                                    if (layer && typeof layer.getBounds === 'function') {
                                                        map.fitBounds(layer.getBounds(), {padding:[20,20]});
                                                        return;
                                                    }
                                                    // fallback: zoom to the two waypoints for this leg
                                                    var a = waypoints[localIdx]; var b = waypoints[localIdx+1];
                                                    if (a && b && map) { map.fitBounds([[a.lat,a.lon],[b.lat,b.lon]], {padding:[20,20]}); }
                                                } catch(e) { console.warn('goto leg failed', e); }
                                            }); })(idx);
                                            entry.appendChild(gotoBtn);
                                            legsContainer.appendChild(entry);
                                        } catch(e) { console.warn('failed to render leg info', idx, e); }
                                    });
                                        } else {
                                                        legsContainer.textContent = <?php echo json_encode(t('per_leg_details_not_available', 'Per-leg details not available.')); ?>;
                                                    }
                            }
                        } catch(e) { console.warn('planner-legs build failed', e); }
                    } catch(e){ console.error('Failed to draw route legs', e); }
                    try { if (routeLayer && map) map.fitBounds(routeLayer.getBounds(), {padding:[20,20]}); } catch(e){}
                    // distance in meters, duration in seconds
                    var distKm = (route.distance || 0)/1000.0;
                    var durH = (route.duration || 0)/3600.0; // hours
                    summaryDist.textContent = <?php echo json_encode(t('planner_total', 'Total:')); ?> + ' ' + distKm.toFixed(2) + ' km (routed)';
                    var hh = Math.floor(durH); var mm = Math.round((durH - hh)*60);
                    summaryDur.textContent = <?php echo json_encode(t('planner_eta', 'ETA:')); ?> + ' ' + hh + 'h ' + (mm<10?'0'+mm:mm) + 'm (routed)';

                    // Optionally save planner to server (convert waypoints to payload with location_id when available)
                    if (doSave) {
                        var payload = { route_id: routeId, items: waypoints.map(function(w){ return w.location_id ? {location_id: w.location_id} : {lat: w.lat, lon: w.lon, label: w.label}; }) };
                        fetch('<?php echo htmlspecialchars(api_base_url() . '/route/save_planner.php'); ?>', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
                            .then(function(r){ if (!r.ok) throw new Error('Save planner HTTP ' + r.status); return r.text().then(function(t){ try{ return JSON.parse(t); }catch(e){ console.error('Save planner returned non-JSON', t); throw e; } }); })
                            .then(function(js2){
                                console.log('save_planner response', js2);
                                if (js2 && js2.ok) {
                                    try{ savePlanner(); }catch(e){}
                                    try { alert(typeof locSaveSuccess !== 'undefined' ? locSaveSuccess : 'Planner saved'); } catch(e) { console.log('Planner saved'); }
                                } else {
                                    console.error('Save failed', js2);
                                    try{ alert(locSaveFailed + ': ' + (js2 && js2.error ? js2.error : 'unknown')); }catch(e){ alert(locSaveFailed); }
                                }
                            })
                            .catch(function(err){ console.error('Save error', err); try{ alert(locSaveError + ': ' + err); }catch(e){ alert(locSaveError); } });
                    } else {
                        console.log('calcRoute: skip saving planner (doSave=false)');
                        updateStats();
                    }
                }).catch(function(err){ console.error('OSRM request failed', err); try{ alert(locOsrmRequestFailed + ': ' + err); }catch(e){ alert(locOsrmRequestFailed); } });
        }

        function updateSummary(totalKm){
            if (typeof totalKm === 'undefined'){
                // compute
                var tot=0; for(var i=0;i<waypoints.length-1;i++){ tot += haversine(waypoints[i],waypoints[i+1]); }
                totalKm = tot;
            }
            summaryDist.textContent = <?php echo json_encode(t('planner_total', 'Total:')); ?> + ' ' + totalKm.toFixed(2) + ' km';
            var speed = getSelectedSpeed(); // km/h from dropdown/localStorage
            var hours = (speed > 0) ? (totalKm / speed) : 0; var hh = Math.floor(hours); var mm = Math.round((hours - hh)*60);
            summaryDur.textContent = <?php echo json_encode(t('planner_eta', 'ETA:')); ?> + ' ' + hh + 'h ' + (mm<10?'0'+mm:mm) + 'm (est @'+speed+' km/h)';
        }

        function getSelectedSpeed(){
            try{
                var sel = document.getElementById('planner-speed');
                if (sel) {
                    var v = parseInt(sel.value,10);
                    if (!isNaN(v) && v>0) return v;
                }
                var key = 'tpv_planner_speed_' + routeId;
                var stored = localStorage.getItem(key);
                if (stored) { var sv = parseInt(stored,10); if (!isNaN(sv) && sv>0) return sv; }
            }catch(e){}
            return 80;
        }

        function initPlannerSpeed(){
            try{
                var sel = document.getElementById('planner-speed');
                var key = 'tpv_planner_speed_' + routeId;
                if (!sel) return;
                var stored = localStorage.getItem(key);
                if (stored && sel.querySelector('option[value="'+stored+'"]')) sel.value = stored;
                sel.addEventListener('change', function(){ try{ localStorage.setItem(key, String(sel.value)); }catch(e){} updateSummary(); });
            }catch(e){ console.warn('initPlannerSpeed failed', e); }
        }

        // separation control removed by user request

        // simple nearest-neighbour optimizer starting at first waypoint
        function optimizeNN(){
            if (waypoints.length<3) return; // nothing to optimize
            var start = waypoints[0];
            var pool = waypoints.slice(1);
            var order = [start];
            var cur = start;
            while(pool.length){
                var bestIdx = 0; var bestD = 1e9;
                for (var i=0;i<pool.length;i++){ var d = haversine(cur, pool[i]); if (d < bestD){ bestD = d; bestIdx = i; } }
                cur = pool.splice(bestIdx,1)[0]; order.push(cur);
            }
            waypoints = order;
            saveAndRedraw();
        }

        // Export GPX
        function exportGPX(){
            if (waypoints.length===0) return;
            var gpx = '<' + '?xml version="1.0" encoding="UTF-8"?>\n<gpx version="1.1" creator="TravelPlannerV3">\n<trk><name>Route '+routeId+'</name><trkseg>\n';
            waypoints.forEach(function(w){ gpx += '<trkpt lat="'+w.lat+'" lon="'+w.lon+'"></trkpt>\n'; });
            gpx += '</trkseg></trk>\n</gpx>';
            var blob = new Blob([gpx], {type: 'application/gpx+xml'});
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a'); a.href = url; a.download = 'route-'+routeId+'-'+(new Date().toISOString().slice(0,19).replace(/[:T]/g,'-'))+'.gpx'; document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
        }

        // wire buttons
        // Do not save by default when user clicks Calc Route — avoid page reload that clears results
        document.getElementById('planner-calc').addEventListener('click', function(){ calcRoute({save:false}); });
        // Optimize button removed; planner follows Trip Items order and reorders via Trip Items UI
        document.getElementById('planner-export').addEventListener('click', function(){ exportGPX(); });

        // Load planner waypoints from Trip Items list after DOM is ready (route-items rendered below)
        document.addEventListener('DOMContentLoaded', function(){
            try{ initPlannerSpeed(); }catch(e){}
            waitForMap(function(){ try { rebuildPlannerFromTripItems(); } catch(e){} try{ renderWaypoints(); updateLine(); updateSummary(); }catch(e){} try{ calcRoute({save:false}); }catch(e){} });
        });
    })();
    </script>

    <?php
// PDF links removed: attachments are not part of the current workflow
?>
    <!-- Trip items are rendered below the Add form to keep the add-flow above the list -->

    <?php
// Alle POI-Typen für Dropdown laden
$poiTypes = [];
try {
    require_once __DIR__ . '/../../config/mysql.php';
    $db = get_db();
    $userId = $_SESSION['user_id'] ?? null;
    if ($userId) {
        // Load POI types only for locations this user has favorited
        $stmt = $db->prepare('SELECT DISTINCT l.type FROM favorites f JOIN locations l ON l.id = f.location_id WHERE l.type IS NOT NULL AND l.type != "" AND f.user_id = :uid ORDER BY l.type ASC');
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $poiTypes[] = $row['type'];
        }
    }
    // If not logged in or no favorites, $poiTypes remains empty (user sees 'Please choose' only)
} catch (Exception $e) {
    // ignore - leave $poiTypes empty
}
?>

    <div class="route-form-section">
        <!-- Add POI Form -->
        <div>
            <form id="add-item-form">
                <input type="hidden" name="route_id" value="<?php echo (int)$route->id; ?>">
                <div class="form-group">
                    <label for="poi_type_filter"><strong><?php echo htmlspecialchars(t('poi_type_label', 'POI Type:')); ?></strong></label>
                    <select id="poi_type_filter" name="poi_type" class="select-min-width">
                        <option value=""><?php echo htmlspecialchars(t('please_choose', 'Please choose')); ?></option>
                        <?php foreach ($poiTypes as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="location_dropdown"><?php echo htmlspecialchars(t('location_label', 'Location:')); ?></label>
                    <select id="location_dropdown" name="location_id" class="select-min-width">
                        <option value=""><?php echo htmlspecialchars(t('please_select_type_first', 'Please select a type first')); ?></option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="arrival"><?php echo htmlspecialchars(t('arrival_label', 'Arrival:')); ?> (<?php echo htmlspecialchars(t('optional', 'optional')); ?>)</label>
                    <input type="text" id="arrival" name="arrival" class="w-full" placeholder="YYYY-MM-DD" value="<?php echo htmlspecialchars(empty($route->items) && !empty($route->start_date) ? substr($route->start_date, 0, 10) : ''); ?>">
                </div>
                <div class="form-group">
                    <label for="departure"><?php echo htmlspecialchars(t('departure_label', 'Departure:')); ?> (<?php echo htmlspecialchars(t('optional', 'optional')); ?>)</label>
                    <input type="text" id="departure" name="departure" class="w-full" placeholder="YYYY-MM-DD">
                </div>
                <div class="form-group">
                    <label for="notes"><?php echo htmlspecialchars(t('notes_label', 'Notes (optional)')); ?></label>
                    <input type="text" id="notes" name="notes" class="w-full">
                </div>
                <button type="submit" class="btn"><?php echo htmlspecialchars(t('add_to_trip', 'Add to trip')); ?></button>
            </form>
        </div>

        <!-- Map Selector Panel -->
        <div>
            <button id="toggle-map-selector" class="btn w-full">
                🗺️ <?php echo htmlspecialchars(t('select_on_map', 'Select on Map')); ?>
            </button>
            <div id="map-selector-panel" class="map-selector-panel">
                <div id="map-selector-container" class="map-selector-container"></div>
                <div id="map-selected-info" class="map-selected-info">
                    <strong id="map-selected-name"></strong><br>
                    <small id="map-selected-meta"></small>
                </div>
            </div>
        </div>
    </div>
    <script src="<?php echo asset_url('assets/vendor/flatpickr/flatpickr.min.js'); ?>" crossorigin=""></script>
    <script>
    // Initialize Flatpickr for date inputs
    document.addEventListener('DOMContentLoaded', function(){
        if (typeof flatpickr !== 'undefined') {
            flatpickr('#arrival', {
                dateFormat: 'Y-m-d',
                allowInput: true,
                mode: 'single'
            });
            flatpickr('#departure', {
                dateFormat: 'Y-m-d',
                allowInput: true,
                mode: 'single'
            });
            // Also initialize date inputs in trip items list
            var arrivalInputs = document.querySelectorAll('.arrival-input');
            var departureInputs = document.querySelectorAll('.departure-input');
            arrivalInputs.forEach(function(input) {
                if (!input.hasAttribute('readonly')) {
                    flatpickr(input, {
                        dateFormat: 'Y-m-d',
                        allowInput: true,
                        mode: 'single'
                    });
                }
            });
            departureInputs.forEach(function(input) {
                if (!input.hasAttribute('readonly')) {
                    flatpickr(input, {
                        dateFormat: 'Y-m-d',
                        allowInput: true,
                        mode: 'single'
                    });
                }
            });
        }
    });
    </script>
    <script>
    // Dynamisches Location-Dropdown nach POI-Typ (sicher prüfen, Elemente können fehlen)
    document.addEventListener('DOMContentLoaded', function(){
        var typeSel = document.getElementById('poi_type_filter');
        var locDropdown = document.getElementById('location_dropdown');
        if (!typeSel || !locDropdown) return;
        typeSel.addEventListener('change', function(){
            var t = typeSel.value;
            locDropdown.innerHTML = '<option value="">'+locLoading+'<\/option>';
            if (!t) {
                locDropdown.innerHTML = '<option value="">'+locPleaseSelectTypeFirst+'<\/option>';
                return;
            }
            var country = (document.getElementById('country-filter') && document.getElementById('country-filter').value) || '';
            var state = (document.getElementById('state-filter') && document.getElementById('state-filter').value) || '';
            var url = '<?php echo htmlspecialchars(api_base_url() . '/locations/search.php'); ?>?type='+encodeURIComponent(t);
            if (country) url += '&country=' + encodeURIComponent(country);
            if (state) url += '&state=' + encodeURIComponent(state);
            fetch(url)
                .then(function(r){ if (!r.ok) throw new Error('Network response was not ok'); return r.json(); })
                .then(function(data){
                    // API returns {page, per_page, data}
                    var rows = Array.isArray(data.data) ? data.data : (Array.isArray(data) ? data : []);
                    locDropdown.innerHTML = '';
                    if (rows.length) {
                        rows.forEach(function(loc){
                            var opt = document.createElement('option');
                            opt.value = loc.id;
                            var parts = [];
                            if (loc.name) parts.push(loc.name);
                            var meta = [];
                            if (loc.city) meta.push(loc.city);
                            if (loc.state) meta.push(loc.state);
                            if (loc.country) meta.push(loc.country);
                            if (meta.length) parts.push(meta.join(', '));
                            if (loc.type) parts.push('['+loc.type+']');
                            opt.textContent = parts.join(' — ');
                            locDropdown.appendChild(opt);
                        });
                    } else {
                        locDropdown.innerHTML = '<option value="">'+<?php echo json_encode(t('no_locations_found', 'No locations found.')); ?>+'<\/option>';
                    }
                }).catch(function(err){
                    locDropdown.innerHTML = '<option value="">'+<?php echo json_encode(t('failed_to_load', 'Failed to load')); ?>+'<\/option>';
                    console.error('locations fetch failed', err);
                });
        });

        // Map Selector Panel functionality
        var mapInstance = null;
        var mapClusterGroup = null;
        var mapPOIs = [];
        var selectedMapPOI = null;

        // Toggle map panel visibility
        var toggleBtn = document.getElementById('toggle-map-selector');
        var mapPanel = document.getElementById('map-selector-panel');
        if (toggleBtn && mapPanel) {
            toggleBtn.addEventListener('click', function() {
                var isHidden = mapPanel.style.display === 'none';
                mapPanel.style.display = isHidden ? 'block' : 'none';
                toggleBtn.textContent = isHidden ? '🗺️ <?php echo htmlspecialchars(t('hide_map', 'Hide Map')); ?>' : '🗺️ <?php echo htmlspecialchars(t('select_on_map', 'Select on Map')); ?>';
                if (isHidden && !mapInstance) {
                    setTimeout(function() { initMapSelector(); }, 100);
                }
            });
        }

        // Initialize map
        function initMapSelector() {
            if (mapInstance) return;
            var container = document.getElementById('map-selector-container');
            if (!container) return;
            
            mapInstance = L.map(container).setView([51, 10], 5);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OSM contributors',
                maxZoom: 19
            }).addTo(mapInstance);
            
            mapClusterGroup = L.markerClusterGroup();
            mapInstance.addLayer(mapClusterGroup);
        }

        // Load POIs from map - trigger automatically when POI type selected
        var typeSel = document.getElementById('poi_type_filter');
        if (typeSel) {
            typeSel.addEventListener('change', function() {
                var poiType = typeSel.value;
                if (!poiType) return;
                // Auto-open map panel when POI type is selected
                var mapPanel = document.getElementById('map-selector-panel');
                var toggleBtn = document.getElementById('toggle-map-selector');
                if (mapPanel && mapPanel.style.display === 'none') {
                    mapPanel.style.display = 'block';
                    if (toggleBtn) {
                        toggleBtn.textContent = '🗺️ <?php echo htmlspecialchars(t('hide_map', 'Hide Map')); ?>';
                    }
                }
                // Always (re)load POIs for the selected type — whether panel was visible or not
                if (!mapInstance) {
                    setTimeout(function() { initMapSelector(); loadMapPOIs(); }, 100);
                } else {
                    loadMapPOIs();
                }
            });
        }

        // Handle country filter change
        var countryFilter = document.getElementById('country-filter');
        if (countryFilter) {
            countryFilter.addEventListener('change', function() {
                // When top-level country changes, also update map state filter
                var country = countryFilter.value;
                var stateFilter = document.getElementById('state-filter');
                if (!stateFilter) return;
                
                stateFilter.innerHTML = '<option value=""><?php echo htmlspecialchars(t('filter_all', 'All')); ?></option>';
                
                if (country) {
                    fetch('<?php echo htmlspecialchars(api_base_url() . '/locations/distinct.php'); ?>?field=state&country=' + encodeURIComponent(country))
                        .then(function(r) { if (!r.ok) throw new Error('network'); return r.json(); })
                        .then(function(js) {
                            if (js && Array.isArray(js.data)) {
                                js.data.forEach(function(s) {
                                    var o = document.createElement('option');
                                    o.value = s;
                                    o.textContent = s;
                                    stateFilter.appendChild(o);
                                });
                            }
                        })
                        .catch(function() {});
                }
                
                // If map is open and has POIs, reload them with new country filter
                if (mapInstance && document.getElementById('poi_type_filter').value) {
                    loadMapPOIs();
                }
            });
        }

        // Also reload POIs when state filter changes
        var stateFilter = document.getElementById('state-filter');
        if (stateFilter) {
            stateFilter.addEventListener('change', function() {
                // If map is open and has POIs, reload them with new state filter
                if (mapInstance && document.getElementById('poi_type_filter').value) {
                    loadMapPOIs();
                }
            });
        }

        // Load POIs on map
        function loadMapPOIs() {
            if (!mapInstance) initMapSelector();
            
            var poiType = document.getElementById('poi_type_filter').value;
            var country = document.getElementById('country-filter').value;
            var state = document.getElementById('state-filter').value;
            
            if (!poiType) {
                return;
            }

            var params = {
                poi_type: poiType,
                country: country || '',
                state: state || ''
            };

            fetch('<?php echo htmlspecialchars(api_base_url() . '/pois/by-type.php'); ?>?' + new URLSearchParams(params))
                .then(function(r) { if (!r.ok) throw new Error('Network response was not ok'); return r.json(); })
                .then(function(data) {
                    mapPOIs = data.pois || [];
                    updateMapMarkers();
                    
                    // If no POIs found, zoom to country/state instead
                    if (mapPOIs.length === 0 && country) {
                        zoomToCountryState(country, state);
                    }
                })
                .catch(function(err) {
                    console.error('Error loading POIs:', err);
                });
        }

        // Update map markers
        function updateMapMarkers() {
            if (!mapClusterGroup) return;
            mapClusterGroup.clearLayers();
            
            if (mapPOIs.length === 0) {
                if (mapInstance) mapInstance.setView([51, 10], 5);
                return;
            }

            var iconsBase = '<?php echo htmlspecialchars(asset_url('assets/icons/')); ?>';
            var bounds = [];

            mapPOIs.forEach(function(poi) {
                var logoFile = 'poi.png';
                if (poi.logo && poi.logo !== '') {
                    logoFile = poi.logo;
                } else if (poi.type) {
                    // map common types to icon filenames
                    var typeMap = {
                        'bank': 'bank.png',
                        'campground': 'campground.png',
                        'food': 'food.png',
                        'gas station': 'gas_station.png',
                        'gas_station': 'gas_station.png',
                        'hotel': 'hotel.png',
                        'park': 'national_park.png',
                        'parking': 'Parking.png',
                        'shopping': 'shopping.png',
                        'supermarket': 'supermarket.png',
                        'attraction': 'Attractions.png',
                        'touristinfo': 'TouristInfo.png',
                        'transportation': 'Transportation.png'
                    };
                    var tkey = poi.type.toString().toLowerCase();
                    if (typeMap[tkey]) {
                        logoFile = typeMap[tkey];
                    } else {
                        // fallback: normalize type to filename (lowercase, spaces -> underscore)
                        var norm = tkey.replace(/\s+/g,'_').replace(/[^a-z0-9_]/g,'') + '.png';
                        logoFile = norm;
                    }
                }
                var icon = L.icon({
                    iconUrl: iconsBase + logoFile,
                    iconSize: [28, 28],
                    iconAnchor: [14, 28],
                    popupAnchor: [0, -28]
                });

                var marker = L.marker([poi.lat, poi.lon], { icon: icon });
                var popupContent = '<strong>' + (poi.name || 'POI') + '</strong><br>' +
                    'Type: ' + (poi.type || 'N/A') + '<br>' +
                    '<button class="map-select-poi-btn" data-poi-id="' + poi.id + '" data-poi-name="' + escapeHtml(poi.name) + '"><?php echo htmlspecialchars(t('select', 'Select')); ?></button>';
                
                marker.bindPopup(popupContent);
                marker.on('popupopen', function() {
                    var btn = mapPanel.querySelector('.map-select-poi-btn');
                    if (btn) {
                        btn.addEventListener('click', function(e) {
                            e.preventDefault();
                            selectMapPOI(parseInt(this.dataset.poiId), this.dataset.poiName);
                        });
                    }
                });

                mapClusterGroup.addLayer(marker);
                bounds.push([poi.lat, poi.lon]);
            });

            if (bounds.length > 0 && mapInstance) {
                mapInstance.fitBounds(bounds, { padding: [30, 30] });
            }
        }

        // Zoom map to country/state bbox
        function zoomToCountryState(country, state) {
            if (!country || !mapInstance) return;
            
            var q = 'country=' + encodeURIComponent(country);
            if (state) q += '&state=' + encodeURIComponent(state);
            
            fetch('<?php echo htmlspecialchars(api_base_url() . '/locations/bbox.php'); ?>?' + q)
                .then(function(r) { if (!r.ok) throw new Error('bbox fetch failed'); return r.json(); })
                .then(function(js) {
                    if (js && js.ok && js.minLat !== undefined) {
                        var sw = [parseFloat(js.minLat), parseFloat(js.minLon)];
                        var ne = [parseFloat(js.maxLat), parseFloat(js.maxLon)];
                        mapInstance.fitBounds([sw, ne], { padding: [20, 20] });
                    }
                })
                .catch(function(err) { console.warn('Country bbox zoom failed:', err); });
        }

        // Select POI from map
        function selectMapPOI(poiId, poiName) {
            var locDropdown = document.getElementById('location_dropdown');
            if (locDropdown && locDropdown.querySelector('option[value="' + poiId + '"]')) {
                locDropdown.value = poiId;
                selectedMapPOI = { id: poiId, name: poiName };
                
                var infoDiv = document.getElementById('map-selected-info');
                if (infoDiv) {
                    document.getElementById('map-selected-name').textContent = poiName;
                    var poi = mapPOIs.find(function(p) { return p.id === poiId; });
                    if (poi) {
                        document.getElementById('map-selected-meta').textContent = 
                            (poi.type || '') + ' • ' + (poi.country || '') + (poi.state ? ' / ' + poi.state : '');
                    }
                    infoDiv.style.display = 'block';
                }
            }
        }

        // escapeHtml is imported from helpers/utils.php
    });
    </script>

    <a href="<?php echo htmlspecialchars(app_url('/index.php/routes')); ?>" class="btn btn-back-routes"><?php echo htmlspecialchars(t('back_to_routes', 'Back to Routes')); ?></a>

    <!-- Insert Trip items (POIs) here so they appear after the Add form but before the interactive scripts -->
    <h2><?php echo htmlspecialchars(t('trip_items_heading', 'Trip items (POIs)')); ?></h2>
    <div id="route-saving-indicator" class="saving-indicator hidden"> 
        <span class="spinner" aria-hidden="true"></span>
        <span id="route-saving-text"><?php echo htmlspecialchars(t('saving_text', 'Saving...')); ?></span>
    </div>
        <?php if (!empty($route->items)): ?>
            <?php $totalItems = count($route->items); ?>
            <ul id="route-items" class="route-items-list">
                <?php foreach ($route->items as $idx => $it): ?>
                    <?php $pos = $idx + 1; ?>
                    <li
                        class="route-item-row"
                        data-position="<?php echo $pos; ?>"
                        data-item-id="<?php echo (int)$it['item_id']; ?>"
                        data-location-id="<?php echo (int)($it['location_id'] ?? 0); ?>"
                        data-arrival="<?php echo htmlspecialchars(!empty($it['arrival']) ? explode(' ', $it['arrival'])[0] : ''); ?>"
                        data-departure="<?php echo htmlspecialchars(!empty($it['departure']) ? explode(' ', $it['departure'])[0] : ''); ?>">

                        <div class="route-item-info">
                            <div class="route-item-col-fixed">
                                <?php $itemName = trim((string)($it['location_name'] ?? '')); ?>
                                <strong><?php echo htmlspecialchars($itemName !== '' ? $itemName : (t('station_label', 'Station') . ' ' . $pos)); ?></strong>
                            </div>

                            <div class="route-item-col-dates">
                                <?php
                                if ($pos === 1 && !empty($route->start_date)) {
                                    $arrivalVal = substr($route->start_date, 0, 10);
                                } else {
                                    $arrivalVal = !empty($it['arrival']) ? explode(' ', $it['arrival'])[0] : '';
                                }
                    ?>
                                <input type="date" class="arrival-input <?php if ($pos === 1): ?>readonly-date-field<?php endif; ?>" value="<?php echo htmlspecialchars($arrivalVal); ?>" <?php if ($pos === 1): ?>readonly<?php endif; ?> />
                            </div>

                            <div class="route-item-col-dates">
                                <?php
                        if ($pos === $totalItems && !empty($route->end_date)) {
                            $departureVal = substr($route->end_date, 0, 10);
                        } else {
                            $departureVal = !empty($it['departure']) ? explode(' ', $it['departure'])[0] : '';
                        }
                    ?>
                                <input type="date" class="departure-input <?php if ($pos === $totalItems): ?>readonly-date-field<?php endif; ?>" value="<?php echo htmlspecialchars($departureVal); ?>" <?php if ($pos === $totalItems): ?>readonly<?php endif; ?> />
                            </div>

                            <div class="route-item-poi-info">
                                <strong class="poi-name"><?php echo htmlspecialchars($it['location_name'] ?? ''); ?></strong>
                                <div class="route-item-poi-type"><?php echo htmlspecialchars($it['location_type'] ?? ''); ?></div>
                            </div>

                            <div class="route-item-controls">
                                <button class="move-up" type="button" title="<?php echo htmlspecialchars(t('move_up', 'Move up')); ?>">▲</button>
                                <button class="move-down" type="button" title="<?php echo htmlspecialchars(t('move_down', 'Move down')); ?>">▼</button>
                                <button class="remove-item" type="button" title="<?php echo htmlspecialchars(t('remove', 'Remove')); ?>"><?php echo htmlspecialchars(t('remove', 'Remove')); ?></button>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
    <?php else: ?>
        <p><?php echo htmlspecialchars(t('no_pois_added_trip', 'No POIs have been added to this trip yet.')); ?></p>
    <?php endif; ?>

    <script>
        var addForm = document.getElementById('add-item-form');
        // arrival/departure UX: when arrival is chosen, close picker and open departure prefilled to arrival+1
        (function(){
            var arrivalEl = document.getElementById('arrival');
            var departureEl = document.getElementById('departure');
            if (arrivalEl && departureEl) {
                arrivalEl.addEventListener('change', function(){
                    try {
                        var a = this.value; // yyyy-mm-dd
                        if (!a) return;
                        var parts = a.split('-');
                        var dt = new Date(parts[0], parts[1]-1, parts[2]);
                        dt.setDate(dt.getDate() + 1);
                        var y = dt.getFullYear();
                        var m = ('0' + (dt.getMonth() + 1)).slice(-2);
                        var d = ('0' + dt.getDate()).slice(-2);
                        departureEl.value = y + '-' + m + '-' + d;
                        // blur arrival to close picker, then focus departure to open it
                        arrivalEl.blur();
                        setTimeout(function(){ try { departureEl.focus(); } catch(e){} }, 150);
                    } catch (e) { /* ignore parsing errors */ }
                });
            }
        })();

        if (addForm) addForm.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var form = ev.target;
            var data = new FormData();
            data.append('route_id', form.querySelector('input[name="route_id"]').value);
            data.append('location_id', form.querySelector('select[name="location_id"]').value);
            data.append('arrival', form.querySelector('#arrival').value);
            data.append('departure', form.querySelector('#departure').value);
            data.append('notes', form.querySelector('#notes').value);
            fetch('<?php echo htmlspecialchars(api_base_url() . '/route/add_item.php'); ?>', {
                method: 'POST',
                body: data
            }).then(function (r) { return r.json(); }).then(function (json) {
                if (json && json.ok) {
                    try { saveFilters(); } catch (e) {}
                                       location.reload();
                } else {
                    try{ alert(locFailedToAddItem + ': ' + (json && json.error ? json.error : 'unknown')); }catch(e){ alert(locFailedToAddItem); }
                }
            }).catch(function (err) { try{ alert(locGenericError + ': ' + err); }catch(e){ alert(locGenericError); } });
        });

        // Typeahead for legacy location_search (only active if the input exists)
        (function () {
            var input = document.getElementById('location_search');
            if (!input) return;
            var suggestions = document.getElementById('location_suggestions');
            var hiddenId = document.getElementById('location_id');
            var timeout = null;

            function clearSuggestions() {
                if (!suggestions) return;
                suggestions.innerHTML = '';
                suggestions.style.display = 'none';
            }

            function showSuggestions(items) {
                if (!suggestions) return;
                suggestions.innerHTML = '';
                if (!items || items.length === 0) { clearSuggestions(); return; }
                items.forEach(function (it) {
                    var el = document.createElement('div');
                    el.style.padding = '6px';
                    el.style.cursor = 'pointer';
                    var coords = '';
                    if (it.latitude !== undefined && it.longitude !== undefined) coords = ' — ' + it.latitude + ',' + it.longitude;
                    el.textContent = it.name + ' (' + (it.type || '') + ')' + coords;
                    el.setAttribute('data-id', it.id);
                    el.addEventListener('click', function () {
                        if (hiddenId) hiddenId.value = this.getAttribute('data-id');
                        input.value = this.textContent;
                        clearSuggestions();
                    });
                    suggestions.appendChild(el);
                });
                suggestions.style.display = 'block';
            }

            input.addEventListener('input', function () {
                if (hiddenId) hiddenId.value = '';
                var q = this.value.trim();
                if (timeout) clearTimeout(timeout);
                if (q.length < 2) { clearSuggestions(); return; }
                timeout = setTimeout(function () {
                    fetch('<?php echo htmlspecialchars(api_base_url() . '/locations/search.php'); ?>?q=' + encodeURIComponent(q) + '&limit=50')
                        .then(function (r) { if (!r.ok) throw new Error('Network response not ok'); return r.json(); })
                        .then(function (json) {
                            var rows = Array.isArray(json.data) ? json.data : (Array.isArray(json) ? json : []);
                            if (rows.length) showSuggestions(rows);
                            else clearSuggestions();
                        }).catch(function () { clearSuggestions(); });
                }, 250);
            });

            // Keyboard navigation for suggestions
            var selectedIndex = -1;
            input.addEventListener('keydown', function (ev) {
                if (!suggestions) return;
                var items = suggestions.querySelectorAll('div');
                if (!items || items.length === 0) return;
                var selectionColor = getThemeColor('selection-bg', '#1a3a5a');
                if (ev.key === 'ArrowDown') {
                    ev.preventDefault();
                    selectedIndex = Math.min(items.length - 1, selectedIndex + 1);
                    items.forEach(function (it, i) { it.style.background = i === selectedIndex ? selectionColor : ''; });
                } else if (ev.key === 'ArrowUp') {
                    ev.preventDefault();
                    selectedIndex = Math.max(0, selectedIndex - 1);
                    items.forEach(function (it, i) { it.style.background = i === selectedIndex ? selectionColor : ''; });
                } else if (ev.key === 'Enter') {
                    ev.preventDefault();
                    if (selectedIndex >= 0 && items[selectedIndex]) {
                        items[selectedIndex].click();
                    }
                }
            });

            document.addEventListener('click', function (ev) { if (suggestions && !suggestions.contains(ev.target) && ev.target !== input) clearSuggestions(); });
        })();
        // Bind row-level controls: arrival/departure edits, remove, and POI-only move behavior
        (function(){
            var list = document.getElementById('route-items');
            if (!list) return;

            // Swap only POI-related data between two rows while keeping arrival/departure inputs in place
            function swapPoiBetweenRows(a, b) {
                if (!a || !b) return;
                // swap data-item-id attributes
                var idA = a.getAttribute('data-item-id');
                var idB = b.getAttribute('data-item-id');
                a.setAttribute('data-item-id', idB);
                b.setAttribute('data-item-id', idA);
                // swap visible POI info (name + type) - use correct selector
                var poiA = a.querySelector('.route-item-poi-info');
                var poiB = b.querySelector('.route-item-poi-info');
                if (poiA && poiB) {
                    var tmp = poiA.innerHTML;
                    poiA.innerHTML = poiB.innerHTML;
                    poiB.innerHTML = tmp;
                }
                // Update position labels to reflect new order instantly
                var allItems = Array.from(list.querySelectorAll('li'));
                allItems.forEach(function(li, idx) {
                    var posLabel = li.querySelector('.route-item-col-fixed strong');
                    if (posLabel) {
                        posLabel.textContent = 'Station ' + (idx + 1);
                    }
                });
                // After swapping DOM content, rebind controls so event listeners are correct
                bindRowControls();
            }

            // Persist current order & associated arrival/departure values to server
            function saveOrder() {
                var items = Array.from(list.querySelectorAll('li')).map(function(li, idx){
                    var arrivalEl = li.querySelector('.arrival-input');
                    var departureEl = li.querySelector('.departure-input');
                    var arrival = arrivalEl ? arrivalEl.value : (li.getAttribute('data-arrival') || null);
                    var departure = departureEl ? departureEl.value : (li.getAttribute('data-departure') || null);
                    return {
                        item_id: li.getAttribute('data-item-id'),
                        location_id: li.getAttribute('data-location-id') || null,
                        arrival: arrival || null,
                        departure: departure || null,
                        position: idx + 1
                    };
                });
                var payload = { route_id: <?php echo (int)$route->id; ?>, items: items };
                var savingEl = document.getElementById('route-saving-indicator'); if (savingEl) savingEl.style.display = 'inline-flex';
                try { console.debug('saveOrder payload (json):', JSON.stringify(payload)); } catch(e) { console.debug('saveOrder payload (obj):', payload); }
                fetch('<?php echo htmlspecialchars(api_base_url() . '/route/reorder_items.php'); ?>', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) })
                    .then(function(r){
                        return r.text().then(function(text){
                            console.debug('reorder_items raw response:', text, 'status:', r.status);
                            try { return JSON.parse(text); } catch(e) { return { ok: false, error: 'invalid_json_response', raw: text, status: r.status }; }
                        });
                    })
                    .then(function(json){
                        console.debug('reorder_items parsed response:', json);
                        if (!json || !json.ok) {
                            try{ alert(locFailedToSaveOrder + ': ' + (json && json.error ? json.error : 'unknown')); }catch(e){ alert(locFailedToSaveOrder); }
                        } else {
                            try { if (typeof rebuildPlannerFromTripItems === 'function') rebuildPlannerFromTripItems(); } catch(e){}
                            try {
                                // Rebuild window.routePOIs order from DOM to keep map in sync
                                if (window.routePOIs && Array.isArray(window.routePOIs)) {
                                    var mapById = {};
                                    window.routePOIs.forEach(function(p){ if (p && p.id) mapById[String(p.id)] = p; });
                                    var newArr = Array.from(list.querySelectorAll('li')).map(function(li){ return mapById[String(li.getAttribute('data-item-id'))] || null; }).filter(Boolean);
                                    window.routePOIs = newArr;
                                    updateStats();
                                }
                            } catch(e) { console.warn('failed to reorder window.routePOIs', e); }
                            try { if (typeof calcRoute === 'function') setTimeout(function(){ try{ calcRoute({save:false}); }catch(e){} }, 220); } catch(e){}
                        }
                        if (savingEl) savingEl.style.display = 'none';
                    }).catch(function(err){ if (savingEl) savingEl.style.display = 'none'; console.error('saveOrder fetch failed', err); try{ alert(locNetworkErrorSavingOrder); }catch(e){ alert(locNetworkErrorSavingOrder); } });
            }

            // Bind handlers for inputs and buttons
            function bindRowControls() {
                // arrival / departure inputs: update li dataset when changed
                Array.from(list.querySelectorAll('.arrival-input')).forEach(function(inp){
                    // remove previous handler if present
                    if (inp._tpv_change) inp.removeEventListener('change', inp._tpv_change);
                    inp._tpv_change = function(){
                        var li = inp.closest('li'); if (!li) return; li.setAttribute('data-arrival', inp.value || '');
                        // debounce save so rapid edits don't produce many requests
                        try { if (window._tpv_save_timer) clearTimeout(window._tpv_save_timer); window._tpv_save_timer = setTimeout(function(){ saveOrder(); }, 600); } catch(e){}
                    };
                    inp.addEventListener('change', inp._tpv_change);
                });
                Array.from(list.querySelectorAll('.departure-input')).forEach(function(inp){
                    if (inp._tpv_change) inp.removeEventListener('change', inp._tpv_change);
                    inp._tpv_change = function(){
                        var li = inp.closest('li'); if (!li) return; li.setAttribute('data-departure', inp.value || '');
                        try { if (window._tpv_save_timer) clearTimeout(window._tpv_save_timer); window._tpv_save_timer = setTimeout(function(){ saveOrder(); }, 600); } catch(e){}
                    };
                    inp.addEventListener('change', inp._tpv_change);
                });

                // remove buttons
                Array.from(list.querySelectorAll('.remove-item')).forEach(function(btn){
                    if (btn._tpv_click) btn.removeEventListener('click', btn._tpv_click);
                    btn._tpv_click = function(ev){
                        ev.preventDefault();
                        var li = this.closest('li'); if (!li) return; var itemId = li.getAttribute('data-item-id');
                        if (!itemId) return; if (!confirm(locConfirmRemove)) return;
                        var fd = new FormData(); fd.append('item_id', itemId);
                        fetch('<?php echo htmlspecialchars(api_base_url() . '/route/remove_item.php'); ?>', { method: 'POST', body: fd })
                            .then(function(r){ return r.json(); }).then(function(js){ if (js && js.ok) { location.reload(); } else { try{ alert(locFailedToRemoveItem + ': ' + (js && js.error ? js.error : 'unknown')); }catch(e){ alert(locFailedToRemoveItem); } } })
                            .catch(function(err){ try{ alert(locGenericError + ': ' + err); }catch(e){ alert(locGenericError); } });
                    };
                    btn.addEventListener('click', btn._tpv_click);
                });

                // move up / move down: swap POI info between rows (keep dates in place)
                Array.from(list.querySelectorAll('.move-up')).forEach(function(btn){
                    if (btn._tpv_up) btn.removeEventListener('click', btn._tpv_up);
                    btn._tpv_up = function(ev){ ev.preventDefault(); var li = this.closest('li'); if (!li) return; var prev = li.previousElementSibling; if (!prev) return; swapPoiBetweenRows(li, prev); saveOrder(); };
                    btn.addEventListener('click', btn._tpv_up);
                });
                Array.from(list.querySelectorAll('.move-down')).forEach(function(btn){
                    if (btn._tpv_down) btn.removeEventListener('click', btn._tpv_down);
                    btn._tpv_down = function(ev){ ev.preventDefault(); var li = this.closest('li'); if (!li) return; var next = li.nextElementSibling; if (!next) return; swapPoiBetweenRows(li, next); saveOrder(); };
                    btn.addEventListener('click', btn._tpv_down);
                });
            }

            // initial bind
            bindRowControls();
        })();

        // Control visibility of "Select on Map" button based on POI type selection
        // Auto-open map when POI type is selected
        (function() {
            var poiTypeFilter = document.getElementById('poi_type_filter');
            var toggleMapSelectorBtn = document.getElementById('toggle-map-selector');
            
            if (!poiTypeFilter || !toggleMapSelectorBtn) return;
            
            function updateMapSelectorVisibility() {
                if (poiTypeFilter.value.trim() !== '') {
                    toggleMapSelectorBtn.style.display = 'block';
                    // Auto-click the button to open the map
                    toggleMapSelectorBtn.click();
                } else {
                    toggleMapSelectorBtn.style.display = 'none';
                }
            }
            
            poiTypeFilter.addEventListener('change', updateMapSelectorVisibility);
            // Set initial state on page load
            updateMapSelectorVisibility();
        })();
    </script>
</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>