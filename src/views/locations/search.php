<?php
// This file provides a search functionality for users to find specific locations.

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

$searchTerm = isset($_GET['search']) ? $_GET['search'] : '';

$locations = $location->search($searchTerm);
?>

<?php include __DIR__ . '/../../includes/header.php'; ?>

<div class="container">
    <h1><?php echo htmlspecialchars(t('search_locations', 'Search Locations')); ?></h1>
    <form method="GET" action="search.php">
        <input type="text" name="search" placeholder="<?php echo htmlspecialchars(t('enter_location_name', 'Enter location name')); ?>" value="<?php echo htmlspecialchars($searchTerm); ?>" required>
        <button type="submit"><?php echo htmlspecialchars(t('search', 'Search')); ?></button>
    </form>

    <?php if ($locations): ?>
        <h2><?php echo htmlspecialchars(t('search_results', 'Search Results:')); ?></h2>
        <ul>
            <?php foreach ($locations as $loc): ?>
                <li><?php echo htmlspecialchars($loc['name']); ?> (<?php echo htmlspecialchars($loc['type']); ?>)</li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p><?php echo htmlspecialchars(t('no_locations_found', 'No locations found.')); ?></p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>