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

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../models/Location.php';

$database = new Database();
$db = $database->getConnection();

$location = new Location($db);
$locations = $location->getAllLocations();

include __DIR__ . '/../../includes/header.php';

?>
<?php $flashOk = function_exists('flash_get') ? flash_get('success') : null;
if ($flashOk): ?>
    <div class="flash-success"><?php echo htmlspecialchars($flashOk); ?></div>
<?php endif; ?>
<?php $flashErr = function_exists('flash_get') ? flash_get('error') : null;
if ($flashErr): ?>
    <div class="error"><?php echo htmlspecialchars($flashErr); ?></div>
<?php endif; ?>

<div class="container">
    <h1><?php echo htmlspecialchars(t('locations_overview', 'Locations Overview')); ?></h1>
    <table class="table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Type</th>
                <th>Coordinates</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($locations as $loc): ?>
                <tr>
                    <td><?php echo htmlspecialchars($loc['id']); ?></td>
                    <td><?php echo htmlspecialchars($loc['name']); ?></td>
                    <td><?php echo htmlspecialchars($loc['type']); ?></td>
                    <td><?php echo htmlspecialchars($loc['coordinates']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>