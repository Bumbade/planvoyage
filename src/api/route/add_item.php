<?php

// src/api/route/add_item.php
// Adds a location to a route (route_items)

require_once __DIR__ . '/../../config/mysql.php';
require_once __DIR__ . '/../../helpers/session.php';

// Ensure session is started so we can check authentication/authorization
start_secure_session();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$data = $_POST + json_decode(file_get_contents('php://input') ?: '{}', true);
$route_id = isset($data['route_id']) ? (int)$data['route_id'] : 0;
$location_id = isset($data['location_id']) ? (int)$data['location_id'] : 0;
$arrival = !empty($data['arrival']) ? $data['arrival'] : null;
$departure = !empty($data['departure']) ? $data['departure'] : null;
$notes = !empty($data['notes']) ? $data['notes'] : null;

if ($route_id <= 0 || $location_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'route_id and location_id are required']);
    exit;
}

try {
    $db = get_db();
    // Authorization: ensure the current user is the owner of the route
    $currentUser = $_SESSION['user_id'] ?? null;
    $ownerStmt = $db->prepare('SELECT user_id FROM routes WHERE id = :id LIMIT 1');
    $ownerStmt->execute([':id' => $route_id]);
    $ownerRow = $ownerStmt->fetch(PDO::FETCH_ASSOC);
    if (!$ownerRow) {
        http_response_code(404);
        echo json_encode(['error' => 'Route not found']);
        exit;
    }
    $ownerId = $ownerRow['user_id'] ?? null;
    if ($ownerId !== null && $currentUser !== null && (int)$ownerId !== (int)$currentUser) {
        http_response_code(403);
        echo json_encode(['error' => 'Not authorized to modify this route']);
        exit;
    }
    // If route owner is NULL (public/legacy), allow if session present; otherwise allow anonymous for legacy routes.
    // Compute next position
    $posStmt = $db->prepare('SELECT COALESCE(MAX(position), 0) + 1 AS next_pos FROM route_items WHERE route_id = :rid');
    $posStmt->execute([':rid' => $route_id]);
    $posRow = $posStmt->fetch(PDO::FETCH_ASSOC);
    $position = $posRow['next_pos'] ?? 1;

    $ins = $db->prepare('INSERT INTO route_items (route_id, location_id, position, arrival, departure, notes, created_at) VALUES (:rid, :lid, :pos, :arrival, :departure, :notes, NOW())');
    $ins->execute([
        ':rid' => $route_id,
        ':lid' => $location_id,
        ':pos' => $position,
        ':arrival' => $arrival,
        ':departure' => $departure,
        ':notes' => $notes
    ]);
    $itemId = (int)$db->lastInsertId();
    // If this was inserted as position 1, update route.start_date to this arrival (date portion)
    if ($position === 1) {
        if ($arrival !== null && $arrival !== '') {
            $sd = substr($arrival, 0, 10);
            $upd = $db->prepare('UPDATE routes SET start_date = :sd WHERE id = :rid');
            $upd->execute([':sd' => $sd, ':rid' => $route_id]);
        } else {
            // clear start_date if no arrival provided
            $upd = $db->prepare('UPDATE routes SET start_date = NULL WHERE id = :rid');
            $upd->execute([':rid' => $route_id]);
        }
    }
    // Return the inserted item
    $stmt = $db->prepare('SELECT ri.id AS item_id, ri.position, ri.arrival, ri.departure, ri.notes, l.id AS location_id, l.name AS location_name, l.type AS location_type FROM route_items ri JOIN locations l ON ri.location_id = l.id WHERE ri.id = :id LIMIT 1');
    $stmt->execute([':id' => $itemId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'item' => $row]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Insert failed', 'msg' => $e->getMessage()]);
    exit;
}
