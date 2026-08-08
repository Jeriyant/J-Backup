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
        session_start([
            'use_strict_mode' => true,
            'lazy_write' => true,
        ]);
    }

    /**
     * Release the exclusive session file lock so concurrent requests
     * (dashboard polling, update checks, etc.) are not serialized.
     */
    public static function releaseSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
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

    /**
     * Generate a one-time password reset token and write it to a server file.
     * Returns the path of the token file so the API can relay it to the user.
     */
    public function generatePasswordResetToken(string $dataDirectory): string
    {
        if ($this->database->userCount() === 0) {
            throw new RuntimeException(
                'Tidak ada akun administrator yang terdaftar. Gunakan Setup Pertama.'
            );
        }

        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $expiresAt = time() + 900; // 15 menit

        $this->database->setSchedulerState('password_reset_hash', $hash);
        $this->database->setSchedulerState('password_reset_expires', (string) $expiresAt);

        $tokenFile = rtrim($dataDirectory, '/\\') . '/reset_token.txt';
        $written = @file_put_contents(
            $tokenFile,
            "J-BACKUP PASSWORD RESET TOKEN\n"
            . "Token  : {$token}\n"
            . "Berlaku: 15 menit sejak dibuat\n"
            . "Dibuat : " . date('Y-m-d H:i:s') . "\n"
            . "\nGunakan token ini sekali saja. Setelah dipakai, token langsung tidak berlaku.\n"
        );

        if ($written === false) {
            $this->database->deleteSchedulerState('password_reset_hash');
            $this->database->deleteSchedulerState('password_reset_expires');
            throw new RuntimeException(
                'File token tidak dapat dibuat di server. '
                . 'Pastikan folder storage dapat ditulis oleh proses web.'
            );
        }

        return $tokenFile;
    }

    /**
     * Verify the reset token and update the password for the (single) admin user.
     */
    public function resetPassword(string $token, string $newPassword): void
    {
        $storedHash = $this->database->schedulerState('password_reset_hash');
        $expiresAt = (int) ($this->database->schedulerState('password_reset_expires') ?? 0);

        // Clean up token regardless of outcome (prevent brute-force)
        $this->database->deleteSchedulerState('password_reset_hash');
        $this->database->deleteSchedulerState('password_reset_expires');

        if (!$storedHash || !$expiresAt) {
            throw new RuntimeException(
                'Token reset tidak ditemukan. Generate token baru terlebih dahulu.'
            );
        }

        if (time() > $expiresAt) {
            throw new RuntimeException('Token reset sudah kedaluwarsa. Generate token baru.');
        }

        if (!hash_equals($storedHash, hash('sha256', $token))) {
            throw new RuntimeException('Token reset tidak valid.');
        }

        $this->validatePassword($newPassword);

        // Find the first (only) admin user
        $user = $this->database->findFirstUser();
        if (!$user) {
            throw new RuntimeException('Akun administrator tidak ditemukan.');
        }

        $this->database->updateUser(
            (int) $user['id'],
            $user['username'],
            password_hash($newPassword, PASSWORD_DEFAULT)
        );
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
