<?php
// PostGIS importer has been removed on this deployment. Return a clear
// JSON error instead of attempting a Postgres connection which would
// produce a 500 and an obscure message.
if (php_sapi_name() !== 'cli') {
	header('Content-Type: application/json; charset=utf-8');
}
echo json_encode([
	'ok' => false,
	'error' => 'postgis_removed',
	'msg' => 'PostGIS import disabled on this host. Use the Overpass importer or LocationController::import.'
]);
exit;
