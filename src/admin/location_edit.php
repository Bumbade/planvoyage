<?php
// Protected admin edit page for a location
// Access: logged-in admin (USER_ID=1 fallback) or by ADMIN_EMAIL env
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../helpers/session.php';
start_secure_session();
// use centralized auth helper when available
if (file_exists(__DIR__ . '/../helpers/auth.php')) {
    require_once __DIR__ . '/../helpers/auth.php';
}

if (!is_admin_user()) {
    // Redirect to login (use app_url if available via templates helper)
    $base = getenv('APP_BASE') ?: '';
    $login = '';
    if ($base !== '') {
        $login = rtrim($base, '/') . '/user/login';
    } else {
        $login = '/user/login';
    }
    header('Location: ' . $login);
    exit;
}

// DB access
require_once __DIR__ . '/../config/mysql.php';
$db = get_db();

$id = isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : 0;
if ($id <= 0) {
    echo "<p>Missing or invalid id parameter.</p>";
    exit;
}

// Helper: check if a column exists in locations table
function column_exists($db, $column)
{
    $stmt = $db->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'locations' AND COLUMN_NAME = :col");
    $stmt->execute([':col' => $column]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return (!empty($r) && (int)$r['c'] > 0);
}

$has_latlon = column_exists($db, 'latitude') && column_exists($db, 'longitude');
$has_point = column_exists($db, 'coordinates');
$has_updated_at = column_exists($db, 'updated_at');

// Load existing record
$stmt = $db->prepare('SELECT * FROM locations WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$location = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$location) {
    echo "<p>Location not found.</p>";
    exit;
}

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!csrf_check($token)) {
        $error = 'Invalid CSRF token';
    } else {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $country = trim($_POST['country'] ?? '');
        $lat = isset($_POST['latitude']) ? trim($_POST['latitude']) : null;
        $lon = isset($_POST['longitude']) ? trim($_POST['longitude']) : null;

        // Build update dynamically depending on available columns
        $sets = [];
        $params = [':id' => $id];
        if ($name !== '') {
            $sets[] = 'name = :name';
            $params[':name'] = $name;
        }
        if ($description !== '') {
            $sets[] = 'description = :description';
            $params[':description'] = $description;
        }
        if ($city !== '') {
            $sets[] = 'city = :city';
            $params[':city'] = $city;
        }
        if ($country !== '') {
            $sets[] = 'country = :country';
            $params[':country'] = $country;
        }
        if ($has_latlon && $lat !== null && $lat !== '') {
            $sets[] = 'latitude = :lat';
            $params[':lat'] = $lat;
        }
        if ($has_latlon && $lon !== null && $lon !== '') {
            $sets[] = 'longitude = :lon';
            $params[':lon'] = $lon;
        }
        if ($has_updated_at) {
            $sets[] = 'updated_at = NOW()';
        }

        try {
            if (!empty($sets)) {
                $sql = 'UPDATE locations SET ' . implode(', ', $sets) . ' WHERE id = :id';
                $u = $db->prepare($sql);
                $u->execute($params);
            }

            // Update POINT column if present and we have both lat+lon
            if ($has_point && $lat !== null && $lat !== '' && $lon !== null && $lon !== '') {
                $pstmt = $db->prepare("UPDATE locations SET coordinates = ST_GeomFromText(CONCAT('POINT(', :lon, ' ', :lat, ')')) WHERE id = :id");
                $pstmt->execute([':lon' => $lon, ':lat' => $lat, ':id' => $id]);
            }

            $success = 'Location updated successfully.';
            // Reload location for form
            $stmt = $db->prepare('SELECT * FROM locations WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);
            $location = $stmt->fetch(PDO::FETCH_ASSOC);
            // Mirror tags_text into tags if present and tags empty
            try {
                if ($location && array_key_exists('tags_text', $location) && array_key_exists('tags', $location) && !empty($location['tags_text']) && (empty($location['tags']) || $location['tags'] === null)) {
                    $m = $db->prepare('UPDATE locations SET tags = :tags WHERE id = :id');
                    $m->execute([':tags' => $location['tags_text'], ':id' => $id]);
                    $location['tags'] = $location['tags_text'];
                }
            } catch (Exception $e) { /* ignore mirror errors */
            }
        } catch (Throwable $e) {
            $error = 'Update failed: ' . $e->getMessage();
        }
    }
}

// Render simple form using existing header/footer
include __DIR__ . '/../includes/header.php';
?>
<main>
    <h1>Edit location #<?php echo htmlspecialchars($id); ?></h1>
    <?php if ($success): ?><div class="flash-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <form method="post" action="<?php echo htmlspecialchars(app_url('src/admin/location_edit.php?id=' . $id)); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <label>Name: <input type="text" name="name" value="<?php echo htmlspecialchars($location['name'] ?? ''); ?>"></label><br>
        <label>Description: <textarea name="description"><?php echo htmlspecialchars($location['description'] ?? ''); ?></textarea></label><br>
        <label>City: <input type="text" name="city" value="<?php echo htmlspecialchars($location['city'] ?? ''); ?>"></label><br>
        <label>Country: <input type="text" name="country" value="<?php echo htmlspecialchars($location['country'] ?? ''); ?>"></label><br>
        <?php if ($has_latlon || $has_point): ?>
            <label>Latitude: <input type="text" name="latitude" value="<?php echo htmlspecialchars($location['latitude'] ?? ''); ?>"></label><br>
            <label>Longitude: <input type="text" name="longitude" value="<?php echo htmlspecialchars($location['longitude'] ?? ''); ?>"></label><br>
        <?php endif; ?>
        <button type="submit">Save changes</button>
    </form>
    <p><a href="<?php echo htmlspecialchars(app_url('locations')); ?>">Back to POIs</a></p>
</main>

<?php include __DIR__ . '/../includes/footer.php';
