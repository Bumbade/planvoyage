<?php
// viewPOIs.php - POI map page
// Use centralized helper loader for robustness and clarity
if (file_exists(__DIR__ . '/../bootstrap/RequiredHelpers.php')) {
    require_once __DIR__ . '/../bootstrap/RequiredHelpers.php';
    RequiredHelpers::loadPoiHelpers();
} else {
    // Fallback: direct loader if bootstrap not available
    if (file_exists(__DIR__ . '/../config/env.php')) {
        require_once __DIR__ . '/../config/env.php';
    }
    if (file_exists(__DIR__ . '/../helpers/url.php')) {
        require_once __DIR__ . '/../helpers/url.php';
    }
    if (file_exists(__DIR__ . '/../helpers/i18n.php')) {
        require_once __DIR__ . '/../helpers/i18n.php';
    }
    if (file_exists(__DIR__ . '/../helpers/poi.php')) {
        require_once __DIR__ . '/../helpers/poi.php';
    }
    if (file_exists(__DIR__ . '/../helpers/session.php')) {
        require_once __DIR__ . '/../helpers/session.php';
        if (function_exists('start_secure_session')) {
            start_secure_session();
        }
    }
    if (file_exists(__DIR__ . '/../helpers/auth.php')) {
        require_once __DIR__ . '/../helpers/auth.php';
    }
}

// CRITICAL: Set global appBase BEFORE any includes
// This ensures all subsequent includes and JavaScript see the correct value
global $appBase;
// Prefer environment APP_BASE if provided (can be a path or full URL)
$envApp = getenv('APP_BASE') ?: null;
if (!empty($envApp)) {
    // If APP_BASE is a full URL (http://...), keep as-is; otherwise use path as provided
    $appBase = $envApp;
    $GLOBALS['appBase'] = $appBase;
} elseif (isset($GLOBALS['appBase']) && !empty($GLOBALS['appBase'])) {
    $appBase = $GLOBALS['appBase'];
} else {
    // Auto-detect from SCRIPT_NAME similar to header.php
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $base = rtrim(dirname($script), '/');
    if (preg_match('#/src/(admin|views|api|controllers)/?$#', $base)) {
        $base = dirname($base);
    }
    if (basename($base) === 'src') {
        $base = dirname($base);
    }
    if ($base === '/' || $base === '.') {
        $base = '';
    }
    $appBase = $base;
    $GLOBALS['appBase'] = $appBase;
}

// Cache asset version early (computed once, not via filemtime() multiple times)
$ASSET_VERSION = defined('APP_VERSION') ? APP_VERSION : filemtime(__FILE__);

// Inject Leaflet CSS and MarkerCluster CSS into the head
// Note: poi-popups.css and poi-controls.css are now consolidated in features.css
$HEAD_EXTRA = '';
$HEAD_EXTRA .= '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">';
$HEAD_EXTRA .= '<link rel="stylesheet" href="' . asset_url('assets/vendor/leaflet.markercluster/MarkerCluster.css') . '">';
$HEAD_EXTRA .= '<link rel="stylesheet" href="' . asset_url('assets/vendor/leaflet.markercluster/MarkerCluster.Default.css') . '">';
// Load centralized POI cluster overrides (no inline styles)
require_once __DIR__ . '/../includes/header.php';
// All POI CSS (popups, controls) now loaded via features.css
// single PHP close tag below to avoid accidental whitespace/output
?>
<?php renderFlashMessages(); ?>

