<?php
// Header include with asset URL helper so CSS/JS resolve when app is in a subfolder
if (file_exists(__DIR__ . '/../config/env.php')) {
    require_once __DIR__ . '/../config/env.php';
}

// Start session (needed for all pages to check user_id)
if (file_exists(__DIR__ . '/../helpers/session.php')) {
    require_once __DIR__ . '/../helpers/session.php';
    start_secure_session();
}

// Ensure i18n helper is available for titles/labels
if (file_exists(__DIR__ . '/../helpers/i18n.php')) {
    require_once __DIR__ . '/../helpers/i18n.php';
}

// Load auth helpers for is_admin_user() etc
if (file_exists(__DIR__ . '/../helpers/auth.php')) {
    require_once __DIR__ . '/../helpers/auth.php';
}

// Load config and URL helpers for consistent URL generation
if (!function_exists('config')) {
    require_once __DIR__ . '/../config/app.php';
}

if (!function_exists('app_url')) {
    require_once __DIR__ . '/../helpers/url.php';
}

// Note: $appBase is set by views for backward compatibility with legacy templates
// But the proper way is to use app_url() function from helpers/url.php
// which reads from config('app.base') in .env

// Load app_url and asset_url from helpers if not already loaded
if (!function_exists('app_url')) {
    if (file_exists(__DIR__ . '/../helpers/url.php')) {
        require_once __DIR__ . '/../helpers/url.php';
    } else {
        // Fallback implementation
        function app_url($path = '') {
            if (!function_exists('config')) {
                require_once __DIR__ . '/../config/app.php';
            }
            $base = config('app.base') ?? '';
            $base = rtrim($base, '/');
            $path = ltrim($path, '/');
            return !empty($base) ? $base . '/index.php/' . $path : '/index.php/' . $path;
        }
    }
}

