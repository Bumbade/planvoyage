<?php

/**
 * User Model - Data access layer for users
 * 
 * Represents a single user in the system.
 * Handles authentication, profile management, and database operations.
 */

class User
{
    private $pdo;
    
    // Entity properties
    private $id;
    private $username;
    private $email;
    private $passwordHash;
    private $fullName;
    private $createdAt;
    private $updatedAt;

    /**
     * Constructor
     * 
     * @param PDO $pdo Database connection
     * @param string $username Username
     * @param string $email Email address
     */
    public function __construct(PDO $pdo, string $username = '', string $email = '')
    {
        $this->pdo = $pdo;
        $this->username = $username;
        $this->email = $email;
    }

    // ============================================
    // Getters
    // ============================================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getFullName(): string
    {
        return $this->fullName ?? '';
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

    public function setUsername(string $username): self
    {
        $this->username = $username;
        return $this;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function setPassword(string $password): self
    {
        $this->passwordHash = password_hash($password, PASSWORD_BCRYPT);
        return $this;
    }

    public function setFullName(string $fullName): self
    {
        $this->fullName = $fullName;
        return $this;
    }

    // ============================================
    // Authentication
    // ============================================

    /**
     * Verify password against stored hash
     */
    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->passwordHash ?? '');
    }

    /**
     * Check if password needs rehashing
     */
    public function needsPasswordRehash(): bool
    {
        return password_needs_rehash($this->passwordHash ?? '', PASSWORD_BCRYPT);
    }

    // ============================================
    // Database Operations
    // ============================================

    /**
     * Save user to database (create or update)
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
            error_log('User::save - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Create new user in database
     */
    public function create(): bool
    {
        try {
            // Check if username or email already exists
            if ($this->userExists()) {
                error_log('User::create - Username or email already exists');
                return false;
            }

            $sql = 'INSERT INTO users (username, email, password_hash, full_name, created_at) 
                   VALUES (:username, :email, :password_hash, :full_name, NOW())';

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':username', $this->username, PDO::PARAM_STR);
            $stmt->bindValue(':email', $this->email, PDO::PARAM_STR);
            $stmt->bindValue(':password_hash', $this->passwordHash ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':full_name', $this->fullName ?? '', PDO::PARAM_STR);
            $stmt->execute();

            $this->id = (int)$this->pdo->lastInsertId();
            return true;
        } catch (Throwable $e) {
            error_log('User::create - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update existing user in database
     */
    public function update(): bool
    {
        try {
            if (!$this->id) {
                throw new Exception('Cannot update user without ID');
            }

            $sql = 'UPDATE users 
                   SET username = :username, email = :email, full_name = :full_name, updated_at = NOW()';

            // Only update password if it's been set
            if ($this->passwordHash !== null) {
                $sql .= ', password_hash = :password_hash';
            }

            $sql .= ' WHERE id = :id';

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
            $stmt->bindValue(':username', $this->username, PDO::PARAM_STR);
            $stmt->bindValue(':email', $this->email, PDO::PARAM_STR);
            $stmt->bindValue(':full_name', $this->fullName ?? '', PDO::PARAM_STR);

            if ($this->passwordHash !== null) {
                $stmt->bindValue(':password_hash', $this->passwordHash, PDO::PARAM_STR);
            }

            $stmt->execute();

            return true;
        } catch (Throwable $e) {
            error_log('User::update - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete user from database
     */
    public function delete(): bool
    {
        try {
            if (!$this->id) {
                throw new Exception('Cannot delete user without ID');
            }

            $sql = 'DELETE FROM users WHERE id = :id';
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':id', $this->id, PDO::PARAM_INT);
            $stmt->execute();

            return true;
        } catch (Throwable $e) {
            error_log('User::delete - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Find user by ID
     * 
     * @return User|null The loaded user or null if not found
     */
    public static function findById(PDO $pdo, int $id): ?self
    {
        try {
            $sql = 'SELECT * FROM users WHERE id = :id LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }

            return self::fromArray($pdo, $row);
        } catch (Throwable $e) {
            error_log('User::findById - ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Find user by email
     * 
     * @return User|null The loaded user or null if not found
     */
    public static function findByEmail(PDO $pdo, string $email): ?self
    {
        try {
            $sql = 'SELECT * FROM users WHERE email = :email LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':email', $email, PDO::PARAM_STR);
            $stmt->execute();

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }

            return self::fromArray($pdo, $row);
        } catch (Throwable $e) {
            error_log('User::findByEmail - ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Find user by username
     * 
     * @return User|null The loaded user or null if not found
     */
    public static function findByUsername(PDO $pdo, string $username): ?self
    {
        try {
            $sql = 'SELECT * FROM users WHERE username = :username LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':username', $username, PDO::PARAM_STR);
            $stmt->execute();

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }

            return self::fromArray($pdo, $row);
        } catch (Throwable $e) {
            error_log('User::findByUsername - ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if username or email already exists
     */
    private function userExists(): bool
    {
        try {
            $sql = 'SELECT COUNT(*) as cnt FROM users 
                   WHERE (username = :username OR email = :email) AND id != :id';

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':username', $this->username, PDO::PARAM_STR);
            $stmt->bindValue(':email', $this->email, PDO::PARAM_STR);
            $stmt->bindValue(':id', $this->id ?? 0, PDO::PARAM_INT);
            $stmt->execute();

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($row['cnt'] ?? 0) > 0;
        } catch (Throwable $e) {
            error_log('User::userExists - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Create User instance from database row
     */
    private static function fromArray(PDO $pdo, array $row): self
    {
        $user = new self($pdo, $row['username'] ?? '', $row['email'] ?? '');
        $user->id = (int)($row['id'] ?? 0) ?: null;
        $user->passwordHash = $row['password_hash'] ?? null;
        $user->fullName = $row['full_name'] ?? null;
        $user->createdAt = $row['created_at'] ?? null;
        $user->updatedAt = $row['updated_at'] ?? null;

        return $user;
    }

    /**
     * Convert to array (for JSON responses, excludes password hash)
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'full_name' => $this->fullName ?? '',
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt
        ];
    }
}
