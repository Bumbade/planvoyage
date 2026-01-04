<?php
// Force clear opcache for search_overpass_quick_v2.php
if (function_exists('opcache_invalidate')) {
    $file = __DIR__ . '/search_overpass_quick_v2.php';
    $result = opcache_invalidate($file, true);
    echo json_encode([
        'file' => $file,
        'opcache_invalidate' => $result,
        'message' => $result ? 'OPcache cleared successfully' : 'Failed to clear OPcache',
        'file_mtime' => date('Y-m-d H:i:s', filemtime($file)),
        'instruction' => 'Now test the search again'
    ], JSON_PRETTY_PRINT);
} else {
    echo json_encode([
        'error' => 'opcache_invalidate not available',
        'message' => 'Please restart Apache to load new code',
        'file' => __DIR__ . '/search_overpass_quick_v2.php',
        'file_mtime' => date('Y-m-d H:i:s', filemtime(__DIR__ . '/search_overpass_quick_v2.php'))
    ], JSON_PRETTY_PRINT);
}
