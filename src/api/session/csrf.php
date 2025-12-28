<?php
header('Content-Type: application/json; charset=utf-8');

// Provide a small endpoint for scripts to obtain a valid CSRF token
// Path: /api/session/csrf -> src/api/session/csrf.php

// Only allow GET/POST for token retrieval
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Load session helpers which provide start_secure_session(), csrf_token(), csrf_check()
$helpers = __DIR__ . '/../../helpers/session.php';
if (!file_exists($helpers)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server configuration error']);
    exit;
}
require_once $helpers;

// Start the secure app session (ensures cookie params and session name)
if (session_status() !== PHP_SESSION_ACTIVE) {
    start_secure_session();
}

$token = csrf_token();

echo json_encode([
    'success' => true,
    'csrf_token' => $token
]);
