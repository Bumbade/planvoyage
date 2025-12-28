<?php

// UserController: handles registration, login, logoff and profile updates
require_once __DIR__ . '/../config/mysql.php';
require_once __DIR__ . '/../helpers/session.php';

class UserController
{
    private $pdo;

    public function __construct()
    {
        $this->pdo = get_db();
        start_secure_session();
    }

    public function register(array $data): array
    {
        // Expected fields: name, email, password
        $name = trim($data['name'] ?? '');
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';

        if ($name === '' || $email === '' || $password === '') {
            return ['success' => false, 'message' => 'Missing required fields'];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid email'];
        }

        // Check existing user (case-insensitive)
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1');
        $stmt->execute([':email' => $email]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'Email already registered'];
        }

        // Enforce stronger password policy: minimum 8 chars, at least one uppercase, one lowercase, one digit and one special character
        $pwLen = mb_strlen($password);
        if ($pwLen < 8) {
            return ['success' => false, 'message' => 'Password must be at least 8 characters long'];
        }
        if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
            return ['success' => false, 'message' => 'Password must contain at least one uppercase letter, one lowercase letter, one number and one special character'];
        }

        // Hash password
        $hash = password_hash($password, PASSWORD_DEFAULT);

        // Insert using actual table columns and ensure new users are not admins and cannot access cannabis by default
        $insert = $this->pdo->prepare('INSERT INTO users (username, email, password, created_at, is_admin, can_access_cannabis) VALUES (:name, :email, :hash, NOW(), 0, 0)');
        $params = [':name' => $name, ':email' => $email, ':hash' => $hash];

        // Temporary debug logging for user registration (avoid logging raw passwords)
        $logParams = ['name' => $name, 'email' => $email, 'password_hash' => substr($hash, 0, 12) . '...'];
        // (debug logging removed)

        try {
            $ok = $insert->execute($params);
        } catch (PDOException $e) {
            error_log('User insert exception: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Database schema error: users.id must be AUTO_INCREMENT or have a default value. Run scripts/ensure_users_autoinc.sql or adjust the users table schema.'
            ];
        }
        if ($ok) {
            $uid = $this->pdo->lastInsertId();
            error_log('User created id: ' . $uid);
            // created
            return ['success' => true, 'message' => 'User registered', 'user_id' => $uid];
        }

        $err = $insert->errorInfo();
        error_log('User insert failed: ' . json_encode($err));
        // (debug file write removed)

        return ['success' => false, 'message' => 'Registration failed'];
    }

    public function login(string $email, string $password): array
    {
        // Rate limiting: allow max 5 attempts per 15 minutes per email (session-based)
        $key = 'login_attempts_' . md5(strtolower($email));
        $attempts = $_SESSION[$key] ?? ['count' => 0, 'first' => time()];
        $maxAttempts = 5;
        $lockoutSec = 15 * 60; // 15 minutes

        // Reset attempts if window passed
        if (time() - ($attempts['first'] ?? 0) > $lockoutSec) {
            $attempts = ['count' => 0, 'first' => time()];
        }

        if (($attempts['count'] ?? 0) >= $maxAttempts) {
            $remaining = $lockoutSec - (time() - $attempts['first']);
            return ['success' => false, 'message' => "Too many login attempts. Try again in {$remaining} seconds."];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // increment attempts on invalid email too
            $attempts['count'] = ($attempts['count'] ?? 0) + 1;
            $_SESSION[$key] = $attempts;
            return ['success' => false, 'message' => 'Invalid email'];
        }

        $stmt = $this->pdo->prepare('SELECT id, password, username, email FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $attempts['count'] = ($attempts['count'] ?? 0) + 1;
            $_SESSION[$key] = $attempts;
            return ['success' => false, 'message' => 'Invalid credentials'];
        }

        if (!password_verify($password, $user['password'])) {
            $attempts['count'] = ($attempts['count'] ?? 0) + 1;
            $_SESSION[$key] = $attempts;
            return ['success' => false, 'message' => 'Invalid credentials'];
        }

        // Successful login: regenerate session id and store user id and username in session
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        if (!empty($user['username'])) {
            $_SESSION['username'] = $user['username'];
        }
        if (!empty($user['email'])) {
            $_SESSION['email'] = $user['email'];
        }

        // reset attempts on success
        unset($_SESSION[$key]);

        return ['success' => true, 'message' => 'Logged in', 'user_id' => (int)$user['id']];
    }

    public function logoff(): void
    {
        // Clears session and cookies
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
    }

    public function updateProfile(int $userId, array $newData): array
    {
        // Allowed fields: name, email, password
        $fields = [];
        $params = [':id' => $userId];

        if (!empty($newData['name'])) {
            // table column is 'username'
            $fields[] = 'username = :name';
            $params[':name'] = trim($newData['name']);
        }
        if (!empty($newData['email']) && filter_var($newData['email'], FILTER_VALIDATE_EMAIL)) {
            $fields[] = 'email = :email';
            $params[':email'] = trim($newData['email']);
        }
        if (!empty($newData['password'])) {
            // require current_password to change password
            $current = $newData['current_password'] ?? '';
            if ($current === '') {
                return ['success' => false, 'message' => 'Current password required to change password'];
            }
            // fetch existing password hash
            $stmt = $this->pdo->prepare('SELECT password FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || !password_verify($current, $row['password'])) {
                return ['success' => false, 'message' => 'Current password is incorrect'];
            }
            // Validate new password against the same policy as registration
            $newPw = $newData['password'];
            $pwLenNew = mb_strlen($newPw);
            if ($pwLenNew < 8) {
                return ['success' => false, 'message' => 'Password must be at least 8 characters long'];
            }
            if (!preg_match('/[A-Z]/', $newPw) || !preg_match('/[a-z]/', $newPw) || !preg_match('/[0-9]/', $newPw) || !preg_match('/[^A-Za-z0-9]/', $newPw)) {
                return ['success' => false, 'message' => 'Password must contain at least one uppercase letter, one lowercase letter, one number and one special character'];
            }
            // table column is 'password'
            $fields[] = 'password = :hash';
            $params[':hash'] = password_hash($newData['password'], PASSWORD_DEFAULT);
        }

        if (empty($fields)) {
            return ['success' => false, 'message' => 'No valid fields to update'];
        }

        $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        // If the username was updated and the current session belongs to this user, refresh cached username
        if (!empty($params[':name']) && !empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $userId) {
            $_SESSION['username'] = $params[':name'];
        }

        return ['success' => true, 'message' => 'Profile updated'];
    }

    public function getById(int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, username, email, created_at FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
