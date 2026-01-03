<?php
/**
 * src/bootstrap/RequiredHelpers.php
 * Centralized loader for required helper files.
 * Replaces scattered file_exists() + require_once checks with a single, robust entrypoint.
 */

class RequiredHelpers
{
    private static $loaded = [];

    /**
     * Load a required helper by name. Silently skips if file doesn't exist.
     *
     * @param string $name One of: 'env', 'url', 'i18n', 'poi', 'session', 'auth', etc.
     * @return bool True if loaded, false if file not found
     */
    public static function load(string $name): bool
    {
        if (isset(self::$loaded[$name])) {
            return self::$loaded[$name];
        }

        $basePath = __DIR__ . '/../helpers/';
        $filePath = $basePath . $name . '.php';

        if (!file_exists($filePath)) {
            self::$loaded[$name] = false;
            return false;
        }

        require_once $filePath;
        self::$loaded[$name] = true;
        return true;
    }

    /**
     * Load multiple helpers at once.
     *
     * @param string[] $names List of helper names
     * @return void
     */
    public static function loadMultiple(array $names): void
    {
        foreach ($names as $name) {
            self::load($name);
        }
    }

    /**
     * Load essential helpers for POI views.
     * Ensures all required dependencies are available.
     *
     * @return void
     */
    public static function loadPoiHelpers(): void
    {
        self::loadMultiple([
            'env',
            'url',
            'i18n',
            'i18n_cache',
            'poi',
            'session',
            'auth',
            'flash_component',
        ]);

        // Start secure session if available
        if (function_exists('start_secure_session')) {
            start_secure_session();
        }
    }

    /**
     * Get list of loaded helpers (useful for debugging).
     *
     * @return array
     */
    public static function getLoadedHelpers(): array
    {
        return array_keys(array_filter(self::$loaded, fn($v) => $v === true));
    }
}
