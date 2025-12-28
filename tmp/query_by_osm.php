<?php
$root = realpath(__DIR__ . '/..');
require_once $root . '/src/config/env.php';
require_once $root . '/src/config/mysql.php';
try {
    $db = get_db();
    $osm = 312525713;
    $stmt = $db->prepare('SELECT * FROM locations WHERE osm_id = :osm ORDER BY id ASC');
    $stmt->execute([':osm' => $osm]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]) . "\n";
}
