<?php

/**
 * Route Model - Data access layer for routes/trips
 * 
 * Represents a single route/trip in the system.
 * Handles all database operations for routes.
 */

class Route
{
    private $pdo;
    
    // Entity properties
    private $id;
    private $userId;
    private $name;
    private $startDate;
    private $endDate;
    private $createdAt;
    private $updatedAt;

    /**
     * Constructor
     * 
     * @param PDO $pdo Database connection
     * @param string $name Route name
     * @param string $startDate Start date (YYYY-MM-DD)
     * @param string $endDate End date (YYYY-MM-DD)
     * @param int $userId User ID
     */
    public function __construct(PDO $pdo, string $name = '', string $startDate = '', 
                               string $endDate = '', int $userId = 0)
    {
        $this->pdo = $pdo;
        $this->name = $name;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->userId = $userId;
    }

    // ============================================
    // Getters
    // ============================================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getStartDate(): string
    {
        return $this->startDate;
    }

    public function getEndDate(): string
    {
        return $this->endDate;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    // ============================================
    // Setters
    // ============================================

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function setStartDate(string $startDate): self
    {
        $this->startDate = $startDate;
        return $this;
    }

    public function setEndDate(string $endDate): self
    {
        $this->endDate = $endDate;
        return $this;
    }

    public function setUserId(int $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    // ============================================
    // Database Operations
    // ============================================

    /**
     * Save route to database (create or update)
     */
    public function save(): bool
    {
        try {
            if ($this->id === null) {
                return $this->create();
            } else {
                return $this->update();
            }
        } catch (Throwable $e) {
            error_log('Route::save - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Create new route in database
     */
    public function create(): bool
    {
        try {
            $sql = 'INSERT INTO routes (user_id, name, start_date, end_date, created_at) 
                   VALUES (:user_id, :name, :start_date, :end_date, NOW())';

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':user_id', $this->userId, PDO::PARAM_INT);
            $stmt->bindValue(':name', $this->name, PDO::PARAM_STR);
            $stmt->bindValue(':start_date', $this->startDate, PDO::PARAM_STR);
            $stmt->bindValue(':end_date', $this->endDate, PDO::PARAM_STR);
            $stmt->execute();

            $this->id = (int)$this->pdo->lastInsertId();
            return true;
        } catch (Throwable $e) {
            error_log('Route::create - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update existing route in database
     */
    public function update(): bool
    {
        try {
            if (!$this->id) {
                throw new Exception('Cannot update route without ID');
            }

            $sql = 'UPDATE routes 
                   SET name = :name, start_date = :start_date, end_date = :end_date, updated_at = NOW()
                   WHERE id = :id AND user_id = :user_id';

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
            $stmt->bindValue(':user_id', $this->userId, PDO::PARAM_INT);
            $stmt->bindValue(':name', $this->name, PDO::PARAM_STR);
            $stmt->bindValue(':start_date', $this->startDate, PDO::PARAM_STR);
            $stmt->bindValue(':end_date', $this->endDate, PDO::PARAM_STR);
            $stmt->execute();

            return true;
        } catch (Throwable $e) {
            error_log('Route::update - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete route from database
     */
    public function delete(): bool
    {
        try {
            if (!$this->id) {
                throw new Exception('Cannot delete route without ID');
            }

            $sql = 'DELETE FROM routes WHERE id = :id AND user_id = :user_id';
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
            $stmt->bindValue(':user_id', $this->userId, PDO::PARAM_INT);
            $stmt->execute();

            return true;
        } catch (Throwable $e) {
            error_log('Route::delete - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Find route by ID
     * 
     * @return Route|null The loaded route or null if not found
     */
    public static function findById(PDO $pdo, int $id): ?self
    {
        try {
            $sql = 'SELECT * FROM routes WHERE id = :id LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }

            return self::fromArray($pdo, $row);
        } catch (Throwable $e) {
            error_log('Route::findById - ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Find all routes for a user
     * 
     * @return array Array of Route objects
     */
    public static function findByUserId(PDO $pdo, int $userId, int $limit = 100, int $offset = 0): array
    {
        try {
            $sql = 'SELECT * FROM routes 
                   WHERE user_id = :user_id 
                   ORDER BY created_at DESC 
                   LIMIT :limit OFFSET :offset';

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $routes = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $routes[] = self::fromArray($pdo, $row);
            }

            return $routes;
        } catch (Throwable $e) {
            error_log('Route::findByUserId - ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Count routes for a user
     */
    public static function countByUserId(PDO $pdo, int $userId): int
    {
        try {
            $sql = 'SELECT COUNT(*) as total FROM routes WHERE user_id = :user_id';
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->execute();

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($row['total'] ?? 0);
        } catch (Throwable $e) {
            error_log('Route::countByUserId - ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Create Route instance from database row
     */
    private static function fromArray(PDO $pdo, array $row): self
    {
        $route = new self($pdo, $row['name'] ?? '', $row['start_date'] ?? '', 
                         $row['end_date'] ?? '', (int)($row['user_id'] ?? 0));
        $route->id = (int)($row['id'] ?? 0) ?: null;
        $route->createdAt = $row['created_at'] ?? null;
        $route->updatedAt = $row['updated_at'] ?? null;

        return $route;
    }

    /**
     * Convert to array (for JSON responses)
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'name' => $this->name,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt
        ];
    }

    /**
     * Legacy method names for backward compatibility
     */
    public function createRoute(PDO $db)
    {
        return $this->create();
    }

    public function updateRoute(PDO $db)
    {
        return $this->update();
    }

    public static function retrieveRoute(PDO $db, $id)
    {
        return self::findById($db, (int)$id);
    }
}
