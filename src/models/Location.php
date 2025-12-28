<?php

/**
 * Location Model - Data access layer for locations/POIs
 * 
 * Represents a single location/POI in the system.
 * Handles all database operations for locations.
 */

class Location
{
    private $pdo;
    
    // Entity properties
    private $id;
    private $name;
    private $type;
    private $latitude;
    private $longitude;
    private $description;
    private $city;
    private $country;
    private $createdAt;
    private $updatedAt;

    /**
     * Constructor
     * 
     * @param PDO $pdo Database connection
     * @param string $name Location name
     * @param string $type Location type (poi, landmark, etc.)
     * @param float $latitude Latitude coordinate
     * @param float $longitude Longitude coordinate
     */
    public function __construct(PDO $pdo, string $name = '', string $type = '', 
                               float $latitude = 0.0, float $longitude = 0.0)
    {
        $this->pdo = $pdo;
        $this->name = $name;
        $this->type = $type;
        $this->latitude = $latitude;
        $this->longitude = $longitude;
    }

    // ============================================
    // Getters
    // ============================================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getLatitude(): float
    {
        return $this->latitude;
    }

    public function getLongitude(): float
    {
        return $this->longitude;
    }

    public function getDescription(): string
    {
        return $this->description ?? '';
    }

    public function getCity(): string
    {
        return $this->city ?? '';
    }

    public function getCountry(): string
    {
        return $this->country ?? '';
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

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function setLatitude(float $latitude): self
    {
        $this->latitude = $latitude;
        return $this;
    }

    public function setLongitude(float $longitude): self
    {
        $this->longitude = $longitude;
        return $this;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function setCity(string $city): self
    {
        $this->city = $city;
        return $this;
    }

    public function setCountry(string $country): self
    {
        $this->country = $country;
        return $this;
    }

    // ============================================
    // Database Operations
    // ============================================

    /**
     * Save location to database (create or update)
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
            error_log('Location::save - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Create new location in database
     */
    public function create(): bool
    {
        try {
            $sql = 'INSERT INTO locations (name, type, latitude, longitude, description, city, country, created_at) 
                   VALUES (:name, :type, :latitude, :longitude, :description, :city, :country, NOW())';

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':name', $this->name, PDO::PARAM_STR);
            $stmt->bindValue(':type', $this->type, PDO::PARAM_STR);
            $stmt->bindValue(':latitude', $this->latitude);
            $stmt->bindValue(':longitude', $this->longitude);
            $stmt->bindValue(':description', $this->description ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':city', $this->city ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':country', $this->country ?? '', PDO::PARAM_STR);
            $stmt->execute();

            $this->id = (int)$this->pdo->lastInsertId();
            return true;
        } catch (Throwable $e) {
            error_log('Location::create - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update existing location in database
     */
    public function update(): bool
    {
        try {
            if (!$this->id) {
                throw new Exception('Cannot update location without ID');
            }

            $sql = 'UPDATE locations 
                   SET name = :name, type = :type, latitude = :latitude, longitude = :longitude,
                       description = :description, city = :city, country = :country, updated_at = NOW()
                   WHERE id = :id';

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
            $stmt->bindValue(':name', $this->name, PDO::PARAM_STR);
            $stmt->bindValue(':type', $this->type, PDO::PARAM_STR);
            $stmt->bindValue(':latitude', $this->latitude);
            $stmt->bindValue(':longitude', $this->longitude);
            $stmt->bindValue(':description', $this->description ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':city', $this->city ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':country', $this->country ?? '', PDO::PARAM_STR);
            $stmt->execute();

            return true;
        } catch (Throwable $e) {
            error_log('Location::update - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete location from database
     */
    public function delete(): bool
    {
        try {
            if (!$this->id) {
                throw new Exception('Cannot delete location without ID');
            }

            $sql = 'DELETE FROM locations WHERE id = :id';
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
            $stmt->execute();

            return true;
        } catch (Throwable $e) {
            error_log('Location::delete - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Load location from database by ID
     * 
     * @return Location|null The loaded location or null if not found
     */
    public static function findById(PDO $pdo, int $id): ?self
    {
        try {
            $sql = 'SELECT * FROM locations WHERE id = :id LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }

            $location = new self($pdo, $row['name'], $row['type'], 
                               (float)$row['latitude'], (float)$row['longitude']);
            $location->id = (int)$row['id'];
            $location->description = $row['description'] ?? null;
            $location->city = $row['city'] ?? null;
            $location->country = $row['country'] ?? null;
            $location->createdAt = $row['created_at'] ?? null;
            $location->updatedAt = $row['updated_at'] ?? null;

            return $location;
        } catch (Throwable $e) {
            error_log('Location::findById - ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Find all locations (with optional filtering)
     * 
     * @return array Array of Location objects
     */
    public static function findAll(PDO $pdo, int $limit = 100, int $offset = 0): array
    {
        try {
            $sql = 'SELECT * FROM locations 
                   ORDER BY created_at DESC 
                   LIMIT :limit OFFSET :offset';

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $locations = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $location = new self($pdo, $row['name'], $row['type'], 
                                   (float)$row['latitude'], (float)$row['longitude']);
                $location->id = (int)$row['id'];
                $location->description = $row['description'] ?? null;
                $location->city = $row['city'] ?? null;
                $location->country = $row['country'] ?? null;
                $location->createdAt = $row['created_at'] ?? null;
                $location->updatedAt = $row['updated_at'] ?? null;

                $locations[] = $location;
            }

            return $locations;
        } catch (Throwable $e) {
            error_log('Location::findAll - ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Search locations by name
     * 
     * @return array Array of Location objects
     */
    public static function search(PDO $pdo, string $query, int $limit = 50): array
    {
        try {
            $sql = 'SELECT * FROM locations 
                   WHERE name ILIKE :query 
                   ORDER BY created_at DESC 
                   LIMIT :limit';

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':query', "%{$query}%", PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            $locations = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $location = new self($pdo, $row['name'], $row['type'], 
                                   (float)$row['latitude'], (float)$row['longitude']);
                $location->id = (int)$row['id'];
                $location->description = $row['description'] ?? null;
                $location->city = $row['city'] ?? null;
                $location->country = $row['country'] ?? null;
                $location->createdAt = $row['created_at'] ?? null;
                $location->updatedAt = $row['updated_at'] ?? null;

                $locations[] = $location;
            }

            return $locations;
        } catch (Throwable $e) {
            error_log('Location::search - ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Count total locations
     */
    public static function count(PDO $pdo): int
    {
        try {
            $sql = 'SELECT COUNT(*) as total FROM locations';
            $stmt = $pdo->query($sql);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($row['total'] ?? 0);
        } catch (Throwable $e) {
            error_log('Location::count - ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Convert to array (for JSON responses)
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'description' => $this->description ?? '',
            'city' => $this->city ?? '',
            'country' => $this->country ?? '',
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt
        ];
    }
}
