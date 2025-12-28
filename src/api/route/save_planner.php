<?php

// src/api/route/save_planner.php
require_once __DIR__ . '/../../config/mysql.php';
require_once __DIR__ . '/../../helpers/session.php';
start_secure_session();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false,'error' => 'method_not_allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input') ?: '{}', true);
$route_id = isset($data['route_id']) ? (int)$data['route_id'] : 0;
$items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
if ($route_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false,'error' => 'missing_route']);
    exit;
}

try {
    $db = get_db();
    $currentUser = $_SESSION['user_id'] ?? null;
    $ownerStmt = $db->prepare('SELECT user_id FROM routes WHERE id = :id LIMIT 1');
    $ownerStmt->execute([':id' => $route_id]);
    $ownerRow = $ownerStmt->fetch(PDO::FETCH_ASSOC);
    if (!$ownerRow) {
        http_response_code(404);
        echo json_encode(['ok' => false,'error' => 'route_not_found']);
        exit;
    }
    $ownerId = $ownerRow['user_id'] ?? null;
    if ($ownerId !== null && $currentUser !== null && (int)$ownerId !== (int)$currentUser) {
        http_response_code(403);
        echo json_encode(['ok' => false,'error' => 'not_authorized']);
        exit;
    }

    // Begin transaction
    $db->beginTransaction();
    // Load existing route_items for this route so we can preserve arrival/departure/notes when possible
    $existingStmt = $db->prepare('SELECT location_id, arrival, departure, notes FROM route_items WHERE route_id = :rid');
    $existingStmt->execute([':rid' => $route_id]);
    $existing = [];
    while ($row = $existingStmt->fetch(PDO::FETCH_ASSOC)) {
        $existing[(int)$row['location_id']] = $row;
    }

    // Remove existing route_items for route
    $del = $db->prepare('DELETE FROM route_items WHERE route_id = :rid');
    $del->execute([':rid' => $route_id]);

    $ins = $db->prepare('INSERT INTO route_items (route_id, location_id, position, arrival, departure, notes, created_at) VALUES (:rid, :lid, :pos, :arrival, :departure, :notes, NOW())');

    $pos = 1;
    $firstArrival = null;
    foreach ($items as $it) {
        $locationId = isset($it['location_id']) ? (int)$it['location_id'] : 0;
        if ($locationId <= 0 && isset($it['lat']) && isset($it['lon'])) {
            // attempt to find nearby location
            $lat = (float)$it['lat'];
            $lon = (float)$it['lon'];
            $near = $db->prepare('SELECT id FROM locations WHERE ABS(latitude - :lat) < 0.0005 AND ABS(longitude - :lon) < 0.0005 LIMIT 1');
            $near->execute([':lat' => $lat,':lon' => $lon]);
            $nr = $near->fetch(PDO::FETCH_ASSOC);
            if ($nr && isset($nr['id'])) {
                $locationId = (int)$nr['id'];
            } else {
                // insert minimal location
                $iname = !empty($it['label']) ? substr($it['label'], 0, 191) : 'Waypoint';
                $iins = $db->prepare('INSERT INTO locations (name,type,created_at,latitude,longitude,logo) VALUES (:name, :type, NOW(), :lat, :lon, :logo)');
                $iins->execute([':name' => $iname,':type' => 'poi',':lat' => $lat,':lon' => $lon,':logo' => 'poi.png']);
                $locationId = (int)$db->lastInsertId();
                // Mirror tags_text into tags if present on minimal insert (defensive)
                try {
                    $sTags = $db->prepare('SELECT tags, tags_text FROM locations WHERE id = :id LIMIT 1');
                    $sTags->execute([':id' => $locationId]);
                    $rr = $sTags->fetch(PDO::FETCH_ASSOC);
                    if ($rr && array_key_exists('tags_text', $rr) && array_key_exists('tags', $rr) && !empty($rr['tags_text']) && (empty($rr['tags']) || $rr['tags'] === null)) {
                        $mup = $db->prepare('UPDATE locations SET tags = :tags WHERE id = :id');
                        $mup->execute([':tags' => $rr['tags_text'], ':id' => $locationId]);
                    }
                } catch (Exception $e) { /* ignore */
                }
            }
        }
        if ($locationId > 0) {
            // Determine arrival/departure/notes: prefer incoming values, fall back to existing saved values
            $arrivalVal = isset($it['arrival']) && $it['arrival'] !== '' ? $it['arrival'] : (isset($existing[$locationId]['arrival']) ? $existing[$locationId]['arrival'] : null);
            $departureVal = isset($it['departure']) && $it['departure'] !== '' ? $it['departure'] : (isset($existing[$locationId]['departure']) ? $existing[$locationId]['departure'] : null);
            $notesVal = isset($it['notes']) ? $it['notes'] : (isset($existing[$locationId]['notes']) ? $existing[$locationId]['notes'] : null);
            // If this is the first position, remember arrival for updating route start_date
            if ($pos === 1) {
                $firstArrival = $arrivalVal;
            }
            $ins->execute([':rid' => $route_id,':lid' => $locationId,':pos' => $pos, ':arrival' => $arrivalVal, ':departure' => $departureVal, ':notes' => $notesVal]);
            $pos++;
        }
    }
    // After inserting items, update route start_date from firstArrival (date portion)
    if ($firstArrival !== null) {
        $sd = substr($firstArrival, 0, 10);
        $upd = $db->prepare('UPDATE routes SET start_date = :sd WHERE id = :rid');
        $upd->execute([':sd' => $sd, ':rid' => $route_id]);
    } else {
        // If firstArrival is null, clear start_date
        $upd = $db->prepare('UPDATE routes SET start_date = NULL WHERE id = :rid');
        $upd->execute([':rid' => $route_id]);
    }

    $db->commit();
    echo json_encode(['ok' => true]);
    exit;
} catch (Exception $e) {
    if ($db && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false,'error' => 'exception','msg' => $e->getMessage()]);
    exit;
}
