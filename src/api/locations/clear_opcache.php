<?php
// Clear PHP opcache to reload search_overpass_quick.php with new mirrors
header('Content-Type: application/json');

$result = [
    'opcache_enabled' => function_exists('opcache_reset'),
    'opcache_reset_success' => false,
    'message' => ''
];

if (function_exists('opcache_reset')) {
    $result['opcache_reset_success'] = opcache_reset();
    $result['message'] = $result['opcache_reset_success'] 
        ? '✓ OPcache successfully cleared. New mirror configuration is now active.' 
        : '✗ OPcache reset failed.';
} else {
    $result['message'] = 'OPcache is not enabled on this server.';
}

// Also clear APCu cache if available
if (function_exists('apcu_clear_cache')) {
    $result['apcu_cleared'] = apcu_clear_cache();
    $result['message'] .= ' APCu cache also cleared.';
}

echo json_encode($result, JSON_PRETTY_PRINT);
