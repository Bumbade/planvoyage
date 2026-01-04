<?php
// poi-list.php - My POIs tile view
// Load required helpers
if (file_exists(__DIR__ . '/../bootstrap/RequiredHelpers.php')) {
    require_once __DIR__ . '/../bootstrap/RequiredHelpers.php';
    RequiredHelpers::loadPoiHelpers();
} else {
    if (file_exists(__DIR__ . '/../helpers/session.php')) {
        require_once __DIR__ . '/../helpers/session.php';
        if (function_exists('start_secure_session')) {
            start_secure_session();
        }
    }
    if (file_exists(__DIR__ . '/../helpers/i18n.php')) {
        require_once __DIR__ . '/../helpers/i18n.php';
    }
}

// Cache asset version
$ASSET_VERSION = defined('APP_VERSION') ? APP_VERSION : filemtime(__FILE__);

require_once __DIR__ . '/../includes/header.php';
?>
<main>
    <!-- My POIs tile view (visible to logged-in users) -->
    <?php if (!empty($_SESSION['user_id'])): ?>
        <div id="my-pois" class="my-pois-section card">
            <h2 class="section-title"><?php echo htmlspecialchars($I18N['pois']['my_pois'] ?? 'My POIs'); ?></h2>
            <div id="my-pois-tiles" aria-live="polite" class="poi-tiles">
                <p class="muted"><?php echo htmlspecialchars($I18N['general']['loading'] ?? 'Loading...'); ?></p>
            </div>
        </div>
    <?php else: ?>
        <div class="warning-notice">
            <?php echo htmlspecialchars(t('please_login_to_view_pois','Please log in to view your POIs.')); ?>
            <a href="<?php echo htmlspecialchars(app_url('index.php/user/login')); ?>"><?php echo htmlspecialchars(t('login','Login')); ?></a>
            &nbsp;|&nbsp;
            <a href="<?php echo htmlspecialchars(app_url('index.php/user/register')); ?>"><?php echo htmlspecialchars(t('register','Register')); ?></a>
        </div>
    <?php endif; ?>
</main>

<script>
    // POI-specific globals
    window.CURRENT_USER_ID = <?php echo json_encode($_SESSION['user_id'] ?? null); ?>;
    window.CSRF_TOKEN = <?php echo json_encode(function_exists('csrf_token') ? csrf_token() : ''); ?>;
</script>

<!-- Global configuration (loaded early, before other modules) -->
<script src="<?php echo htmlspecialchars(asset_url('assets/js/poi-globals.js')); ?>?v=<?php echo $ASSET_VERSION; ?>"></script>

<!-- I18N filter group titles -->
<script src="<?php echo htmlspecialchars(asset_url('assets/js/poi-i18n-loader.js')); ?>?v=<?php echo $ASSET_VERSION; ?>" defer></script>

<!-- Load only PoiTiles (no map/Leaflet required) -->
<script type="module" src="<?php echo htmlspecialchars(asset_url('assets/js/poi-tiles-only.js')); ?>?v=<?php echo $ASSET_VERSION; ?>" defer></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
