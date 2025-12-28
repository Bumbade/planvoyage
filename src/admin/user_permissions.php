<?php
/**
 * Admin: User Permissions Management
 * URL: /admin/user_permissions.php
 *
 * Allows admins to:
 * - View all users
 * - Grant/revoke cannabis filter access
 * - Manage category permissions
 */

// Security & Initialization
if (file_exists(__DIR__ . '/../helpers/session.php')) {
    require_once __DIR__ . '/../helpers/session.php';
    start_secure_session();
}
if (file_exists(__DIR__ . '/../helpers/auth.php')) {
    require_once __DIR__ . '/../helpers/auth.php';
}

// Require admin access
if (!function_exists('is_admin_user') || !is_admin_user()) {
    http_response_code(403);
    die('Access denied. Admin only.');
}

// Load helpers
require_once __DIR__ . '/../helpers/i18n.php';
require_once __DIR__ . '/../config/env.php';
if (file_exists(__DIR__ . '/../config/mysql.php')) {
    require_once __DIR__ . '/../config/mysql.php';
}

$message = '';
$error = '';

// Handle permission update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    try {
        $db = get_db();

        if ($_POST['action'] === 'toggle_cannabis') {
            $user_id = (int)($_POST['user_id'] ?? 0);
            if ($user_id > 0) {
                // Get current state
                $stmt = $db->prepare('SELECT can_access_cannabis FROM users WHERE id = :id');
                $stmt->bindValue(':id', $user_id, PDO::PARAM_INT);
                $stmt->execute();
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                $new_state = !($row['can_access_cannabis'] ?? 0);

                // Update
                $stmt = $db->prepare('UPDATE users SET can_access_cannabis = :state WHERE id = :id');
                $stmt->bindValue(':state', $new_state ? 1 : 0, PDO::PARAM_INT);
                $stmt->bindValue(':id', $user_id, PDO::PARAM_INT);
                $stmt->execute();

                $message = $new_state ?
                    'Cannabis filter access granted.' :
                    'Cannabis filter access revoked.';
            }
        }
    } catch (Throwable $e) {
        $error = 'Error: ' . htmlspecialchars($e->getMessage());
    }
}

// Load all users
$users = [];
try {
    $db = get_db();
    $stmt = $db->prepare('SELECT id, email, is_admin, can_access_cannabis FROM users ORDER BY email ASC');
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error = 'Error loading users: ' . htmlspecialchars($e->getMessage());
}

// Load header
if (file_exists(__DIR__ . '/../includes/header.php')) {
    require_once __DIR__ . '/../includes/header.php';
}
?>

<main style="max-width: 900px; margin: 2rem auto; padding: 0 1rem;">
    <h1>User Permissions</h1>
    
    <?php if ($message): ?>
        <div style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 1rem; margin-bottom: 1rem; border-radius: 4px;">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div style="background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 1rem; margin-bottom: 1rem; border-radius: 4px;">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; border: 1px solid #ddd;">
            <thead>
                <tr style="background: #f5f5f5; border-bottom: 2px solid #ddd;">
                    <th style="padding: 0.75rem; text-align: left; border-right: 1px solid #ddd;">Email</th>
                    <th style="padding: 0.75rem; text-align: center; border-right: 1px solid #ddd; width: 120px;">Admin</th>
                    <th style="padding: 0.75rem; text-align: center; border-right: 1px solid #ddd; width: 150px;">Cannabis Filter</th>
                    <th style="padding: 0.75rem; text-align: center; width: 120px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 0.75rem; border-right: 1px solid #ddd;">
                            <?php echo htmlspecialchars($user['email']); ?>
                        </td>
                        <td style="padding: 0.75rem; text-align: center; border-right: 1px solid #ddd;">
                            <?php if ($user['is_admin']): ?>
                                <span style="color: #28a745; font-weight: bold;">✓ Admin</span>
                            <?php else: ?>
                                <span style="color: #999;">-</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 0.75rem; text-align: center; border-right: 1px solid #ddd;">
                            <?php if ($user['can_access_cannabis']): ?>
                                <span style="color: #28a745; font-weight: bold;">✓ Granted</span>
                            <?php else: ?>
                                <span style="color: #999;">Not granted</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 0.75rem; text-align: center;">
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="toggle_cannabis">
                                <input type="hidden" name="user_id" value="<?php echo (int)$user['id']; ?>">
                                <button type="submit" 
                                    style="padding: 0.5rem 1rem; border: 1px solid #007bff; background: #fff; color: #007bff; border-radius: 4px; cursor: pointer; font-size: 0.9rem;">
                                    <?php echo $user['can_access_cannabis'] ? 'Revoke' : 'Grant'; ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <?php if (empty($users)): ?>
        <p style="text-align: center; color: #999; padding: 2rem;">No users found.</p>
    <?php endif; ?>
    
    <div style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #ddd;">
        <a href="http://192.168.178.115/Allgemein/planvoyage/src/admin/themes.php" style="color: #007bff; text-decoration: none;">← Back to Admin</a>
    </div>
</main>

<?php
if (file_exists(__DIR__ . '/../includes/footer.php')) {
    require_once __DIR__ . '/../includes/footer.php';
}
?>

