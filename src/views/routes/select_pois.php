<?php
// Routes POI Selector: Map-based POI selection with filtering
// Start session early for user context
if (file_exists(__DIR__ . '/../../helpers/session.php')) {
    require_once __DIR__ . '/../../helpers/session.php';
    start_secure_session();
}

// Include necessary files
require_once __DIR__ . '/../../helpers/i18n.php';
require_once __DIR__ . '/../../helpers/utils.php';
require_once __DIR__ . '/../../models/Route.php';
require_once __DIR__ . '/../../controllers/RouteController.php';

// Create RouteController instance
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

// Load distinct countries and states from locations table
$countries = [];
$states = [];
try {
    require_once __DIR__ . '/../../config/mysql.php';
    $db = get_db();
    $cRows = $db->query("SELECT DISTINCT country FROM locations WHERE country IS NOT NULL AND country != '' ORDER BY country ASC")->fetchAll(PDO::FETCH_COLUMN);
    $sRows = $db->query("SELECT DISTINCT state FROM locations WHERE state IS NOT NULL AND state != '' ORDER BY state ASC")->fetchAll(PDO::FETCH_COLUMN);
    $countries = $cRows ?: [];
    $states = $sRows ?: [];
} catch (Exception $e) {
    $countries = [];
    $states = [];
}

// POI Categories definition (matching viewPOIs.php)
$poiCategories = [
    'hotels' => ['label' => t('hotels', 'Hotels'), 'tags' => ['tourism=hotel','tourism=motel','tourism=hostel','tourism=guest_house','tourism=chalet','tourism=apartment','amenity=hotel']],
    'food' => ['label' => t('food', 'Food'), 'tags' => ['amenity=restaurant','amenity=biergarten','amenity=fast_food','amenity=food_court','shop=bakery','amenity=cafe','amenity=pub','amenity=bar']],
    'shopping' => ['label' => t('shopping', 'Shopping'), 'tags' => ['shop=shoes','shop=supermarket','shop=gift','shop=clothes','shop=electronics','shop=books','shop=department_store','shop=variety_store','shop=convenience','shop=art','shop=computer','shop=mall']],
    'supermarket' => ['label' => t('supermarket', 'Supermarket'), 'tags' => ['shop=supermarket','shop=mall','shop=department_store']],
    'banks' => ['label' => t('banks', 'Banks'), 'tags' => ['amenity=bank','amenity=atm']],
    'fuel' => ['label' => t('fuel', 'Gas station'), 'tags' => ['amenity=fuel','amenity=charging_station']],
    'campgrounds' => ['label' => t('campgrounds', 'Campgrounds'), 'tags' => ['tourism=camp_site','tourism=caravan_site']],
    'provincial_parks' => ['label' => t('provincial_parks', 'Parks'), 'tags' => ['leisure=park','natural=nature_reserve','leisure=nature_reserve']],
    'dump_station' => ['label' => t('dump_station', 'Dump station'), 'tags' => ['amenity=sanitary_dump_station','amenity=waste_disposal']],
    'tourist_info' => ['label' => t('tourist_info', 'Tourist Information'), 'tags' => ['tourism=information']],
    'transport' => ['label' => t('transport', 'Transport'), 'tags' => ['amenity=ferry_terminal','amenity=bus_station','highway=bus_stop','aeroway=aerodrome','public_transport=stop_position','public_transport=station']],
    'laundry' => ['label' => t('laundry', 'Laundry'), 'tags' => ['amenity=laundry','shop=laundry']],
    'pharmacy' => ['label' => t('pharmacy', 'Pharmacy'), 'tags' => ['amenity=pharmacy','amenity=hospital']],
    'parking' => ['label' => t('parking', 'Parking'), 'tags' => ['amenity=parking','amenity=parking_entrance','amenity=parking_space']],
    'fitness' => ['label' => t('fitness', 'Fitness'), 'tags' => ['leisure=fitness_centre','leisure=sports_hall','leisure=sports_centre','leisure=fitness_station']],
    'attractions' => ['label' => t('attractions', 'Attractions'), 'tags' => ['tourism=museum','tourism=theme_park','natural=waterfall','waterway=waterfall','tourism=attraction','tourism=viewpoint','tourism=zoo','leisure=nature_reserve']],
    'nightlife' => ['label' => t('nightlife', 'Nightlife'), 'tags' => ['amenity=bar','amenity=pub','amenity=nightclub']],
    'tobacco_vape' => ['label' => t('tobacco_vape', 'Tobacco / Vape'), 'tags' => ['shop=tobacco','shop=e-cigarette']],
    'tobacco' => ['label' => t('tobacco_vape', 'Tobacco / Vape'), 'tags' => ['shop=tobacco','shop=e-cigarette']],
    'cannabis' => ['label' => t('cannabis', 'Cannabis'), 'tags' => ['shop=cannabis']]
];

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

