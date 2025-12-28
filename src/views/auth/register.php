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

// Handle POST registration submission
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../controllers/UserController.php';
    $controller = new UserController();

    $token = $_POST['csrf_token'] ?? null;
    if (!csrf_check($token)) {
        $error = 'Invalid CSRF token';
    } else {
        $result = $controller->register($_POST);
        if (!empty($result['success'])) {
            // Redirect to login (use front-controller path to avoid rewrite issues)
            header('Location: ' . app_url('index.php/user/login'));
            exit;
        }
        $error = $result['message'] ?? 'Registration failed';
    }
}

include __DIR__ . '/../../includes/header.php';
?>
<main>
    <div class="auth-container">
        <h1><?php echo htmlspecialchars(t('register', 'Register')); ?></h1>
        <?php if (!empty($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php $flash = flash_get('success');
if ($flash): ?>
            <div class="flash-success"><?php echo htmlspecialchars($flash); ?></div>
        <?php endif; ?>
        <form method="post" action="<?php echo htmlspecialchars(app_url('index.php/user/register')); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <label>
                <span><?php echo htmlspecialchars(t('name_label', 'Name:')); ?></span>
                <input type="text" name="name" required>
            </label>
            <label>
                <span><?php echo htmlspecialchars(t('email_label', 'Email:')); ?></span>
                <input type="email" name="email" required autocomplete="email">
            </label>
            <label>
                <span><?php echo htmlspecialchars(t('password_label', 'Password:')); ?></span>
                <input id="password" name="password" type="password" autocomplete="new-password" required pattern="(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}" title="<?php echo t('password_requirements_title','Mindestens 8 Zeichen, ein Großbuchstabe, ein Kleinbuchstabe, eine Zahl und ein Sonderzeichen'); ?>">
            </label>
            <label>
                <span><?php echo htmlspecialchars(t('password_confirm_label', 'Confirm Password:')); ?></span>
                <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required>
            </label>
            <label style="display:flex;align-items:center;gap:8px;margin-top:6px;">
                <input id="show_password" type="checkbox" aria-controls="password password_confirm">
                <span><?php echo htmlspecialchars(t('show_password', 'Show password')); ?></span>
            </label>
            <div id="pw-match" style="color:#b00;margin-top:6px;display:none;"><?php echo htmlspecialchars(t('pw_mismatch','Passwörter stimmen nicht überein')); ?></div>
            <div id="pw-rules" class="pw-rules" aria-live="polite">
                <ul>
                    <li id="rule-length" class="invalid"><?php echo htmlspecialchars(t('pw_rule_length','Mindestens 8 Zeichen')); ?></li>
                    <li id="rule-upper" class="invalid"><?php echo htmlspecialchars(t('pw_rule_upper','Mindestens ein Großbuchstabe (A-Z)')); ?></li>
                    <li id="rule-lower" class="invalid"><?php echo htmlspecialchars(t('pw_rule_lower','Mindestens ein Kleinbuchstabe (a-z)')); ?></li>
                    <li id="rule-digit" class="invalid"><?php echo htmlspecialchars(t('pw_rule_digit','Mindestens eine Zahl (0-9)')); ?></li>
                    <li id="rule-special" class="invalid"><?php echo htmlspecialchars(t('pw_rule_special','Mindestens ein Sonderzeichen (z. B. !@#$%)')); ?></li>
                </ul>
            </div>
            <button type="submit"><?php echo htmlspecialchars(t('register_button', 'Register')); ?></button>
        </form>
    </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
<style>
.pw-rules{font-size:0.9rem;margin-top:0.5rem}
.pw-rules ul{list-style:none;padding-left:0;margin:0}
.pw-rules li{padding:2px 0}
.pw-rules li.invalid{color:#b00}
.pw-rules li.valid{color:#080}
</style>
<script>
(function(){
    var pw = document.getElementById('password');
    var pwc = document.getElementById('password_confirm');
    if(!pw) return;
    var rules = {
        length: document.getElementById('rule-length'),
        upper: document.getElementById('rule-upper'),
        lower: document.getElementById('rule-lower'),
        digit: document.getElementById('rule-digit'),
        special: document.getElementById('rule-special')
    };
    var pwMatchEl = document.getElementById('pw-match');
    var showBox = document.getElementById('show_password');
    function set(el, ok){ if(!el) return; el.className = ok ? 'valid' : 'invalid'; }
    function validate(val){
        set(rules.length, val.length >= 8);
        set(rules.upper, /[A-Z]/.test(val));
        set(rules.lower, /[a-z]/.test(val));
        set(rules.digit, /[0-9]/.test(val));
        set(rules.special, /[^A-Za-z0-9]/.test(val));
    }
    pw.addEventListener('input', function(e){ validate(e.target.value); checkMatch(); });
    if(pwc) pwc.addEventListener('input', checkMatch);
    if(showBox){ showBox.addEventListener('change', function(){ var t = this.checked ? 'text' : 'password'; try{ pw.type = t; if(pwc) pwc.type = t; }catch(e){} }); }

    function checkMatch(){
        if(!pwc) return;
        if(pw.value === '' && pwc.value === ''){ pwMatchEl.style.display = 'none'; return; }
        if(pw.value === pwc.value){ pwMatchEl.style.display = 'none'; return true; }
        pwMatchEl.style.display = 'block'; return false;
    }

    // Prevent submit if passwords don't match
    var form = document.querySelector('form');
    if(form){ form.addEventListener('submit', function(e){ if(!checkMatch()){ e.preventDefault(); pw.focus(); } }); }

    // run once to initialize
    validate(pw.value || '');
    checkMatch();
})();
</script>
