<?php

declare(strict_types=1);

namespace JBackup;

use DateTimeImmutable;
use RuntimeException;

final class JobRunner
{
    private const MAX_LOG_LENGTH = 200000;
    private ?string $activeSshTaskId = null;

    public function __construct(
        private readonly Database $database,
        private readonly string $runtimeDirectory,
        private readonly bool $simulate = false,
        private readonly ?SecretStore $secretStore = null,
    ) {
    }

    public function run(): int
    {
        if (!is_dir($this->runtimeDirectory)) {
            mkdir($this->runtimeDirectory, 0770, true);
        }
        $lock = fopen($this->runtimeDirectory . '/worker.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return 0;
        }

        try {
            $this->database->setSchedulerState(
                'worker_heartbeat',
                Database::now()
            );
            $this->recoverInterruptedJobs();
            $this->recoverInterruptedSshTasks();
            $this->runSshTasks();
            $this->enqueueDueSchedules();
            $processed = 0;
            while ($job = $this->database->nextQueuedJob()) {
                $this->runJob($job);
                $processed++;
            }
            return $processed;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function recoverInterruptedJobs(): void
    {
        $statement = $this->database->pdo()->prepare(
            <<<'SQL'
            UPDATE jobs
            SET status = 'failed', finished_at = ?, error = ?
            WHERE status IN ('running', 'cancel_requested')
            SQL
        );
        $statement->execute([
            Database::now(),
            'Worker berhenti sebelum pekerjaan selesai.',
        ]);
    }

    private function recoverInterruptedSshTasks(): void
    {
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

    private function generateSshKey(array $payload, array $secret = []): array
    {
        $config = $this->sshConfig($payload, false);
        $keyPath = $config['key_path'];
        $publicPath = $keyPath . '.pub';
        $keyType = (string) ($payload['ssh_key_type'] ?? 'ed25519');
        if (!in_array($keyType, ['ed25519', 'rsa4096'], true)) {
            throw new RuntimeException('Tipe key SSH tidak didukung.');
        }
        $comment = trim((string) ($payload['ssh_key_comment'] ?? 'J-BACKUP-Key'));
        $comment = preg_replace('/[\x00-\x1F\x7F]/', '', $comment) ?: 'J-BACKUP-Key';
        $comment = substr($comment, 0, 128);
        $sshDirectory = dirname($keyPath);
        $this->appendSshLog("Memeriksa folder dan pasangan kunci SSH.\n");
        if (!is_dir($sshDirectory)) {
            if (!mkdir($sshDirectory, 0770, true) && !is_dir($sshDirectory)) {
                throw new RuntimeException('Folder SSH tidak dapat dibuat oleh worker.');
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
                    ], 30, ['SSHPASS' => $password]);
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
            $config['key_path'],
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
        if (!is_file($keyPath)) {
            throw new RuntimeException(
                'Private key lokal tidak ditemukan. Public key remote tidak dapat dicabut dengan aman.'
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

        $target = $config['user'] . '@' . $config['host'];
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
                $keyPath,
                '-p',
                (string) $config['port'],
                $target,
                'sh -s -- ' . $parts[0] . ' ' . $parts[1],
            ], 20, [], $remoteScript);
        }
        $this->appendSshLog("Public key remote berhasil dicabut.\n");
        $this->database->deleteSchedulerState('ssh_connection');

        $notRemoved = [];
        foreach ([$keyPath, $publicPath, dirname($keyPath) . '/known_hosts'] as $file) {
            if (is_file($file) && !@unlink($file)) {
                $notRemoved[] = $file;
            }
        }
        $this->secretStore?->delete('ssh_password');
        if ($notRemoved !== []) {
            throw new RuntimeException(
                'Public key remote sudah dicabut, tetapi file lokal gagal dihapus: '
                . implode(', ', $notRemoved)
            );
        }
        $this->appendSshLog(
            "Private key, public key, known_hosts, dan password tersimpan telah dihapus.\n"
        );

        return [
            'disconnected' => true,
            'target' => $target,
            'message' => 'Koneksi SSH diputus dan key berhasil dihapus.',
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
        $keyPath = $this->absolutePath(
            (string) ($payload['ssh_key_path'] ?? $settings['ssh_key_path'])
        );

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

        $allowedDirectory = rtrim($this->runtimeDirectory, '/') . '/.ssh';
        if (dirname($keyPath) !== $allowedDirectory) {
            throw new RuntimeException(
                "Pembuatan dan pengujian kunci hanya diizinkan dalam {$allowedDirectory}."
            );
        }

        return [
            'host' => $host,
            'user' => $user,
            'port' => $port,
            'key_path' => $keyPath,
        ];
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
        date_default_timezone_set($settings['timezone'] ?: 'Asia/Jakarta');
        $now = new DateTimeImmutable();
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
        $anchor = strtotime($schedule['updated_at']);
        if ($lastRun !== null) {
            $lastTimestamp = strtotime($lastRun);
            if ($lastTimestamp !== false) {
                $anchor = max($anchor === false ? 0 : $anchor, $lastTimestamp);
            }
        }
        return $anchor !== false && ($now->getTimestamp() - $anchor) >= $seconds;
    }

    private function runJob(array $job): void
    {
        $this->appendLog($job['id'], sprintf(
            "[%s] %s %s dimulai.\n",
            date('Y-m-d H:i:s'),
            $job['type'] === 'sync' ? 'Sinkronisasi' : 'Backup',
            $job['database_name']
        ));

        try {
            $result = $job['type'] === 'sync'
                ? $this->runSync($job)
                : $this->runBackup($job);

            $this->database->updateJob($job['id'], [
                'status' => 'success',
                'output_path' => $result['output_path'],
                'size_bytes' => $result['size_bytes'] ?? 0,
                'verification' => $result['verification'],
                'checksum' => $result['checksum'] ?? null,
                'error' => null,
                'finished_at' => Database::now(),
            ]);
            $this->appendLog($job['id'], "Pekerjaan selesai dan terverifikasi.\n");
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
        }
    }

    private function runSync(array $job): array
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

        $staging = $this->absoluteDirectory($settings['staging_dir'], true);
        $keyPath = $this->absolutePath($settings['ssh_key_path']);
        $paths = $this->jobSourcePaths($job);
        $sourceDirectory = $staging . '/' . $this->sourceStorageKey($job);
        if (
            !is_dir($sourceDirectory)
            && !mkdir($sourceDirectory, 0770, true)
            && !is_dir($sourceDirectory)
        ) {
            throw new RuntimeException('Folder staging sumber tidak dapat dibuat.');
        }

        foreach ($paths as $path) {
            $alias = Database::validateSourceAlias((string) $path['alias']);
            $remotePath = Database::validateRemotePath((string) $path['path']);
            $destination = $sourceDirectory . '/' . $alias;
            $source = sprintf(
                '%s@%s:%s/',
                $user,
                $host,
                $remotePath
            );
            $this->appendLog(
                $job['id'],
                "Menyalin {$remotePath} sebagai {$alias} ke {$destination}\n"
            );

            if ($this->simulate) {
                if (!is_dir($destination)) {
                    mkdir($destination, 0770, true);
                }
            } else {
                if (
                    !is_dir($destination)
                    && !mkdir($destination, 0770, true)
                    && !is_dir($destination)
                ) {
                    throw new RuntimeException(
                        "Folder tujuan sinkronisasi tidak dapat dibuat: {$destination}"
                    );
                }
                $ssh = sprintf(
                    'ssh -p %d -i %s',
                    $port,
                    escapeshellarg($keyPath)
                );
                $this->runCommand(
                    [
                        $settings['rsync_binary'],
                        '-rzh',
                        '--partial',
                        '--delete',
                        '--protect-args',
                        '-e',
                        $ssh,
                        $source,
                        $destination . '/',
                    ],
                    $job['id']
                );
            }

            if (!is_dir($destination)) {
                throw new RuntimeException(
                    "Folder tujuan sinkronisasi tidak ditemukan: {$destination}"
                );
            }
            $this->appendLog(
                $job['id'],
                "Terverifikasi di folder tujuan: {$destination}\n"
            );
        }

        return [
            'output_path' => $sourceDirectory,
            'verification' => 'destination-present',
        ];
    }

    private function runBackup(array $job): array
    {
        $settings = $this->database->settings();
        $staging = $this->absoluteDirectory($settings['staging_dir'], false);
        $backupRoot = $this->absoluteDirectory($settings['backup_dir'], true);
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
        $sourceDirectory = $staging . '/' . $this->sourceStorageKey($job);
        $sources = [];
        foreach ($paths as $path) {
            $alias = Database::validateSourceAlias((string) $path['alias']);
            $source = $sourceDirectory . '/' . $alias;
            if (!is_dir($source)) {
                throw new RuntimeException("Folder staging tidak ditemukan: {$source}");
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
            $now->format('m'),
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

        $outputs = [];
        if (($job['archive_mode'] ?? 'combined') === 'separate') {
            foreach ($sources as $source) {
                $outputs[] = $this->createArchive(
                    $job,
                    [$source['alias']],
                    $sourceDirectory,
                    $destinationDirectory,
                    $job['source_name'] . '-' . $source['alias'],
                    $source['alias'],
                    $now,
                    $settings,
                    $compression
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
                $compression
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
        int $compression
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
                        $partialPath,
                    ], $sources),
                    $job['id'],
                    $workingDirectory
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
                $this->runCommand(
                    [$settings['seven_zip_binary'], 't', $partialPath],
                    $job['id']
                );
            }
            if (!rename($partialPath, $finalPath)) {
                throw new RuntimeException('File sementara tidak dapat difinalisasi.');
            }
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
        ?string $workingDirectory = null
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

        try {
            while (true) {
                foreach ([1, 2] as $pipeIndex) {
                    $chunk = stream_get_contents($pipes[$pipeIndex]);
                    if ($chunk !== false && $chunk !== '') {
                        $this->appendLog($jobId, $chunk);
                        $recentOutput = substr(
                            $recentOutput . $chunk,
                            -8000
                        );
                    }
                }

                $job = $this->database->job($jobId);
                if (($job['status'] ?? '') === 'cancel_requested') {
                    proc_terminate($process, 15);
                    throw new RuntimeException('Pekerjaan dibatalkan.');
                }

                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exitCode = (int) $status['exitcode'];
                    break;
                }
                usleep(100000);
            }
        } finally {
            foreach ([1, 2] as $pipeIndex) {
                $chunk = stream_get_contents($pipes[$pipeIndex]);
                if ($chunk !== false && $chunk !== '') {
                    $this->appendLog($jobId, $chunk);
                    $recentOutput = substr($recentOutput . $chunk, -8000);
                }
                fclose($pipes[$pipeIndex]);
            }
            proc_close($process);
        }

        if ($exitCode !== 0) {
            if (basename($command[0]) === 'rsync' && $exitCode === 23) {
                $message = str_contains(
                    strtolower($recentOutput),
                    'operation not permitted'
                )
                    ? 'Rsync tidak dapat menulis atribut file pada folder staging. '
                        . 'Gunakan folder yang dapat ditulis worker atau mode staging kompatibel WSL.'
                    : 'Sebagian file tidak dapat disalin oleh rsync (kode 23). '
                        . 'Periksa detail izin atau file yang gagal pada log.';
                throw new RuntimeException($message);
            }
            throw new RuntimeException(
                sprintf('%s berhenti dengan kode %d.', basename($command[0]), $exitCode)
            );
        }
    }

    private function archiveName(
        string $template,
        string $databaseName,
        DateTimeImmutable $date
    ): string {
        $databaseName = $this->safeArchiveToken($databaseName);
        $rendered = strtr($template ?: '{date}_{time}-{name}.7z', [
            '{name}' => $databaseName,
            '{date}' => $date->format('Y-m-d'),
            '{time}' => $date->format('H-i-s'),
            '{year}' => $date->format('Y'),
            '{month}' => $date->format('m'),
            '{day}' => $date->format('d'),
        ]);
        if ($rendered !== basename($rendered) || in_array($rendered, ['.', '..'], true)) {
            throw new RuntimeException('Template nama file menghasilkan path tidak valid.');
        }
        return str_ends_with(strtolower($rendered), '.7z')
            ? $rendered
            : $rendered . '.7z';
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
        return rtrim($path, '/');
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

    private function appendLog(string $jobId, string $text): void
    {
        $text = str_replace("\r\n", "\n", $text);
        $this->database->appendJobLog(
            $jobId,
            substr($text, -self::MAX_LOG_LENGTH)
        );
    }
}
