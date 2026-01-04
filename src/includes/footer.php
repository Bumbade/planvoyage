<?php
// This file includes the footer section of the website.
?>

<footer>
<?php
// Include site-wide JS
if (!function_exists('asset_url')) {
    // load the URL/asset helpers (do not include the full header which prints navigation)
    if (file_exists(__DIR__ . '/../helpers/url.php')) {
        require_once __DIR__ . '/../helpers/url.php';
    }
}
// Ensure translation helper `t()` is available for footer strings
if (!function_exists('t')) {
    if (file_exists(__DIR__ . '/../helpers/i18n.php')) {
        require_once __DIR__ . '/../helpers/i18n.php';
    }
}
?>

<!-- Bootstrap 5.3 JS Bundle (includes Popper) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Custom Scripts -->
<script src="<?php echo htmlspecialchars(asset_url('assets/js/main.js')); ?>"></script>
<?php
// Include page-specific styles loader
if (file_exists(__DIR__ . '/page_styles.php')) {
    require_once __DIR__ . '/page_styles.php';
}
?>

    <div class="container">
        <p>&copy; <?php echo date("Y"); ?> <?php echo htmlspecialchars(t('site_title', 'PlanVoyage.de')); ?>. All rights reserved.</p>
        <p><a href="privacy.php">Privacy Policy</a> | <a href="terms.php">Terms of Service</a></p>
    </div>
</footer>