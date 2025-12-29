<?php

// Simple .env loader and env() helper
// Usage: require_once __DIR__ . '/config/env.php';
// Then call env('DB_HOST') or env('SOME_KEY', 'default')

if (!function_exists('load_dotenv')) {
    function load_dotenv(?string $path = null): void
    {
        if ($path === null) {
            // project root /.env (two levels up from src/config)
            $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
        }

        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (strpos($line, '=') === false) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Strip surrounding quotes
            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }

            // Do not overwrite existing env vars
            if (getenv($key) === false) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }
}

if (!function_exists('env')) {
    function env(string $key, $default = null)
    {
        $val = getenv($key);
        if ($val !== false) {
            return $val;
        }
        if (array_key_exists($key, $_ENV)) {
            return $_ENV[$key];
        }
        return $default;
    }
}

// Auto-load .env file (if present)
load_dotenv();

// Developer helper: if APP_DEBUG isn't set in the environment, enable it temporarily
// so API debug output can be inspected during troubleshooting. Remove or comment
// this block in production when finished.
if (getenv('APP_DEBUG') === false) {
    putenv('APP_DEBUG=1');
    $_ENV['APP_DEBUG'] = '1';
}
