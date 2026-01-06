<?php
// Temporary debug helper to inspect how Apache/PHP present request info to the app.
header('Content-Type: text/plain; charset=utf-8');
phpinfo();
echo "\n----- REQUEST VARIABLES -----\n";
echo "SCRIPT_NAME=" . ($_SERVER['SCRIPT_NAME'] ?? '') . "\n";
echo "REQUEST_URI=" . ($_SERVER['REQUEST_URI'] ?? '') . "\n";
echo "PATH_INFO=" . ($_SERVER['PATH_INFO'] ?? '') . "\n";
echo "DOCUMENT_ROOT=" . ($_SERVER['DOCUMENT_ROOT'] ?? '') . "\n";
echo "SCRIPT_FILENAME=" . ($_SERVER['SCRIPT_FILENAME'] ?? '') . "\n";