<?php $flashOk = flash_get('success');
if ($flashOk): ?>
    <div class="flash-success"><?php echo htmlspecialchars($flashOk); ?></div>
<?php endif; ?>

<main>
    <section class="trip-header">
        <h2><?php echo htmlspecialchars($route->name); ?></h2>
        <p><?php echo htmlspecialchars(t('select_pois_for_route', 'Select POIs to add to this route')); ?></p>
    </section>

    <!-- Filters Section -->
    <div class="filter-section">
        <h3><?php echo htmlspecialchars(t('filter_and_select', 'Filter and Select')); ?></h3>
        
        <div class="filter-grid">
            <!-- POI Type Selector -->
            <div class="filter-group-select">
                <label for="poi-type-selector">
                    <?php echo htmlspecialchars(t('poi_type', 'POI Type:')); ?>
                </label>
                <select id="poi-type-selector">
                    <option value=""><?php echo htmlspecialchars(t('select_poi_type', 'Select a POI type...')); ?></option>
                    <?php foreach ($poiCategories as $key => $cfg): ?>
                        <option value="<?php echo htmlspecialchars($key); ?>">
                            <?php echo htmlspecialchars($cfg['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Country Filter -->
            <div class="filter-group-select">
                <label for="country-filter-pois">
                    <?php echo htmlspecialchars(t('country_label', 'Country:')); ?>
                </label>
                <select id="country-filter-pois">
                    <option value=""><?php echo htmlspecialchars(t('filter_all', 'All')); ?></option>
                    <?php foreach ($countries as $c): ?>
                        <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars(t('country_' . $c, $c)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- State Filter -->
            <div class="filter-group-select">
                <label for="state-filter-pois">
                    <?php echo htmlspecialchars(t('state_label', 'State:')); ?>
                </label>
                <select id="state-filter-pois">
                    <option value=""><?php echo htmlspecialchars(t('filter_all', 'All')); ?></option>
                </select>
            </div>

            <!-- Location Filter -->
            <div class="filter-group-select">
                <label for="location-filter-pois">
                    <?php echo htmlspecialchars(t('location_label', 'Location:')); ?>
                </label>
                <select id="location-filter-pois">
                    <option value=""><?php echo htmlspecialchars(t('all_locations', 'All locations')); ?></option>
                </select>
            </div>
        </div>

        <div class="button-group">
            <button id="load-pois-map" class="btn">
                <?php echo htmlspecialchars(t('load_pois_on_map', 'Load POIs on Map')); ?>
            </button>
            <button id="reset-pois-filter" class="btn">
                <?php echo htmlspecialchars(t('reset_filters', 'Reset Filters')); ?>
            </button>
            <span id="pois-status" class="pois-status"></span>
        </div>
    </div>

    <!-- Map Section -->
    <div class="map-container-modal">
        <h3><?php echo htmlspecialchars(t('map_view', 'Map View')); ?></h3>
        <div id="pois-map-select"></div>
    </div>

    <!-- Selected POIs Section -->
    <div class="selected-pois-section">
        <h3>
            <?php echo htmlspecialchars(t('selected_pois', 'Selected POIs')); ?>
            <span id="selected-count-badge">0</span>
        </h3>
        <div id="selected-pois-list">
            <!-- Selected POIs will be listed here -->
        </div>
        <div class="selected-actions">
            <button id="add-selected-to-route" class="btn btn-primary" disabled>
                <?php echo htmlspecialchars(t('add_to_route', 'Add to Route')); ?>
            </button>
            <button id="clear-selected" class="btn">
                <?php echo htmlspecialchars(t('clear_selection', 'Clear Selection')); ?>
            </button>
        </div>
    </div>

    <!-- Back Link -->
    <div class="back-link">
        <a href="<?php echo htmlspecialchars(app_url('/index.php/routes/view') . '?id=' . urlencode($route->id)); ?>" class="btn">
            ← <?php echo htmlspecialchars(t('back_to_route', 'Back to Route')); ?>
        </a>
    </div>
</main>

<!-- Leaflet CSS & JS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
<script src="<?php echo asset_url('assets/vendor/leaflet/leaflet.js'); ?>" crossorigin=""></script>

<!-- MarkerCluster CSS & JS -->
<link rel="stylesheet" href="<?php echo asset_url('assets/vendor/leaflet.markercluster/MarkerCluster.css'); ?>">
<link rel="stylesheet" href="<?php echo asset_url('assets/vendor/leaflet.markercluster/MarkerCluster.Default.css'); ?>">
<script src="<?php echo asset_url('assets/vendor/leaflet.markercluster/leaflet.markercluster.js'); ?>"></script>


<script>
// Configuration and State
var routeId = <?php echo json_encode($routeId, JSON_UNESCAPED_UNICODE); ?>;
var poiCategories = <?php echo json_encode($poiCategories, JSON_UNESCAPED_UNICODE); ?>;
var countries = <?php echo json_encode($countries, JSON_UNESCAPED_UNICODE); ?>;
var states = <?php echo json_encode($states, JSON_UNESCAPED_UNICODE); ?>;

var selectedPOIs = {}; // Map: poiId => POI object
var poiMarkers = {}; // Map: poiId => Leaflet marker
var posGISPOIs = []; // Current list of POIs from overpass

// Translation strings
var i18n = {
    loadingPois: <?php echo json_encode(t('loading_pois', 'Loading POIs...')); ?>,
    noPoisFound: <?php echo json_encode(t('no_pois_found', 'No POIs found with current filters')); ?>,
    poiAdded: <?php echo json_encode(t('poi_added_to_route', 'POI added to route')); ?>,
    poiCountFormat: <?php echo json_encode(t('poi_count_format', '%count POIs found')); ?>,
    selectPoisFirst: <?php echo json_encode(t('select_pois_first', 'Select POIs first')); ?>,
    errorLoadingPois: <?php echo json_encode(t('error_loading_pois', 'Error loading POIs')); ?>,
    selectTypeFirst: <?php echo json_encode(t('select_type_first', 'Please select a POI type first')); ?>
};

// Initialize map
var map = L.map('pois-map-select').setView([51, 10], 5);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OSM contributors'
}).addTo(map);

var clusterGroup = L.markerClusterGroup();
map.addLayer(clusterGroup);

// Get elements
var poiTypeSelector = document.getElementById('poi-type-selector');
var countryFilterPois = document.getElementById('country-filter-pois');
var stateFilterPois = document.getElementById('state-filter-pois');
var locationFilterPois = document.getElementById('location-filter-pois');
var loadPoisBtn = document.getElementById('load-pois-map');
var resetPoisBtn = document.getElementById('reset-pois-filter');
var poiStatus = document.getElementById('pois-status');
var selectedPoisList = document.getElementById('selected-pois-list');
var selectedCountBadge = document.getElementById('selected-count-badge');
var addToRouteBtn = document.getElementById('add-selected-to-route');
var clearSelectionBtn = document.getElementById('clear-selected');

// Event: Load POIs on Map
loadPoisBtn.addEventListener('click', function() {
    var poiType = poiTypeSelector.value;
    if (!poiType) {
        alert(i18n.selectTypeFirst);
        return;
    }
    loadPOIsFromoverpass();
});

// Function to load POIs from overpass based on filters
function loadPOIsFromoverpass() {
    var poiType = poiTypeSelector.value;
    var country = countryFilterPois.value;
    var state = stateFilterPois.value;
    var locationId = locationFilterPois.value;

    if (!poiType) {
        alert(i18n.selectTypeFirst);
        return;
    }

    poiStatus.textContent = i18n.loadingPois;
    
    var searchUrl = '<?php echo htmlspecialchars(api_base_url() . '/locations/search.php'); ?>?type=' + encodeURIComponent(poiType) + '&mine=1';
    if (country) searchUrl += '&country=' + encodeURIComponent(country);
    if (state) searchUrl += '&state=' + encodeURIComponent(state);
    if (locationId) searchUrl += '&id=' + encodeURIComponent(locationId);

    fetch(searchUrl)
        .then(function(r) { if (!r.ok) throw new Error('Network response was not ok'); return r.json(); })
        .then(function(data) {
            var rows = data.data || [];
            posGISPOIs = rows.map(function(r){ r.lat = r.latitude; r.lon = r.longitude; return r; });
            updateMapMarkers();
            poiStatus.textContent = i18n.poiCountFormat.replace('%count', posGISPOIs.length);
        })
        .catch(function(err) {
            console.error('Error loading POIs:', err);
            poiStatus.textContent = i18n.errorLoadingPois;
        });
}

// Update map markers based on current POI list
function updateMapMarkers() {
    clusterGroup.clearLayers();
    poiMarkers = {};

    if (posGISPOIs.length === 0) {
        poiStatus.textContent = i18n.noPoisFound;
        map.setView([51, 10], 5);
        return;
    }

    var bounds = [];
    var iconsBase = '<?php echo htmlspecialchars(asset_url('assets/icons/')); ?>';

    posGISPOIs.forEach(function(poi) {
        var logoFile = (poi.logo && poi.logo !== '') ? poi.logo : 'poi.png';
        var icon = L.icon({
            iconUrl: iconsBase + logoFile,
            iconSize: [28, 28],
            iconAnchor: [14, 28],
            popupAnchor: [0, -28]
        });

        var marker = L.marker([poi.lat, poi.lon], { icon: icon });
        var popupContent = '<strong>' + (poi.name || 'POI') + '</strong><br>' +
            'Type: ' + (poi.type || 'N/A') + '<br>' +
            'Country: ' + (poi.country || 'N/A') + '<br>' +
            'State: ' + (poi.state || 'N/A');
        marker.bindPopup(popupContent);
        clusterGroup.addLayer(marker);

        poiMarkers[poi.id] = marker;
        bounds.push([poi.lat, poi.lon]);
    });

    if (bounds.length > 0) {
        map.fitBounds(bounds, { padding: [30, 30] });
    }
}

// Handle Country Filter Change
countryFilterPois.addEventListener('change', function() {
    var country = countryFilterPois.value;
    stateFilterPois.innerHTML = '<option value=""><?php echo htmlspecialchars(t('filter_all', 'All')); ?></option>';
    
    if (country) {
        fetch('<?php echo htmlspecialchars(api_base_url() . '/locations/distinct.php'); ?>?field=state&country=' + encodeURIComponent(country))
            .then(function(r) { if (!r.ok) throw new Error('network'); return r.json(); })
            .then(function(js) {
                if (js && Array.isArray(js.data)) {
                    js.data.forEach(function(s) {
                        var o = document.createElement('option');
                        o.value = s;
                        o.textContent = s;
                        stateFilterPois.appendChild(o);
                    });
                }
            })
            .catch(function() {});
    }

    // Update locations based on country
    updateLocationFilter();
});

// Handle State Filter Change
stateFilterPois.addEventListener('change', updateLocationFilter);

// Update location filter dropdown based on country/state
function updateLocationFilter() {
    var country = countryFilterPois.value;
    var state = stateFilterPois.value;
    
    locationFilterPois.innerHTML = '<option value=""><?php echo htmlspecialchars(t('all_locations', 'All locations')); ?></option>';

    var params = new URLSearchParams();
    if (country) params.append('country', country);
    if (state) params.append('state', state);

    fetch('<?php echo htmlspecialchars(api_base_url() . '/locations/list.php'); ?>?' + params)
        .then(function(r) { if (!r.ok) throw new Error('network'); return r.json(); })
        .then(function(js) {
            if (js && Array.isArray(js.locations)) {
                js.locations.forEach(function(loc) {
                    var o = document.createElement('option');
                    o.value = loc.id;
                    o.textContent = loc.name;
                    locationFilterPois.appendChild(o);
                });
            }
        })
        .catch(function() {});
}

// Reset filters
resetPoisBtn.addEventListener('click', function() {
    poiTypeSelector.value = '';
    countryFilterPois.value = '';
    stateFilterPois.innerHTML = '<option value=""><?php echo htmlspecialchars(t('filter_all', 'All')); ?></option>';
    locationFilterPois.innerHTML = '<option value=""><?php echo htmlspecialchars(t('all_locations', 'All locations')); ?></option>';
    posGISPOIs = [];
    updateMapMarkers();
    poiStatus.textContent = '';
});

// Clear selected POIs
clearSelectionBtn.addEventListener('click', function() {
    selectedPOIs = {};
    updateSelectedPoisList();
});

// Add selected POIs to route (will POST to an API)
addToRouteBtn.addEventListener('click', function() {
    var poiIds = Object.keys(selectedPOIs);
    if (poiIds.length === 0) {
        alert(i18n.selectPoisFirst);
        return;
    }

    // TODO: Implement POST to add POIs to route
    console.log('Adding POIs to route:', poiIds);
    alert('POI selection feature ready. Backend integration needed.');
});

// Update selected POIs display
function updateSelectedPoisList() {
    selectedPoisList.innerHTML = '';
    var count = Object.keys(selectedPOIs).length;
    
    if (count === 0) {
        selectedPoisList.innerHTML = '<p class="hint-text"><?php echo htmlspecialchars(t('no_pois_selected', 'No POIs selected yet')); ?></p>';
        selectedCountBadge.style.display = 'none';
        addToRouteBtn.disabled = true;
    } else {
        selectedCountBadge.textContent = count;
        selectedCountBadge.style.display = 'inline';
        addToRouteBtn.disabled = false;

        Object.values(selectedPOIs).forEach(function(poi) {
            var logoFile = (poi.logo && poi.logo !== '') ? poi.logo : 'poi.png';
            var iconsBase = '<?php echo htmlspecialchars(asset_url('assets/icons/')); ?>';
            
            var card = document.createElement('div');
            card.className = 'poi-select-card selected';
            card.innerHTML = '<img src="' + iconsBase + logoFile + '" class="poi-select-logo" alt="' + htmlEscape(poi.name) + '" />' +
                '<div class="poi-select-name">' + htmlEscape(poi.name || 'POI') + '</div>' +
                '<div class="poi-select-meta">' + htmlEscape(poi.type || 'N/A') + '</div>' +
                '<button class="btn btn-small" onclick="removePOI(' + poi.id + ')">Remove</button>';
            selectedPoisList.appendChild(card);
        });
    }
}

// Remove POI from selection
function removePOI(poiId) {
    delete selectedPOIs[poiId];
    updateSelectedPoisList();
}

// escapeHtml is imported from helpers/utils.php (use it directly in templates)
// For JavaScript context, convert to escapeHtml by aliasing:
var escapeHtml = (window.escapeHtml && typeof window.escapeHtml === 'function') ? window.escapeHtml : (htmlEscape || (function(text) {
    var map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return String(text).replace(/[&<>\"']/g, function(c) { return map[c]; });
}));


// Initialize location filter on page load
document.addEventListener('DOMContentLoaded', function() {
    updateLocationFilter();
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
