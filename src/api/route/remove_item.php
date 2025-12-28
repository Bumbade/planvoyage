<?php

// src/api/route/remove_item.php
// Removes an item from a route (route_items) and reorders subsequent items.

require_once __DIR__ . '/../../config/mysql.php';
require_once __DIR__ . '/../../helpers/session.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

start_secure_session();

$data = $_POST + json_decode(file_get_contents('php://input') ?: '{}', true);
$item_id = isset($data['item_id']) ? (int)$data['item_id'] : 0;

if ($item_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'item_id is required']);
    exit;
}

try {
    $db = get_db();

    // Find the item and associated route
    $stmt = $db->prepare('SELECT route_id, position FROM route_items WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $item_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Item not found']);
        exit;
    }
    $route_id = (int)$row['route_id'];
    $pos = (int)$row['position'];

    // Authorization: ensure current user owns the route (if owner set)
    $currentUser = $_SESSION['user_id'] ?? null;
    $ownerStmt = $db->prepare('SELECT user_id FROM routes WHERE id = :id LIMIT 1');
    $ownerStmt->execute([':id' => $route_id]);
    $ownerRow = $ownerStmt->fetch(PDO::FETCH_ASSOC);
    $ownerId = $ownerRow['user_id'] ?? null;
    if ($ownerId !== null && $currentUser !== null && (int)$ownerId !== (int)$currentUser) {
        http_response_code(403);
        echo json_encode(['error' => 'Not authorized to remove this item']);
        exit;
    }

    // Delete the item
    $del = $db->prepare('DELETE FROM route_items WHERE id = :id');
    $del->execute([':id' => $item_id]);

    // Shift positions down for items with position > deleted position
    $upd = $db->prepare('UPDATE route_items SET position = position - 1 WHERE route_id = :rid AND position > :pos');
    $upd->execute([':rid' => $route_id, ':pos' => $pos]);

    echo json_encode(['ok' => true]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Delete failed', 'msg' => $e->getMessage()]);
    exit;
}
