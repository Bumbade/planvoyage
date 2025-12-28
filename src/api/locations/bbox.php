<?php

// src/api/locations/bbox.php
require_once __DIR__ . '/../../config/mysql.php';
// restrict bbox results to session user when logged in (unless admin)
if (file_exists(__DIR__ . '/../../helpers/session.php')) {
    require_once __DIR__ . '/../../helpers/session.php';
    start_secure_session();
}
if (file_exists(__DIR__ . '/../../helpers/auth.php')) {
    require_once __DIR__ . '/../../helpers/auth.php';
}
header('Content-Type: application/json; charset=utf-8');
try {
    $country = isset($_GET['country']) ? trim($_GET['country']) : '';
    $state = isset($_GET['state']) ? trim($_GET['state']) : '';
    if ($country === '' && $state === '') {
        echo json_encode(['ok' => false, 'error' => 'missing_params']);
        exit;
    }
    $db = get_db();
    // detect columns
    $cols = $db->query('SHOW COLUMNS FROM locations')->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'Field');
    $hasLatLon = in_array('latitude', $colNames, true) && in_array('longitude', $colNames, true);
    $hasCoordinates = in_array('coordinates', $colNames, true);
    if (!$hasLatLon && !$hasCoordinates) {
        echo json_encode(['ok' => false, 'error' => 'no_coordinates']);
        exit;
    }
    $where = [];
    $params = [];
    if ($country !== '') {
        $where[] = 'country = :country';
        $params[':country'] = $country;
    }
    if ($state !== '') {
        $where[] = 'state = :state';
        $params[':state'] = $state;
    }
    // If a user is logged in and not admin, add user_id filter
    $sessUid = (int)($_SESSION['user_id'] ?? 0);
    if ($sessUid > 0 && !(function_exists('is_admin_user') && is_admin_user())) {
        // Restrict to locations favorited by the current user (preferred),
        // fallback to matching user_id column for legacy rows.
        $where[] = '(EXISTS (SELECT 1 FROM favorites f WHERE f.location_id = locations.id AND f.user_id = :user_id) OR user_id = :user_id)';
        $params[':user_id'] = $sessUid;
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    if ($hasLatLon) {
        $sql = "SELECT MIN(latitude) AS minLat, MAX(latitude) AS maxLat, MIN(longitude) AS minLon, MAX(longitude) AS maxLon FROM locations" . $whereSql;
    } else {
        $sql = "SELECT MIN(ST_Y(coordinates)) AS minLat, MAX(ST_Y(coordinates)) AS maxLat, MIN(ST_X(coordinates)) AS minLon, MAX(ST_X(coordinates)) AS maxLon FROM locations" . $whereSql;
    }
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || ($row['minLat'] === null && $row['minLon'] === null)) {
        echo json_encode(['ok' => false, 'error' => 'not_found']);
        exit;
    }
    echo json_encode(['ok' => true, 'minLat' => (float)$row['minLat'], 'maxLat' => (float)$row['maxLat'], 'minLon' => (float)$row['minLon'], 'maxLon' => (float)$row['maxLon']]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'exception', 'msg' => $e->getMessage()]);
    exit;
}
