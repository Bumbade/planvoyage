<?php

// src/helpers/import_log.php
// Simple newline-delimited JSON log for import jobs (PBF/PDF)
function import_log_write(array $entry): bool
{
    $logDir = __DIR__ . '/../../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $path = $logDir . '/imports.log';
    $entry['ts'] = (new DateTime())->format(DateTime::ATOM);
    // normalize keys
    if (!isset($entry['type'])) {
        $entry['type'] = 'import';
    }
    if (!isset($entry['status'])) {
        $entry['status'] = 'started';
    }
    $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    return (bool)file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

function import_log_read(int $limit = 200): array
{
    $path = __DIR__ . '/../../logs/imports.log';
    if (!is_file($path)) {
        return [];
    }
    $lines = array_reverse(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    $out = [];
    foreach ($lines as $i => $line) {
        if ($i >= $limit) {
            break;
        }
        $json = json_decode($line, true);
        if ($json !== null) {
            $out[] = $json;
        }
    }
    return $out;
}

/**
 * Write a debug entry for import operations. Uses a separate file to avoid
 * mixing debug traces with higher-level import job logs.
 */
function import_debug_log(array $entry): bool
{
    $logDir = __DIR__ . '/../../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $path = $logDir . '/imports_debug.log';
    $entry['ts'] = (new DateTime())->format(DateTime::ATOM);
    $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    return (bool)file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}
