<?php

/**
 * Simple PDO MySQL helper
 * Uses centralized configuration from config/app.php
 */
function get_db()
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // Load central configuration
    if (!function_exists('config')) {
        require_once __DIR__ . '/app.php';
    }

    // Get DB configuration
    $cfg = config_section('db');
    
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $cfg['host'],
        $cfg['port'],
        $cfg['name'],
        $cfg['charset']
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => true,
    ];

    try {
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], $options);
        return $pdo;
    } catch (PDOException $e) {
        error_log('DB connect error: ' . $e->getMessage());
        if (is_debug()) {
            echo "Database connection failed: " . htmlspecialchars($e->getMessage());
        } else {
            echo "Database connection failed. Check logs for details.";
        }
        exit(1);
    }
}
