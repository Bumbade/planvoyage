<?php

/**
 * PlanVoyage - Central Application Configuration
 * 
 * Centralized configuration loader that reads from .env and provides
 * typed configuration values for the entire application.
 * 
 * Usage:
 *   require_once __DIR__ . '/config/app.php';
 *   echo config('app.name');
 *   echo config('db.host');
 */

require_once __DIR__ . '/env.php';

// ============================================
// Configuration Store
// ============================================

$_CONFIG = [];

/**
 * Get configuration value using dot notation
 * 
 * Examples:
 *   config('app.debug')
 *   config('db.host')
 *   config('pg.port')
 *   config('api.pagination_max', 100)
 */
function config(string $key, $default = null)
{
    global $_CONFIG;
    
    // Build config once
    if (empty($_CONFIG)) {
        $_CONFIG = _build_config();
    }
    
    // Support dot notation: 'app.name' -> $_CONFIG['app']['name']
    $parts = explode('.', $key);
    $value = $_CONFIG;
    
    foreach ($parts as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    
    return $value;
}

/**
 * Build configuration array from environment variables
 */
function _build_config(): array
{
    return [
        // ========== Application ==========
        'app' => [
            'name'     => env('APP_NAME', 'PlanVoyage'),
            'base'     => env('APP_BASE', ''),
            'debug'    => env('APP_DEBUG', '0') === '1' || env('APP_DEBUG', '0') === 'true',
            'env'      => env('APP_ENV', 'production'),
            'timezone' => env('APP_TIMEZONE', 'UTC'),
        ],

        // ========== Database (MySQL) ==========
        'db' => [
            'host'     => env('DB_HOST', '192.168.178.2'),
            'port'     => (int)env('DB_PORT', '3306'),
            'name'     => env('DB_NAME', 'travel_planner_v4'),
            'user'     => env('DB_USER', 'travel'),
            'pass'     => env('DB_PASS', 'planner'),
            'charset'  => env('DB_CHARSET', 'utf8mb4'),
            'timezone' => env('DB_TIMEZONE', 'UTC'),
        ],
        

        // ========== Geocoding & Location Services ==========
        'geocoding' => [
            'provider'    => env('GEOCODING_PROVIDER', 'overpass'),
            'reverse'     => env('REVERSE_GEOCODING_ENABLED', '1') === '1',
            'poi_import'  => env('POI_IMPORT_ENABLED', '1') === '1',
            'cache_ttl'   => (int)env('GEOCODING_CACHE_TTL', '86400'),
        ],

        // ========== OSRM (Route Optimization) ==========
        'osrm' => [
            'host'      => env('OSRM_HOST', '192.168.178.115'),
            'port'      => (int)env('OSRM_PORT', '5000'),
            'enabled'   => env('OSRM_ENABLED', '1') === '1',
            'timeout'   => (int)env('OSRM_TIMEOUT', '30'),
        ],

        // ========== Session & Security ==========
        'session' => [
            'name'              => env('SESSION_NAME', 'planvoyage_session'),
            'timeout'           => (int)env('SESSION_TIMEOUT', '3600'),
            'cookie_secure'     => env('SESSION_COOKIE_SECURE', '0') === '1',
            'cookie_httponly'   => env('SESSION_COOKIE_HTTPONLY', '1') === '1',
            'cookie_samesite'   => env('SESSION_COOKIE_SAMESITE', 'Lax'),
        ],

        'security' => [
            'csrf_token_length' => (int)env('CSRF_TOKEN_LENGTH', '32'),
            'password_hash'     => env('PASSWORD_HASH_ALGO', 'bcrypt'),
            'password_cost'     => (int)env('PASSWORD_HASH_COST', '12'),
        ],

        // ========== API Configuration ==========
        'api' => [
            'pagination_default' => (int)env('API_PAGINATION_DEFAULT', '20'),
            'pagination_max'     => (int)env('API_PAGINATION_MAX', '100'),
            'cache_ttl'          => (int)env('API_CACHE_TTL', '3600'),
            'rate_limit'         => env('API_RATE_LIMIT_ENABLED', '0') === '1',
            'rate_limit_requests' => (int)env('API_RATE_LIMIT_REQUESTS', '100'),
            'rate_limit_window'   => (int)env('API_RATE_LIMIT_WINDOW', '3600'),
        ],

        // ========== File Upload Settings ==========
        'upload' => [
            'dir'            => env('UPLOAD_DIR', 'uploads'),
            'max_size'       => (int)env('UPLOAD_MAX_SIZE', '10485760'),  // 10MB
            'allowed_types'  => explode(',', env('UPLOAD_ALLOWED_TYPES', 'jpg,jpeg,png,gif,pdf')),
            'create_dirs'    => env('UPLOAD_CREATE_DIRS', '1') === '1',
        ],

        // ========== Email Configuration ==========
        'mail' => [
            'driver'    => env('MAIL_DRIVER', 'mail'),
            'host'      => env('MAIL_HOST', ''),
            'port'      => (int)env('MAIL_PORT', '587'),
            'username'  => env('MAIL_USERNAME', ''),
            'password'  => env('MAIL_PASSWORD', ''),
            'from'      => env('MAIL_FROM', 'noreply@planvoyage.local'),
            'from_name' => env('MAIL_FROM_NAME', 'PlanVoyage'),
        ],

        // ========== Logging ==========
        'logging' => [
            'level'     => env('LOG_LEVEL', 'info'),
            'file'      => env('LOG_FILE', 'logs/app.log'),
            'max_size'  => (int)env('LOG_MAX_SIZE', '10485760'),  // 10MB
            'keep_days' => (int)env('LOG_KEEP_DAYS', '30'),
        ],

        // ========== Feature Flags ==========
        'features' => [
            'user_registration' => env('FEATURE_USER_REGISTRATION', '1') === '1',
            'user_profile'      => env('FEATURE_USER_PROFILE', '1') === '1',
            'route_sharing'     => env('FEATURE_ROUTE_SHARING', '0') === '1',
            'export_gpx'        => env('FEATURE_EXPORT_GPX', '1') === '1',
            'import_gpx'        => env('FEATURE_IMPORT_GPX', '1') === '1',
            'offline_mode'      => env('FEATURE_OFFLINE_MODE', '0') === '1',
        ],
    ];
}

/**
 * Check if configuration key exists
 */
function config_exists(string $key): bool
{
    return config($key) !== null;
}

/**
 * Get all configuration
 */
function config_all(): array
{
    global $_CONFIG;
    if (empty($_CONFIG)) {
        $_CONFIG = _build_config();
    }
    return $_CONFIG;
}

/**
 * Get configuration section
 * 
 * Examples:
 *   config_section('db')
 *   config_section('app')
 */
function config_section(string $section): array
{
    return config($section, []);
}

/**
 * Helper: Get database DSN for connections
 */
function db_dsn(): string
{
    $cfg = config_section('db');
    return sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $cfg['host'],
        $cfg['port'],
        $cfg['name'],
        $cfg['charset']
    );
}

