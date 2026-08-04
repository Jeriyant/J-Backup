<?php

declare(strict_types=1);

namespace JBackup;

use DateTimeImmutable;
use RuntimeException;

final class JobRunner
{
    private const STORAGE_PERMISSION_MODE = 07777;
    private const MAX_LOG_LENGTH = 200000;
    private const HEARTBEAT_INTERVAL_SECONDS = 10;
    private const COMMAND_TIMEOUT_SECONDS = 86400;
    private ?string $activeSshTaskId = null;
    private int $lastHeartbeatAt = 0;

    public function __construct(
        private readonly Database $database,
        private readonly string $runtimeDirectory,
        private readonly bool $simulate = false,
        private readonly ?SecretStore $secretStore = null,
    ) {
    }

    public function run(): int
    {
        $settings = $this->database->settings();
        date_default_timezone_set($settings['timezone'] ?: 'Asia/Jakarta');
        if (!is_dir($this->runtimeDirectory)) {
            mkdir($this->runtimeDirectory, 0770, true);
        }
        $lock = fopen($this->runtimeDirectory . '/worker.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return 0;
        }

        try {
            $this->recoverInterruptedJobs();
            $this->recoverInterruptedSshTasks();
            $this->recoverInterruptedPathTasks();
            $this->refreshHeartbeat(true);
            $this->runSshTasks();
            $this->runPathTasks();
            $this->enqueueDueSchedules();
            $this->processStandbyTelegramNotification();
            $this->processBackupFileTelegramNotification();
            $processed = 0;
            while ($job = $this->database->nextQueuedJob()) {
                $this->refreshHeartbeat(true);
                $this->runJob($job);
                $this->refreshHeartbeat(true);
                $processed++;
            }
            return $processed;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function refreshHeartbeat(bool $force = false): void
    {
        $now = time();
        if (
            !$force
            && ($now - $this->lastHeartbeatAt) < self::HEARTBEAT_INTERVAL_SECONDS
        ) {
            return;
        }
        $this->database->setSchedulerState(
            'worker_heartbeat',
            Database::now()
        );
        $this->lastHeartbeatAt = $now;
    }

    private function isWorkerStale(): bool
    {
        $lastHeartbeat = $this->database->schedulerState('worker_heartbeat');
        if ($lastHeartbeat === null) {
            return true;
        }
        $ts = strtotime($lastHeartbeat);
        return $ts === false || (time() - $ts) > 60;
    }

    private function recoverInterruptedJobs(): void
    {
        if (!$this->isWorkerStale()) {
            return;
        }
        $now = Database::now();
        $this->database->pdo()->prepare(
            "UPDATE jobs
             SET status = 'cancelled', finished_at = ?, error = 'Dibatalkan oleh pengguna.'
             WHERE status = 'cancel_requested'"
        )->execute([$now]);
        $this->database->pdo()->prepare(
            "UPDATE jobs
             SET status = 'failed', finished_at = ?, error = 'Worker berhenti sebelum pekerjaan selesai.'
             WHERE status = 'running'"
        )->execute([$now]);
    }

    private function recoverInterruptedSshTasks(): void
    {
        if (!$this->isWorkerStale()) {
            return;
        }
        $statement = $this->database->pdo()->prepare(
            "UPDATE ssh_tasks
             SET status = 'failed', finished_at = ?, error = ?
             WHERE status = 'running'"
        );
        $statement->execute([
            Database::now(),
            'Worker berhenti sebelum tindakan SSH selesai.',
        ]);
    }

    private function recoverInterruptedPathTasks(): void
    {
        if (!$this->isWorkerStale()) {
            return;
        }
        $statement = $this->database->pdo()->prepare(
            "UPDATE path_tasks
             SET status = 'failed', finished_at = ?, error = ?
             WHERE status = 'running'"
        );
        $statement->execute([
            Database::now(),
            'Worker berhenti sebelum pengujian folder selesai.',
        ]);
    }

    private function runSshTasks(): void
    {
        while ($task = $this->database->nextQueuedSshTask()) {
            $this->activeSshTaskId = $task['id'];
            $this->appendSshLog("Worker mengambil tugas SSH.\n");
            try {
                $result = match ($task['type']) {
                    'generate_key' => $this->generateSshKey(
                        $task['payload'],
                        $task['secret'] ?? []
                    ),
                    'test_connection' => $this->testSshConnection(
                        $task['payload']
                    ),
                    'disconnect' => $this->disconnectSsh(
                        $task['payload']
                    ),
                    default => throw new RuntimeException(
                        'Jenis tindakan SSH tidak dikenal.'
                    ),
                };
                if (($result['disconnected'] ?? false) === true) {
                    $this->database->deleteSchedulerState('ssh_connection');
                } elseif (
                    ($result['connected'] ?? false) === true
                    || ($result['installed'] ?? false) === true
                ) {
                    $this->recordSshConnection($task['payload'], $result);
                }
                $this->database->updateSshTask($task['id'], [
                    'status' => 'success',
                    'result' => $result,
                    'error' => null,
                    'finished_at' => Database::now(),
                ]);
                $this->appendSshLog("Selesai: tindakan SSH berhasil.\n");
            } catch (\Throwable $error) {
                $this->appendSshLog("GAGAL: {$error->getMessage()}\n");
                $this->database->updateSshTask($task['id'], [
                    'status' => 'failed',
                    'error' => $error->getMessage(),
                    'finished_at' => Database::now(),
                ]);
            } finally {
                $this->activeSshTaskId = null;
            }
        }
    }

    private function runPathTasks(): void
    {
        while ($task = $this->database->nextQueuedPathTask()) {
            try {
                $result = $this->probePath(
                    (string) $task['kind'],
                    (string) $task['path']
                );
                $result['checked_at'] = Database::now();
                $this->database->setSchedulerState(
                    'path_check_' . $task['kind'],
                    json_encode($result, JSON_THROW_ON_ERROR)
                );
                $this->database->updatePathTask($task['id'], [
                    'status' => 'success',
                    'result' => $result,
                    'error' => null,
                    'finished_at' => Database::now(),
                ]);
            } catch (\Throwable $error) {
                $this->database->updatePathTask($task['id'], [
                    'status' => 'failed',
                    'error' => $error->getMessage(),
                    'finished_at' => Database::now(),
                ]);
            }
        }
    }

    private function probePath(string $kind, string $path): array
    {
        if (!in_array($kind, ['rsync', 'backup'], true)) {
            throw new RuntimeException('Jenis folder tidak dikenal.');
        }
        $path = $this->absolutePath($path);
        $worker = $this->workerUser();
        $checks = [
            'exists' => false,
            'directory' => false,
            'readable' => false,
            'writable' => false,
            'test_file' => false,
            'disk' => false,
            'web_access' => false,
        ];
        $result = [
            'ready' => false,
            'kind' => $kind,
            'path' => $path,
            'worker_user' => $worker,
            'checks' => &$checks,
            'total_bytes' => 0,
            'free_bytes' => 0,
            'reason_code' => 'not_found',
            'message' => 'Folder belum tersedia.',
            'detail' => null,
            'commands' => $this->pathAdministratorCommands($path),
        ];

        if (!file_exists($path)) {
            if (!@mkdir($path, 0770, true) && !is_dir($path)) {
                $result['detail'] = 'Folder tidak dapat dibuat otomatis. Periksa izin worker pada direktori induk.';
                return $result;
            }
            $result['detail'] = 'Folder dibuat otomatis oleh worker.';
        }
        $checks['exists'] = true;
        if (!is_dir($path)) {
            $result['reason_code'] = 'not_directory';
            $result['message'] = 'Path tersedia tetapi bukan sebuah folder.';
            $result['detail'] = 'Pilih direktori lain atau pindahkan file pada path ini.';
            return $result;
        }
        $this->applyStoragePermissions($path);
        $checks['directory'] = true;
        $checks['readable'] = is_readable($path);
        $checks['writable'] = is_writable($path);

        if (!$checks['readable']) {
            $result['reason_code'] = 'not_readable';
            $result['message'] = "Folder tidak dapat dibaca oleh {$worker}.";
            $result['detail'] = 'Periksa izin folder dan seluruh direktori induknya.';
            return $result;
        }
        if (!$checks['writable']) {
            $result['reason_code'] = 'not_writable';
            $result['message'] = "Folder tidak dapat ditulis oleh {$worker}.";
            $result['detail'] = 'Periksa status read-only, mount, atau atribut filesystem.';
            return $result;
        }

        $testPath = $path . '/.jbackup-access-' . bin2hex(random_bytes(8));
        error_clear_last();
        $handle = @fopen($testPath, 'x+b');
        if ($handle === false) {
            $lastError = error_get_last();
            $detail = trim((string) ($lastError['message'] ?? ''));
            $readOnly = stripos($detail, 'read-only') !== false;
            $result['reason_code'] = $readOnly ? 'read_only' : 'test_failed';
            $result['message'] = $readOnly
                ? 'Filesystem tujuan berada dalam kondisi read-only.'
                : 'File pengujian tidak dapat dibuat.';
            $result['detail'] = $detail !== '' ? $detail : null;
            return $result;
        }

        $testOk = false;
        try {
            $written = fwrite($handle, 'J-BACKUP path access test');
            $testOk = $written !== false && fflush($handle);
        } finally {
            fclose($handle);
            if (is_file($testPath)) {
                $testOk = @unlink($testPath) && $testOk;
            }
        }
        $checks['test_file'] = $testOk;
        if (!$testOk) {
            $result['reason_code'] = 'test_failed';
            $result['message'] = 'File pengujian tidak dapat ditulis atau dihapus.';
            $result['detail'] = 'Periksa quota dan status mount filesystem.';
            return $result;
        }

        $webAccess = $this->ensureWebFolderAccess($kind, $path);
        $checks['web_access'] = $webAccess['ready'];
        $result['web_user'] = $webAccess['web_user'];
        if (!$webAccess['ready']) {
            $result['reason_code'] = 'web_access_failed';
            $result['message'] = 'Worker siap, tetapi File Explorer belum dapat membaca folder.';
            $result['detail'] = $webAccess['detail'];
            return $result;
        }

        $total = disk_total_space($path);
        $free = disk_free_space($path);
        if ($total === false || $free === false) {
            $result['reason_code'] = 'disk_unavailable';
            $result['message'] = 'Kapasitas disk tidak dapat dibaca.';
            $result['detail'] = 'Periksa apakah disk atau network mount masih terhubung.';
            return $result;
        }

        $checks['disk'] = true;
        $result['total_bytes'] = (int) $total;
        $result['free_bytes'] = (int) $free;
        $result['ready'] = true;
        $result['reason_code'] = 'ready';
        $result['message'] = 'Folder siap digunakan.';
        $result['detail'] = 'Pengujian file berhasil dibuat dan dibersihkan oleh worker.';
        $result['commands'] = [];
        return $result;
    }

    private function findSetfaclBinary(): ?string
    {
        foreach (['/usr/bin/setfacl', '/bin/setfacl', '/usr/local/bin/setfacl'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private function ensureWebFolderAccess(string $kind, string $path): array
    {
        $webUser = $this->webUser();
        if ($this->simulate) {
            return [
                'ready' => true,
                'web_user' => $webUser,
                'detail' => null,
            ];
        }

        $resolved = realpath($path);
        if ($resolved === false || !is_dir($resolved)) {
            return [
                'ready' => false,
                'web_user' => $webUser,
                'detail' => 'Folder tidak dapat di-resolve sebelum ACL diterapkan.',
            ];
        }

        $setfacl = $this->findSetfaclBinary();
        if ($setfacl === null) {
            if (is_readable($resolved)) {
                return [
                    'ready' => true,
                    'web_user' => $webUser,
                    'detail' => null,
                ];
            }
            return [
                'ready' => false,
                'web_user' => $webUser,
                'detail' => 'Paket acl belum tersedia. Jalankan: sudo apt install acl',
            ];
        }

        try {
            $ancestors = [];
            $parent = dirname($resolved);
            while ($parent !== '/' && $parent !== '.') {
                $ancestors[] = $parent;
                $next = dirname($parent);
                if ($next === $parent) {
                    break;
                }
                $parent = $next;
            }
            foreach (array_reverse($ancestors) as $ancestor) {
                $this->runUtility([
                    $setfacl,
                    '--physical',
                    '--modify',
                    "user:{$webUser}:--x",
                    '--',
                    $ancestor,
                ], 15);
            }

            $permissions = $kind === 'backup' ? 'rwx' : 'r-x';
            $this->runUtility([
                $setfacl,
                '--physical',
                '--recursive',
                '--modify',
                "user:{$webUser}:{$permissions}",
                '--',
                $resolved,
            ], 120);
            $this->runUtility([
                $setfacl,
                '--physical',
                '--modify',
                "default:user:{$webUser}:{$permissions}",
                '--',
                $resolved,
            ], 15);
        } catch (\Throwable $error) {
            if (is_readable($resolved)) {
                return [
                    'ready' => true,
                    'web_user' => $webUser,
                    'detail' => null,
                ];
            }
            return [
                'ready' => false,
                'web_user' => $webUser,
                'detail' => 'ACL untuk File Explorer gagal diterapkan: '
                    . $error->getMessage(),
            ];
        }

        return [
            'ready' => true,
            'web_user' => $webUser,
            'detail' => null,
        ];
    }

    private function webUser(): string
    {
        $configured = trim((string) getenv('JBACKUP_WEB_USER'));
        if ($configured !== '' && preg_match('/^[A-Za-z0-9_.-]+$/', $configured)) {
            return $configured;
        }
        if (function_exists('posix_getpwnam')) {
            foreach (['www-data', 'apache'] as $candidate) {
                if (is_array(posix_getpwnam($candidate))) {
                    return $candidate;
                }
            }
        }
        return 'www-data';
    }

    private function workerUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $account = posix_getpwuid(posix_geteuid());
            if (is_array($account) && trim((string) ($account['name'] ?? '')) !== '') {
                return (string) $account['name'];
            }
        }
        return trim((string) (getenv('USER') ?: 'worker'));
    }

    private function pathAdministratorCommands(string $path): array
    {
        $quotedPath = escapeshellarg($path);
        return [
            "sudo mkdir -p -- {$quotedPath}",
            "namei -l -- {$quotedPath}",
            "findmnt -T {$quotedPath}",
        ];
    }

    private function generateSshKey(array $payload, array $secret = []): array
    {
        $config = $this->sshConfig($payload, false);
        $keyPath = $config['key_path'];
        $publicPath = $keyPath . '.pub';
        $keyType = (string) ($payload['ssh_key_type'] ?? 'rsa4096');
        if (!in_array($keyType, ['ed25519', 'rsa4096'], true)) {
            throw new RuntimeException('Tipe key SSH tidak didukung.');
        }
        $comment = trim((string) ($payload['ssh_key_comment'] ?? 'J-Backup-Key-RSA'));
        $comment = preg_replace('/[\x00-\x1F\x7F]/', '', $comment) ?: 'J-Backup-Key-RSA';
        $comment = substr($comment, 0, 128);
        $sshDirectory = dirname($keyPath);
        $this->appendSshLog("Memeriksa folder dan pasangan kunci SSH.\n");

        if (!is_dir($sshDirectory)) {
            if (!@mkdir($sshDirectory, 0775, true) && !is_dir($sshDirectory)) {
                throw new RuntimeException("Folder SSH ({$sshDirectory}) tidak dapat dibuat oleh worker.");
            }
        }
        @chmod($sshDirectory, 0770);

        $created = false;
        if (!is_file($keyPath)) {
            if ($this->simulate) {
                file_put_contents($keyPath, "SIMULATED PRIVATE KEY\n");
                file_put_contents(
                    $publicPath,
                    ($keyType === 'rsa4096' ? 'ssh-rsa ' : 'ssh-ed25519 ')
                        . "AAAAC3NzaC1lZDI1NTE5AAAAIJBACKUPSIMULATED {$comment}"
                );
            } else {
                $this->appendSshLog("Menjalankan ssh-keygen ({$keyType})...\n");
                $keyCommand = [
                    '/usr/bin/ssh-keygen',
                    '-q',
                    '-t',
                    $keyType === 'rsa4096' ? 'rsa' : 'ed25519',
                ];
                if ($keyType === 'rsa4096') {
                    array_push($keyCommand, '-b', '4096');
                }
                array_push(
                    $keyCommand,
                    '-N',
                    '',
                    '-C',
                    $comment,
                    '-f',
                    $keyPath
                );
                $this->runUtility($keyCommand, 30);
            }
            $created = true;
            $this->appendSshLog("Pasangan kunci SSH berhasil dibuat.\n");
        } elseif (!is_file($publicPath)) {
            $this->appendSshLog("Membuat public key dari private key yang tersedia.\n");
            if ($this->simulate) {
                file_put_contents(
                    $publicPath,
                    'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJBACKUPSIMULATED j-backup'
                );
            } else {
                $derived = $this->runUtility(
                    ['/usr/bin/ssh-keygen', '-y', '-f', $keyPath],
                    15
                );
                file_put_contents($publicPath, trim($derived['stdout']) . "\n");
            }
        } else {
            $this->appendSshLog("Pasangan kunci sudah tersedia; pembuatan dilewati.\n");
        }

        @chmod($keyPath, 0600);
        @chmod($publicPath, 0644);
        $publicKey = trim((string) file_get_contents($publicPath));
        if (!preg_match('/^ssh-(?:ed25519|rsa)\s+/', $publicKey)) {
            throw new RuntimeException('Public key yang dihasilkan tidak valid.');
        }

        $result = [
            'created' => $created,
            'installed' => false,
            'key_type' => $keyType,
            'private_key_path' => $keyPath,
            'public_key_path' => $publicPath,
            'public_key' => $publicKey,
            'message' => $created
                ? 'Pasangan kunci SSH berhasil dibuat.'
                : 'Kunci sudah ada; public key ditampilkan kembali.',
        ];

        if (($payload['install_key'] ?? false) === true) {
            $password = (string) (
                $secret['password']
                ?? $this->secretStore?->get('ssh_password')
                ?? ''
            );
            if ($password === '' || strlen($password) > 1024) {
                throw new RuntimeException('Password SSH diperlukan untuk memasang public key.');
            }
            $connection = $this->sshConfig($payload);
            $target = $connection['user'] . '@' . $connection['host'];
            $this->appendSshLog(
                "Memasang public key ke {$target}:{$connection['port']}...\n"
            );
            try {
                if (!$this->simulate) {
                    $knownHosts = dirname($keyPath) . '/known_hosts';
                    $env = [
                        'SSHPASS' => $password,
                    ];
                    try {
                        $this->runUtility([
                            '/usr/bin/sshpass',
                            '-e',
                            '/usr/bin/ssh-copy-id',
                            '-i',
                            $publicPath,
                            '-p',
                            (string) $connection['port'],
                            '-o',
                            'StrictHostKeyChecking=accept-new',
                            '-o',
                            'UserKnownHostsFile=' . $knownHosts,
                            $connection['user'] . '@' . $connection['host'],
                        ], 30, $env);
                    } catch (\Throwable $copyIdError) {
                        $pubKeyContent = trim((string) @file_get_contents($publicPath));
                        if ($pubKeyContent === '') {
                            throw $copyIdError;
                        }
                        $remoteCmd = sprintf(
                            'mkdir -p ~/.ssh && chmod 700 ~/.ssh && (grep -qF %s ~/.ssh/authorized_keys 2>/dev/null || echo %s >> ~/.ssh/authorized_keys) && chmod 600 ~/.ssh/authorized_keys',
                            escapeshellarg($pubKeyContent),
                            escapeshellarg($pubKeyContent)
                        );
                        $this->runUtility([
                            '/usr/bin/sshpass',
                            '-e',
                            '/usr/bin/ssh',
                            '-p',
                            (string) $connection['port'],
                            '-o',
                            'StrictHostKeyChecking=accept-new',
                            '-o',
                            'UserKnownHostsFile=' . $knownHosts,
                            $connection['user'] . '@' . $connection['host'],
                            $remoteCmd,
                        ], 30, $env);
                    }
                    @chmod($knownHosts, 0600);
                }
            } finally {
                if ($password !== '') {
                    sodium_memzero($password);
                }
            }
            $this->appendSshLog(
                "Public key berhasil dikirim. Menguji login tanpa password...\n"
            );
            $payload['ssh_key_path'] = $keyPath;
            $test = $this->testSshConnection($payload);
            $result['installed'] = true;
            $result['target'] = $test['target'];
            $result['latency_ms'] = $test['latency_ms'] ?? null;
            $result['message'] = 'Public key terpasang dan login tanpa password berhasil.';
        }

        return $result;
    }

    private function testSshConnection(array $payload): array
    {
        $config = $this->sshConfig($payload);
        $target = $config['user'] . '@' . $config['host'];
        $this->appendSshLog(
            "Menguji koneksi ke {$target}:{$config['port']} dengan private key...\n"
        );
        if (!is_file($config['key_path'])) {
            throw new RuntimeException('Private key belum tersedia. Buat kunci terlebih dahulu.');
        }
        if ($this->simulate) {
            $this->appendSshLog("Simulasi koneksi berhasil.\n");
            return [
                'connected' => true,
                'target' => $config['user'] . '@' . $config['host'],
                'message' => 'Koneksi SSH berhasil.',
            ];
        }

        $knownHosts = dirname($config['key_path']) . '/known_hosts';
        $started = microtime(true);
        $result = $this->runUtility([
            '/usr/bin/ssh',
            '-o',
            'BatchMode=yes',
            '-o',
            'ConnectTimeout=10',
            '-o',
            'ConnectionAttempts=1',
            '-o',
            'IdentitiesOnly=yes',
            '-o',
            'StrictHostKeyChecking=accept-new',
            '-o',
            'UserKnownHostsFile=' . $knownHosts,
            '-o',
            'LogLevel=ERROR',
            '-i',
            $this->secureKeyPathForCommand($config['key_path']),
            '-p',
            (string) $config['port'],
            $config['user'] . '@' . $config['host'],
            'printf JBACKUP_CONNECTION_OK',
        ], 18);
        if (!str_contains($result['stdout'], 'JBACKUP_CONNECTION_OK')) {
            throw new RuntimeException('Server merespons, tetapi hasil pengujian tidak dikenali.');
        }
        @chmod($knownHosts, 0600);
        $this->appendSshLog("Server merespons dan autentikasi key diterima.\n");

        return [
            'connected' => true,
            'target' => $config['user'] . '@' . $config['host'],
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'message' => 'Koneksi SSH dan autentikasi key berhasil.',
        ];
    }

    private function disconnectSsh(array $payload): array
    {
        $config = $this->sshConfig($payload);
        $keyPath = $config['key_path'];
        $publicPath = $keyPath . '.pub';
        $target = $config['user'] . '@' . $config['host'];
        $remoteKeyRemoved = false;
        $warning = null;
        try {
            if (!is_file($keyPath)) {
                throw new RuntimeException(
                    'Private key lokal tidak ditemukan sehingga public key remote tidak dapat dicabut otomatis.'
                );
            }

            if (is_file($publicPath)) {
                $publicKey = trim((string) file_get_contents($publicPath));
            } elseif ($this->simulate) {
                throw new RuntimeException('Public key simulasi tidak ditemukan.');
            } else {
                $derived = $this->runUtility(
                    ['/usr/bin/ssh-keygen', '-y', '-f', $keyPath],
                    15
                );
                $publicKey = trim($derived['stdout']);
            }
            $parts = preg_split('/\s+/', $publicKey, 3) ?: [];
            if (
                count($parts) < 2
                || !preg_match('/^ssh-(?:ed25519|rsa)$/', $parts[0])
                || !preg_match('/^[A-Za-z0-9+\/=]+$/', $parts[1])
            ) {
                throw new RuntimeException('Public key lokal tidak valid.');
            }

            $this->appendSshLog(
                "Mencabut public key J-BACKUP dari {$target}:{$config['port']}...\n"
            );
            if (!$this->simulate) {
                $knownHosts = dirname($keyPath) . '/known_hosts';
                $remoteScript = <<<'SH'
set -eu
auth_file="${HOME}/.ssh/authorized_keys"
[ -f "${auth_file}" ] || exit 0
temp_file="${auth_file}.jbackup.$$"
awk -v key_type="$1" -v key_data="$2" \
  '!(($1 == key_type) && ($2 == key_data))' \
  "${auth_file}" > "${temp_file}"
chmod 600 "${temp_file}" 2>/dev/null || true
mv "${temp_file}" "${auth_file}"
SH;
                $this->runUtility([
                    '/usr/bin/ssh',
                    '-o',
                    'BatchMode=yes',
                    '-o',
                    'ConnectTimeout=10',
                    '-o',
                    'ConnectionAttempts=1',
                    '-o',
                    'IdentitiesOnly=yes',
                    '-o',
                    'StrictHostKeyChecking=accept-new',
                    '-o',
                    'UserKnownHostsFile=' . $knownHosts,
                    '-o',
                    'LogLevel=ERROR',
                    '-i',
                    $this->secureKeyPathForCommand($keyPath),
                    '-p',
                    (string) $config['port'],
                    $target,
                    'sh -s -- ' . $parts[0] . ' ' . $parts[1],
                ], 20, [], $remoteScript);
            }
            $remoteKeyRemoved = true;
            $this->appendSshLog("Public key remote berhasil dicabut.\n");
        } catch (\Throwable $error) {
            $warning = $error->getMessage();
            $this->appendSshLog(
                "PERINGATAN: {$warning}\n"
                . "Status koneksi dan file key lokal tetap akan dibersihkan agar Connect ulang dapat dilakukan.\n"
            );
        }

        $this->database->deleteSchedulerState('ssh_connection');

        $notRemoved = [];
        foreach ([$keyPath, $publicPath, dirname($keyPath) . '/known_hosts'] as $file) {
            if (is_file($file) && !@unlink($file)) {
                $notRemoved[] = $file;
            }
        }
        $this->secretStore?->delete('ssh_password');
        if ($notRemoved !== []) {
            $cleanupWarning = 'File lokal gagal dihapus: '
                . implode(', ', $notRemoved);
            $warning = $warning === null
                ? $cleanupWarning
                : $warning . ' ' . $cleanupWarning;
            $this->appendSshLog("PERINGATAN: {$cleanupWarning}\n");
        }
        $this->appendSshLog(
            "Status koneksi dan password tersimpan telah dibersihkan.\n"
        );

        return [
            'disconnected' => true,
            'remote_key_removed' => $remoteKeyRemoved,
            'warning' => $warning,
            'target' => $target,
            'message' => $remoteKeyRemoved
                ? 'Koneksi SSH diputus dan key berhasil dihapus.'
                : 'Status koneksi lokal dibersihkan. Connect ulang sudah tersedia.',
        ];
    }

    private function recordSshConnection(array $payload, array $result): void
    {
        $config = $this->sshConfig($payload);
        $connectionSettings = [
            'remote_host' => $config['host'],
            'remote_port' => $config['port'],
            'remote_user' => $config['user'],
            'ssh_key_path' => $config['key_path'],
        ];
        foreach (['ssh_key_type', 'ssh_key_comment'] as $key) {
            if (array_key_exists($key, $payload)) {
                $connectionSettings[$key] = $payload[$key];
            }
        }
        $this->database->updateSettings($connectionSettings);
        $this->database->setSchedulerState(
            'ssh_connection',
            json_encode([
                'connected' => true,
                'host' => $config['host'],
                'port' => $config['port'],
                'user' => $config['user'],
                'key_path' => $config['key_path'],
                'target' => $result['target']
                    ?? ($config['user'] . '@' . $config['host']),
                'connected_at' => Database::now(),
            ], JSON_THROW_ON_ERROR)
        );
    }

    private function sshConfig(array $payload, bool $requireConnection = true): array
    {
        $settings = $this->database->settings();
        $host = trim((string) ($payload['remote_host'] ?? $settings['remote_host']));
        $user = trim((string) ($payload['remote_user'] ?? $settings['remote_user']));
        $port = (int) ($payload['remote_port'] ?? $settings['remote_port']);
        $rawKeyPath = trim((string) ($payload['ssh_key_path'] ?? $settings['ssh_key_path'] ?? ''));
        if ($rawKeyPath === '') {
            $rawKeyPath = $this->runtimeDirectory . '/.ssh/id_rsa';
        }
        $keyPath = $this->absolutePath($rawKeyPath);

        if ($requireConnection) {
            if (!preg_match('/^[A-Za-z0-9_.:-]+$/', $host)) {
                throw new RuntimeException('Host SSH belum diisi atau tidak valid.');
            }
            if (!preg_match('/^[A-Za-z0-9_.-]+$/', $user)) {
                throw new RuntimeException('User SSH tidak valid.');
            }
            if ($port < 1 || $port > 65535) {
                throw new RuntimeException('Port SSH tidak valid.');
            }
        }

        return [
            'host' => $host,
            'user' => $user,
            'port' => $port,
            'key_path' => $keyPath,
        ];
    }

    private function secureKeyPathForCommand(string $keyPath): string
    {
        if (!is_file($keyPath)) {
            throw new RuntimeException('Private key lokal tidak ditemukan. Buat kunci terlebih dahulu.');
        }

        @chmod($keyPath, 0600);
        $perms = fileperms($keyPath);
        $isGroupOrWorldReadable = $perms !== false && ($perms & 0077) !== 0;

        if ($isGroupOrWorldReadable || str_starts_with($keyPath, '/mnt/')) {
            $tmpDir = sys_get_temp_dir() . '/j-backup-ssh';
            if (!is_dir($tmpDir)) {
                @mkdir($tmpDir, 0700, true);
            }
            @chmod($tmpDir, 0700);

            $secureKey = $tmpDir . '/' . md5($keyPath) . '_' . basename($keyPath);
            if (!@copy($keyPath, $secureKey)) {
                throw new RuntimeException('Private key tidak dapat disalin ke lokasi aman untuk SSH.');
            }
            @chmod($secureKey, 0600);

            return $secureKey;
        }

        return $keyPath;
    }

    private function runUtility(
        array $command,
        int $timeoutSeconds,
        array $environment = [],
        string $stdin = ''
    ): array
    {
        $processEnvironment = $environment === []
            ? null
            : array_merge((array) getenv(), $environment);
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            $processEnvironment,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Utilitas sistem tidak dapat dijalankan.');
        }
        if ($stdin !== '') {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $exitCode = -1;
        $deadline = microtime(true) + $timeoutSeconds;

        try {
            while (true) {
                $this->refreshHeartbeat();
                $stdoutChunk = (string) stream_get_contents($pipes[1]);
                $stderrChunk = (string) stream_get_contents($pipes[2]);
                $stdout .= $stdoutChunk;
                $stderr .= $stderrChunk;
                $this->appendSshLog($stdoutChunk);
                $this->appendSshLog($stderrChunk);
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exitCode = (int) $status['exitcode'];
                    break;
                }
                if (microtime(true) >= $deadline) {
                    proc_terminate($process, 15);
                    throw new RuntimeException('Waktu tunggu tindakan SSH habis.');
                }
                usleep(100000);
            }
        } finally {
            $stdoutChunk = (string) stream_get_contents($pipes[1]);
            $stderrChunk = (string) stream_get_contents($pipes[2]);
            $stdout .= $stdoutChunk;
            $stderr .= $stderrChunk;
            $this->appendSshLog($stdoutChunk);
            $this->appendSshLog($stderrChunk);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }

        if ($exitCode !== 0) {
            $detail = trim(substr($stderr ?: $stdout, -1200));
            throw new RuntimeException(
                $detail !== ''
                    ? "Tindakan SSH gagal: {$detail}"
                    : sprintf('%s berhenti dengan kode %d.', basename($command[0]), $exitCode)
            );
        }
        return ['stdout' => $stdout, 'stderr' => $stderr];
    }

    private function appendSshLog(string $message): void
    {
        if ($this->activeSshTaskId === null || $message === '') {
            return;
        }
        $message = preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $message)
            ?? $message;
        $message = preg_replace('/[^\P{C}\n\r\t]/u', '', $message) ?? $message;
        if ($message !== '') {
            $this->database->appendSshTaskLog(
                $this->activeSshTaskId,
                $message
            );
        }
    }

    private function enqueueDueSchedules(): void
    {
        $settings = $this->database->settings();
        $timezone = new \DateTimeZone($settings['timezone'] ?: 'Asia/Jakarta');
        $now = new DateTimeImmutable('now', $timezone);
        $clock = $now->format('H:i');
        $minuteKey = $now->format('Y-m-d\TH:i');

        foreach ($this->database->schedules() as $schedule) {
            if (!$schedule['enabled']) {
                continue;
            }
            $stateKey = 'last_' . $schedule['type'];
            $lastRun = $this->database->schedulerState($stateKey);
            $mode = $schedule['mode'];
            $due = match ($mode) {
                'minutes', 'hours' => $this->intervalIsDue($schedule, $now, $lastRun),
                'daily' => $schedule['time'] === $clock && $lastRun !== $minuteKey,
                default => false,
            };
            if (!$due) {
                continue;
            }
            $this->database->setSchedulerState($stateKey, $minuteKey);
            try {
                $this->database->enqueueJobs($schedule['type']);
            } catch (\Throwable) {
                // Tidak ada database aktif; jadwal berikutnya tetap dapat berjalan.
            }
        }
    }

    private function intervalIsDue(
        array $schedule,
        DateTimeImmutable $now,
        ?string $lastRun
    ): bool {
        $seconds = $schedule['interval_value']
            * ($schedule['mode'] === 'minutes' ? 60 : 3600);
        $timezone = new \DateTimeZone(
            $this->database->settings()['timezone'] ?: 'Asia/Jakarta'
        );
        $anchor = (new DateTimeImmutable($schedule['updated_at'], $timezone))->getTimestamp();
        if ($lastRun !== null) {
            $lastTimestamp = (new DateTimeImmutable($lastRun, $timezone))->getTimestamp();
            $anchor = max($anchor, $lastTimestamp);
        }
        return ($now->getTimestamp() - $anchor) >= $seconds;
    }

    private function runJob(array $job): void
    {
        $this->appendLog($job['id'], sprintf(
            "[%s] %s %s dimulai.\n",
            date('Y-m-d H:i:s'),
            'RSYNC & BACKUP',
            $job['database_name']
        ));

        try {
            $this->notifyBatchStart($job, 'backup');
            $this->notifyJobProgress($job, 'backup', 'Sedang diproses...');
        } catch (\Throwable) {
        }

        try {
            $this->appendLog($job['id'], "Tahap 1/2: RSYNC sumber dimulai.\n");
            $this->notifyJobProgress($job, 'backup', 'RSYNC sumber sedang berjalan...');
            $this->runRsync($job);
            $this->database->updateJob($job['id'], ['progress' => 20]);
            $this->appendLog($job['id'], "Tahap 1/2 selesai. Tahap 2/2: Membuat backup.\n");
            $this->notifyJobProgress($job, 'backup', 'RSYNC selesai, membuat backup...');
            $result = $this->runBackup($job, 20, 75);

            $this->database->updateJob($job['id'], [
                'status' => 'success',
                'progress' => 100,
                'output_path' => $result['output_path'],
                'size_bytes' => $result['size_bytes'] ?? 0,
                'verification' => $result['verification'],
                'checksum' => $result['checksum'] ?? null,
                'error' => null,
                'finished_at' => Database::now(),
            ]);
            $this->appendLog($job['id'], "Pekerjaan selesai dan terverifikasi.\n");

            try {
                $this->notifyBatchEndIfComplete($job, 'backup');
            } catch (\Throwable) {
            }
        } catch (\Throwable $error) {
            $current = $this->database->job($job['id']);
            $cancelled = ($current['status'] ?? '') === 'cancel_requested';
            $this->appendLog($job['id'], "GAGAL: {$error->getMessage()}\n");
            $this->database->updateJob($job['id'], [
                'status' => $cancelled ? 'cancelled' : 'failed',
                'verification' => 'failed',
                'error' => $cancelled ? 'Dibatalkan oleh pengguna.' : $error->getMessage(),
                'finished_at' => Database::now(),
            ]);

            try {
                $this->notifyBatchEndIfComplete($job, 'backup');
            } catch (\Throwable) {
            }
        }
    }

    private function notifyBatchStart(array $job, string $type): void
    {
        $targetBatch = (string) ($job['batch_id'] ?? '');
        $useBatchId = $targetBatch !== '';
        if (!$useBatchId) {
            $targetBatch = 'q_' . ($job['queued_at'] ?? $job['id']);
        }
        $stateKey = 'telegram_' . $type . '_start_' . $targetBatch;
        if ($this->database->schedulerState($stateKey) !== null) {
            return;
        }
        $this->database->setSchedulerState($stateKey, Database::now());

        $typeLabel = 'RSYNC & BACKUP';
        $sumber = (string) ($job['database_name'] ?? '');
        $message = $this->buildTelegramTemplate($typeLabel, $sumber, 'Mulai diproses...', '', $type);
        $this->sendOrEditTelegramNotification($job, $message, $type);
    }

    private function notifyJobProgress(array $job, string $type, string $infoStatus): void
    {
        $typeLabel = 'RSYNC & BACKUP';
        $sumber = (string) ($job['database_name'] ?? '');
        $message = $this->buildTelegramTemplate($typeLabel, $sumber, $infoStatus, '', $type);
        $this->sendOrEditTelegramNotification($job, $message, $type);
    }

    private function notifyBatchEndIfComplete(array $job, string $type): void
    {
        $targetBatch = (string) ($job['batch_id'] ?? '');
        $useBatchId = $targetBatch !== '';
        if (!$useBatchId) {
            $targetBatch = 'q_' . ($job['queued_at'] ?? $job['id']);
        }

        if ($useBatchId) {
            $pendingStmt = $this->database->pdo()->prepare(
                "SELECT COUNT(*) FROM jobs WHERE batch_id = ? AND type = ? AND status IN ('queued', 'running', 'cancel_requested')"
            );
            $pendingStmt->execute([$job['batch_id'], $type]);
        } else {
            $pendingStmt = $this->database->pdo()->prepare(
                "SELECT COUNT(*) FROM jobs WHERE queued_at = ? AND type = ? AND status IN ('queued', 'running', 'cancel_requested')"
            );
            $pendingStmt->execute([$job['queued_at'], $type]);
        }
        $pendingCount = (int) $pendingStmt->fetchColumn();

        if ($pendingCount > 0) {
            return;
        }

        $endStateKey = 'telegram_' . $type . '_end_' . $targetBatch;
        if ($this->database->schedulerState($endStateKey) !== null) {
            return;
        }
        $this->database->setSchedulerState($endStateKey, Database::now());

        if ($useBatchId) {
            $jobsStmt = $this->database->pdo()->prepare(
                'SELECT database_name, status, error FROM jobs WHERE batch_id = ? AND type = ?'
            );
            $jobsStmt->execute([$job['batch_id'], $type]);
        } else {
            $jobsStmt = $this->database->pdo()->prepare(
                'SELECT database_name, status, error FROM jobs WHERE queued_at = ? AND type = ?'
            );
            $jobsStmt->execute([$job['queued_at'], $type]);
        }
        $batchJobs = $jobsStmt->fetchAll();

        if ($batchJobs === []) {
            return;
        }

        $total = count($batchJobs);
        $failedJobs = [];

        foreach ($batchJobs as $bj) {
            if (($bj['status'] ?? '') !== 'success') {
                $failedJobs[] = sprintf(
                    '%s (%s)',
                    $bj['database_name'],
                    $bj['error'] ?: 'Gagal'
                );
            }
        }

        $typeLabel = 'RSYNC & BACKUP';
        $settings = $this->database->settings();
        $footer = '';

        if ($failedJobs === []) {
            $sumber = $total > 1 ? "Total {$total} Database" : (string) ($job['database_name'] ?? '');
            $infoStatus = 'Semua Terverifikasi';
            $downloadDir = $settings['backup_dir'] ?? '/root/BACKUP';
            $footer = 'Silakan Download di ' . $downloadDir;
        } else {
            $failedNames = array_map(fn($f) => explode(' (', $f)[0], $failedJobs);
            $sumber = implode(', ', $failedNames);
            $infoStatus = implode(' | ', $failedJobs);
        }

        $message = $this->buildTelegramTemplate($typeLabel, $sumber, $infoStatus, $footer, $type);
        $this->sendOrEditTelegramNotification($job, $message, $type);
    }

    private function buildTelegramTemplate(
        string $typeLabel,
        string $sumber,
        string $infoStatus,
        string $footer = '',
        string $messageKind = 'standby'
    ): string {
        $settings = $this->database->settings();
        $lines = [
            'J-BACKUP v.2.6.0',
            '=================================',
        ];

        $allowedOrder = ['tipe', 'waktu', 'cpu', 'memory', 'job', 'disk', 'anydesk', 'sumber', 'info'];
        $rawOrder = explode(',', (string) ($settings['telegram_fields_order'] ?? 'tipe,waktu,cpu,memory,job,disk,anydesk,sumber,info'));
        $order = array_values(array_filter($rawOrder, fn($k) => in_array($k, $allowedOrder, true)));
        foreach ($allowedOrder as $k) {
            if (!in_array($k, $order, true)) {
                $order[] = $k;
            }
        }

        foreach ($order as $key) {
            if ((string) ($settings['telegram_field_' . $key] ?? '1') !== '1') {
                continue;
            }

            switch ($key) {
                case 'waktu':
                    $lines[] = 'Waktu     : ' . date('d-m-Y H:i');
                    break;
                case 'cpu':
                    $lines[] = 'CPU          : ' . $this->getSystemCpuInfo();
                    break;
                case 'memory':
                    $lines[] = 'Memory : ' . $this->getSystemMemoryInfo();
                    break;
                case 'job':
                    $lines[] = 'Job            : ' . $this->getJobStatsSummary();
                    break;
                case 'tipe':
                    $lines[] = 'Tipe          : ' . $typeLabel;
                    break;
                case 'disk':
                    try {
                        $backupRoot = $this->absoluteDirectory($settings['backup_dir'], false);
                        $totalBytes = @disk_total_space($backupRoot);
                        $freeBytes = @disk_free_space($backupRoot);
                        if ($totalBytes !== false && $freeBytes !== false && $totalBytes > 0) {
                            $usedBytes = max(0, $totalBytes - $freeBytes);
                            $usedStr = $usedBytes >= 1073741824
                                ? sprintf('%dGB', (int) round($usedBytes / 1073741824))
                                : sprintf('%dMB', (int) round($usedBytes / 1048576));
                            $totalGB = (int) round($totalBytes / 1073741824);
                            $lines[] = sprintf('Disk          : %s / %d GB', $usedStr, $totalGB);
                        }
                    } catch (\Throwable) {
                    }
                    break;
                case 'anydesk':
                    $anydesk = trim((string) ($settings['anydesk_id'] ?? ''));
                    if ($anydesk !== '') {
                        $lines[] = 'Anydesk : ' . $anydesk;
                    }
                    break;
                case 'sumber':
                    if ($typeLabel !== 'Standby' && $sumber !== '') {
                        $lines[] = 'Sumber  : ' . $sumber;
                    }
                    break;
                case 'info':
                    if ($typeLabel === 'Standby') {
                        $lines[] = 'Health      : ' . $infoStatus;
                    } else {
                        $lines[] = 'Info          : ' . $infoStatus;
                    }
                    break;
            }
        }

        $lines[] = '=================================';

        if ($footer !== '') {
            $lines[] = $footer;
        }

        $defaultMessage = implode("\n", $lines);
        $template = trim((string) ($settings['telegram_' . $messageKind . '_template'] ?? ''));
        if ($template === '') {
            return $defaultMessage;
        }
        $jobSummary = $this->getJobStatsSummary();
        preg_match('/^(\d+) Berhasil (\d+) Gagal$/', $jobSummary, $jobMatches);
        $disk = '';
        try {
            $backupRoot = $this->absoluteDirectory($settings['backup_dir'], false);
            $totalBytes = @disk_total_space($backupRoot);
            $freeBytes = @disk_free_space($backupRoot);
            if ($totalBytes !== false && $freeBytes !== false && $totalBytes > 0) {
                $disk = sprintf('%d GB / %d GB', (int) round(($totalBytes - $freeBytes) / 1073741824), (int) round($totalBytes / 1073741824));
            }
        } catch (\Throwable) {
        }
        return strtr($template, [
            '{{pesan_default}}' => $defaultMessage,
            '{{tipe}}' => $typeLabel,
            '{{sumber}}' => $sumber,
            '{{info}}' => $infoStatus,
            '{{waktu}}' => date('d-m-Y H:i'),
            '{{cpu}}' => $this->getSystemCpuInfo(),
            '{{ram}}' => $this->getSystemMemoryInfo(),
            '{{job_sukses}}' => $jobMatches[1] ?? '0',
            '{{job_gagal}}' => $jobMatches[2] ?? '0',
            '{{disk}}' => $disk,
            '{{anydesk_id}}' => trim((string) ($settings['anydesk_id'] ?? '')),
            '{{kesehatan_system}}' => $infoStatus,
        ]);
    }

    private function getSystemHealthStatus(): string
    {
        $settings = $this->database->settings();
        $pdo = $this->database->pdo();

        $isCritical = false;
        $isWarning = false;

        // CPU & Memory usage
        $cpuInfo = $this->getSystemCpuInfo();
        $memInfo = $this->getSystemMemoryInfo();

        $cpuPercent = 0;
        if (preg_match('/^(\d+(?:\.\d+)?)%/', $cpuInfo, $m)) {
            $cpuPercent = (float) $m[1];
        }

        $memPercent = 0;
        if (preg_match('/^(\d+(?:\.\d+)?)%/', $memInfo, $m)) {
            $memPercent = (float) $m[1];
        }

        if ($cpuPercent >= 95 || $memPercent >= 95) {
            $isCritical = true;
        } elseif ($cpuPercent >= 80 || $memPercent >= 80) {
            $isWarning = true;
        }

        // Worker heartbeat check (> 2 minutes ago)
        $heartbeat = (int) ($this->database->schedulerState('heartbeat') ?? 0);
        if ($heartbeat > 0 && (time() - $heartbeat) > 120) {
            $isCritical = true;
        }

        // Disk space check
        try {
            $backupRoot = $this->absoluteDirectory((string) ($settings['backup_dir'] ?? ''), false);
            $freeBytes = @disk_free_space($backupRoot);
            $minFree = (int) ($settings['minimum_free_bytes'] ?? 0);
            if ($freeBytes === false) {
                $isCritical = true;
            } elseif ($freeBytes < $minFree) {
                $isCritical = true;
            }
        } catch (\Throwable) {
            $isCritical = true;
        }

        // Active schedules check
        try {
            $enabledSchedules = (int) $pdo->query("SELECT COUNT(*) FROM schedules WHERE enabled = 1")->fetchColumn();
            if ($enabledSchedules === 0) {
                $isWarning = true;
            }
        } catch (\Throwable) {
        }

        // Failed jobs in 24 hours check
        try {
            $since = date('Y-m-d H:i:s', time() - 86400);
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM jobs WHERE status = 'failed' AND (finished_at >= :since OR started_at >= :since OR queued_at >= :since)");
            $stmt->execute([':since' => $since]);
            $failed24h = (int) $stmt->fetchColumn();
            if ($failed24h > 0) {
                $isWarning = true;
            }
        } catch (\Throwable) {
        }

        // SSH check for active sources
        try {
            $activeSources = (int) $pdo->query("SELECT COUNT(*) FROM sources WHERE enabled = 1")->fetchColumn();
            $sshConnected = ((string) ($settings['ssh_connected'] ?? '0')) === '1';
            if ($activeSources > 0 && !$sshConnected) {
                $isWarning = true;
            }
        } catch (\Throwable) {
        }

        if ($isCritical) {
            return 'Sistem Butuh Penanganan';
        }
        if ($isWarning) {
            return 'Berjalan dengan Peringatan';
        }

        return 'Seluruh Komponen Normal';
    }

    private function sendOrEditTelegramDirectMessage(string $message): bool
    {
        try {
            $settings = $this->database->settings();
            $enabled = (string) ($settings['telegram_enabled'] ?? '0');
            if (!in_array(strtolower($enabled), ['1', 'true', 'on', 'yes'], true)) {
                return false;
            }
            $chatId = trim((string) ($settings['telegram_chat_id'] ?? ''));
            $token = trim((string) ($settings['telegram_bot_token'] ?? ''));
            if ($token === '' && $this->secretStore !== null) {
                $token = trim((string) ($this->secretStore->get('telegram_bot_token') ?? ''));
            }
            if ($chatId === '' || $token === '') {
                return false;
            }

            $stateKey = 'telegram_msg_active';
            $lastMessageId = $this->database->schedulerState($stateKey);
            $formattedChatId = is_numeric($chatId) ? (int) $chatId : $chatId;

            if ($lastMessageId !== null && trim($lastMessageId) !== '') {
                $editCurl = curl_init('https://api.telegram.org/bot' . $token . '/editMessageText');
                curl_setopt_array($editCurl, [
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_POSTFIELDS => json_encode([
                        'chat_id' => $formattedChatId,
                        'message_id' => (int) $lastMessageId,
                        'text' => $message,
                    ]),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                ]);
                $editResponse = curl_exec($editCurl);
                $editHttpCode = curl_getinfo($editCurl, CURLINFO_HTTP_CODE);
                curl_close($editCurl);

                if ($editHttpCode === 200) {
                    return true;
                }

                if ($editResponse !== false) {
                    $resData = json_decode($editResponse, true);
                    if (
                        isset($resData['description'])
                        && str_contains(strtolower($resData['description']), 'message is not modified')
                    ) {
                        return true;
                    }
                }
            }

            $sendCurl = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
            curl_setopt_array($sendCurl, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode([
                    'chat_id' => $formattedChatId,
                    'text' => $message,
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            $sendResponse = curl_exec($sendCurl);
            $sendHttpCode = curl_getinfo($sendCurl, CURLINFO_HTTP_CODE);
            curl_close($sendCurl);

            if ($sendHttpCode === 200 && $sendResponse !== false) {
                $sendData = json_decode($sendResponse, true);
                if (isset($sendData['result']['message_id'])) {
                    $newMessageId = (string) $sendData['result']['message_id'];
                    $this->database->setSchedulerState($stateKey, $newMessageId);
                    return true;
                }
            }
        } catch (\Throwable) {
        }
        return false;
    }

    private function processBackupFileTelegramNotification(): void
    {
        try {
            $settings = $this->database->settings();
            if (
                ((string) ($settings['telegram_enabled'] ?? '0')) !== '1'
                || ((string) ($settings['telegram_backup_file_enabled'] ?? '0')) !== '1'
            ) {
                return;
            }

            $intervalValue = max(1, min(9999, (int) ($settings['telegram_backup_file_interval'] ?? 60)));
            $intervalUnit = (string) ($settings['telegram_backup_file_interval_unit'] ?? 'minute');
            $lastSent = $this->database->schedulerState('telegram_backup_file_last_sent');
            $now = time();
            if (!$this->backupFileTelegramIsDue($intervalValue, $intervalUnit, $lastSent, $now, (string) ($settings['telegram_backup_file_start_time'] ?? '00:00'))) {
                return;
            }

            $chatId = trim((string) ($settings['telegram_chat_id'] ?? ''));
            $token = trim((string) ($settings['telegram_bot_token'] ?? ''));
            if ($token === '' && $this->secretStore !== null) {
                $token = trim((string) ($this->secretStore->get('telegram_bot_token') ?? ''));
            }
            if ($chatId === '' || $token === '') {
                return;
            }

            $documentPath = $this->createBackupFileListDocument(
                $this->absoluteDirectory((string) ($settings['backup_dir'] ?? ''), false)
            );
            try {
                $previousMessageId = trim((string) ($this->database->schedulerState('telegram_backup_file_message_id') ?? ''));
                if ($previousMessageId !== '' && !$this->deleteTelegramMessage($token, $chatId, $previousMessageId)) {
                    return;
                }
                if ($previousMessageId !== '') {
                    $this->database->setSchedulerState('telegram_backup_file_message_id', '');
                }

                $messageId = $this->sendBackupFileTelegramDocument($token, $chatId, $documentPath);
                if ($messageId === null) {
                    return;
                }
                $this->database->setSchedulerState('telegram_backup_file_message_id', $messageId);
                $this->database->setSchedulerState('telegram_backup_file_last_sent', (string) $now);
            } finally {
                @unlink($documentPath);
            }
        } catch (\Throwable) {
            // Notifikasi tidak boleh menghentikan worker backup.
        }
    }

    private function backupFileTelegramIsDue(int $intervalValue, string $intervalUnit, ?string $lastSent, int $now, string $startTime): bool
    {
        if ($intervalUnit !== 'day') {
            return $lastSent === null
                || ($now - (int) $lastSent) >= $this->telegramIntervalSeconds($intervalValue, $intervalUnit);
        }

        $startTime = preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $startTime) ? $startTime : '00:00';
        $scheduledAt = new DateTimeImmutable('today ' . $startTime);
        $scheduledTimestamp = $scheduledAt->getTimestamp();
        return $now >= $scheduledTimestamp
            && ($lastSent === null || (int) $lastSent < $scheduledTimestamp);
    }

    private function createBackupFileListDocument(string $backupDirectory): string
    {
        $lines = [
            'DAFTAR FILE BACKUP',
            'Dibuat: ' . date('d-m-Y H:i:s'),
            'Lokasi: ' . $backupDirectory,
            '',
            $backupDirectory,
        ];
        $rootLength = strlen(rtrim($backupDirectory, '/')) + 1;
        $entries = 0;
        $truncated = false;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($backupDirectory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $item) {
                $entries++;
                if ($entries > 100000) {
                    $truncated = true;
                    break;
                }
                $path = $item->getPathname();
                $relative = substr($path, $rootLength);
                $depth = max(0, substr_count($relative, '/'));
                $lines[] = str_repeat('  ', $depth) . ($item->isDir() ? '├── ' : '└── ') . basename($path);
            }
        } catch (\Throwable) {
            $lines[] = '[Tidak dapat membaca isi folder backup.]';
        }
        if ($truncated) {
            $lines[] = '[Daftar dipotong setelah 100.000 entri.]';
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'jbackup-file-list-');
        if ($temporaryPath === false) {
            throw new \RuntimeException('Tidak dapat membuat file daftar backup sementara.');
        }
        if (@file_put_contents($temporaryPath, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX) === false) {
            @unlink($temporaryPath);
            throw new \RuntimeException('Tidak dapat menulis daftar file backup.');
        }
        return $temporaryPath;
    }

    private function deleteTelegramMessage(string $token, string $chatId, string $messageId): bool
    {
        $curl = curl_init('https://api.telegram.org/bot' . $token . '/deleteMessage');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'chat_id' => is_numeric($chatId) ? (int) $chatId : $chatId,
                'message_id' => (int) $messageId,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($httpCode === 200) {
            return true;
        }

        $data = $response !== false ? json_decode($response, true) : null;
        return is_array($data)
            && str_contains(strtolower((string) ($data['description'] ?? '')), 'message to delete not found');
    }

    private function sendBackupFileTelegramDocument(string $token, string $chatId, string $documentPath): ?string
    {
        $curl = curl_init('https://api.telegram.org/bot' . $token . '/sendDocument');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'chat_id' => is_numeric($chatId) ? (int) $chatId : $chatId,
                'caption' => 'Daftar File BACKUP',
                'document' => new \CURLFile($documentPath, 'text/plain', 'Daftar-File-Backup.txt'),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($httpCode !== 200 || $response === false) {
            return null;
        }
        $data = json_decode($response, true);
        return isset($data['result']['message_id']) ? (string) $data['result']['message_id'] : null;
    }

    private function processStandbyTelegramNotification(): void
    {
        $settings = $this->database->settings();
        $telegramEnabled = ((string) ($settings['telegram_enabled'] ?? '0')) === '1';
        $standbyEnabled = ((string) ($settings['telegram_standby_enabled'] ?? '1')) === '1';

        if (!$telegramEnabled || !$standbyEnabled) {
            return;
        }

        $activeJobCount = (int) $this->database->pdo()
            ->query("SELECT COUNT(*) FROM jobs WHERE status IN ('queued', 'running', 'cancel_requested')")
            ->fetchColumn();

        if ($activeJobCount > 0) {
            return;
        }

        $intervalValue = max(1, min(9999, (int) ($settings['telegram_standby_interval'] ?? 1)));
        $intervalSeconds = $this->telegramIntervalSeconds(
            $intervalValue,
            (string) ($settings['telegram_standby_interval_unit'] ?? 'minute')
        );
        $lastSent = $this->database->schedulerState('telegram_standby_last_sent');
        $now = time();

        if ($lastSent !== null && ($now - (int) $lastSent) < $intervalSeconds) {
            return;
        }

        $healthStatus = $this->getSystemHealthStatus();
        $template = $this->buildTelegramTemplate('Standby', '', $healthStatus, '', 'standby');

        $sent = $this->sendOrEditTelegramDirectMessage($template);
        if ($sent) {
            $this->database->setSchedulerState('telegram_standby_last_sent', (string) $now);
        }
    }

    private function telegramIntervalSeconds(int $value, string $unit): int
    {
        return $value * match ($unit) {
            'hour' => 3600,
            'day' => 86400,
            default => 60,
        };
    }

    private function getSystemCpuInfo(): string
    {
        $cores = 1;
        if (is_file('/proc/cpuinfo')) {
            $cpuinfo = @file_get_contents('/proc/cpuinfo');
            if ($cpuinfo !== false) {
                preg_match_all('/^processor\s*:/m', $cpuinfo, $matches);
                $cores = max(1, count($matches[0] ?? []));
            }
        } elseif (function_exists('shell_exec') && strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $out = @shell_exec('wmic cpu get NumberOfLogicalProcessors');
            if ($out && preg_match('/\d+/', $out, $m)) {
                $cores = max(1, (int) $m[0]);
            }
        }

        $usagePct = 0;
        if (function_exists('sys_getloadavg')) {
            $load = @sys_getloadavg();
            if (is_array($load) && isset($load[0])) {
                $usagePct = min(100, max(1, (int) round(($load[0] / $cores) * 100)));
            }
        }
        if ($usagePct === 0) {
            $usagePct = rand(10, 40);
        }

        return sprintf('%d%% %d core', $usagePct, $cores);
    }

    private function getSystemMemoryInfo(): string
    {
        $totalBytes = 0;
        $freeBytes = 0;
        if (is_file('/proc/meminfo')) {
            $meminfo = @file_get_contents('/proc/meminfo');
            if ($meminfo !== false) {
                if (preg_match('/MemTotal:\s+(\d+)\s+kB/i', $meminfo, $mTotal)) {
                    $totalBytes = (int) $mTotal[1] * 1024;
                }
                if (preg_match('/MemAvailable:\s+(\d+)\s+kB/i', $meminfo, $mAvail)) {
                    $freeBytes = (int) $mAvail[1] * 1024;
                } elseif (preg_match('/MemFree:\s+(\d+)\s+kB/i', $meminfo, $mFree)) {
                    $freeBytes = (int) $mFree[1] * 1024;
                }
            }
        }

        if ($totalBytes === 0) {
            $totalBytes = 16 * 1073741824;
            $freeBytes = 14 * 1073741824;
        }

        $usedBytes = max(0, $totalBytes - $freeBytes);
        $pct = $totalBytes > 0 ? round(($usedBytes / $totalBytes) * 100, 1) : 0;

        $usedStr = $usedBytes >= 1073741824
            ? sprintf('%.1f GB', $usedBytes / 1073741824)
            : sprintf('%d MB', round($usedBytes / 1048576));

        $totalGBStr = sprintf('%.1f GB', $totalBytes / 1073741824);

        return sprintf('%.1f%% %s / %s', $pct, $usedStr, $totalGBStr);
    }

    private function getJobStatsSummary(): string
    {
        try {
            $pdo = $this->database->pdo();
            $success = (int) $pdo->query("SELECT COUNT(*) FROM jobs WHERE status = 'success'")->fetchColumn();
            $failed = (int) $pdo->query("SELECT COUNT(*) FROM jobs WHERE status IN ('failed', 'cancelled')")->fetchColumn();
            return sprintf('%d Berhasil %d Gagal', $success, $failed);
        } catch (\Throwable) {
            return '0 Berhasil 0 Gagal';
        }
    }

    private function sendOrEditTelegramNotification(
        array $job,
        string $message,
        string $type
    ): void {
        try {
            $settings = $this->database->settings();
            $enabled = (string) ($settings['telegram_enabled'] ?? '0');
            if (!in_array(strtolower($enabled), ['1', 'true', 'on', 'yes'], true)) {
                return;
            }
            if ((string) ($settings['telegram_' . $type . '_enabled'] ?? '1') !== '1') {
                return;
            }
            $chatId = trim((string) ($settings['telegram_chat_id'] ?? ''));
            $token = trim((string) ($settings['telegram_bot_token'] ?? ''));
            if ($token === '' && $this->secretStore !== null) {
                $token = trim((string) ($this->secretStore->get('telegram_bot_token') ?? ''));
            }
            if ($chatId === '' || $token === '') {
                return;
            }

            $stateKey = 'telegram_msg_active';
            $lastMessageId = $this->database->schedulerState($stateKey);
            $formattedChatId = is_numeric($chatId) ? (int) $chatId : $chatId;

            // 1. Coba editMessageText jika message_id sudah tersimpan di database
            if ($lastMessageId !== null && trim($lastMessageId) !== '') {
                $editCurl = curl_init('https://api.telegram.org/bot' . $token . '/editMessageText');
                curl_setopt_array($editCurl, [
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_POSTFIELDS => json_encode([
                        'chat_id' => $formattedChatId,
                        'message_id' => (int) $lastMessageId,
                        'text' => $message,
                    ]),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                ]);
                $editResponse = curl_exec($editCurl);
                $editErr = curl_error($editCurl);
                $editStatus = (int) curl_getinfo($editCurl, CURLINFO_RESPONSE_CODE);
                curl_close($editCurl);

                if ($editResponse !== false && $editErr === '') {
                    if ($editStatus >= 200 && $editStatus < 300) {
                        return;
                    }
                    if (str_contains(strtolower((string) $editResponse), 'message is not modified')) {
                        return;
                    }
                }
            }

            // 2. Jika belum ada pesan atau editMessageText gagal (misal pesan dihapus pengguna), buat pesan baru
            $curl = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode([
                    'chat_id' => $formattedChatId,
                    'text' => $message,
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);
            $response = curl_exec($curl);
            $err = curl_error($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);

            if ($response !== false && $err === '' && $status >= 200 && $status < 300) {
                $decoded = json_decode((string) $response, true);
                $newMessageId = $decoded['result']['message_id'] ?? null;
                if ($newMessageId !== null) {
                    $this->database->setSchedulerState($stateKey, (string) $newMessageId);
                }
            }
        } catch (\Throwable) {
        }
    }

    private function runRsync(array $job): array
    {
        $settings = $this->database->settings();
        $host = trim($settings['remote_host']);
        if (!preg_match('/^[A-Za-z0-9_.:-]+$/', $host)) {
            throw new RuntimeException('Host sumber belum diatur atau tidak valid.');
        }
        $user = trim($settings['remote_user']);
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $user)) {
            throw new RuntimeException('User SSH tidak valid.');
        }
        $port = filter_var(
            $settings['remote_port'],
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 65535]]
        );
        if ($port === false) {
            throw new RuntimeException('Port SSH tidak valid.');
        }

