<?php
/**
 * RouteService - Centralized API service for route operations
 * 
 * Handles:
 * - Route list retrieval (using Route model)
 * - Route creation (using Route model)
 * - Route updates (using Route model)
 * - Unified error responses
 */

require_once __DIR__ . '/../models/Route.php';

class RouteService
{
    private $pdo;

    public function __construct(?PDO $pdo = null)
    {
        if ($pdo) {
            $this->pdo = $pdo;
        } else {
            if (file_exists(__DIR__ . '/../config/mysql.php')) {
                require_once __DIR__ . '/../config/mysql.php';
                $this->pdo = get_db();
            }
        }
    }

    /**
     * Get paginated list of routes using Route model
     * 
     * Returns JSON with pagination info and route data
     */
    public function listRoutes(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!$this->pdo) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Database connection failed'
            ]);
            return;
        }

        try {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
            $offset = ($page - 1) * $perPage;
            $userId = $_SESSION['user_id'] ?? null;

            if (!$userId) {
                http_response_code(401);
                echo json_encode([
                    'success' => false,
                    'error' => 'Authentication required'
                ]);
                return;
            }

            // Use Route model to fetch user's routes
            $routes = Route::findByUserId($this->pdo, $userId, $perPage, $offset);

            // Convert to array format
            $data = array_map(fn($route) => $route->toArray(), $routes);

            echo json_encode([
                'success' => true,
                'page' => $page,
                'per_page' => $perPage,
                'data' => $data
            ]);
        } catch (Throwable $e) {
            error_log('RouteService::listRoutes - ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Failed to retrieve routes'
            ]);
        }
    }

    /**
     * Create a new route using Route model
     * 
     * Expects POST:
     * - name: Route name
     * - start_date: Start date (YYYY-MM-DD)
     * - end_date: End date (YYYY-MM-DD)
     * 
     * Returns JSON with new route ID
     */
    public function createRoute(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode([
                'success' => false,
                'error' => 'Method not allowed'
            ]);
            return;
        }

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'error' => 'Authentication required'
            ]);
            return;
        }

        try {
            $name = trim($_POST['name'] ?? '');
            $startDate = trim($_POST['start_date'] ?? '');
            $endDate = trim($_POST['end_date'] ?? '');

            // Validate input
            if (empty($name)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Route name is required'
                ]);
                return;
            }

            if (empty($startDate) || empty($endDate)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Start and end dates are required'
                ]);
                return;
            }

            // Validate dates
            if (!$this->isValidDate($startDate) || !$this->isValidDate($endDate)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid date format (use YYYY-MM-DD)'
                ]);
                return;
            }

            if ($startDate > $endDate) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'End date must be after start date'
                ]);
                return;
            }

            // Create new Route using model
            $route = new Route($this->pdo, $userId);
            $route->setName($name)
                  ->setStartDate($startDate)
                  ->setEndDate($endDate);

            if (!$route->save()) {
                throw new Exception('Failed to save route');
            }

            echo json_encode([
                'success' => true,
                'id' => $route->getId(),
                'name' => $route->getName(),
                'start_date' => $route->getStartDate(),
                'end_date' => $route->getEndDate()
            ]);
        } catch (Throwable $e) {
            error_log('RouteService::createRoute - ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Failed to create route'
            ]);
        }
    }

    /**
     * Validate date format (YYYY-MM-DD)
     */
    private function isValidDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
}
