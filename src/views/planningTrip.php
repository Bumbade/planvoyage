<?php
// planningTrip.php - trip planning UI with map-driven POI loading
if (file_exists(__DIR__ . '/../config/env.php')) {
    require_once __DIR__ . '/../config/env.php';
}
if (file_exists(__DIR__ . '/../helpers/i18n.php')) {
    require_once __DIR__ . '/../helpers/i18n.php';
}

// Inject Leaflet CSS and MarkerCluster CSS into the head
$HEAD_EXTRA = '';
$HEAD_EXTRA .= '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">';
$HEAD_EXTRA .= '<link rel="stylesheet" href="' . asset_url('assets/vendor/leaflet.markercluster/MarkerCluster.css') . '">';
$HEAD_EXTRA .= '<link rel="stylesheet" href="' . asset_url('assets/vendor/leaflet.markercluster/MarkerCluster.Default.css') . '">';

require_once __DIR__ . '/../includes/header.php';
?>

<?php $flashOk = function_exists('flash_get') ? flash_get('success') : null; if ($flashOk): ?>
    <div class="flash-success"><?php echo htmlspecialchars($flashOk); ?></div>
<?php endif; ?>
<?php $flashErr = function_exists('flash_get') ? flash_get('error') : null; if ($flashErr): ?>
    <div class="error"><?php echo htmlspecialchars($flashErr); ?></div>
<?php endif; ?>

<main>
    <h1><?php echo t('plan_trip', 'Plan a Trip'); ?></h1>
    <p><?php echo t('planning_instructions', 'Use the map to select POIs for your trip. Pan/zoom to load POIs in the current view.'); ?></p>

    <div id="planning-controls">
        <!-- Future controls go here (filters, date/time, vehicle) -->
    </div>

    <div id="pois-map" class="pois-map"></div>

</main>

<!-- Leaflet JS -->
<script src="<?php echo asset_url('assets/vendor/leaflet/leaflet.js'); ?>" crossorigin=""></script>
<!-- MarkerCluster JS -->
<script src="<?php echo asset_url('assets/vendor/leaflet.markercluster/leaflet.markercluster.js'); ?>"></script>

<script>
    // Expose APP_BASE and translations for frontend scripts
    window.APP_BASE = <?php echo json_encode(isset($appBase) ? $appBase : '/'); ?>;
    window.I18N = window.I18N || {};
    window.I18N.pois = {
        loading: <?php echo json_encode(t('pois_loading', 'Loading POIs…')); ?>,
        none_found: <?php echo json_encode(t('pois_none_found', 'No POIs found in the current view.')); ?>,
        failed: <?php echo json_encode(t('pois_failed', 'Failed to load POIs. See console for details.')); ?>
    };
</script>

<script src="<?php echo htmlspecialchars(asset_url('assets/js/PoiMapManager.js')); ?>?v=<?php echo time(); ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
