<?php
header('Content-Type: application/json; charset=utf-8');
$out = [];

$paths = [
    'main' => __DIR__ . '/../../../logs/overpass_quick.log',
    'tmp'  => __DIR__ . '/../../../tmp/overpass_quick_cache/overpass_quick.log',
];

foreach ($paths as $k => $p) {
    $dir = dirname($p);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $writableDir = is_dir($dir) && is_writable($dir);
    $exists = file_exists($p);
    $writableFile = $exists ? is_writable($p) : $writableDir;
    $writeResult = null;
    $lastErr = null;
    try {
        $res = @file_put_contents($p, date('c') . " TEST WRITE\n", FILE_APPEND | LOCK_EX);
        if ($res === false) {
            $lastErr = error_get_last();
        }
        $writeResult = $res;
    } catch (Throwable $e) {
        $lastErr = $e->getMessage();
    }

    $out[$k] = [
        'path' => $p,
        'dir_exists' => is_dir($dir),
        'dir_writable' => $writableDir,
        'file_exists' => $exists,
        'file_writable' => $writableFile,
        'write_result' => $writeResult,
        'filesize' => file_exists($p) ? filesize($p) : null,
        'last_error' => $lastErr,
    ];
}

$out['php_user'] = get_current_user();
$out['php_sapi'] = php_sapi_name();
$out['time'] = date('c');

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

?>
