<?php

// src/api/locations/distinct.php
require_once __DIR__ . '/../../config/mysql.php';
// support session-scoped visibility: if logged-in and not admin, restrict distinct values to user's rows
if (file_exists(__DIR__ . '/../../helpers/session.php')) {
    require_once __DIR__ . '/../../helpers/session.php';
    start_secure_session();
}
if (file_exists(__DIR__ . '/../../helpers/auth.php')) {
    require_once __DIR__ . '/../../helpers/auth.php';
}
header('Content-Type: application/json; charset=utf-8');
try {
    $field = isset($_GET['field']) ? trim($_GET['field']) : '';
    if ($field === '') {
        echo json_encode(['ok' => false,'error' => 'missing_field']);
        exit;
    }
    $allowed = ['country','state','type'];
    if (!in_array($field, $allowed, true)) {
        echo json_encode(['ok' => false,'error' => 'invalid_field']);
        exit;
    }
    $country = isset($_GET['country']) ? trim($_GET['country']) : '';
    $db = get_db();
    $clauses = [];
    $params = [];
    if ($country !== '' && $field !== 'country') {
        $clauses[] = 'country = :country';
        $params[':country'] = $country;
    }
    // If a user is logged in and not admin, include a user_id filter
    $sessUid = (int)($_SESSION['user_id'] ?? 0);
    if ($sessUid > 0 && !(function_exists('is_admin_user') && is_admin_user())) {
        // Prefer favorites mapping for scoping; keep legacy user_id equality as fallback.
        $clauses[] = '(EXISTS (SELECT 1 FROM favorites f WHERE f.location_id = locations.id AND f.user_id = :_user_id) OR user_id = :_user_id)';
        $params[':_user_id'] = $sessUid;
    }

    // Build WHERE fragment
    $whereFrag = '';
    if (!empty($clauses)) {
        $whereFrag = ' WHERE ' . implode(' AND ', $clauses) . ' AND COALESCE(`' . $field . '`, \'\') != \'\'';
    } else {
        $whereFrag = ' WHERE COALESCE(`' . $field . '`, \'\') != \'\'';
    }

    if ($field === 'country') {
        $sql = "SELECT DISTINCT country AS val FROM locations" . $whereFrag . " ORDER BY country ASC";
        $stmt = $db->prepare($sql);
        // bind params if present
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
    } else {
        $sql = "SELECT DISTINCT `" . $field . "` AS val FROM locations" . $whereFrag . " ORDER BY `" . $field . "` ASC";
        $stmt = $db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
    }
    $stmt->execute();
    $vals = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(['ok' => true,'data' => $vals]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false,'error' => 'exception','msg' => $e->getMessage()]);
    exit;
}
