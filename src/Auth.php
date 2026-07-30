<?php

declare(strict_types=1);

namespace JBackup;

use RuntimeException;

final class Auth
{
    public function __construct(private readonly Database $database)
    {
    }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('jbackup_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    public function status(): array
    {
        if ($this->check() && $this->sessionExpired()) {
            $this->clearAuthentication();
        }
        return [
            'setup_required' => $this->database->userCount() === 0,
            'authenticated' => $this->check(),
            'username' => $_SESSION['username'] ?? null,
            'csrf_token' => $this->csrfToken(),
        ];
    }

    public function setup(string $username, string $password): void
    {
        if ($this->database->userCount() > 0) {
            throw new RuntimeException('Administrator sudah dibuat.');
        }
        $this->validatePassword($password);
        $this->database->createUser(
            $username,
            password_hash($password, PASSWORD_DEFAULT)
        );
        $this->login($username, $password);
    }

    public function login(string $username, string $password): void
    {
        $user = $this->database->findUser($username);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            throw new RuntimeException('Username atau password salah.');
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
        $_SESSION['last_activity_at'] = time();
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => 'Strict',
            ]);
        }
        session_destroy();
    }

    public function check(): bool
    {
        return isset($_SESSION['user_id'], $_SESSION['username']);
    }

    public function requireUser(): void
    {
        if (!$this->check()) {
            throw new HttpException('Silakan masuk.', 401);
        }
        if ($this->sessionExpired()) {
            $this->clearAuthentication();
            throw new HttpException(
                'Sesi berakhir karena tidak ada aktivitas. Silakan masuk kembali.',
                401
            );
        }
        if (($_SERVER['HTTP_X_JBACKUP_BACKGROUND'] ?? '') !== '1') {
            $_SESSION['last_activity_at'] = time();
        }
    }

    public function updateAccount(
        string $currentPassword,
        string $username,
        string $newPassword
    ): array {
        $user = $this->database->findUserById(
            (int) ($_SESSION['user_id'] ?? 0)
        );
        if (
            !$user
            || !password_verify($currentPassword, $user['password_hash'])
        ) {
            throw new RuntimeException('Password saat ini salah.');
        }
        $passwordHash = null;
        if ($newPassword !== '') {
            $this->validatePassword($newPassword);
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        }
        $updated = $this->database->updateUser(
            (int) $user['id'],
            $username,
            $passwordHash
        );
        $_SESSION['username'] = $updated['username'];
        $_SESSION['last_activity_at'] = time();
        return $updated;
    }

    public function csrfToken(): string
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
        }
        return $_SESSION['csrf_token'];
    }

    public function verifyCsrf(?string $token): void
    {
        if (!$token || !hash_equals($this->csrfToken(), $token)) {
            throw new HttpException('Token keamanan tidak valid.', 419);
        }
    }

    public static function verifyOrigin(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin === '') {
            return;
        }
        $originHost = parse_url($origin, PHP_URL_HOST);
        $host = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0];
        if (!$originHost || !hash_equals(strtolower($host), strtolower((string) $originHost))) {
            throw new HttpException('Origin permintaan tidak diizinkan.', 403);
        }
    }

    private function validatePassword(string $password): void
    {
        if (strlen($password) < 1) {
            throw new RuntimeException('Password minimal 1 karakter.');
        }
    }

    private function sessionExpired(): bool
    {
        $timeout = (int) (
            $this->database->settings()['session_timeout_minutes'] ?? 30
        );
        if ($timeout <= 0) {
            return false;
        }
        $lastActivity = (int) ($_SESSION['last_activity_at'] ?? time());
        return time() - $lastActivity >= $timeout * 60;
    }

    private function clearAuthentication(): void
    {
        unset(
            $_SESSION['user_id'],
            $_SESSION['username'],
            $_SESSION['last_activity_at']
        );
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
}

final class HttpException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
