<?php

// src/helpers/auth.php
// Centralized auth helper functions (admin checks etc.)
if (file_exists(__DIR__ . '/session.php')) {
    require_once __DIR__ . '/session.php';
}

// Optionally include DB helper when available for stronger checks
if (file_exists(__DIR__ . '/../config/mysql.php')) {
    require_once __DIR__ . '/../config/mysql.php';
}

// Returns true when the current session is an administrator.
function is_admin_user()
{
    if (empty($_SESSION['user_id'])) {
        return false;
    }
    // session flag takes precedence
    if (!empty($_SESSION['is_admin'])) {
        return true;
    }
    // environment override by ADMIN_EMAIL
    $adminEmail = getenv('ADMIN_EMAIL') ?: null;
    if ($adminEmail) {
        $email = $_SESSION['email'] ?? '';
        if ($email && strcasecmp(trim($email), trim($adminEmail)) === 0) {
            return true;
        }
    }
    // If DB helper available, check users.is_admin flag
    try {
        if (function_exists('get_db')) {
            $db = get_db();
            $stmt = $db->prepare('SELECT is_admin FROM users WHERE id = :id LIMIT 1');
            $stmt->bindValue(':id', (int)($_SESSION['user_id'] ?? 0), PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['is_admin'])) {
                return true;
            }
        }
    } catch (Throwable $e) {
        // ignore DB errors and fall back to legacy check
    }

    // legacy fallback: user id 1 is admin
    return ((int)($_SESSION['user_id'] ?? 0)) === 1;
}

// Returns true when the current user has permission for a specific category filter
function has_category_permission($category)
{
    // Admins always have all permissions
    if (is_admin_user()) {
        return true;
    }

    // Check if user is logged in
    if (empty($_SESSION['user_id'])) {
        return false;
    }

    // Cannabis filter requires explicit permission
    if ($category === 'cannabis') {
        try {
            if (function_exists('get_db')) {
                $db = get_db();
                $stmt = $db->prepare('SELECT can_access_cannabis FROM users WHERE id = :id LIMIT 1');
                $stmt->bindValue(':id', (int)($_SESSION['user_id']), PDO::PARAM_INT);
                $stmt->execute();
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                return !empty($row['can_access_cannabis']);
            }
        } catch (Throwable $e) {
            // ignore DB errors
            return false;
        }
    }

    // All other categories are public
    return true;
}

// Backwards-compatible aliases for previous local helpers
if (!function_exists('session_is_admin')) {
    function session_is_admin()
    {
        return is_admin_user();
    }
}
if (!function_exists('is_admin_user_import')) {
    function is_admin_user_import()
    {
        return is_admin_user();
    }
}
if (!function_exists('is_admin_user_debug')) {
    function is_admin_user_debug()
    {
        return is_admin_user();
    }
}
