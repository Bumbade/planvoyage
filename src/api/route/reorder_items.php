<?php

// src/api/route/reorder_items.php
// Reorder route_items for a given route. Accepts POST with `route_id` and `items[]` (ordered item IDs).

require_once __DIR__ . '/../../config/mysql.php';
require_once __DIR__ . '/../../helpers/session.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

start_secure_session();

// Accept both form-encoded and JSON payloads. JSON should be { route_id: N, items: [ { item_id:, position:, arrival:, departure: }, ... ] }
$raw = file_get_contents('php://input');
$json = json_decode($raw, true);
// Debug log incoming payload for troubleshooting (write into existing `logs/` folder)
try {
    $logFile = __DIR__ . '/../../logs/reorder_debug.log';
    @file_put_contents($logFile, json_encode(['ts' => date('c'), 'raw' => $raw, 'decoded' => $json]) . PHP_EOL, FILE_APPEND | LOCK_EX);
} catch (Exception $e) { /* ignore logging failures */ }
if (is_array($json) && isset($json['route_id'])) {
    $route_id = (int)$json['route_id'];
    $items = is_array($json['items']) ? $json['items'] : [];
} else {
    // fallback to POST form where items[] is list of ids
    $data = $_POST + (array)$json;
    $route_id = isset($data['route_id']) ? (int)$data['route_id'] : 0;
    $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : null;
}

if ($route_id <= 0 || $items === null) {
    http_response_code(400);
    echo json_encode(['error' => 'route_id and items[] are required']);
    exit;
}

// Normalize items into ordered array of ['id'=>int, 'pos'=>int]
$ordered = [];
$idx = 1;

// To support clients that send location_id instead of route_items.id (when data-item-id was 0),
// load existing route_items for this route and map by location_id as queues.
try {
    $existingStmt = get_db()->prepare('SELECT id, location_id FROM route_items WHERE route_id = ? ORDER BY position ASC, id ASC');
    $existingStmt->execute([$route_id]);
    $existingRows = $existingStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $existingRows = [];
}
$availableByLoc = [];
foreach ($existingRows as $er) {
    $lid = isset($er['location_id']) ? (int)$er['location_id'] : 0;
    if ($lid > 0) {
        if (!isset($availableByLoc[$lid])) $availableByLoc[$lid] = [];
        $availableByLoc[$lid][] = (int)$er['id'];
    }
}

// Log availableByLoc for debugging
try {
    $logFile = __DIR__ . '/../../logs/reorder_debug.log';
    @file_put_contents($logFile, json_encode(['ts' => date('c'), 'route_id' => $route_id, 'existingRows' => $existingRows, 'availableByLoc' => $availableByLoc]) . PHP_EOL, FILE_APPEND | LOCK_EX);
} catch (Exception $e) { }

foreach ($items as $it) {
    $id = 0; $pos = $idx;
    if (is_array($it)) {
        $pos = isset($it['position']) ? (int)$it['position'] : $idx;
        if (isset($it['item_id']) && (int)$it['item_id'] > 0) {
            $id = (int)$it['item_id'];
        } elseif (isset($it['id']) && (int)$it['id'] > 0) {
            $id = (int)$it['id'];
        } elseif (isset($it['location_id']) && (int)$it['location_id'] > 0) {
            $lid = (int)$it['location_id'];
            // pop the next available route_item id for this location
            if (isset($availableByLoc[$lid]) && count($availableByLoc[$lid]) > 0) {
                $id = array_shift($availableByLoc[$lid]);
            }
        }
    } elseif (is_scalar($it)) {
        $id = (int)$it;
    }
    if ($id > 0) {
        $ordered[] = ['id' => $id, 'pos' => $pos];
    }
    $idx++;
}

// Log the normalized ordered array for debugging
try {
    $logFile = __DIR__ . '/../../logs/reorder_debug.log';
    @file_put_contents($logFile, json_encode(['ts' => date('c'), 'route_id' => $route_id, 'ordered' => $ordered]) . PHP_EOL, FILE_APPEND | LOCK_EX);
} catch (Exception $e) { }

if (count($ordered) === 0) {
    // Fallback: try per-item lookup by location_id when initial mapping failed
    try {
        $db = get_db();
        $used = [];
        foreach ($items as $it) {
            $lid = null;
            if (is_array($it) && isset($it['location_id'])) {
                $lid = (int)$it['location_id'];
            }
            if (!$lid) continue;
            // find a route_item id for this route+location
            $stmt = $db->prepare('SELECT id FROM route_items WHERE route_id = ? AND location_id = ? LIMIT 1');
            $stmt->execute([$route_id, $lid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['id'])) {
                $rid = (int)$row['id'];
                if (!in_array($rid, $used, true)) {
                    $used[] = $rid;
                    $ordered[] = ['id' => $rid, 'pos' => count($ordered) + 1];
                }
            }
        }
        // log fallback result
        try { @file_put_contents(__DIR__ . '/../../logs/reorder_debug.log', json_encode(['ts'=>date('c'),'route_id'=>$route_id,'fallback_ordered'=>$ordered]) . PHP_EOL, FILE_APPEND | LOCK_EX); } catch (Exception $e) {}
    } catch (Exception $e) { /* ignore fallback errors */ }

    if (count($ordered) === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'no valid items']);
        exit;
    }
}

try {
    $db = get_db();
    // Authorization: owner only if owner set
    $currentUser = $_SESSION['user_id'] ?? null;
    $ownerStmt = $db->prepare('SELECT user_id FROM routes WHERE id = :id LIMIT 1');
    $ownerStmt->execute([':id' => $route_id]);
    $ownerRow = $ownerStmt->fetch(PDO::FETCH_ASSOC);
    $ownerId = $ownerRow['user_id'] ?? null;
    if ($ownerId !== null && $currentUser !== null && (int)$ownerId !== (int)$currentUser) {
        http_response_code(403);
        echo json_encode(['error' => 'Not authorized to reorder items on this route']);
        exit;
    }

    // Validate IDs belong to route
    $ids = array_column($ordered, 'id');
    $placeholders = rtrim(str_repeat('?,', count($ids)), ',');
    $checkStmt = $db->prepare("SELECT id FROM route_items WHERE id IN ($placeholders) AND route_id = ?");
    $execParams = $ids;
    $execParams[] = $route_id;
    $checkStmt->execute($execParams);
    $found = $checkStmt->fetchAll(PDO::FETCH_COLUMN, 0);
    if (count($found) !== count($ids)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid item IDs for this route']);
        exit;
    }

    // Update positions (preserve arrival/departure as-is)
    $db->beginTransaction();
    $upd = $db->prepare('UPDATE route_items SET position = :pos WHERE id = :id AND route_id = :rid');
    foreach ($ordered as $row) {
        $upd->execute([':pos' => $row['pos'], ':id' => $row['id'], ':rid' => $route_id]);
    }
    $db->commit();

    echo json_encode(['ok' => true]);
    exit;

} catch (Exception $e) {
    if ($db && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Reorder failed', 'msg' => $e->getMessage()]);
    exit;
}
