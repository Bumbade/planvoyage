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

// Check if already logged in - redirect to home
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    header('Location: ' . view_url(''));
    exit;
}

// Handle POST login submission
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../controllers/UserController.php';
    // Lightweight file debug log to capture runtime issues (safe: do not store raw passwords)
    $logPath = __DIR__ . '/../../tmp/login_debug.log';
    $safePw = isset($_POST['password']) ? ('[LEN=' . strlen($_POST['password']) . ']') : '[NO_PW]';
    $logEntry = date('c') . " - POST received - email=" . ($_POST['email'] ?? '[no_email]') . " password=" . $safePw . "\n";
    @file_put_contents($logPath, $logEntry, FILE_APPEND | LOCK_EX);
    // Also try writing to the repo `src/logs` folder so we can read it from this workspace
    $altLog = __DIR__ . '/../../logs/login_debug.log';
    @file_put_contents($altLog, $logEntry, FILE_APPEND | LOCK_EX);
    error_log('[login.php] ' . trim($logEntry));
    try {
        $controller = new UserController();
        @file_put_contents($logPath, date('c') . " - UserController constructed\n", FILE_APPEND | LOCK_EX);
        @file_put_contents($altLog, date('c') . " - UserController constructed\n", FILE_APPEND | LOCK_EX);
        error_log('[login.php] UserController constructed');

        $token = $_POST['csrf_token'] ?? null;
        if (!csrf_check($token)) {
            $error = 'Invalid CSRF token';
            @file_put_contents($logPath, date('c') . " - CSRF check failed token=" . ($token ?? '[none]') . "\n", FILE_APPEND | LOCK_EX);
            @file_put_contents($altLog, date('c') . " - CSRF check failed token=" . ($token ?? '[none]') . "\n", FILE_APPEND | LOCK_EX);
            error_log('[login.php] CSRF check failed token=' . ($token ?? '[none]'));
        } else {
            @file_put_contents($logPath, date('c') . " - CSRF check passed\n", FILE_APPEND | LOCK_EX);
            @file_put_contents($altLog, date('c') . " - CSRF check passed\n", FILE_APPEND | LOCK_EX);
            error_log('[login.php] CSRF check passed');
            $result = $controller->login($_POST['email'] ?? '', $_POST['password'] ?? '');
            @file_put_contents($logPath, date('c') . " - Controller login returned: " . json_encode(['success' => !empty($result['success']), 'message' => $result['message'] ?? '']) . "\n", FILE_APPEND | LOCK_EX);
            @file_put_contents($altLog, date('c') . " - Controller login returned: " . json_encode(['success' => !empty($result['success']), 'message' => $result['message'] ?? '']) . "\n", FILE_APPEND | LOCK_EX);
            error_log('[login.php] Controller login returned: ' . json_encode(['success' => !empty($result['success']), 'message' => $result['message'] ?? '']));
            if (!empty($result['success'])) {
                // Redirect to POIs page (a view file, not a directory)
                header('Location: ' . view_url('views/viewPOIs.php'));
                exit;
            }
            $error = $result['message'] ?? 'Login failed';
        }
    } catch (\Throwable $e) {
        // Log the unexpected exception and present a safe error message.
        error_log('Login POST exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        @file_put_contents($logPath, date('c') . " - Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", FILE_APPEND | LOCK_EX);
        @file_put_contents($altLog, date('c') . " - Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", FILE_APPEND | LOCK_EX);
        error_log('[login.php] Exception: ' . $e->getMessage());
        if (function_exists('http_response_code')) {
            http_response_code(500);
        }
        // Show a generic message to the user; include details when APP_DEBUG is set.
        $debug = getenv('APP_DEBUG') ?: null;
        if (!empty($debug) && ($debug === '1' || strtolower($debug) === 'true')) {
            $error = 'Internal server error: ' . $e->getMessage();
        } else {
            $error = 'Internal server error. Please check server logs.';
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>
<main>
    <div class="auth-container">
        <h1><?php echo htmlspecialchars(t('login', 'Login')); ?></h1>
        <?php if (!empty($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php $flash = flash_get('success');
if ($flash): ?>
            <div class="flash-success"><?php echo htmlspecialchars($flash); ?></div>
        <?php endif; ?>
        <form method="post" action="<?php echo htmlspecialchars(app_url('index.php/user/login')); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <label>
                <span><?php echo htmlspecialchars(t('email_label', 'Email:')); ?></span>
                <input type="email" name="email" required autocomplete="email">
            </label>
            <label>
                <span><?php echo htmlspecialchars(t('password_label', 'Password:')); ?></span>
                <div style="position: relative; display: inline-block; width: 100%;">
                    <input type="password" name="password" id="password-field" required autocomplete="current-password" style="width: 100%; padding-right: 45px;">
                    <button type="button" id="toggle-password" style="position: absolute; right: 5px; top: 50%; transform: translateY(-50%); border: none; background: transparent; cursor: pointer; padding: 5px 10px; font-size: 18px;" title="Passwort anzeigen/verbergen">
                        👁️
                    </button>
                </div>
            </label>
            <button type="submit"><?php echo htmlspecialchars(t('login_button', 'Login')); ?></button>
        </form>
    </div>
</main>
<script>
// Toggle password visibility
document.addEventListener('DOMContentLoaded', function() {
    const toggleBtn = document.getElementById('toggle-password');
    const passwordField = document.getElementById('password-field');
    
    if (toggleBtn && passwordField) {
        toggleBtn.addEventListener('click', function() {
            const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordField.setAttribute('type', type);
            // Toggle icon between eye and crossed-out eye
            this.textContent = type === 'password' ? '👁️' : '👁️‍🗨️';
        });
    }
});
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
