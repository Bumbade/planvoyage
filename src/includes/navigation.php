<?php
/**
 * Navigation Template
 * 
 * Displays main navigation bar with Bootstrap styling.
 * Requires: config/app.php, helpers/session.php, helpers/i18n.php
 */

// Load configuration
if (!function_exists('config')) {
    require_once __DIR__ . '/../config/app.php';
}

// Load helpers
if (file_exists(__DIR__ . '/../helpers/session.php')) {
    require_once __DIR__ . '/../helpers/session.php';
    start_secure_session();
}

if (file_exists(__DIR__ . '/../helpers/i18n.php')) {
    require_once __DIR__ . '/../helpers/i18n.php';
}

if (file_exists(__DIR__ . '/../helpers/url.php')) {
    require_once __DIR__ . '/../helpers/url.php';
}

// Load auth helper if present so we can show admin links
if (file_exists(__DIR__ . '/../helpers/auth.php')) {
    require_once __DIR__ . '/../helpers/auth.php';
}

// Translation helper fallback
if (!function_exists('t')) {
    function t($key, $default = null) {
        // If i18n is loaded, use it; otherwise return default or key
        if (function_exists('get_text')) {
            return get_text($key);
        }
        return $default ?? $key;
    }
}

// Try to fetch username when logged in
$navUser = null;
if (!empty($_SESSION['user_id'])) {
    // Prefer cached username in session
    if (!empty($_SESSION['username'])) {
        $navUser = $_SESSION['username'];
    } else {
        try {
            if (file_exists(__DIR__ . '/../config/mysql.php')) {
                require_once __DIR__ . '/../config/mysql.php';
                $db = get_db();
                $stmt = $db->prepare('SELECT username FROM users WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => (int)$_SESSION['user_id']]);
                $u = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($u && !empty($u['username'])) {
                    $navUser = $u['username'];
                    // cache for subsequent requests
                    $_SESSION['username'] = $navUser;
                }
            }
        } catch (Exception $e) {
            // don't break navigation if DB fails; just leave $navUser null
            error_log('Nav user lookup failed: ' . $e->getMessage());
        }
    }
}

// Determine if current session is an admin (fallback to session flag)
$isAdmin = false;
if (!empty($_SESSION['is_admin'])) {
    $isAdmin = true;
} elseif (function_exists('is_admin_user') && is_admin_user()) {
    $isAdmin = true;
}
?>
<nav class="navbar navbar-expand-lg navbar-light bg-light">
    <div class="container-fluid">
        <a class="navbar-brand d-flex align-items-center" href="<?php echo app_url('/'); ?>">
            <img src="<?php echo asset_url('img/PlanVoyage-Logo_200.png'); ?>" alt="<?php echo htmlspecialchars(t('brand_name', 'plan_voyage')); ?>" width="100" height="100" style="object-fit:contain; margin-right:8px;" />
            <span class="brand-text"><?php echo htmlspecialchars(t('brand_name', 'plan_voyage')); ?></span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <!-- Locations -->
                <li class="nav-item">
                    <a class="nav-link nav-btn" href="<?php echo app_url('/index.php/locations'); ?>">
                        <span class="nav-emoji">📍</span><span class="nav-label"> <?php echo t('locations') ?? 'Locations'; ?></span>
                    </a>
                </li>
                
                <!-- Routes/Trips -->
                <li class="nav-item">
                    <a class="nav-link nav-btn" href="<?php echo app_url('/index.php/routes'); ?>">
                        <span class="nav-emoji">🗺️</span><span class="nav-label"> <?php echo t('trips') ?? 'Routes'; ?></span>
                    </a>
                </li>
                <?php if (!empty($isAdmin)): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="adminDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        ⚙️ <?php echo t('admin') ?? 'Admin'; ?>
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="adminDropdown">
                        <li><a class="dropdown-item" href="<?php echo app_url('/admin/themes.php'); ?>"><?php echo t('admin_themes') ?? 'Themes'; ?></a></li>
                        <li><a class="dropdown-item" href="<?php echo app_url('/admin/user_permissions.php'); ?>"><?php echo t('user_permissions') ?? 'User Permissions'; ?></a></li>
                        <li><a class="dropdown-item" href="<?php echo app_url('/admin/import_status.php'); ?>"><?php echo t('import_status') ?? 'Import Status'; ?></a></li>
                        <li><a class="dropdown-item" href="<?php echo app_url('/admin/overpass_quick_status.php'); ?>">Overpass Quick Proxy</a></li>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>
            <ul class="navbar-nav">
                <?php if ($navUser): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <?php echo htmlspecialchars($navUser); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdown">
                            <li><a class="dropdown-item" href="<?php echo app_url('/user/profile'); ?>">
                                👤 <?php echo t('profile') ?? 'Profile'; ?>
                            </a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="<?php echo app_url('index.php/user/logoff'); ?>">
                                🚪 <?php echo t('logout') ?? 'Logout'; ?>
                            </a></li>
                        </ul>
                    </li>
                <?php else: ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo app_url('index.php/user/login'); ?>">
                            🔐 <?php echo t('login') ?? 'Login'; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo app_url('index.php/user/register'); ?>">
                            ✍️ <?php echo t('register') ?? 'Register'; ?>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