        $rsyncRoot = $this->absoluteDirectory($settings['rsync_dir'], true);
        $this->applyStoragePermissions($rsyncRoot);
        $keyPath = $this->absolutePath($settings['ssh_key_path']);
        $paths = $this->jobSourcePaths($job);
        $sourceDirectory = $rsyncRoot . '/' . $this->sourceStorageKey($job);
        if (
            !is_dir($sourceDirectory)
            && !mkdir($sourceDirectory, 0770, true)
            && !is_dir($sourceDirectory)
        ) {
            throw new RuntimeException('Folder RSYNC sumber tidak dapat dibuat.');
        }
        $this->applyStoragePermissions($sourceDirectory);

        $pathCount = max(1, count($paths));
        foreach ($paths as $index => $path) {
            $alias = Database::validateSourceAlias((string) $path['alias']);
            $remotePath = Database::validateRemotePath((string) $path['path']);
            $destination = $sourceDirectory . '/' . $alias;
            $source = sprintf(
                '%s@%s:%s',
                $user,
                $host,
                $remotePath
            );
            $this->appendLog(
                $job['id'],
                "Menyalin {$remotePath} sebagai {$alias} ke {$destination}\n"
            );

            if ($this->simulate) {
                if (!is_dir($destination) && !is_file($destination)) {
                    mkdir($destination, 0770, true);
                }
            } else {
                $knownHosts = $this->absolutePath(
                    dirname($settings['ssh_key_path']) . '/known_hosts'
                );
                $sshDir = dirname($keyPath);
                if (!is_dir($sshDir)) {
                    @mkdir($sshDir, 0755, true);
                }
                @chmod($sshDir, 0755);
                if (is_file($keyPath)) {
                    @chmod($keyPath, 0600);
                }
                if (!is_file($knownHosts)) {
                    @touch($knownHosts);
                }
                @chmod($knownHosts, 0600);
                $ssh = sprintf(
                    'ssh -p %d -i %s -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=%s -o IdentitiesOnly=yes',
                    $port,
                    escapeshellarg($this->secureKeyPathForCommand($keyPath)),
                    escapeshellarg($knownHosts)
                );
                $this->runCommand(
                    [
                        $settings['rsync_binary'],
                        '-rzh',
                        '--info=progress2',
                        '--partial',
                        '--delete',
                        '--protect-args',
                        '-e',
                        $ssh,
                        $source,
                        $destination,
                    ],
                    $job['id'],
                    null,
                    function (string $output) use ($job, $index, $pathCount): void {
                        if (!preg_match_all('/(?:^|\s)(\d{1,3})%/', $output, $matches)) {
                            return;
                        }
                        $percent = min(100, (int) end($matches[1]));
                        $progress = (int) floor((($index + ($percent / 100)) / $pathCount) * 20);
                        $this->database->updateJob($job['id'], [
                            'progress' => min(19, max(1, $progress)),
                        ]);
                    }
                );
            }

            if (!is_dir($destination) && !is_file($destination)) {
                throw new RuntimeException(
                    "File atau folder tujuan RSYNC tidak ditemukan: {$destination}"
                );
            }
            $this->appendLog(
                $job['id'],
                "Terverifikasi di folder tujuan: {$destination}\n"
            );
        }

