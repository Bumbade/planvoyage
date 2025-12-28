<?php

// Simple i18n loader: set LANG via env('APP_LANG') or default to 'en'
function t(string $key, $default = null)
{
    static $strings = null;
    if ($strings === null) {
        $lang = env('APP_LANG', 'en');
        $file = __DIR__ . '/../lang/' . $lang . '.php';
        if (!file_exists($file)) {
            $file = __DIR__ . '/../lang/en.php';
        }
        $strings = include $file;
    }

    return $strings[$key] ?? $default ?? $key;
}
