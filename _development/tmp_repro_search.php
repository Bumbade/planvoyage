<?php
// Temporary reproduction wrapper for src/api/locations/search.php
// Usage: run `php tmp_repro_search.php` from repository root

chdir(__DIR__);

// Simulate GET parameters that caused the 500 in the UI
$_GET['type'] = 'Campground';
$_GET['country'] = 'CA';
$_GET['state'] = 'British Columbia';
// optional: set limit/offset
$_GET['limit'] = '50';
$_GET['offset'] = '0';

// Minimal server env
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';

// Run the actual endpoint
require __DIR__ . '/src/api/locations/search.php';
