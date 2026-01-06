<?php
// scripts/backfill_cannabis.php
// Usage: php scripts/backfill_cannabis.php [--execute]
// Default: dry-run (shows matching row count and sample rows)

$execute = in_array('--execute', $argv, true);
require_once __DIR__ . '/../config/mysql.php';
$db = get_db();
$pattern = '%"shop":"cannabis"%';
try {
    // count matching rows first
    $countSql = "SELECT COUNT(*) AS cnt FROM locations WHERE (logo IS NULL OR TRIM(logo) = '') AND COALESCE(LOWER(tags_text), '') LIKE :pat";
    $stmt = $db->prepare($countSql);
    $stmt->bindValue(':pat', strtolower($pattern), PDO::PARAM_STR);
    $stmt->execute();
    $cnt = (int)$stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    echo "Matching rows: $cnt\n";

    if (!$execute) {
        echo "Dry-run mode. To apply changes run with --execute\n";
        if ($cnt > 0) {
            // show up to 10 sample rows
            $sample = $db->prepare("SELECT id, name, tags_text, logo FROM locations WHERE (logo IS NULL OR TRIM(logo) = '') AND COALESCE(LOWER(tags_text), '') LIKE :pat LIMIT 10");
            $sample->bindValue(':pat', strtolower($pattern), PDO::PARAM_STR);
            $sample->execute();
            $rows = $sample->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                echo "id={" . $r['id'] . "} name={" . ($r['name'] ?? '') . "} logo={" . ($r['logo'] ?? '') . "}\n";
            }
        }
        exit(0);
    }

    if ($cnt === 0) {
        echo "No rows to update.\n";
        exit(0);
    }

    // perform update in a transaction
    $db->beginTransaction();
    $updateSql = "UPDATE locations SET type = 'Cannabis', logo = 'Cannabis.png' WHERE (logo IS NULL OR TRIM(logo) = '') AND COALESCE(LOWER(tags_text), '') LIKE :pat";
    $u = $db->prepare($updateSql);
    $u->bindValue(':pat', strtolower($pattern), PDO::PARAM_STR);
    $u->execute();
    $affected = $u->rowCount();
    $db->commit();

    echo "Update applied. Rows affected: $affected\n";
    exit(0);
} catch (Exception $e) {
    if ($db && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(2);
}
