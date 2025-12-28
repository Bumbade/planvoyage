<?php
global $appBase;
if (!function_exists('config')) {
    require_once __DIR__ . '/../config/app.php';
}
$appBase = config('app.base') ?? '/Allgemein/planvoyage/src';
// Start session early if needed
if (file_exists(__DIR__ . '/../helpers/session.php')) {
    require_once __DIR__ . '/../helpers/session.php';
    start_secure_session();
}
require_once __DIR__ . '/../includes/header.php';
?>
<main>
    <h1><?php echo htmlspecialchars(t('calculate_trip', 'Calculate Trip')); ?></h1>
    <p><?php echo htmlspecialchars(t('routing_results_info', 'Routing results, turn-by-turn directions, and POIs along the route will appear here.')); ?></p>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
