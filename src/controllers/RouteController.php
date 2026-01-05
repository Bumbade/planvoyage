<?php

// src/controllers/RouteController.php
require_once __DIR__ . '/../config/mysql.php';

class RouteController
{
    /** @var PDO */
    private $db;

    public function __construct()
    {
        $this->db = get_db();
    }

    // Routing helpers used by src/index.php
    public function index()
    {
        $routes = $this->getAllRoutes();
        include __DIR__ . '/../views/routes/index.php';
    }

    public function create()
    {
        // Only logged-in users may create routes
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            $base = getenv('APP_BASE') ?: '';
            $redirect = $base !== '' ? rtrim($base, '/') . '/user/login' : '/user/login';
            header('Location: ' . $redirect);
            exit;
        }

        // If this is a POST, handle creation
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // CSRF protection
            $token = $_POST['csrf_token'] ?? null;
            if (!function_exists('csrf_check') || !csrf_check($token)) {
                flash_set('error', 'Invalid CSRF token');
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            }
            $name = trim($_POST['name'] ?? '');
            $start_date = $_POST['start_date'] ?? null;
            $end_date = $_POST['end_date'] ?? null;

            if ($name === '') {
                // simple validation
                flash_set('error', 'Route name is required');
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            }

            // Ensure dates are provided (DB requires NOT NULL)
            if (empty($start_date) || empty($end_date)) {
                flash_set('error', 'Start and end dates are required');
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            }

            // Ensure end date is not before start date
            $sd_ts = strtotime($start_date);
            $ed_ts = strtotime($end_date);
            if ($sd_ts === false || $ed_ts === false || $ed_ts < $sd_ts) {
                flash_set('error', 'End date must be the same as or after the start date');
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            }

            // Delegate insertion to a testable method so we can call it from tests without invoking headers.
            $res = $this->createRoute($name, $start_date, $end_date, $userId);
            if (!empty($res['ok'])) {
                $newId = (int)$res['id'];
                flash_set('success', 'Route created successfully');
                header('Location: ' . str_replace('/create', '/view', $_SERVER['REQUEST_URI']) . '?id=' . $newId);
                exit;
            } else {
                flash_set('error', $res['error'] ?? 'Failed to create route');
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            }
        }

        include __DIR__ . '/../views/routes/create.php';
    }

    public function view()
    {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $route = $this->viewRoute($id);
        include __DIR__ . '/../views/routes/view.php';
    }

    // Data access used by views
    public function getAllRoutes()
    {
        // Only return routes belonging to the currently logged-in user.
        // If no user is logged in, return an empty array.
        $userId = $_SESSION['user_id'] ?? null;
        if ($userId === null) {
            return [];
        }

        $stmt = $this->db->prepare('SELECT id, user_id, name, start_date, end_date, created_at FROM routes WHERE user_id = :uid ORDER BY created_at DESC');
        $stmt->execute([':uid' => (int)$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function viewRoute($id)
    {
        // Fetch whatever columns exist for this route and normalize into an object.
        $stmt = $this->db->prepare('SELECT * FROM routes WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $obj = new stdClass();
        // provide safe defaults for fields used by views
        $obj->id = $row['id'] ?? null;
        $obj->user_id = $row['user_id'] ?? null;
        $obj->name = $row['name'] ?? '';
        $obj->start_date = $row['start_date'] ?? '';
        $obj->end_date = $row['end_date'] ?? '';
        $obj->description = $row['description'] ?? '';
        $obj->created_at = $row['created_at'] ?? null;
        $obj->updated_at = $row['updated_at'] ?? null;
        // Load route items (if the table exists)
        try {
            // Determine which optional contact/address columns exist in `locations` and include them if present.
            $availableCols = [];
            try {
                $colsStmt = $this->db->query("SHOW COLUMNS FROM locations");
                $cols = $colsStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($cols as $c) {
                    $availableCols[] = $c['Field'];
                }
            } catch (Exception $e) {
                // If SHOW COLUMNS fails, fall back to an empty list (no extras)
                $availableCols = [];
            }

            $wanted = ['address','addr_street','addr_housenumber','addr_city','addr_postcode','phone','email','website','opening_hours'];
            $extras = array_values(array_intersect($wanted, $availableCols));

            $extraSelect = '';
            foreach ($extras as $col) {
                // alias to keep same key in result
                $extraSelect .= ", l." . $col . " AS " . $col;
            }

            $sql = 'SELECT ri.id AS item_id, ri.position, ri.arrival, ri.departure, ri.notes, l.id AS location_id, l.name AS location_name, l.type AS location_type, l.latitude, l.longitude, l.logo AS logo, l.country AS country, l.state AS state' . $extraSelect . "\n                 FROM route_items ri\n                 LEFT JOIN locations l ON ri.location_id = l.id\n                 WHERE ri.route_id = :rid\n                 ORDER BY ri.position ASC";

            $itStmt = $this->db->prepare($sql);
            $itStmt->execute([':rid' => $id]);
            $items = $itStmt->fetchAll(PDO::FETCH_ASSOC);
            $obj->items = $items;
        } catch (Exception $e) {
            // If the route_items table doesn't exist or query fails, provide empty items
            $obj->items = [];
        }
        return $obj;
    }

    /**
     * Insert a route programmatically. Returns ['ok'=>true,'id'=>int] on success or ['ok'=>false,'error'=>string].
     * This method is safe to call from CLI tests and does not send headers.
     */
    public function createRoute(string $name, string $start_date, string $end_date, $userId = null): array
    {
        try {
            $stmt = $this->db->prepare('INSERT INTO routes (user_id, name, start_date, end_date, created_at) VALUES (:uid, :name, :sd, :ed, NOW())');
            $params = [
                ':uid' => $userId === null ? null : (int)$userId,
                ':name' => $name,
                ':sd' => $start_date,
                ':ed' => $end_date
            ];
            // Log parameters for debugging
            try {
                $logDir = __DIR__ . '/../../_development/logs';
                if (!is_dir($logDir)) {
                    @mkdir($logDir, 0777, true);
                }
                $dbg = ['ts' => date('c'), 'user_id' => $userId, 'params' => $params];
                @file_put_contents($logDir . '/route_create_debug.log', json_encode($dbg) . PHP_EOL, FILE_APPEND | LOCK_EX);
            } catch (Exception $e) {
                // ignore
            }

            $ok = $stmt->execute($params);
            if ($ok) {
                $newId = (int)$this->db->lastInsertId();
                return ['ok' => true, 'id' => $newId];
            }
            $err = $stmt->errorInfo();
            return ['ok' => false, 'error' => 'Insert failed: ' . json_encode($err)];
        } catch (Exception $e) {
            return ['ok' => false, 'error' => 'Exception: ' . $e->getMessage()];
        }
    }
}
