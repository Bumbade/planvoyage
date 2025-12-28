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

// Require login
if (empty($_SESSION['user_id'])) {
    header('Location: ' . app_url('index.php/user/login'));
    exit;
}

$error = '';
$status = '';
$user = ['username' => '', 'email' => ''];

// Load current user data from session
if (!empty($_SESSION['user_id'])) {
    // Query database to get current user data
    require_once __DIR__ . '/../../config/mysql.php';
    try {
        $pdo = get_db();
        $stmt = $pdo->prepare('SELECT id, username, email FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $user = $row;
        }
    } catch (Exception $e) {
        error_log('Profile page user fetch error: ' . $e->getMessage());
    }
}

// Handle POST profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../controllers/UserController.php';
    $controller = new UserController();

    $token = $_POST['csrf_token'] ?? null;
    if (!csrf_check($token)) {
        $error = 'Invalid CSRF token';
    } else {
        $result = $controller->updateProfile($_SESSION['user_id'], $_POST);
        if (!empty($result['success'])) {
            $status = 'Profile updated successfully';
            // Reload user data from database
            try {
                $pdo = get_db();
                $stmt = $pdo->prepare('SELECT id, username, email FROM users WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $_SESSION['user_id']]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $user = $row;
                }
            } catch (Exception $e) {
                error_log('Profile reload error: ' . $e->getMessage());
            }
        } else {
            $error = $result['message'] ?? '';
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>
<main>
    <h1><?php echo htmlspecialchars(t('profile', 'Profile')); ?></h1>
    <?php if (!empty($status)): ?>
        <div class="status"><?php echo htmlspecialchars($status); ?></div>
    <?php endif; ?>
    <?php $flash = flash_get('success');
if ($flash): ?>
        <div class="flash-success"><?php echo htmlspecialchars($flash); ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post" action="<?php echo htmlspecialchars(app_url('index.php/user/profile')); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <label><?php echo htmlspecialchars(t('name_label', 'Name:')); ?> <input type="text" name="name" value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" autocomplete="name"></label><br>
        <label><?php echo htmlspecialchars(t('email_label', 'Email:')); ?> <input type="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" autocomplete="email"></label><br>
        <label><?php echo htmlspecialchars(t('new_password_label', 'New password:')); ?> <input type="password" name="password" autocomplete="new-password"></label><br>
        <label><?php echo htmlspecialchars(t('current_password_label', 'Current password (required to change):')); ?> <input type="password" name="current_password" autocomplete="current-password"></label><br>
        <button type="submit"><?php echo htmlspecialchars(t('update_profile', 'Update profile')); ?></button>
    </form>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

