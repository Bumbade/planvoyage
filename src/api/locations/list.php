<?php


// API: Get locations filtered by country/state
// Enforce user-scoped visibility: logged-in non-admin users only see their own locations
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../../config/mysql.php';
    // Load session + auth helper so we can restrict results
    if (file_exists(__DIR__ . '/../../helpers/session.php')) {
        require_once __DIR__ . '/../../helpers/session.php';
        start_secure_session();
    }
    if (file_exists(__DIR__ . '/../../helpers/auth.php')) {
        require_once __DIR__ . '/../../helpers/auth.php';
    }
    $db = get_db();

    $country = isset($_GET['country']) ? $_GET['country'] : '';
    $state = isset($_GET['state']) ? $_GET['state'] : '';

    $sql = "SELECT id, name FROM locations WHERE 1=1";
    $params = [];

    if ($country) {
        $sql .= " AND LOWER(country) = LOWER(?)";
        $params[] = $country;
    }

    if ($state) {
        $sql .= " AND LOWER(state) = LOWER(?)";
        $params[] = $state;
    }

    // If a user is logged in and not admin, restrict to their locations
    $sessUid = (int)($_SESSION['user_id'] ?? 0);
    if ($sessUid > 0 && !(function_exists('is_admin_user') && is_admin_user())) {
        // Restrict to locations favorited by the current user; fallback to legacy user_id equality
        $sql .= " AND (EXISTS (SELECT 1 FROM favorites f WHERE f.location_id = locations.id AND f.user_id = ?) OR user_id = ?)";
        $params[] = $sessUid;
        // second param for the fallback user_id equality bind
        $params[] = $sessUid;
    }

    $sql .= " ORDER BY name ASC LIMIT 1000";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['locations' => $locations], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('Locations API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
    exit;
}
