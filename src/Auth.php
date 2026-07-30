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
        if (strlen($password) < 6) {
            throw new RuntimeException('Password minimal 6 karakter.');
        }
    }
}

final class HttpException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