// view_url() is deprecated - use asset_url() instead
if (!function_exists('view_url')) {
    function view_url($path = '') {
        return asset_url($path);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('site_title', 'PlanVoyage.de')); ?></title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?php echo htmlspecialchars(asset_url('assets/img/favicon.ico')); ?>">
    
    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Consolidated CSS System (18.12.2025) -->
    <!-- Core Styles: base styles, global variables, tokens, leaflet fixes -->
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/core.css')); ?>">
    
    <!-- Layout Styles: header, footer, navigation, page structure -->
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/layout.css')); ?>">
    
    <!-- Feature Styles: auth, POI, routes, documents, locations, forms, controls, tiles -->
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/features.css')); ?>">
    
    <!-- Theme Styles: dark mode, light mode, theme variables -->
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/css/themes.css')); ?>">
    
    <!-- Small inline fallback styles to ensure minimal styling if external CSS fails to load -->
    <style>
        /* Theme CSS variables - injected by theme_loader.php */
        <?php
        // Load and inject active theme CSS variables
        if (file_exists(__DIR__ . '/../helpers/theme_loader.php')) {
            require_once __DIR__ . '/../helpers/theme_loader.php';
            echo output_theme_css();
        }
        ?>
        
        /* fallback only; overridden by external CSS when available */
        header { background: #0044ff; color: #ffffff; padding: 10px 0; text-align: center; }
        .main-nav { background: #2c3e50; color: #fff; padding: 10px 16px; display:flex; align-items:center; }
        .main-nav .nav-links { list-style:none; margin:0; padding:0; display:flex; gap:12px; }
        .main-nav .nav-links li a { color: inherit; padding:6px 8px; text-decoration:none; }
    </style>
    <?php
    // Allow views to inject extra head content (eg. page-specific CSS like Leaflet)
    if (!empty($HEAD_EXTRA)) {
        echo $HEAD_EXTRA;
    }
    ?>
    <!-- Ensure Leaflet default icon URLs are set to CDN when Leaflet loads -->
    <script>
        (function(){
            var cdn = 'https://unpkg.com/leaflet@1.9.4/dist/images/';
            function setLeafletIconDefaults(){
                try{
                    if (window.L && L.Icon && L.Icon.Default && typeof L.Icon.Default.mergeOptions === 'function'){
                        L.Icon.Default.mergeOptions({
                            iconUrl: cdn + 'marker-icon.png',
                            iconRetinaUrl: cdn + 'marker-icon-2x.png',
                            shadowUrl: cdn + 'marker-shadow.png'
                        });
                        if (window.DEBUG) console.log('Leaflet icon defaults set to CDN:', cdn);
                        return true;
                    }
                } catch (e) {
                    if (window.DEBUG) console.warn('Setting Leaflet icon defaults failed', e);
                }
                return false;
            }
            if (!setLeafletIconDefaults()){
                var _t = setInterval(function(){ if (setLeafletIconDefaults()) clearInterval(_t); }, 50);
                setTimeout(function(){ clearInterval(_t); }, 5000);
            }
        })();
    </script>
    <?php
    // Expose a small set of translations for client-side POI scripts.
    // Keep this minimal to avoid printing the whole translations file on every page.
    try {
        $__i18n_pois = [
            'loading' => t('pois_loading', 'Loading POIs…'),
            'failed' => t('pois_failed', 'Failed to load POIs. See console for details.'),
            'no_imported_in_db' => t('no_imported_pois_in_db', 'No imported POIs in the application database for the visible area.'),
            'import_visible_title' => t('import_visible_pois_title', 'Import visible POIs into app database'),
            'details' => t('details', 'Details'),
            'import' => t('import_poi', 'Import'),
            'no_visible_osm' => t('no_visible_osm', 'No visible POIs with osm_id to import. Zoom in or click markers to see details.'),
            'import_selected' => t('import_selected', 'Import selected'),
            // additional import-related strings
            'none_found' => t('none_found', 'No POIs found in the current view.'),
            'confirm_batch_import' => t('confirm_batch_import', 'Import %d visible POIs into the application database?'),
            'importing' => t('importing', 'Importing…'),
            'importing_n' => t('importing_n', 'Importing %d POIs…'),
            'importing_osm' => t('importing_osm', 'Importing OSM %s…'),
            'import_failed_prefix' => t('import_failed_prefix', 'Import failed: %s'),
            'import_failed' => t('import_failed', 'Import failed'),
            'import_failed_unknown' => t('import_failed_unknown', 'Unknown error'),
            'import_request_failed_console' => t('import_request_failed_console', 'Import request failed (see console)'),
            'import_request_failed' => t('import_request_failed', 'Import request failed'),
            'imported' => t('imported', 'Imported'),
            'imported_with_id' => t('imported_with_id', 'Imported (id %s)'),
            'imported_poi' => t('imported_poi', 'Imported POI id %s'),
            'import_error' => t('import_error', 'Import error'),
            'import_error_console' => t('import_error_console', 'Import error. See console.'),
            'import_forbidden' => t('import_forbidden', 'Import forbidden (403). Check server permissions or CSRF token.'),
            'import_request_failed_console' => t('import_request_failed_console', 'Import request failed (see console)'),
            'import_cancelled' => t('import_cancelled', 'Import cancelled by user.'),
            'import_complete_summary' => t('import_complete_summary', 'Import complete — inserted: %d, updated: %d, skipped: %d, failed: %d'),
            'batch_import_forbidden' => t('batch_import_forbidden', 'Batch import forbidden (403). Check server permissions or CSRF token.'),
            'batch_import_error' => t('batch_import_error', 'Batch import error. See console.'),
            'no_markers_selected' => t('no_markers_selected', 'No markers selected. Hold Ctrl (or Cmd) and click markers to select them.'),
            'confirm_import_selected' => t('confirm_import_selected', 'Import %d selected POIs into the application database?'),
            'import_selected_complete' => t('import_selected_complete', 'Import selected complete.'),
            // Filter labels exposed to frontend
            'hotels' => t('hotels', 'Hotels'),
            'attractions' => t('attractions', 'Attractions'),
            'food' => t('food', 'Food'),
            'nightlife' => t('nightlife', 'Nightlife'),
            'fuel' => t('fuel', 'Fuel'),
            'parking' => t('parking', 'Parking'),
            'banks' => t('banks', 'Bank / ATM'),
            'healthcare' => t('healthcare', 'Healthcare'),
            'fitness' => t('fitness', 'Fitness'),
            'laundry' => t('laundry', 'Laundry'),
            'supermarket' => t('supermarket', 'Supermarket'),
            'tobacco' => t('tobacco', 'Tobacco'),
            'cannabis' => t('cannabis', 'Cannabis'),
            'transport' => t('transport', 'Transport'),
            'dump_stations' => t('dump_stations', 'Dump Stations'),
            'campgrounds' => t('campgrounds', 'Campgrounds'),
        ];
        echo "<script>window.I18N = window.I18N || {}; window.I18N.pois = " . json_encode($__i18n_pois, JSON_UNESCAPED_UNICODE) . ";</script>\n";
        // Expose canonical POI type translations for client-side localization
        $__i18n_pois_types = [
            'food' => t('poi_type.food', 'Food'),
            'fast_food' => t('poi_type.fast_food', 'Fast Food'),
            'hotel' => t('poi_type.hotel', 'Hotel'),
            'bank' => t('poi_type.bank', 'Bank / ATM'),
            'fuel' => t('poi_type.fuel', 'Fuel'),
            'parking' => t('poi_type.parking', 'Parking'),
            'healthcare' => t('poi_type.healthcare', 'Healthcare'),
            'attraction' => t('poi_type.attraction', 'Attraction'),
            'transport' => t('poi_type.transport', 'Transport'),
            'supermarket' => t('poi_type.supermarket', 'Supermarket'),
            'shopping' => t('poi_type.shopping', 'Shopping'),
            'nightlife' => t('poi_type.nightlife', 'Nightlife'),
            'fitness' => t('poi_type.fitness', 'Fitness'),
            'laundry' => t('poi_type.laundry', 'Laundry'),
            'tobacco' => t('poi_type.tobacco', 'Tobacco'),
            'cannabis' => t('poi_type.cannabis', 'Cannabis'),
            'dump_station' => t('poi_type.dump_station', 'Dump Station'),
            'campgrounds' => t('poi_type.campgrounds', 'Campgrounds'),
            'poi' => t('poi_type.poi', 'Point of Interest')
        ];
        echo "<script>window.I18N = window.I18N || {}; window.I18N.pois_types = " . json_encode($__i18n_pois_types, JSON_UNESCAPED_UNICODE) . ";</script>\n";
        // Expose a compact countries mapping for client-side use (ISO -> localized name)
        $__i18n_countries = [
            'CA' => t('country_CA', 'Canada'),
            'US' => t('country_US', 'United States'),
            'MX' => t('country_MX', 'Mexico'),
            'GB' => t('country_GB', 'United Kingdom'),
            'FR' => t('country_FR', 'France'),
            'DE' => t('country_DE', 'Germany'),
            'IT' => t('country_IT', 'Italy'),
            'ES' => t('country_ES', 'Spain'),
            'AT' => t('country_AT', 'Austria'),
            'CH' => t('country_CH', 'Switzerland'),
            'NL' => t('country_NL', 'Netherlands'),
            'BE' => t('country_BE', 'Belgium'),
            'LU' => t('country_LU', 'Luxembourg'),
            'PT' => t('country_PT', 'Portugal'),
            'GR' => t('country_GR', 'Greece'),
            'SE' => t('country_SE', 'Sweden'),
            'NO' => t('country_NO', 'Norway'),
            'DK' => t('country_DK', 'Denmark'),
            'FI' => t('country_FI', 'Finland'),
            'PL' => t('country_PL', 'Poland'),
            'CZ' => t('country_CZ', 'Czech Republic'),
            'SK' => t('country_SK', 'Slovakia'),
            'HU' => t('country_HU', 'Hungary'),
            'RO' => t('country_RO', 'Romania'),
            'BG' => t('country_BG', 'Bulgaria'),
            'GE' => t('country_GE', 'Georgia'),
            'RU' => t('country_RU', 'Russia'),
            'UA' => t('country_UA', 'Ukraine'),
            'JP' => t('country_JP', 'Japan'),
            'CN' => t('country_CN', 'China'),
            'IN' => t('country_IN', 'India'),
            'AU' => t('country_AU', 'Australia'),
            'NZ' => t('country_NZ', 'New Zealand'),
            'BR' => t('country_BR', 'Brazil'),
            'AR' => t('country_AR', 'Argentina'),
            'ZA' => t('country_ZA', 'South Africa'),
            // Provide a localized label for unknown / missing country values
            'Unknown' => t('country_unknown', 'Unknown'),
        ];
        echo "<script>window.I18N = window.I18N || {}; window.I18N.countries = " . json_encode($__i18n_countries, JSON_UNESCAPED_UNICODE) . ";</script>\n";
    } catch (\Exception $e) {
        // fail silently if i18n isn't available
    }
    // Provide a lightweight non-module `escapeHtml` shim for legacy inline scripts
    // This ensures pages using `escapeHtml(...)` in inline scripts don't fail
    // when the module-based utils export isn't available yet.
    echo "<script>(function(){if(typeof window.escapeHtml==='function')return;window.escapeHtml=function(s){if(s===null||s===undefined)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\"/g,'&quot;').replace(/'/g,'&#39;');};})();</script>\n";
    ?>
</head>
<body>
    <header>
        <?php
        // Render the shared navigation template so all pages get the same nav.
        // Prefer `includes/navigation.php` (consolidated); fall back to `templates/navigation.php` for older layouts.
        if (file_exists(__DIR__ . '/../includes/navigation.php')) {
            require_once __DIR__ . '/../includes/navigation.php';
        } elseif (file_exists(__DIR__ . '/../templates/navigation.php')) {
            require_once __DIR__ . '/../templates/navigation.php';
        }
        ?>
    </header>