<main>
    <h1><?php echo t('pois', 'POIs'); ?></h1>
    <!-- Styles moved to src/assets/css/features.css -->

    <div id="pois-controls">
        <p><?php echo t('pois_map_instructions', 'Pan/zoom the map to load POIs in the visible area.'); ?></p>
    </div>

    <!-- load/reset buttons moved below the filter so they appear centered under the category grid -->

    <!-- debug panel removed for production UX -->

    <div class="pois-layout">
    <div id="pois-controls-side" class="pois-side">
        <div class="margin-top-small">
            <label for="poi-search" class="form-label"><?php echo t('pois_search_label','Search POIs by name'); ?></label>
            <div class="flex-row gap-small">
                <input id="poi-search" type="search" placeholder="<?php echo t('pois_search_placeholder','Search by name (press Enter)'); ?>" class="form-input-flex" />
                <button id="poi-search-btn" class="btn"><?php echo t('pois_search','Search'); ?></button>
                <!-- Country preselection: centers map on chosen country and triggers POI load -->
                <select id="poi-country-select" class="form-input">
                    <option value=""><?php echo htmlspecialchars(t('select_country','Select country')); ?></option>
                </select>
            </div>
        </div>
        
        <!-- Load/Reset Controls oben -->
        <div id="poi-load-controls" style="margin-top: 1rem;">
            <button id="load-pois-btn" class="btn"><?php echo htmlspecialchars(t('load_pois', 'Load POIs')); ?></button>
            <button id="reset-poi-filters" class="btn"><?php echo htmlspecialchars(t('reset_filters', 'Reset Filters')); ?></button>
        </div>
        
        <h3 id="poi-filter-heading" style="display:none;"><?php echo t('pois_filter','Filter POIs'); ?></h3>
        <fieldset id="poi-filter" style="display:none;">
<?php
// Render filter buttons client-side. Server provides an allow-list of categories
// the current user is permitted to see to avoid rendering protected options.
// Note: $allowed will be loaded from poi-config.php API, no need to render inline
$allowed = [];
// Prefer a central provider function if available, otherwise fall back to local list.
if (function_exists('get_poi_categories')) {
    $allKeys = (array) get_poi_categories();
} else {
    $allKeys = [
        'hotel','attraction','tourist_info','food','nightlife','gas_stations','charging_station','parking',
        'bank','healthcare','fitness','laundry','supermarket','tobacco','cannabis',
        'transport','dump_station','campgrounds','natureparks'
    ];
}
foreach ($allKeys as $k) {
    if (!function_exists('has_category_permission') || has_category_permission($k)) {
        $allowed[] = $k;
    }
}
?>

        <!-- POI filters loaded from server-generated API endpoint to avoid large inline scripts -->
        <script src="<?php echo htmlspecialchars(app_url('src/api/poi-config.php')); ?>?v=<?php echo $ASSET_VERSION; ?>"></script>
        <!-- Filters are rendered by `src/assets/js/poi-filters.js` (imported in poi-entry.js) -->

        <!-- POI Filter Container (populated by JavaScript) -->
        <fieldset id="poi-filter-section" style="display:none;">
            <legend id="poi-filter-heading"><?php echo t('pois_filter_categories', 'Categories'); ?></legend>
            <div id="poi-filter" role="group" aria-labelledby="poi-filter-heading">
            </div>
        </fieldset>
        <!-- POI Control Buttons -->
        <div id="poi-filter-buttons">
            <button id="apply-poi-filter" class="btn btn-primary"><?php echo t('apply','Apply'); ?></button>
            <?php if (!empty($_SESSION['user_id'])): ?>
                <button id="import-selected-pois" class="btn btn-success"><?php echo htmlspecialchars(t('import_selected','Import Selected')); ?> (<span id="selected-count">0</span>)</button>
            <?php else: ?>
                <button class="btn" disabled title="<?php echo htmlspecialchars(t('please_login_to_import','Please log in to import POIs')); ?>"><?php echo htmlspecialchars(t('import_selected','Import Selected')); ?> (<span id="selected-count">0</span>)</button>
            <?php endif; ?>
        </div>

        <?php if (empty($_SESSION['user_id'])): ?>
            <div class="warning-notice">
                <?php echo htmlspecialchars(t('please_login_to_edit_or_import_pois','Please log in to add or import POIs.')); ?>
                    <a href="<?php echo htmlspecialchars(app_url('index.php/user/login')); ?>"><?php echo htmlspecialchars(t('login','Login')); ?></a>
                    &nbsp;|&nbsp;
                    <a href="<?php echo htmlspecialchars(app_url('index.php/user/register')); ?>"><?php echo htmlspecialchars(t('register','Register')); ?></a>
            </div>
        <?php endif; ?>
            <div id="poi-empty-hint">
                <div class="warning-notice">
                    <strong><?php echo t('pois_none_found','No POIs found with the current filters.'); ?></strong>
                    <div class="poi-hint-item">
                        <button id="show-all-pois" class="btn btn-small"><?php echo t('pois_show_all','Show all POIs in view'); ?></button>
                    </div>
                </div>
            </div>
    </div>
    
    <!-- Karte mit Filter-Rahmen -->
    <div class="pois-map-with-filters">
        <!-- Filter oben -->
        <div class="poi-filters-top" id="poi-filters-top"></div>
        
        <!-- Filter links -->
        <div class="poi-filters-left" id="poi-filters-left"></div>
        
        <!-- Karte in der Mitte -->
        <div id="pois-map" class="pois-map"></div>
        
        <!-- Filter rechts -->
        <div class="poi-filters-right" id="poi-filters-right"></div>
        
        <!-- Filter unten -->
        <div class="poi-filters-bottom" id="poi-filters-bottom"></div>
    </div>
    </div><!-- .pois-layout -->

    <div id="poi-list" class="poi-list" aria-live="polite"></div>
