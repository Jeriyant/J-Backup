<?php

declare(strict_types=1);

use JBackup\Auth;
use JBackup\HttpException;
use JBackup\UpdateChecker;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$container = require __DIR__ . '/src/bootstrap.php';
/** @var \JBackup\Database $database */
$database = $container['database'];
/** @var \JBackup\Auth $auth */
$auth = $container['auth'];
/** @var \JBackup\SecretStore $secretStore */
$secretStore = $container['secret_store'];

const JBACKUP_VERSION = '0.1.0';

function input(): array
{
    $content = file_get_contents('php://input');
    if ($content === false || $content === '') {
        return [];
    }
    $decoded = json_decode($content, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Format JSON tidak valid.');
    }
    return $decoded;
}

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function requireMethod(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        throw new HttpException('Metode tidak diizinkan.', 405);
    }
}

function securePasswordTransport(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (in_array($remoteAddress, ['127.0.0.1', '::1'], true)) {
        return true;
    }
    return false;
}

function validateSettings(array $values): array
{
    $integerRules = [
        'remote_port' => [1, 65535, 'Port SSH'],
        'compression_level' => [0, 9, 'Tingkat kompresi'],
        'minimum_free_bytes' => [0, PHP_INT_MAX, 'Minimum ruang kosong'],
    ];
    foreach ($integerRules as $key => [$minimum, $maximum, $label]) {
        if (array_key_exists($key, $values)) {
            $parsed = filter_var(
                $values[$key],
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => $minimum, 'max_range' => $maximum]]
            );
            if ($parsed === false) {
                throw new RuntimeException("{$label} tidak valid.");
            }
            $values[$key] = $parsed;
        }
    }

    foreach ([
        'remote_root',
        'staging_dir',
        'backup_dir',
        'ssh_key_path',
        'rsync_binary',
        'seven_zip_binary',
    ] as $pathKey) {
        if (
            isset($values[$pathKey])
            && (!str_starts_with((string) $values[$pathKey], '/')
                || str_contains((string) $values[$pathKey], "\0"))
        ) {
            throw new RuntimeException("{$pathKey} harus berupa path absolut Linux.");
        }
    }

    if (
        isset($values['timezone'])
        && !in_array($values['timezone'], DateTimeZone::listIdentifiers(), true)
    ) {
        throw new RuntimeException('Zona waktu tidak valid.');
    }
    if (
        isset($values['filename_template'])
        && (str_contains($values['filename_template'], '/')
            || str_contains($values['filename_template'], '\\'))
    ) {
        throw new RuntimeException('Template nama file tidak boleh berisi path.');
    }
    if (
        isset($values['ssh_key_type'])
        && !in_array($values['ssh_key_type'], ['ed25519', 'rsa4096'], true)
    ) {
        throw new RuntimeException('Tipe key SSH tidak didukung.');
    }
    if (
        isset($values['ssh_key_comment'])
        && (
            strlen((string) $values['ssh_key_comment']) > 128
            || preg_match('/[\x00-\x1F\x7F]/', (string) $values['ssh_key_comment'])
        )
    ) {
        throw new RuntimeException('Komentar key SSH tidak valid.');
    }
    return $values;
}

function publicSettings(array $settings): array
{
    foreach (['remote_port', 'compression_level', 'minimum_free_bytes'] as $key) {
        $settings[$key] = (int) ($settings[$key] ?? 0);
    }
    return $settings;
}

function sshConnectionInfo(
    \JBackup\Database $database,
    array $settings
): array
{
    $raw = $database->schedulerState('ssh_connection');
    $state = is_string($raw) ? json_decode($raw, true) : null;
    $keyPath = (string) ($settings['ssh_key_path'] ?? '');
    $connected = is_array($state)
        && ($state['connected'] ?? false) === true
        && hash_equals(
            (string) ($state['host'] ?? ''),
            (string) ($settings['remote_host'] ?? '')
        )
        && (int) ($state['port'] ?? 0) === (int) ($settings['remote_port'] ?? 0)
        && hash_equals(
            (string) ($state['user'] ?? ''),
            (string) ($settings['remote_user'] ?? '')
        )
        && is_file($keyPath);

    return [
        'connected' => $connected,
        'target' => $connected ? (string) ($state['target'] ?? '') : null,
        'connected_at' => $connected
            ? (string) ($state['connected_at'] ?? '')
            : null,
    ];
}

