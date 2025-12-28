<?php

// Small endpoint to return tail of an import logfile and a parsed progress percentage when possible
header('Content-Type: application/json; charset=utf-8');

$file = $_GET['file'] ?? '';
if (!$file) {
    echo json_encode(['ok' => false, 'error' => 'missing file']);
    exit;
}

// Only allow basenames matching import_pbf_* to avoid arbitrary reads
$base = basename($file);
if (!preg_match('/^import_pbf_[0-9a-zA-Z_\-\.]+\.log$/', $base)) {
    // also accept files under sys_get_temp_dir with that prefix
    if (strpos($base, 'import_pbf_') !== 0) {
        echo json_encode(['ok' => false, 'error' => 'invalid file']);
        exit;
    }
}

$tmpDirs = [sys_get_temp_dir(), '/tmp'];
$found = null;
foreach ($tmpDirs as $d) {
    $path = rtrim($d, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $base;
    if (is_file($path)) {
        $found = $path;
        break;
    }
}

if (!$found) {
    echo json_encode(['ok' => false, 'error' => 'file not found']);
    exit;
}

$size = filesize($found);
$mtime = filemtime($found);

$maxBytes = 16 * 1024; // read up to last 16KB
$fh = fopen($found, 'rb');
if (!$fh) {
    echo json_encode(['ok' => false, 'error' => 'cannot open file']);
    exit;
}
if ($size > $maxBytes) {
    fseek($fh, -$maxBytes, SEEK_END);
}
$data = stream_get_contents($fh);
fclose($fh);

$lines = explode("\n", trim($data));
$lastLines = array_slice($lines, -200);
$text = implode("\n", $lastLines);

// Try to parse a progress percentage from common patterns
$percent = null;
// patterns: "Processed 12345 of 50000" or "Processed: 12%" or "12%"
if (preg_match('/Processed\s+(\d+)\s+of\s+(\d+)/i', $text, $m)) {
    $p = (int)$m[1];
    $t = (int)$m[2];
    if ($t > 0) {
        $percent = round($p / $t * 100, 1);
    }
} elseif (preg_match('/(\d{1,3})%/', $text, $m)) {
    $percent = (float)$m[1];
}

echo json_encode(['ok' => true, 'file' => $base, 'mtime' => $mtime, 'size' => $size, 'tail' => $lastLines, 'percent' => $percent]);
