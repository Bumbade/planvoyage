<?php

// create_route.php removed: PDF upload/create logic deprecated.
// Use the controller-backed create flow instead (src/controllers/RouteController::create).
http_response_code(410);
require_once __DIR__ . '/../../helpers/i18n.php';
echo htmlspecialchars(t('legacy_route_removed', 'Route creation via legacy create_route.php has been removed. Use the web UI create form.'));
exit;