</main>

<!-- Leaflet JS (defer: loaded after page render) -->
<script src="<?php echo asset_url('assets/vendor/leaflet/leaflet.js'); ?>" crossorigin="" defer></script>
<!-- MarkerCluster JS (defer) -->
<script src="<?php echo asset_url('assets/vendor/leaflet.markercluster/leaflet.markercluster.js'); ?>" defer></script>

<!-- Ensure Leaflet uses CDN images if local images are missing -->
<script src="<?php echo htmlspecialchars(asset_url('assets/js/leaflet-icons-fix.js')); ?>?v=<?php echo $ASSET_VERSION; ?>" defer></script>

<script>
    // POI-specific globals (general config in poi-globals.js)
    // Expose current logged-in user id (or null) for POI-specific logic
    window.CURRENT_USER_ID = <?php echo json_encode($_SESSION['user_id'] ?? null); ?>;
    if (window.DEBUG) console.log('DEBUG: window.CURRENT_USER_ID set to: ' + window.CURRENT_USER_ID);
    // expose CSRF token for POST actions from the frontend
    window.CSRF_TOKEN = <?php echo json_encode(function_exists('csrf_token') ? csrf_token() : ''); ?>;
</script>

<!-- Global configuration (loaded early, before other modules) -->
<script src="<?php echo htmlspecialchars(asset_url('assets/js/poi-globals.js')); ?>?v=<?php echo $ASSET_VERSION; ?>"></script>

<!-- Country preselection: populate from backend and center map on select (defer) -->
<script src="<?php echo htmlspecialchars(asset_url('assets/js/poi-country-select.js')); ?>?v=<?php echo $ASSET_VERSION; ?>" defer></script>

<!-- I18N filter group titles (loaded before filters renderer, defer) -->
<script src="<?php echo htmlspecialchars(asset_url('assets/js/poi-i18n-loader.js')); ?>?v=<?php echo $ASSET_VERSION; ?>" defer></script>

<!-- Asset diagnostic helper: only runs in DEBUG mode (defer) -->
<script src="<?php echo htmlspecialchars(asset_url('assets/js/poi-asset-diagnostics.js')); ?>?v=<?php echo $ASSET_VERSION; ?>" defer></script>

<!-- Secure popup template helper (loaded before ESM bootstrap) -->
<script src="<?php echo htmlspecialchars(asset_url('assets/js/PoiPopupTemplate.js')); ?>?v=<?php echo $ASSET_VERSION; ?>" defer></script>

<!-- ESM entry that bootstraps PoiMapManager and PoiTiles (defer for non-critical load) -->
<!-- Quick-search module (separate from filter-driven map search) -->
<script type="module" src="<?php echo htmlspecialchars(asset_url('assets/js/PoiMapManager_Quick.js')); ?>?v=<?php echo $ASSET_VERSION; ?>" defer></script>
<script type="module" src="<?php echo htmlspecialchars(asset_url('assets/js/poi-entry.js')); ?>?v=<?php echo $ASSET_VERSION; ?>" defer></script>

<?php
// Include footer with Bootstrap JS and main.js
if (file_exists(__DIR__ . '/../includes/footer.php')) {
    require_once __DIR__ . '/../includes/footer.php';
}
?>
