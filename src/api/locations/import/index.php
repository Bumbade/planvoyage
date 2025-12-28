<?php
// Directory-index proxy. PostGIS importer removed on this host — return
// a clear JSON error to avoid attempts to reach an unavailable Postgres.
if (php_sapi_name() !== 'cli') {
	header('Content-Type: application/json; charset=utf-8');
}
echo json_encode([
	'ok' => false,
	'error' => 'postgis_removed',
	'msg' => 'PostGIS import disabled on this host. Use the Overpass importer or LocationController::import.'
]);
exit;
