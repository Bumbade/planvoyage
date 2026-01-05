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
include __DIR__ . '/../../includes/header.php';
?>

<div class="container">
    <h1><?php echo htmlspecialchars(t('create_route', 'Create a New Route')); ?></h1>
    <?php $flashErr = flash_get('error');
if ($flashErr): ?>
        <div class="error"><?php echo htmlspecialchars($flashErr); ?></div>
    <?php endif; ?>
    <?php $flashOk = flash_get('success');
if ($flashOk): ?>
        <div class="flash-success"><?php echo htmlspecialchars($flashOk); ?></div>
    <?php endif; ?>
    <form action="" method="POST">
        <?php if (function_exists('csrf_token')): ?>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <?php endif; ?>
        <div class="form-group">
            <label for="name"><?php echo htmlspecialchars(t('route_name_label', 'Route Name:')); ?></label>
            <input type="text" id="name" name="name" required>
        </div>
        <div class="form-group">
            <label for="start_date">Start Date:</label>
            <input type="date" id="start_date" name="start_date" required>
        </div>
        <div class="form-group">
            <label for="end_date"><?php echo htmlspecialchars(t('route_end_date', 'End Date:')); ?></label>
            <input type="date" id="end_date" name="end_date" required>
            <div id="date-error" class="error"></div>
        </div>
        <!-- PDF upload removed: attachments not required in this workflow -->
        <button type="submit" id="submit-btn"><?php echo htmlspecialchars(t('create_new_route', 'Create Route')); ?></button>
    </form>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script>
    (function () {
        var start = document.getElementById('start_date');
        var end = document.getElementById('end_date');
        var submit = document.getElementById('submit-btn');
        var err = document.getElementById('date-error');

        function validateDates() {
            err.style.display = 'none';
            err.textContent = '';
            submit.disabled = false;
            if (!start.value || !end.value) return;
            var sd = new Date(start.value);
            var ed = new Date(end.value);
            if (isNaN(sd.getTime()) || isNaN(ed.getTime())) return;
                // ensure end is same or after start
                if (ed < sd) {
                    err.textContent = locEndDateError;
                    err.style.display = 'block';
                    submit.disabled = true;
                } else {
                // also set the min attribute on end to help the user
                end.min = start.value;
            }
        }

        start.addEventListener('change', function() {
            // Auto-fill end date with start date + 2 weeks if end date is empty
            if (start.value && !end.value) {
                var startDate = new Date(start.value);
                startDate.setDate(startDate.getDate() + 14); // Add 14 days (2 weeks)
                var year = startDate.getFullYear();
                var month = String(startDate.getMonth() + 1).padStart(2, '0');
                var day = String(startDate.getDate()).padStart(2, '0');
                end.value = year + '-' + month + '-' + day;
            }
            validateDates();
        });
        end.addEventListener('change', validateDates);
        // run once on load
        validateDates();
    })();
</script>
<script>
    var locEndDateError = <?php echo json_encode(t('end_date_must_after_start', 'End date must be the same as or after the start date.')); ?>;
</script>