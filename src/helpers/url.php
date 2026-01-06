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
            // Try to robustly derive the app base from the server environment.
            // First prefer SCRIPT_NAME dirname (fast), but fall back to comparing
            // SCRIPT_FILENAME with DOCUMENT_ROOT to compute the web-relative path
            $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
            $base = rtrim(dirname($script), '/');
            if ($base === '/') $base = '';

            // If base looks like it's pointing to ANY src subfolder (views, api, admin, controllers, etc.),
            // attempt to derive the application root by comparing filesystem paths.
            if (empty($base) || preg_match('#/src/(views|api|admin|controllers|assets|helpers|config)#', $base)) {
                $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'])) : '';
                $scriptFile = isset($_SERVER['SCRIPT_FILENAME']) ? str_replace('\\', '/', realpath($_SERVER['SCRIPT_FILENAME'])) : '';
                if ($docRoot && $scriptFile && strpos($scriptFile, $docRoot) === 0) {
                    // Find the 'src' directory in the script path and go up one level to get app root
                    if (preg_match('#^(.*?)/src/#', substr($scriptFile, strlen($docRoot)), $match)) {
                        $base = $match[1];
                        if ($base === '') $base = '';
                    } else {
                        // Fallback: go up to grandparent directory
                        $appDir = dirname(dirname($scriptFile));
                        $rel = substr($appDir, strlen($docRoot));
                        $rel = '/' . trim(str_replace('\\', '/', $rel), '/');
                        if ($rel === '/') $rel = '';
                        $base = $rel;
                    }
                }
            }
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
        
        // If path already starts with 'src/assets/', use it as-is
        if (strpos($p, 'src/assets/') === 0) {
            return app_url($p);
        }
        
        // If path starts with 'assets/', prepend 'src/'
        if (strpos($p, 'assets/') === 0) {
            return app_url('src/' . $p);
        }
        
        // Otherwise add full 'src/assets/' prefix
        return app_url('src/assets/' . $p);
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
