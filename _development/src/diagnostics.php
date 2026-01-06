<?php
echo "Server Diagnostics\n";
echo "==================\n\n";

echo "PHP Version: " . phpversion() . "\n";
echo "Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "\n";
echo "REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A') . "\n";
echo "SCRIPT_NAME: " . ($_SERVER['SCRIPT_NAME'] ?? 'N/A') . "\n";
echo "PATH_INFO: " . ($_SERVER['PATH_INFO'] ?? 'N/A') . "\n";
echo "SCRIPT_FILENAME: " . ($_SERVER['SCRIPT_FILENAME'] ?? 'N/A') . "\n";
echo "\nmod_rewrite available: " . (function_exists('apache_get_modules') && in_array('mod_rewrite', apache_get_modules()) ? 'Yes' : 'Unknown (check with Apache)') . "\n";

// Test PATH_INFO extraction
echo "\n--- Router Path Test ---\n";
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/core/Router.php';

$router = new Router('/Allgemein/planvoyage/src');
echo "AppBase: /Allgemein/planvoyage/src\n";

// Use reflection to test extractPath
$reflect = new ReflectionClass('Router');
$method = $reflect->getMethod('extractPath');
$method->setAccessible(true);

$extracted = $method->invoke($router);
echo "Extracted Path: " . $extracted . "\n";
?>
