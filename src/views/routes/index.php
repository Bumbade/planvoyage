<?php
// This file displays the overview of all routes created by the user.
// The controller wrapper (RouteController::index) may include this file and
// provide a $routes variable. If not provided, fetch routes directly from DB.

// Start session early for delete handling
if (file_exists(__DIR__ . '/../../helpers/session.php')) {
    require_once __DIR__ . '/../../helpers/session.php';
    start_secure_session();
}

// IMPORTANT: Handle delete request BEFORE any output or header include
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['route_id'])) {
    $route_id = (int)$_POST['route_id'];
    try {
        require_once __DIR__ . '/../../config/mysql.php';
        $db = get_db();

        // Verify ownership
        $stmt = $db->prepare('SELECT user_id FROM routes WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $route_id]);
        $route = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($route && isset($_SESSION) && $route['user_id'] == $_SESSION['user_id']) {
            // Delete the route
            $stmt = $db->prepare('DELETE FROM routes WHERE id = :id');
            $stmt->execute([':id' => $route_id]);

            // Use session flash instead of header redirect
            if (file_exists(__DIR__ . '/../../helpers/session.php')) {
                require_once __DIR__ . '/../../helpers/session.php';
                flash_set('success', 'Route deleted successfully.');
            }
            header('Location: ' . $_SERVER['HTTP_REFERER']);
            exit;
        }
    } catch (Exception $e) {
        error_log('Failed to delete route: ' . $e->getMessage());
    }
}

global $appBase;
if (!function_exists('config')) {
    require_once __DIR__ . '/../../config/app.php';
}
$appBase = config('app.base') ?? '/Allgemein/planvoyage/src';
require_once __DIR__ . '/../../includes/header.php';

if (!isset($routes)) {
    // fallback: load DB helper and query
    require_once __DIR__ . '/../../config/mysql.php';
    $db = get_db();
    $stmt = $db->prepare('SELECT id, user_id, name, start_date, end_date, created_at FROM routes ORDER BY created_at DESC');
    $stmt->execute();
    $routes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Debug helper: append ?debug_routes=1 to the URL to print the fetched routes array.
if (!empty($_GET['debug_routes'])) {
    echo '<div class="debug-box">';
    echo '<h3>DEBUG: $routes</h3>';
    echo '<pre>' . htmlspecialchars(print_r($routes, true)) . '</pre>';
    echo '<p>DB quick-check SQL (run in your MySQL client):</p>';
    echo '<pre>SELECT id, user_id, name, start_date, end_date, created_at FROM routes ORDER BY created_at DESC LIMIT 50;</pre>';
    echo '</div>';
}

// Quick stats: show total number of routes and newest route timestamp
try {
    if (!isset($db)) {
        require_once __DIR__ . '/../../config/mysql.php';
        $db = get_db();
    }
    $cntStmt = $db->query('SELECT COUNT(*) AS c, MAX(created_at) AS newest FROM routes');
    $cntRow = $cntStmt->fetch(PDO::FETCH_ASSOC);
    $totalRoutes = (int)($cntRow['c'] ?? 0);
    $newest = $cntRow['newest'] ?? null;
} catch (Exception $e) {
    $totalRoutes = null;
    $newest = null;
}

// Flash messages
$flashOk = flash_get('success');
$flashErr = flash_get('error');
?>

<?php if ($flashOk): ?>
    <div class="flash-success">
        <?php echo htmlspecialchars($flashOk); ?>
    </div>
<?php endif; ?>

<?php if ($flashErr): ?>
    <div class="flash-error">
        <?php echo htmlspecialchars($flashErr); ?>
    </div>
<?php endif; ?>

<div class="container">
    <h1><?php echo htmlspecialchars(t('my_travel_routes', 'My Travel Routes')); ?></h1>

    <?php if (!empty($_SESSION['user_id'])): ?>
        <a href="<?php echo htmlspecialchars(app_url('/index.php/routes/create')); ?>" class="btn btn-primary"><?php echo htmlspecialchars(t('create_new_route', 'Create New Route')); ?></a>
        <table class="table">
            <thead>
                <tr>
                    <th><?php echo htmlspecialchars(t('col_id', 'ID')); ?></th>
                    <th><?php echo htmlspecialchars(t('col_name', 'Name')); ?></th>
                    <th><?php echo htmlspecialchars(t('route_start_date', 'Start Date:')); ?></th>
                    <th><?php echo htmlspecialchars(t('route_end_date', 'End Date:')); ?></th>
                    <th><?php echo htmlspecialchars(t('col_actions', 'Actions')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($routes as $route): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($route['id']); ?></td>
                        <td><?php echo htmlspecialchars($route['name']); ?></td>
                        <td><?php echo htmlspecialchars($route['start_date']); ?></td>
                        <td><?php echo htmlspecialchars($route['end_date']); ?></td>
                        <td>
                            <div style="display:flex;gap:8px;align-items:center;">
                                <?php $viewUrl = app_url('/index.php/routes/view') . '?id=' . urlencode($route['id']); ?>
                                <a href="<?php echo htmlspecialchars($viewUrl); ?>" class="btn btn-info">
                                    <?php echo htmlspecialchars(t('view', 'View')); ?>
                                </a>
                                <form method="POST" class="form-inline" style="margin:0;background-color:transparent;box-shadow:none;border:none;" onsubmit="return confirm('<?php echo t('confirm_delete_route', 'Are you sure you want to delete this route?'); ?>');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="route_id" value="<?php echo htmlspecialchars($route['id']); ?>">
                                    <button type="submit" class="btn btn-danger">
                                        <?php echo htmlspecialchars(t('delete', 'Delete')); ?>
                                    </button>
                                </form>  
                                <?php $viewUrl = app_url('/printout.php') . '?id=' . urlencode($route['id']); ?>
                                <a href="<?php echo htmlspecialchars($viewUrl); ?>" class="btn btn-info">
                                    Print Overview
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="notice">
            <h2><?php echo htmlspecialchars(t('access_registered_only', 'Registered users only')); ?></h2>
            <p><?php echo htmlspecialchars(t('must_login_to_view_routes', 'You must log in or register to view routes.')); ?></p>
                <p>
                <a href="<?php echo htmlspecialchars(app_url('/user/login')); ?>" class="btn btn-secondary"><?php echo htmlspecialchars(t('login', 'Login')); ?></a>
                <a href="<?php echo htmlspecialchars(app_url('/user/register')); ?>" class="btn btn-link"><?php echo htmlspecialchars(t('register', 'Register')); ?></a>
            </p>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>