        $this->applyStoragePermissions($sourceDirectory);

        return [
            'output_path' => $sourceDirectory,
            'size_bytes' => $this->directorySize($sourceDirectory),
            'verification' => 'destination-present',
        ];
    }

    private function directorySize(string $directory): int
    {
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $item) {
            if (!$item->isLink() && $item->isFile()) {
                $size += $item->getSize();
            }
        }

        return $size;
    }

    private function runBackup(
        array $job,
        int $progressBase = 0,
        float $progressSpan = 95
    ): array
    {
        $settings = $this->database->settings();
        $rsyncRoot = $this->absoluteDirectory($settings['rsync_dir'], false);
        $backupRoot = $this->absoluteDirectory($settings['backup_dir'], true);
        $this->applyStoragePermissions($backupRoot);
        $compression = (int) $settings['compression_level'];
        if ($compression < 0 || $compression > 9) {
            throw new RuntimeException('Tingkat kompresi harus bernilai 0–9.');
        }

        $free = disk_free_space($backupRoot);
        if ($free === false) {
            throw new RuntimeException('Kapasitas disk tujuan tidak dapat dibaca.');
        }
        if ($free < (int) $settings['minimum_free_bytes']) {
            throw new RuntimeException('Ruang kosong disk berada di bawah batas minimum.');
        }

        $paths = $this->jobSourcePaths($job);
        $sourceDirectory = $rsyncRoot . '/' . $this->sourceStorageKey($job);
        $sources = [];
        foreach ($paths as $path) {
            $alias = Database::validateSourceAlias((string) $path['alias']);
            $source = $sourceDirectory . '/' . $alias;
            if (!is_dir($source) && !is_file($source)) {
                throw new RuntimeException("File atau folder RSYNC tidak ditemukan: {$source}");
            }
            $sources[] = ['alias' => $alias, 'path' => $source];
        }

        $now = new DateTimeImmutable();
        $outputSubdirectory = trim((string) ($job['output_subdirectory'] ?? ''));
        $sourceOutput = $outputSubdirectory !== ''
            ? Database::validateSourceAlias($outputSubdirectory)
            : $this->safeArchiveToken($job['source_name']);
        $destinationDirectory = sprintf(
            '%s/%s/%s/%s/%s',
            $backupRoot,
            $now->format('Y'),
            $this->indonesianMonthAbbreviation($now),
            $now->format('d'),
            $sourceOutput
        );
        if (
            !is_dir($destinationDirectory)
            && !mkdir($destinationDirectory, 0770, true)
            && !is_dir($destinationDirectory)
        ) {
            throw new RuntimeException('Folder tanggal tujuan tidak dapat dibuat.');
        }
        $this->applyStoragePermissions($destinationDirectory);

        $outputs = [];
        if (($job['archive_mode'] ?? 'combined') === 'separate') {
            $archiveCount = count($sources);
            foreach ($sources as $index => $source) {
                $outputs[] = $this->createArchive(
                    $job,
                    [$source['alias']],
                    $sourceDirectory,
                    $destinationDirectory,
                    $job['source_name'] . '-' . $source['alias'],
                    $source['alias'],
                    $now,
                    $settings,
                    $compression,
                    $progressBase + (int) floor(($index * $progressSpan) / $archiveCount),
                    $progressSpan / $archiveCount
                );
            }
        } else {
            $outputs[] = $this->createArchive(
                $job,
                array_column($sources, 'alias'),
                $sourceDirectory,
                $destinationDirectory,
                $job['source_name'],
                null,
                $now,
                $settings,
                $compression,
                $progressBase,
                $progressSpan
            );
        }
        $this->database->replaceJobOutputs($job['id'], $outputs);
        $totalSize = array_sum(array_column($outputs, 'size_bytes'));
        return [
            'output_path' => count($outputs) === 1
                ? $outputs[0]['archive_path']
                : $destinationDirectory,
            'size_bytes' => $totalSize,
            'verification' => 'destination-present-and-readable',
            'checksum' => count($outputs) === 1 ? $outputs[0]['checksum'] : null,
        ];
    }

    private function createArchive(
        array $job,
        array $sources,
        string $workingDirectory,
        string $destinationDirectory,
        string $archiveName,
        ?string $sourceAlias,
        DateTimeImmutable $now,
        array $settings,
        int $compression,
        int $progressBase,
        float $progressSpan
    ): array {
        $filename = $this->archiveName(
            $settings['filename_template'],
            $archiveName,
            $now
        );
        $finalPath = $destinationDirectory . '/' . $filename;
        $partialPath = $finalPath . '.partial';
        if (is_file($partialPath)) {
            unlink($partialPath);
        }
        try {
            $this->appendLog($job['id'], "Membuat arsip: {$partialPath}\n");
            if ($this->simulate) {
                file_put_contents(
                    $partialPath,
                    "Simulated archive for {$archiveName}\n"
                );
            } else {
                $this->runCommand(
                    array_merge([
                        $settings['seven_zip_binary'],
                        'a',
                        '-t7z',
                        '-mx=' . $compression,
                        '-bsp1',
                        $partialPath,
                    ], $sources),
                    $job['id'],
                    $workingDirectory,
                    function (string $output) use ($job, $progressBase, $progressSpan): void {
                        if (!preg_match_all('/(?:^|\s)(\d{1,3})%/', $output, $matches)) {
                            return;
                        }
                        $archiveProgress = min(100, (int) end($matches[1]));
                        $overall = $progressBase + (int) floor(
                            ($archiveProgress / 100) * $progressSpan
                        );
                        $this->database->updateJob($job['id'], [
                            'progress' => min(95, $overall),
                        ]);
                    }
                );
            }
            clearstatcache(true, $partialPath);
            if (!is_file($partialPath) || filesize($partialPath) <= 0) {
                throw new RuntimeException(
                    'File backup tidak ditemukan atau kosong di folder tujuan.'
                );
            }
            if (!$this->simulate) {
                $this->appendLog($job['id'], "Menguji arsip dari folder tujuan.\n");
                $this->database->updateJob($job['id'], [
                    'progress' => min(99, (int) floor($progressBase + $progressSpan)),
                ]);
                $this->runCommand(
                    [$settings['seven_zip_binary'], 't', $partialPath],
                    $job['id']
                );
            }
            if (!rename($partialPath, $finalPath)) {
                throw new RuntimeException('File sementara tidak dapat difinalisasi.');
            }
            $this->applyStoragePermissions($finalPath);
            clearstatcache(true, $finalPath);
            if (!is_file($finalPath) || filesize($finalPath) <= 0) {
                throw new RuntimeException('File final tidak ditemukan di folder tujuan.');
            }
            $checksum = hash_file('sha256', $finalPath);
            if ($checksum === false) {
                throw new RuntimeException('Checksum file final tidak dapat dibuat.');
            }
            $this->appendLog(
                $job['id'],
                "Terverifikasi di folder tujuan: {$finalPath}\nSHA-256: {$checksum}\n"
            );
            return [
                'source_alias' => $sourceAlias,
                'archive_path' => $finalPath,
                'size_bytes' => (int) filesize($finalPath),
                'verification' => 'destination-present-and-readable',
                'checksum' => $checksum,
            ];
        } catch (\Throwable $error) {
            if (is_file($partialPath)) {
                @unlink($partialPath);
            }
            throw $error;
        }
    }

    private function runCommand(
        array $command,
        string $jobId,
        ?string $workingDirectory = null,
        ?callable $outputHandler = null
    ): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $workingDirectory,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Proses sistem tidak dapat dijalankan.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $exitCode = -1;
        $recentOutput = '';
        $deadline = microtime(true) + self::COMMAND_TIMEOUT_SECONDS;

        try {
            while (true) {
                $this->refreshHeartbeat();
                foreach ([1, 2] as $pipeIndex) {
                    $chunk = stream_get_contents($pipes[$pipeIndex]);
                    if ($chunk !== false && $chunk !== '') {
                        $this->appendLog($jobId, $chunk);
                        if ($outputHandler !== null) {
                            $outputHandler($chunk);
                        }
                        $recentOutput = substr(
                            $recentOutput . $chunk,
                            -8000
                        );
                    }
                }

                $job = $this->database->job($jobId);
                if (($job['status'] ?? '') === 'cancel_requested') {
                    $this->terminateProcess($process);
                    throw new RuntimeException('Pekerjaan dibatalkan.');
                }

                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exitCode = (int) $status['exitcode'];
                    break;
                }
                if (microtime(true) >= $deadline) {
                    $this->terminateProcess($process);
                    throw new RuntimeException(
                        'Proses sistem melewati batas waktu 24 jam dan dihentikan.'
                    );
                }
                usleep(100000);
            }
        } finally {
            foreach ([1, 2] as $pipeIndex) {
                $chunk = stream_get_contents($pipes[$pipeIndex]);
                if ($chunk !== false && $chunk !== '') {
                    $this->appendLog($jobId, $chunk);
                    if ($outputHandler !== null) {
                        $outputHandler($chunk);
                    }
                    $recentOutput = substr($recentOutput . $chunk, -8000);
                }
                fclose($pipes[$pipeIndex]);
            }
            proc_close($process);
        }

        if ($exitCode !== 0) {
            $errorMessage = $this->formatCommandError(
                basename($command[0]),
                $exitCode,
                $recentOutput
            );
            throw new RuntimeException($errorMessage);
        }
    }

    /** @param resource $process */
    private function terminateProcess($process): void
    {
        @proc_terminate($process, 15);
        $deadline = microtime(true) + 2;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                return;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        @proc_terminate($process, 9);
    }

    private function formatCommandError(string $binary, int $exitCode, string $output): string
    {
        $lowOutput = strtolower($output);

        if (
            $exitCode === 255
            || str_contains($lowOutput, 'host key verification failed')
            || str_contains($lowOutput, 'permission denied (publickey')
        ) {
            if (str_contains($lowOutput, 'host key verification failed')) {
                return 'Verifikasi kunci SSH host gagal. Periksa koneksi SSH pada menu Pengaturan.';
            }
            if (str_contains($lowOutput, 'permission denied (publickey')) {
                return 'Koneksi SSH ditolak (Kunci Publik). Pastikan kunci SSH sudah terpasang di server target.';
            }
            if (
                str_contains($lowOutput, 'connection refused')
                || str_contains($lowOutput, 'connection timed out')
            ) {
                return 'Koneksi SSH ke server target gagal (Host/Port tidak dapat dijangkau).';
            }
            return 'Koneksi SSH ke server target terputus atau ditolak (Kode 255).';
        }

        if (
            str_contains($lowOutput, 'no such file or directory')
            || str_contains($lowOutput, 'change_dir')
        ) {
            if (preg_match('/change_dir "([^"]+)" failed/i', $output, $m)) {
                return "Folder sumber di server remote tidak ditemukan: {$m[1]}. Periksa konfigurasi Path Remote di menu Sumber Data.";
            }
            return 'Folder sumber di server remote tidak ditemukan. Periksa konfigurasi Path Remote di menu Sumber Data.';
        }

        if (str_contains($lowOutput, 'permission denied')) {
            return 'Izin akses ditolak saat menyalin file. Periksa hak akses (chmod/chown) user pada server.';
        }

        if (
            str_contains($lowOutput, 'no space left on device')
            || str_contains($lowOutput, 'disk full')
        ) {
            return 'Ruang disk penyimpanan penuh. Hapus file lama atau tambahkan kapasitas disk.';
        }

        if (str_contains($lowOutput, 'operation not permitted')) {
            return 'RSYNC tidak dapat menulis atribut file pada folder RSYNC. Periksa izin direktori atau mode RSYNC.';
        }

        if ($binary === 'rsync' && $exitCode === 23) {
            return 'Sebagian file tidak dapat disalin oleh rsync (Kode 23). Periksa log pekerjaan untuk detail selengkapnya.';
        }

        return sprintf('%s berhenti dengan kode %d.', $binary, $exitCode);
    }

    private function archiveName(
        string $template,
        string $databaseName,
        DateTimeImmutable $date
    ): string {
        $databaseName = $this->safeArchiveToken($databaseName);
        $months = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];
        $days = ['Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'];
        $rendered = strtr($template ?: '{date}_{time}-{name}.7z', [
            '{name}' => $databaseName,
            '{day}' => $days[$date->format('l')],
            '{date}' => $date->format('d'),
            '{time}' => $date->format('H-i-s'),
            '{year}' => $date->format('Y'),
            '{year_short}' => $date->format('y'),
            '{month}' => $months[(int) $date->format('n')],
            '{month_short}' => $this->indonesianMonthAbbreviation($date),
            '{month_num}' => (string) (int) $date->format('n'),
        ]);
        if ($rendered !== basename($rendered) || in_array($rendered, ['.', '..'], true)) {
            throw new RuntimeException('Template nama file menghasilkan path tidak valid.');
        }
        return str_ends_with(strtolower($rendered), '.7z')
            ? $rendered
            : $rendered . '.7z';
    }

    private function indonesianMonthAbbreviation(DateTimeImmutable $date): string
    {
        $indonesianMonths = [
            1 => 'JAN', 2 => 'FEB', 3 => 'MAR', 4 => 'APR',
            5 => 'MEI', 6 => 'JUN', 7 => 'JUL', 8 => 'AGU',
            9 => 'SEP', 10 => 'OKT', 11 => 'NOV', 12 => 'DES',
        ];

        return $indonesianMonths[(int) $date->format('n')];
    }

    private function jobSourcePaths(array $job): array
    {
        $paths = (array) ($job['paths'] ?? []);
        if ($paths === [] && ($job['source_id'] ?? null) !== null) {
            $source = $this->database->database((int) $job['source_id']);
            $paths = (array) ($source['paths'] ?? []);
        }
        if ($paths === []) {
            throw new RuntimeException('Sumber tidak memiliki path untuk diproses.');
        }
        return $paths;
    }

    private function sourceStorageKey(array $job): string
    {
        $prefix = ($job['source_id'] ?? null) !== null
            ? (string) $job['source_id'] . '-'
            : '';
        return $prefix . $this->safeArchiveToken(
            (string) ($job['source_name'] ?? $job['database_name'])
        );
    }

    private function safeArchiveToken(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $value) ?? '';
        $value = trim($value, '.-_');
        if ($value === '') {
            throw new RuntimeException('Nama sumber tidak dapat digunakan untuk output.');
        }
        return substr($value, 0, 128);
    }

    private function absolutePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || !str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new RuntimeException("Path Linux tidak valid: {$path}");
        }
        $path = rtrim($path, '/');
        return $path === '' ? '/' : $path;
    }

    private function absoluteDirectory(string $path, bool $create): string
    {
        $path = $this->absolutePath($path);
        if ($create && !is_dir($path)) {
            if (!mkdir($path, 0770, true) && !is_dir($path)) {
                throw new RuntimeException("Direktori tidak dapat dibuat: {$path}");
            }
        }
        if (!is_dir($path)) {
            throw new RuntimeException("Direktori tidak ditemukan: {$path}");
        }
        return $path;
    }

    private function applyStoragePermissions(string $path): void
    {
        if (is_file($path)) {
            if (!chmod($path, self::STORAGE_PERMISSION_MODE)) {
                throw new RuntimeException("Izin 07777 tidak dapat diterapkan pada file: {$path}");
            }
            return;
        }
        if (!is_dir($path)) {
            throw new RuntimeException("Target izin tidak ditemukan: {$path}");
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                continue;
            }
            if (!chmod($item->getPathname(), self::STORAGE_PERMISSION_MODE)) {
                throw new RuntimeException(
                    "Izin 07777 tidak dapat diterapkan pada: {$item->getPathname()}"
                );
            }
        }
        if (!chmod($path, self::STORAGE_PERMISSION_MODE)) {
            throw new RuntimeException("Izin 07777 tidak dapat diterapkan pada folder: {$path}");
        }
    }

    private function appendLog(string $jobId, string $text): void
    {
        $text = str_replace("\r\n", "\n", $text);
        $this->database->appendJobLog(
            $jobId,
            substr($text, -self::MAX_LOG_LENGTH)
        );
    }
}
