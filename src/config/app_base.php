<?php
// src/config/app_base.php

$appBase = '';
if (isset($_SERVER['APP_BASE'])) {
    $appBase = $_SERVER['APP_BASE'];
} elseif (getenv('APP_BASE')) {
    $appBase = getenv('APP_BASE');
} else {
    // Fallback to auto-detection
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);
    $appBase = dirname(dirname($scriptName)); // Assumes index.php is in /src
    if ($appBase === '/' || $appBase === '\\') {
        $appBase = '';
    }
}

return $appBase;
