<?php
/**
 * src/helpers/i18n_cache.php
 * Cached translation helper to avoid repeated lookups.
 * Significantly improves performance when t() is called many times.
 *
 * Usage:
 *   t_cached('key', 'default') - returns cached translation
 *   t_cache_clear() - clears the cache (use after language change)
 */

/**
 * Static cache for translations
 */
$_t_cache = [];
$_t_cache_enabled = true;

/**
 * Cached translation lookup.
 * First checks in-memory cache, then calls t() if not found.
 *
 * @param string $key Translation key
 * @param string $default Default text if key not found
 * @return string Translated text
 */
function t_cached(string $key, string $default = ''): string
{
    global $_t_cache, $_t_cache_enabled;

    // If caching disabled, use regular t()
    if (!$_t_cache_enabled) {
        return function_exists('t') ? t($key, $default) : $default;
    }

    // Check cache first
    if (isset($_t_cache[$key])) {
        return $_t_cache[$key];
    }

    // Call t() to get translation
    $result = function_exists('t') ? t($key, $default) : $default;

    // Store in cache
    $_t_cache[$key] = $result;

    return $result;
}

/**
 * Manually cache a translation.
 * Useful for preloading common strings at view initialization.
 *
 * @param string $key Translation key
 * @param string $value Translated value
 * @return void
 */
function t_cache_set(string $key, string $value): void
{
    global $_t_cache;
    $_t_cache[$key] = $value;
}

/**
 * Batch cache multiple translations at once.
 * Improves performance significantly when view needs many strings.
 *
 * @param array<string, string> $keyValuePairs Key => Translated Value
 * @return void
 */
function t_cache_batch(array $keyValuePairs): void
{
    global $_t_cache;
    foreach ($keyValuePairs as $key => $value) {
        $_t_cache[$key] = $value;
    }
}

/**
 * Get current cache size (for debugging).
 *
 * @return int Number of cached translations
 */
function t_cache_size(): int
{
    global $_t_cache;
    return count($_t_cache);
}

/**
 * Clear the translation cache.
 * Call after language change or when translations are updated.
 *
 * @return void
 */
function t_cache_clear(): void
{
    global $_t_cache;
    $_t_cache = [];
}

/**
 * Enable/disable caching globally.
 *
 * @param bool $enabled
 * @return void
 */
function t_cache_enabled(bool $enabled = true): void
{
    global $_t_cache_enabled;
    $_t_cache_enabled = $enabled;
}
