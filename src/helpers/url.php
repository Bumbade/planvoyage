<?php
/**
 * URL Helper - SINGLE SOURCE OF TRUTH
 * 
 * All URL generation happens here. Period.
 * NO app_url(), asset_url() or similar functions should be defined anywhere else.
 */

// Ensure config is loaded
if (!function_exists('config')) {
    require_once __DIR__ . '/../config/app.php';
}

/**
 * Generate application route URL and asset URL.
 * This implementation prefers a configured `app.base` from config/app.php (which reads .env APP_BASE),
 * and falls back to the directory of `$_SERVER['SCRIPT_NAME']` (so `index.php` is not included).
 */
if (!function_exists('app_url')) {
    function app_url($path = '') {
        // Prefer explicit configured base if available, otherwise use script dir.
        $configured = config('app.base') ?? '';
        if (!empty($configured)) {
            $base = rtrim($configured, '/');
        } else {
            $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
            $base = rtrim(dirname($script), '/');
            if ($base === '/') $base = '';
        }

        $path = ltrim((string)$path, '/');

        // If the configured base already ends with '/src' and the requested path
        // also starts with 'src/', avoid duplicating 'src/src' in generated URLs.
        if ($base !== '' && preg_match('#/src$#', $base) && str_starts_with($path, 'src/')) {
            $path = preg_replace('#^src/#', '', $path);
        }

        // Build simple URL: if no base configured, return absolute path starting with '/'
        if ($base === '') {
            return '/' . $path;
        }
        return rtrim($base, '/') . '/' . $path;
    }
}

if (!function_exists('asset_url')) {
    function asset_url($path = '') {
        $p = ltrim((string)$path, '/');
        if (strpos($p, 'assets/') === 0) {
            // caller already prefixed with 'assets/' - avoid duplication
            return app_url($p);
        }
        return app_url('assets/' . $p);
    }
}

/**
 * Generate direct API endpoint URL (without index.php routing)
 * Used for direct PHP files like api/poi-config.php
 */
if (!function_exists('api_url')) {
    function api_url($path = '') {
        $configured = config('app.base') ?? '';
        $base = rtrim($configured, '/');
        $path = ltrim((string)$path, '/');
        
        if ($base === '') {
            return '/' . $path;
        }
        return $base . '/' . $path;
    }
}
