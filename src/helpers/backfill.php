<?php

// src/helpers/backfill.php
// Simple logger for post-import/backfill operations.

function log_backfill(array $entry)
{
    // Ensure logs directory exists (project-level logs/)
    $base = __DIR__ . '/../../logs';
    if (!is_dir($base)) {
        @mkdir($base, 0775, true);
    }
    $file = $base . '/backfill.log';

    $record = [
        'ts' => date('c'),
        'pid' => getmypid() ?: null,
        'entry' => $entry,
    ];

    // Write as newline-delimited JSON for easy parsing
    $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

function read_backfill_log($limit = 200)
{
    $file = __DIR__ . '/../../logs/backfill.log';
    if (!is_file($file)) {
        return [];
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return [];
    }
    $lines = array_reverse($lines);
    $out = [];
    $count = 0;
    foreach ($lines as $l) {
        if ($count >= $limit) {
            break;
        }
        $j = @json_decode($l, true);
        if ($j) {
            $out[] = $j;
        }
        $count++;
    }
    return $out;
}