function diskInfo(string $path): array
{
    if (!is_dir($path)) {
        return [
            'available' => false,
            'path' => $path,
            'total' => 0,
            'used' => 0,
            'free' => 0,
            'used_percent' => 0,
            'error' => 'Folder tujuan belum tersedia.',
        ];
    }
    $total = disk_total_space($path);
    $free = disk_free_space($path);
    if ($total === false || $free === false) {
        return [
            'available' => false,
            'path' => $path,
            'total' => 0,
            'used' => 0,
            'free' => 0,
            'used_percent' => 0,
            'error' => 'Kapasitas disk tidak dapat dibaca.',
        ];
    }
    $used = max($total - $free, 0);
    return [
        'available' => true,
        'path' => $path,
        'total' => (int) $total,
        'used' => (int) $used,
        'free' => (int) $free,
        'used_percent' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
    ];
}

function storageRoot(array $settings): string
{
    $configured = rtrim((string) ($settings['backup_dir'] ?? ''), '/');
    $root = realpath($configured);
    if ($root === false || !is_dir($root)) {
        throw new HttpException('Folder tujuan backup belum tersedia.', 404);
    }
    return rtrim($root, DIRECTORY_SEPARATOR);
}

function storageRelativePath(string $path): string
{
    $path = trim(str_replace('\\', '/', $path), '/');
    if ($path === '') {
        return '';
    }
    $segments = explode('/', $path);
    foreach ($segments as $segment) {
        if (
            $segment === ''
            || $segment === '.'
            || $segment === '..'
            || str_contains($segment, "\0")
        ) {
            throw new HttpException('Path penyimpanan tidak valid.', 400);
        }
    }
    return implode('/', $segments);
}

function storagePath(string $root, string $relative, bool $directory = false): string
{
    $relative = storageRelativePath($relative);
    $candidate = $relative === ''
        ? $root
        : $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $resolved = realpath($candidate);
    $prefix = $root . DIRECTORY_SEPARATOR;
    if (
        $resolved === false
        || ($resolved !== $root && !str_starts_with($resolved, $prefix))
        || ($directory ? !is_dir($resolved) : !is_file($resolved))
        || is_link($candidate)
    ) {
        throw new HttpException(
            $directory ? 'Folder backup tidak ditemukan.' : 'File backup tidak ditemukan.',
            404
        );
    }
    return $resolved;
}

function storageListing(string $root, string $relative): array
{
    $relative = storageRelativePath($relative);
    $directory = storagePath($root, $relative, true);
    $entries = [];
    $iterator = new DirectoryIterator($directory);
    foreach ($iterator as $item) {
        if ($item->isDot() || $item->isLink()) {
            continue;
        }
        $name = $item->getFilename();
        $childRelative = ltrim($relative . '/' . $name, '/');
        $real = $item->getRealPath();
        if (
            $real === false
            || ($real !== $root
                && !str_starts_with($real, $root . DIRECTORY_SEPARATOR))
        ) {
            continue;
        }
        $entries[] = [
            'name' => $name,
            'path' => str_replace(DIRECTORY_SEPARATOR, '/', $childRelative),
            'type' => $item->isDir() ? 'directory' : 'file',
            'size' => $item->isFile() ? $item->getSize() : 0,
            'modified_at' => date(DATE_ATOM, $item->getMTime()),
        ];
    }
    usort($entries, static function (array $left, array $right): int {
        if ($left['type'] !== $right['type']) {
            return $left['type'] === 'directory' ? -1 : 1;
        }
        return strnatcasecmp($left['name'], $right['name']);
    });
    return [
        'path' => $relative,
        'root' => $root,
        'entries' => $entries,
    ];
}

