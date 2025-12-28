<?php

// Session helpers: start a secure session
function start_secure_session()
{
    if (session_status() === PHP_SESSION_NONE) {
        if (headers_sent($file, $line)) {
            // Can't start a session after output has been sent. Log or handle gracefully.
            error_log("start_secure_session: headers already sent in $file on line $line");
            return false;
        }

        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $httponly = true;

        // Ensure the cookie domain does not include a port (e.g. host:8103) as that makes the cookie invalid.
        $rawHost = $_SERVER['HTTP_HOST'] ?? '';
        $hostOnly = '';
        if ($rawHost !== '') {
            // strip port if present
            $hostOnly = preg_replace('/:.*/', '', $rawHost);
        }
        // If hostOnly is empty, use default (empty string) so PHP uses the current host.
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => $hostOnly,
            'secure' => $secure,
            'httponly' => $httponly,
            'samesite' => 'Lax'
        ]);

        session_start();
    }
    return true;
}

// Simple CSRF token generator/validator
function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_token'];
}

function csrf_check($token)
{
    return hash_equals($_SESSION['csrf_token'] ?? '', $token ?? '');
}

// Flash message helpers (store one-time messages in session)
function flash_set(string $key, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        start_secure_session();
    }
    $_SESSION['flash_messages'][$key] = $message;
}

function flash_get(string $key)
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        start_secure_session();
    }
    $msg = $_SESSION['flash_messages'][$key] ?? null;
    if ($msg !== null) {
        unset($_SESSION['flash_messages'][$key]);
    }
    return $msg;
}
