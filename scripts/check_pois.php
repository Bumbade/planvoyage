<?php
// Usage: php scripts/check_pois.php <user_id> <comma_separated_ids>
$argv0 = $argv;
if ($argc < 3) {
    fwrite(STDERR, "Usage: php scripts/check_pois.php <user_id> <comma_separated_ids>\n");
    exit(2);
}
$userId = (int)$argv[1];
$idsArg = $argv[2];
$ids = array_filter(array_map('trim', explode(',', $idsArg)), function($v){ return $v !== ''; });
if (!$ids) { fwrite(STDERR, "No IDs provided\n"); exit(2); }
$root = __DIR__ . '/..';
$envFile = $root . '/.env';
$env = [];
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (!strpos($line, '=')) continue;
        list($k,$v) = explode('=', $line, 2);
        $env[trim($k)] = trim($v);
    }
}
$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? '3306';
$db   = $env['DB_NAME'] ?? 'travel_planner_v4';
$user = $env['DB_USER'] ?? 'root';
$pass = $env['DB_PASS'] ?? '';
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $db);
try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Exception $e) {
    fwrite(STDERR, "DB connect failed: " . $e->getMessage() . "\n");
    exit(3);
}
$in = implode(',', array_map('intval', $ids));
// 1) Which IDs exist in locations
$sql1 = "SELECT id, name FROM locations WHERE id IN ($in) ORDER BY id";
$stmt1 = $pdo->query($sql1);
$locations = $stmt1->fetchAll(PDO::FETCH_ASSOC);
// 2) Which of these are favorited by the user
$sql2 = "SELECT f.location_id AS id, f.created_at FROM favorites f WHERE f.user_id = :uid AND f.location_id IN ($in) ORDER BY f.created_at DESC";
$stmt2 = $pdo->prepare($sql2);
$stmt2->bindValue(':uid', $userId, PDO::PARAM_INT);
stmt2_execute:
try { $stmt2->execute(); } catch (Exception $e) { fwrite(STDERR, "Query failed: " . $e->getMessage() . "\n"); exit(4); }
$favs = $stmt2->fetchAll(PDO::FETCH_ASSOC);
// 3) Orphan favorites (favorites for user pointing to missing locations)
$sql3 = "SELECT f.location_id AS id FROM favorites f WHERE f.user_id = :uid AND NOT EXISTS (SELECT 1 FROM locations l WHERE l.id = f.location_id)";
$stmt3 = $pdo->prepare($sql3);
$stmt3->bindValue(':uid', $userId, PDO::PARAM_INT);
$stmt3->execute();
$orphans = $stmt3->fetchAll(PDO::FETCH_ASSOC);
$out = [
    'user_id' => $userId,
    'requested_ids' => array_values(array_map('intval', $ids)),
    'locations_found' => $locations,
    'favorites_for_user' => $favs,
    'orphan_favorites' => $orphans,
];
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"; 
