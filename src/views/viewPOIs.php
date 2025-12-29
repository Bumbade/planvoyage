<?php
// viewPOIs.php - POI map page
// Load env and i18n helpers, then include the global header (which emits the <head>)
if (file_exists(__DIR__ . '/../config/env.php')) {
    require_once __DIR__ . '/../config/env.php';
}
if (file_exists(__DIR__ . '/../helpers/url.php')) {
    require_once __DIR__ . '/../helpers/url.php';
}
if (file_exists(__DIR__ . '/../helpers/i18n.php')) {
    require_once __DIR__ . '/../helpers/i18n.php';
}
// POI helpers (category lists etc)
if (file_exists(__DIR__ . '/../helpers/poi.php')) {
    require_once __DIR__ . '/../helpers/poi.php';
}
// ensure session helpers available for CSRF token
if (file_exists(__DIR__ . '/../helpers/session.php')) {
    require_once __DIR__ . '/../helpers/session.php';
    start_secure_session();
}
// ensure auth helpers available for admin checks
if (file_exists(__DIR__ . '/../helpers/auth.php')) {
    require_once __DIR__ . '/../helpers/auth.php';
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

// Inject Leaflet CSS and MarkerCluster CSS into the head
// Note: poi-popups.css and poi-controls.css are now consolidated in features.css
$HEAD_EXTRA = '';
$HEAD_EXTRA .= '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">';
$HEAD_EXTRA .= '<link rel="stylesheet" href="' . asset_url('assets/vendor/leaflet.markercluster/MarkerCluster.css') . '">';
$HEAD_EXTRA .= '<link rel="stylesheet" href="' . asset_url('assets/vendor/leaflet.markercluster/MarkerCluster.Default.css') . '">';
// Load centralized POI cluster overrides (no inline styles)
require_once __DIR__ . '/../includes/header.php';
// poi-clusters.css merged into features.css; no separate include required
require_once __DIR__ . '/../includes/header.php';
// All POI CSS (popups, controls) now loaded via features.css
// single PHP close tag below to avoid accidental whitespace/output
?>
<?php $flashOk = function_exists('flash_get') ? flash_get('success') : null; if ($flashOk): ?>
    <div class="flash-success"><?php echo htmlspecialchars($flashOk); ?></div>
<?php endif; ?>
<?php $flashErr = function_exists('flash_get') ? flash_get('error') : null; if ($flashErr): ?>
    <div class="error"><?php echo htmlspecialchars($flashErr); ?></div>
<?php endif; ?>

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
                <select id="poi-country-select" class="form-input" style="min-width:200px;margin-left:6px">
                    <option value=""><?php echo htmlspecialchars(t('select_country','Select country')); ?></option>
                </select>
            </div>
        </div>
        <h3 id="poi-filter-heading"><?php echo t('pois_filter','Filter POIs'); ?></h3>
        <fieldset id="poi-filter">
<?php
// Render filter buttons client-side. Server provides an allow-list of categories
// the current user is permitted to see to avoid rendering protected options.
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
<script>
    // Expose minimal allow-list for the client-side renderer
    window.POI_ALLOWED_CATEGORIES = <?php echo json_encode($allowed, JSON_UNESCAPED_UNICODE); ?>;
</script>

        <!-- POI filters loaded from server-generated API endpoint to avoid large inline scripts -->
        <?php $ASSET_VERSION = defined('APP_VERSION') ? APP_VERSION : filemtime(__FILE__); ?>
        <script src="<?php echo htmlspecialchars(app_url('src/api/poi-config.php')); ?>?v=<?php echo $ASSET_VERSION; ?>"></script>
        <!-- Filters are rendered by `src/assets/js/poi-filters.js` (imported in poi-entry.js) -->

        <!-- POI Filter Container (populated by JavaScript) -->
        <fieldset id="poi-filter-section">
            <legend id="poi-filter-heading"><?php echo t('pois_filter_categories', 'Categories'); ?></legend>
            <div id="poi-filter" role="group" aria-labelledby="poi-filter-heading">
                <!-- Load / Reset buttons centered under the filter (placed inside the grid so they span all columns) -->
                <div id="poi-load-controls">
                    <button id="load-pois-btn" class="btn"><?php echo htmlspecialchars(t('load_pois', 'Load POIs')); ?></button>
                    <button id="reset-poi-filters" class="btn"><?php echo htmlspecialchars(t('reset_filters', 'Reset filters')); ?></button>
                </div>
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
    <div id="pois-map" class="pois-map"></div>
    </div><!-- .pois-layout -->

    <div id="poi-list" class="poi-list" aria-live="polite"></div>

    <!-- My POIs tile view (visible to logged-in users) -->
    <?php if (!empty($_SESSION['user_id'])): ?>
        <details id="my-pois" class="my-pois-section" aria-expanded="false">
            <summary>
                <h2><?php echo htmlspecialchars($I18N['pois']['my_pois'] ?? 'My POIs'); ?></h2>
            </summary>
            <div id="my-pois-tiles" aria-live="polite" class="">
                <p class="muted"><?php echo htmlspecialchars($I18N['general']['loading'] ?? 'Loading...'); ?></p>
            </div>
        </details>
    <?php endif; ?>
</main>

<!-- Leaflet JS -->
<script src="<?php echo asset_url('assets/vendor/leaflet/leaflet.js'); ?>" crossorigin=""></script>
<!-- MarkerCluster JS -->
<script src="<?php echo asset_url('assets/vendor/leaflet.markercluster/leaflet.markercluster.js'); ?>"></script>

<!-- Ensure Leaflet uses CDN images if local images are missing -->
<script>
    (function(){
        try {
            if (typeof L === 'undefined' || !L || !L.Icon || !L.Icon.Default) return;
            var cdnPath = 'https://unpkg.com/leaflet@1.9.4/dist/images/';
            L.Icon.Default.mergeOptions({
                iconUrl: cdnPath + 'marker-icon.png',
                iconRetinaUrl: cdnPath + 'marker-icon-2x.png',
                shadowUrl: cdnPath + 'marker-shadow.png'
            });
            if (window.DEBUG) console.log('Leaflet icons: using CDN images from', cdnPath);
        } catch (e) {
            if (window.DEBUG) console.warn('Could not set Leaflet default icon URLs', e);
        }
    })();
</script>

<script>
    // Compute JS-visible base that points to the `src/` directory under the app base.
    <?php
    $norm = rtrim($GLOBALS['appBase'] ?? '', '/');
    if ($norm === '') {
        $jsBase = '/src';
    } elseif (preg_match('#/src$#', $norm)) {
        $jsBase = $norm;
    } else {
        $jsBase = $norm . '/src';
    }
    // expose asset version (computed above near poi-config)
    $asset_ver = isset($ASSET_VERSION) ? $ASSET_VERSION : (defined('APP_VERSION') ? APP_VERSION : filemtime(__FILE__));
    // DEBUG flag
    $debugFlag = (bool) ((getenv('APP_DEBUG') !== false && getenv('APP_DEBUG') !== '') || (defined('DEBUG') && DEBUG));
    ?>
    window.APP_BASE = <?php echo json_encode($jsBase); ?>;
    window.DEBUG = <?php echo json_encode($debugFlag); ?>;
    if (window.DEBUG) console.log('DEBUG: window.APP_BASE set to: ' + window.APP_BASE);
    // Expose a canonical icons base so frontend can resolve logo filenames reliably
    try {
        window.ICONS_BASE = (window.APP_BASE || '') + '/assets/icons/';
        if (window.DEBUG) console.log('DEBUG: window.ICONS_BASE set to: ' + window.ICONS_BASE);
    } catch (e) {
        if (window.DEBUG) console.warn('Could not set ICONS_BASE', e);
    }
    // Enable POI-specific debug logging when app DEBUG is on
    window.POI_DEBUG = !!window.DEBUG;
    if (window.POI_DEBUG) console.log('POI_DEBUG enabled');
    // Alias for console convenience: some developers type `APP_DEBUG` in DevTools
    // Keep this in sync with `window.DEBUG` to avoid ReferenceError when inspected.
    window.APP_DEBUG = window.DEBUG;
    // Expose current logged-in user id (or null) for client-side logic
    window.CURRENT_USER_ID = <?php echo json_encode($_SESSION['user_id'] ?? null); ?>;
    if (window.DEBUG) console.log('DEBUG: window.CURRENT_USER_ID set to: ' + window.CURRENT_USER_ID);
    // expose CSRF token for POST actions from the frontend
    window.CSRF_TOKEN = <?php echo json_encode(function_exists('csrf_token') ? csrf_token() : ''); ?>;
</script>

<script>
    // Expose canonical API base for client requests. Prefer configured api_url(),
    // otherwise derive from APP_BASE by removing trailing '/src'.
    <?php
    $apiCandidate = rtrim(api_url(''), '/');
    if (empty($apiCandidate) || $apiCandidate === '/') {
        $derived = preg_replace('#/src$#', '', $jsBase);
        if ($derived === '') $derived = '/';
        $apiBase = $derived;
    } else {
        $apiBase = $apiCandidate;
    }
    ?>
    window.API_BASE = <?php echo json_encode($apiBase); ?>;
    if (window.DEBUG) console.log('DEBUG: window.API_BASE set to: ' + window.API_BASE);
</script>

<script>
    // Country preselection: populate from restcountries and center map on select
    (function(){
        function populateCountries(sel, list){
            try{
                list.sort((a,b)=>a.name.common.localeCompare(b.name.common));
                for(const c of list){
                    const o = document.createElement('option');
                    o.value = c.cca2 || c.cca3 || (c.name && c.name.common) || '';
                    o.textContent = (c.name && c.name.common) ? c.name.common : o.value;
                            if (Array.isArray(c.latlng) && c.latlng.length>=2) {
                                o.dataset.lat = String(c.latlng[0]);
                                o.dataset.lng = String(c.latlng[1]);
                            }
                            if (c.area !== undefined && c.area !== null) {
                                o.dataset.area = String(c.area);
                            }
                    sel.appendChild(o);
                }
            }catch(e){ console.warn('populateCountries failed', e); }
        }

        document.addEventListener('DOMContentLoaded', ()=>{
            const sel = document.getElementById('poi-country-select');
            if(!sel) return;
            // Load country list (lightweight fields, include area for zoom heuristics)
            fetch('https://restcountries.com/v3.1/all?fields=name,cca2,cca3,latlng,area')
                .then(r=>r.ok? r.json() : Promise.reject(r.status))
                .then(js=> populateCountries(sel, js || []))
                .catch(e=>{
                    console.warn('Could not load country list', e);
                    // Fallback: populate a small curated country list when external API fails
                    const fallback = [
                        { name: { common: 'Germany' }, cca2: 'DE', latlng: [51, 9], area: 357022 },
                        { name: { common: 'United States' }, cca2: 'US', latlng: [38, -97], area: 9525067 },
                        { name: { common: 'United Kingdom' }, cca2: 'GB', latlng: [54, -2], area: 244376 },
                        { name: { common: 'France' }, cca2: 'FR', latlng: [46, 2], area: 643801 },
                        { name: { common: 'Canada' }, cca2: 'CA', latlng: [60, -95], area: 9984670 }
                    ];
                    try { populateCountries(sel, fallback); } catch (e2) { console.warn('populateCountries fallback failed', e2); }
                });

            sel.addEventListener('change', async ()=>{
                const opt = sel.selectedOptions && sel.selectedOptions[0];
                if(!opt) return;
                const lat = opt.dataset.lat, lng = opt.dataset.lng;
                if(!lat || !lng) return;
                try{
                    // estimate zoom from country area (km^2) when available
                    const area = opt.dataset && opt.dataset.area ? parseFloat(opt.dataset.area) : null;
                    let z = 5;
                    if (area && !Number.isNaN(area)){
                        if (area > 3000000) z = 3;
                        else if (area > 1000000) z = 4;
                        else if (area > 300000) z = 5;
                        else if (area > 100000) z = 6;
                        else if (area > 20000) z = 7;
                        else if (area > 5000) z = 8;
                        else z = 9;
                    } else {
                        z = 5;
                    }

                    // prefer the running manager instance exposed by poi-entry.js
                    if(window.PV_POI_MANAGER && window.PV_POI_MANAGER.map){
                        window.PV_POI_MANAGER.map.setView([parseFloat(lat), parseFloat(lng)], z, {animate:true});
                        try{ window.PV_POI_MANAGER.fetchAndPlot({force:true}); }catch(e){ if(window.DEBUG) console.warn('fetchAndPlot failed after country select', e); }
                    } else {
                        // if manager not ready yet, wait for it
                        document.addEventListener('PV_POI_MANAGER_READY', ()=>{
                            try{ window.PV_POI_MANAGER.map.setView([parseFloat(lat), parseFloat(lng)], z, {animate:true}); window.PV_POI_MANAGER.fetchAndPlot({force:true}); }catch(e){}
                        }, {once:true});
                    }
                }catch(e){ console.warn('Country select handler failed', e); }
            });
        });
    })();
</script>

<script>
    // Expose localized filter group titles for client-side renderer
    (function(){
        try {
            window.I18N = window.I18N || {};
            window.I18N.filter_groups = {
                'tourism': <?php echo json_encode(t('filter_group.tourism','Tourismus')); ?>,
                'gastronomy': <?php echo json_encode(t('filter_group.gastronomy','Gastronomie')); ?>,
                'mobility': <?php echo json_encode(t('filter_group.mobility','Infrastruktur')); ?>,
                'services': <?php echo json_encode(t('filter_group.services','Dienstleistungen')); ?>,
                'sport': <?php echo json_encode(t('filter_group.sport','Sport')); ?>,
                'specialty': <?php echo json_encode(t('filter_group.specialty','Spezialhandel')); ?>
            };
        } catch (e) {
            if (window.DEBUG) console.warn('Could not set filter group i18n', e);
        }
    })();
</script>

<!-- Asset diagnostic helper: checks key CSS/JS URLs and logs document.styleSheets -->
<script>
    (function(){
        try {
            if (!window.DEBUG) return; // asset diagnostics only in DEBUG
            const urls = <?php
                $assetChecks = [
                    asset_url('assets/vendor/leaflet/leaflet.css'),
                    asset_url('assets/vendor/leaflet.markercluster/MarkerCluster.css'),
                    asset_url('assets/vendor/leaflet.markercluster/MarkerCluster.Default.css'),
                    // poi-popups.css now in features.css
                    asset_url('assets/vendor/leaflet/leaflet.js'),
                    asset_url('assets/vendor/leaflet.markercluster/leaflet.markercluster.js'),
                    asset_url('assets/js/PoiMapManager.js')
                ];
                echo json_encode($assetChecks, JSON_UNESCAPED_SLASHES);
            ?>;

            async function check(u){
                try{
                    const res = await fetch(u, {cache: 'no-store'});
                    console.log('[ASSET CHECK]', u, res.status, res.ok ? 'OK' : 'NOT OK');
                    return {url:u, status:res.status, ok:res.ok};
                }catch(e){
                    console.warn('[ASSET CHECK] fetch failed for', u, e && e.message ? e.message : e);
                    return {url:u, error: String(e)};
                }
            }

            Promise.all(urls.map(u => check(u))).then(results => {
                console.group('Asset check results');
                results.forEach(r => console.log(r));
                try {
                    console.group('Loaded styleSheets');
                    for (const ss of document.styleSheets) {
                        console.log(ss.href, ss.ownerNode && ss.ownerNode.tagName, ss.disabled ? 'disabled' : 'enabled');
                    }
                    console.groupEnd();
                } catch (e) {
                    if (window.DEBUG) console.warn('Could not enumerate document.styleSheets', e);
                }
                console.groupEnd();
            }).catch(e => console.warn('Asset checks failed', e));
        } catch (e) {
            if (window.DEBUG) console.error('Asset diagnostic setup failed', e);
        }
    })();
</script>

<!-- Secure popup template helper (loaded before ESM bootstrap) -->
<script src="<?php echo htmlspecialchars(asset_url('assets/js/PoiPopupTemplate.js')); ?>?v=<?php echo $ASSET_VERSION; ?>"></script>

<!-- ESM entry that bootstraps PoiMapManager and PoiTiles -->
<script type="module" src="<?php echo htmlspecialchars(asset_url('assets/js/poi-entry.js')); ?>?v=<?php echo $ASSET_VERSION; ?>"></script>

<?php
// Include footer with Bootstrap JS and main.js
if (file_exists(__DIR__ . '/../includes/footer.php')) {
    require_once __DIR__ . '/../includes/footer.php';
}
?>
