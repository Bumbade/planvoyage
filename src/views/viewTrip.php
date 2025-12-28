<?php
global $appBase;
if (!function_exists('config')) {
    require_once __DIR__ . '/../config/app.php';
}
$appBase = config('app.base') ?? '/Allgemein/planvoyage/src';

// Include header (which now starts session automatically)
include __DIR__ . '/../includes/header.php';
$trips = [];
if (!empty($_SESSION['user_id'])) {
    try {
        if (file_exists(__DIR__ . '/../config/mysql.php')) {
            require_once __DIR__ . '/../config/mysql.php';
            $db = get_db();
            // Note: Routes are stored in the 'routes' table, not 'trips' table
            $stmt = $db->prepare('SELECT id, name, start_date, end_date, created_at FROM routes WHERE user_id = :user_id ORDER BY created_at DESC');
            $stmt->execute([':user_id' => (int)$_SESSION['user_id']]);
            $trips = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        error_log('Failed to fetch trips: ' . $e->getMessage());
    }
}

// Handle delete request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['trip_id'])) {
    $trip_id = (int)$_POST['trip_id'];
    try {
        if (file_exists(__DIR__ . '/../config/mysql.php')) {
            require_once __DIR__ . '/../config/mysql.php';
            $db = get_db();

            // Verify ownership - routes table, not trips
            $stmt = $db->prepare('SELECT user_id FROM routes WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $trip_id]);
            $trip = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($trip && $trip['user_id'] == $_SESSION['user_id']) {
                // Delete the trip from routes table
                $stmt = $db->prepare('DELETE FROM routes WHERE id = :id');
                $stmt->execute([':id' => $trip_id]);
                flash_set('success', t('trip_deleted', 'Trip deleted successfully.'));
                header('Location: ' . app_url('trips'));
                exit;
            }
        }
    } catch (Exception $e) {
        error_log('Failed to delete trip: ' . $e->getMessage());
        flash_set('error', t('error_deleting_trip', 'Error deleting trip.'));
    }
}
?>

<?php $flashOk = flash_get('success');
if ($flashOk): ?>
    <div class="flash-success"><?php echo htmlspecialchars($flashOk); ?></div>
<?php endif; ?>
<?php $flashErr = flash_get('error');
if ($flashErr): ?>
    <div class="error"><?php echo htmlspecialchars($flashErr); ?></div>
<?php endif; ?>

<main class="container">
    <h1><?php echo t('trips', 'Trips'); ?></h1>
    
    <?php if (!empty($_SESSION['user_id'])): ?>
        <a href="<?php echo htmlspecialchars(view_url('views/routes/create.php')); ?>" class="btn btn-primary margin-bottom-medium">
            <?php echo htmlspecialchars(t('create_new_trip', 'Create New Trip')); ?>
        </a>
        
        <?php if (empty($trips)): ?>
            <div class="panel-light text-center">
                <p><?php echo htmlspecialchars(t('no_trips', 'No trips yet. Create your first trip!')); ?></p>
            </div>
        <?php else: ?>
            <div class="grid-auto-fill">
                <?php foreach ($trips as $trip): ?>
                    <div class="card-box">
                        <h3 class="text-primary margin-top-small">
                            <a href="<?php echo htmlspecialchars(view_url('views/routes/view.php?id=' . urlencode($trip['id']))); ?>" class="text-primary link-no-underline">
                                <?php echo htmlspecialchars($trip['name']); ?>
                            </a>
                        </h3>
                        
                        <?php if (!empty($trip['description'])): ?>
                            <p class="trip-description-meta">
                                <?php echo htmlspecialchars(substr($trip['description'], 0, 100)) . (strlen($trip['description']) > 100 ? '...' : ''); ?>
                            </p>
                        <?php endif; ?>
                        
                        <div class="trip-dates-meta">
                            <?php if (!empty($trip['start_date']) || !empty($trip['end_date'])): ?>
                                <p class="trip-date-item">
                                    📅 
                                    <?php
                                    if (!empty($trip['start_date']) && !empty($trip['end_date'])) {
                                        echo htmlspecialchars($trip['start_date']) . ' → ' . htmlspecialchars($trip['end_date']);
                                    } elseif (!empty($trip['start_date'])) {
                                        echo htmlspecialchars(t('start', 'Start')) . ': ' . htmlspecialchars($trip['start_date']);
                                    }
                    ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        
                        <div class="trip-actions">
                            <a href="<?php echo htmlspecialchars(view_url('views/routes/view.php?id=' . urlencode($trip['id']))); ?>" class="btn btn-primary trip-action-btn">
                                <?php echo htmlspecialchars(t('view', 'View')); ?>
                            </a>
                            <a href="<?php echo htmlspecialchars(view_url('views/routes/view.php?id=' . urlencode($trip['id']))); ?>" class="btn btn-success trip-action-btn">
                                <?php echo htmlspecialchars(t('edit', 'Edit')); ?>
                            </a>
                            <form method="POST" class="trip-action-form" onsubmit="return confirm('<?php echo t('confirm_delete_trip', 'Are you sure you want to delete this trip?'); ?>');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="trip_id" value="<?php echo htmlspecialchars($trip['id']); ?>">
                                <button type="submit" class="btn btn-danger trip-action-btn">
                                    <?php echo htmlspecialchars(t('delete', 'Delete')); ?>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="panel-light login-notice">
            <p><?php echo htmlspecialchars(t('login_to_view_trips', 'Please log in to view your trips.')); ?></p>
            <a href="<?php echo htmlspecialchars(app_url('index.php/user/login')); ?>" class="btn btn-primary">
                <?php echo htmlspecialchars(t('login', 'Login')); ?>
            </a>
        </div>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