/**
 * Helper: Get PostgreSQL DSN for PostGIS connections
 */
function pg_dsn(): string
{
    // PostGIS / PostgreSQL support has been removed in this deployment.
    // Keep a stub to avoid fatal errors from lingering calls: return empty DSN and log a warning.
    try {
        if (function_exists('error_log')) {
            error_log('pg_dsn() called but PostGIS support has been removed. Use Overpass or MySQL instead.');
        }
    } catch (Throwable $e) {}
    return '';
}

/**
 * Helper: Get OSRM URL
 */
function osrm_url(): string
{
    $cfg = config_section('osrm');
    return sprintf('http://%s:%d', $cfg['host'], $cfg['port']);
}

/**
 * Helper: Get API base URL
 */
function api_base_url(): string
{
    $base = config('app.base');
    $base = rtrim($base, '/');

    // If APP_BASE is set use it. Otherwise attempt to derive the application
    // base path from the current script location so URLs include any folder
    // components (e.g. /Allgemein/planvoyage_V2). This avoids missing
    // path segments when the environment variable isn't configured.
    if ($base !== '') {
        // Prefer direct API paths under src/api (avoid index.php in URLs)
        return rtrim($base, '/') . '/src/api';
    }

    // Try to derive from SCRIPT_NAME or PHP_SELF: expect path like
    // /Allgemein/planvoyage_V2/src/index.php -> we want /Allgemein/planvoyage_V2
    $script = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
    if ($script !== '') {
        $maybeBase = dirname(dirname($script));
        if ($maybeBase === '/' || $maybeBase === '.' || $maybeBase === '\\') {
            $maybeBase = '';
        }
        return rtrim($maybeBase, '/') . '/src/api';
    }

    // Fallback to previous behaviour
    return '/src/api';
}

/**
 * Helper: Get upload directory path
 */
function upload_dir(): string
{
    $dir = config('upload.dir');
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * Helper: Check if application is in debug mode
 */
function is_debug(): bool
{
    return config('app.debug') === true;
}

/**
 * Helper: Check if application is in production
 */
function is_production(): bool
{
    return config('app.env') === 'production';
}

/**
 * Helper: Validate upload file
 */
function validate_upload(string $filename, int $size): bool
{
    $cfg = config_section('upload');
    
    // Check file size
    if ($size > $cfg['max_size']) {
        error_log("Upload too large: {$size} bytes (max: {$cfg['max_size']})");
        return false;
    }
    
    // Check file type
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($ext, $cfg['allowed_types'], true)) {
        error_log("Upload file type not allowed: {$ext}");
        return false;
    }
    
    return true;
}

// Auto-load configuration on include
return config_all();