try {
    $action = (string) ($_GET['action'] ?? 'status');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($action === 'status') {
        requireMethod('GET');
        respond([
            'ok' => true,
            'version' => JBACKUP_VERSION,
            ...$auth->status(),
        ]);
    }

    if ($action === 'setup') {
        requireMethod('POST');
        Auth::verifyOrigin();
        $payload = input();
        $auth->verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        $auth->setup(
            (string) ($payload['username'] ?? ''),
            (string) ($payload['password'] ?? '')
        );
        respond(['ok' => true, 'csrf_token' => $auth->csrfToken()], 201);
    }

    if ($action === 'login') {
        requireMethod('POST');
        Auth::verifyOrigin();
        $payload = input();
        $auth->verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        $auth->login(
            (string) ($payload['username'] ?? ''),
            (string) ($payload['password'] ?? '')
        );
        respond(['ok' => true, 'csrf_token' => $auth->csrfToken()]);
    }

    $auth->requireUser();
    if ($method !== 'GET') {
        Auth::verifyOrigin();
        $auth->verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    }

    if ($action === 'logout') {
        requireMethod('POST');
        $auth->logout();
        respond(['ok' => true]);
    }

    if ($action === 'reset_database') {
        requireMethod('POST');
        $payload = input();
        if (!hash_equals('RESET', trim((string) ($payload['confirmation'] ?? '')))) {
            throw new HttpException(
                'Ketik RESET untuk mengonfirmasi penghapusan seluruh konfigurasi.',
                422
            );
        }

        $activeCount = (int) $database->pdo()->query(
            "SELECT (
                (SELECT COUNT(*) FROM jobs
                 WHERE status IN ('running', 'cancel_requested'))
                +
                (SELECT COUNT(*) FROM ssh_tasks WHERE status = 'running')
            )"
        )->fetchColumn();
        if ($activeCount > 0) {
            throw new HttpException(
                'Reset ditolak karena worker sedang menjalankan pekerjaan.',
                409
            );
        }

        $dataDirectory = rtrim((string) $container['data_directory'], '/\\');
        $managedSshDirectory = $dataDirectory . '/.ssh';
        $cleanupFailures = [];
        if (is_dir($managedSshDirectory)) {
            try {
                $sshFiles = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator(
                        $managedSshDirectory,
                        FilesystemIterator::SKIP_DOTS
                    ),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($sshFiles as $sshFile) {
                    $path = $sshFile->getPathname();
                    $removed = $sshFile->isDir() && !$sshFile->isLink()
                        ? @rmdir($path)
                        : @unlink($path);
                    if (!$removed) {
                        $cleanupFailures[] = $path;
                    }
                }
            } catch (UnexpectedValueException) {
                $cleanupFailures[] = $managedSshDirectory;
            }
        }
        if ($cleanupFailures !== []) {
            throw new HttpException(
                'Reset dibatalkan karena file SSH lokal tidak dapat dihapus. '
                . 'Jalankan kembali installer untuk memperbaiki izin folder .ssh.',
                409
            );
        }

        $database->resetApplication();
        $auth->logout();
        respond([
            'ok' => true,
            'setup_required' => true,
            'warnings' => [],
        ]);
    }

    if ($action === 'dashboard') {
        requireMethod('GET');
        $settings = publicSettings($database->settings());
        $connection = sshConnectionInfo($database, $settings);
        $settings['ssh_password_saved'] = $secretStore->has('ssh_password');
        $settings['ssh_connected'] = $connection['connected'];
        $settings['ssh_connected_target'] = $connection['target'];
        $settings['ssh_connected_at'] = $connection['connected_at'];
        $queueCount = (int) $database->pdo()->query(
            "SELECT COUNT(*) FROM jobs WHERE status = 'queued'"
        )->fetchColumn();
        respond([
            'version' => JBACKUP_VERSION,
            'user' => ['username' => $_SESSION['username']],
            'settings' => $settings,
            'databases' => $database->databases(),
            'schedules' => $database->schedules(),
            'jobs' => $database->jobs(150),
            'disk' => diskInfo($settings['backup_dir']),
            'active_job' => $database->activeJob(),
            'queue_count' => $queueCount,
            'worker_heartbeat' => $database->schedulerState('worker_heartbeat'),
        ]);
    }

    if ($action === 'storage_list') {
        requireMethod('GET');
        $settings = $database->settings();
        respond(storageListing(
            storageRoot($settings),
            (string) ($_GET['path'] ?? '')
        ));
    }

    if ($action === 'storage_download') {
        requireMethod('GET');
        $settings = $database->settings();
        $file = storagePath(
            storageRoot($settings),
            (string) ($_GET['path'] ?? '')
        );
        header_remove('Content-Type');
        header('Content-Type: application/octet-stream');
        header(
            'Content-Disposition: attachment; filename="'
            . addcslashes(basename($file), "\\\"")
            . '"'
        );
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: private, no-store');
        readfile($file);
        exit;
    }

    if ($action === 'storage_upload') {
        requireMethod('POST');
        $settings = $database->settings();
        $root = storageRoot($settings);
        $directory = storagePath(
            $root,
            (string) ($_POST['path'] ?? ''),
            true
        );
        $upload = $_FILES['file'] ?? null;
        if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $code = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
            throw new RuntimeException(
                $code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE
                    ? 'File melebihi batas upload server.'
                    : 'File upload tidak diterima.'
            );
        }
        $name = basename((string) ($upload['name'] ?? ''));
        if (
            $name === ''
            || $name === '.'
            || $name === '..'
            || !preg_match('/\.7z$/i', $name)
            || preg_match('/[\x00-\x1F\x7F]/', $name)
        ) {
            throw new RuntimeException('Hanya file backup .7z dengan nama valid yang dapat diupload.');
        }
        $destination = $directory . DIRECTORY_SEPARATOR . $name;
        if (file_exists($destination)) {
            throw new HttpException('File dengan nama tersebut sudah tersedia.', 409);
        }
        $temporary = $destination . '.uploading-' . bin2hex(random_bytes(5));
        if (!move_uploaded_file((string) $upload['tmp_name'], $temporary)) {
            throw new RuntimeException('File upload tidak dapat dipindahkan ke folder tujuan.');
        }
        try {
            if (!rename($temporary, $destination)) {
                throw new RuntimeException('File upload tidak dapat difinalisasi.');
            }
            @chgrp($destination, 'jbackup');
            @chmod($destination, 0660);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
        respond([
            'file' => [
                'name' => $name,
                'path' => ltrim(
                    storageRelativePath((string) ($_POST['path'] ?? '')) . '/' . $name,
                    '/'
                ),
                'size' => filesize($destination),
            ],
        ], 201);
    }

    if ($action === 'database_create') {
        requireMethod('POST');
        respond(['database' => $database->createDatabase(input())], 201);
    }

    if ($action === 'database_import') {
        requireMethod('POST');
        $payload = input();
        respond([
            'result' => $database->importDatabases(
                $payload['names'] ?? '',
                (bool) ($payload['include_sys'] ?? true)
            ),
        ], 201);
    }

    if ($action === 'database_update') {
        requireMethod('POST');
        $payload = input();
        $id = (int) ($payload['id'] ?? 0);
        $databaseRow = $database->updateDatabase($id, $payload);
        if (!$databaseRow) {
            throw new HttpException('Database tidak ditemukan.', 404);
        }
        respond(['database' => $databaseRow]);
    }

    if ($action === 'database_delete') {
        requireMethod('POST');
        $payload = input();
        if (!$database->deleteDatabase((int) ($payload['id'] ?? 0))) {
            throw new HttpException('Database tidak ditemukan.', 404);
        }
        respond(['ok' => true]);
    }

    if ($action === 'settings_update') {
        requireMethod('POST');
        $payload = input();
        $sshPassword = (string) ($payload['ssh_password'] ?? '');
        unset($payload['ssh_password'], $payload['ssh_password_saved']);
        $validatedSettings = validateSettings($payload);
        if ($sshPassword !== '') {
            if (strlen($sshPassword) > 1024) {
                throw new RuntimeException('Password SSH terlalu panjang.');
            }
            if (!securePasswordTransport()) {
                throw new RuntimeException(
                    'Password SSH hanya boleh dikirim melalui HTTPS atau localhost.'
                );
            }
            $secretStore->set('ssh_password', $sshPassword);
        }
        $settings = publicSettings(
            $database->updateSettings($validatedSettings)
        );
        $settings['ssh_password_saved'] = $secretStore->has('ssh_password');
        respond([
            'settings' => $settings,
        ]);
    }

    if ($action === 'ssh_password_delete') {
        requireMethod('POST');
        $secretStore->delete('ssh_password');
        respond(['ok' => true]);
    }

    if ($action === 'ssh_password_reveal') {
        requireMethod('POST');
        if (!securePasswordTransport()) {
            throw new RuntimeException(
                'Password SSH hanya dapat ditampilkan melalui HTTPS atau localhost.'
            );
        }
        $password = $secretStore->get('ssh_password');
        if ($password === null) {
            throw new HttpException('Password SSH belum tersimpan.', 404);
        }
        respond(['password' => $password]);
    }

    if ($action === 'schedule_update') {
        requireMethod('POST');
        $payload = input();
        respond([
            'schedule' => $database->updateSchedule(
                (string) ($payload['type'] ?? ''),
                $payload
            ),
        ]);
    }

    if ($action === 'ssh_task_create') {
        requireMethod('POST');
        $payload = input();
        $type = (string) ($payload['type'] ?? '');
        $settings = $database->settings();
        $installKey = $type === 'generate_key'
            && ($payload['install_key'] ?? false) === true;
        $taskPayload = [
            'remote_host' => trim((string) (
                $payload['remote_host'] ?? $settings['remote_host']
            )),
            'remote_port' => (int) (
                $payload['remote_port'] ?? $settings['remote_port']
            ),
            'remote_user' => trim((string) (
                $payload['remote_user'] ?? $settings['remote_user']
            )),
            'ssh_key_path' => trim((string) (
                $payload['ssh_key_path'] ?? $settings['ssh_key_path']
            )),
            'ssh_key_type' => (string) (
                $payload['ssh_key_type'] ?? $settings['ssh_key_type']
            ),
            'ssh_key_comment' => trim((string) (
                $payload['ssh_key_comment'] ?? $settings['ssh_key_comment']
            )),
            'install_key' => $installKey,
        ];
        validateSettings([
            'remote_port' => $taskPayload['remote_port'],
            'ssh_key_path' => $taskPayload['ssh_key_path'],
            'ssh_key_type' => $taskPayload['ssh_key_type'],
            'ssh_key_comment' => $taskPayload['ssh_key_comment'],
        ]);
        $secret = [];
        if ($installKey) {
            $password = (string) ($payload['password'] ?? '');
            if ($password !== '') {
                if (strlen($password) > 1024) {
                    throw new RuntimeException('Password SSH terlalu panjang.');
                }
                if (!securePasswordTransport()) {
                    throw new RuntimeException(
                        'Password SSH hanya boleh dikirim melalui HTTPS atau localhost.'
                    );
                }
                $secretStore->set('ssh_password', $password);
            } elseif (!$secretStore->has('ssh_password')) {
                throw new RuntimeException(
                    'Password SSH diperlukan untuk setup koneksi pertama.'
                );
            }
        }
        respond([
            'task' => $database->createSshTask($type, $taskPayload, $secret),
        ], 202);
    }

    if ($action === 'ssh_task_status') {
        requireMethod('GET');
        $task = $database->sshTask((string) ($_GET['id'] ?? ''));
        if (!$task) {
            throw new HttpException('Tindakan SSH tidak ditemukan.', 404);
        }
        respond([
            'task' => $task,
            'worker_heartbeat' => $database->schedulerState('worker_heartbeat'),
        ]);
    }

    if ($action === 'jobs_create') {
        requireMethod('POST');
        $payload = input();
        respond([
            'jobs' => $database->enqueueJobs(
                (string) ($payload['type'] ?? ''),
                (array) ($payload['database_ids'] ?? [])
            ),
        ], 202);
    }

    if ($action === 'job_cancel') {
        requireMethod('POST');
        $payload = input();
        if (!$database->cancelJob((string) ($payload['id'] ?? ''))) {
            throw new HttpException('Pekerjaan aktif atau antrean tidak ditemukan.', 404);
        }
        respond(['ok' => true]);
    }

    if ($action === 'update_check') {
        requireMethod('GET');
        require_once __DIR__ . '/src/UpdateChecker.php';
        $settings = $database->settings();
        respond(UpdateChecker::latest(
            $settings['github_repository'],
            JBACKUP_VERSION
        ));
    }

    throw new HttpException('Endpoint tidak ditemukan.', 404);
} catch (HttpException $error) {
    respond(['error' => $error->getMessage()], $error->status);
} catch (PDOException $error) {
    $status = $error->getCode() === '23000' ? 409 : 500;
    respond([
        'error' => $status === 409
            ? 'Nama tersebut sudah terdaftar.'
            : 'Database internal mengalami kesalahan.',
    ], $status);
} catch (Throwable $error) {
    respond(['error' => $error->getMessage()], 400);
}
