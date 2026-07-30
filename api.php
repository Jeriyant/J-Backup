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

const JBACKUP_VERSION = '0.4.0';

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
        'session_timeout_minutes' => [0, 1440, 'Timeout sesi'],
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
    foreach ([
        'remote_port',
        'compression_level',
        'minimum_free_bytes',
        'session_timeout_minutes',
    ] as $key) {
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

function explorerRoot(array $settings, string $kind): string
{
    $setting = $kind === 'realtime' ? 'staging_dir' : 'backup_dir';
    $label = $kind === 'realtime' ? 'realtime' : 'backup';
    $configured = rtrim((string) ($settings[$setting] ?? ''), '/');
    $root = realpath($configured);
    if ($root === false || !is_dir($root)) {
        throw new HttpException("Folder {$label} belum tersedia.", 404);
    }
    return rtrim($root, DIRECTORY_SEPARATOR);
}

function storageRoot(array $settings): string
{
    return explorerRoot($settings, 'backup');
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

function systemMetrics(): array
{
    $uptime = null;
    $uptimeRaw = @file_get_contents('/proc/uptime');
    if (is_string($uptimeRaw)) {
        $uptime = max(0, (int) floor((float) explode(' ', trim($uptimeRaw))[0]));
    }

    $cores = 1;
    $cpuInfo = @file_get_contents('/proc/cpuinfo');
    if (is_string($cpuInfo)) {
        $cores = max(1, preg_match_all('/^processor\s*:/m', $cpuInfo));
    }
    $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
    $cpuPercent = is_array($load)
        ? round(min(100, max(0, ((float) $load[0] / $cores) * 100)), 1)
        : null;

    $memory = ['total' => 0, 'used' => 0, 'available' => 0, 'used_percent' => 0];
    $memoryRaw = @file_get_contents('/proc/meminfo');
    if (is_string($memoryRaw)) {
        preg_match('/^MemTotal:\s+(\d+)\s+kB/im', $memoryRaw, $totalMatch);
        preg_match('/^MemAvailable:\s+(\d+)\s+kB/im', $memoryRaw, $availableMatch);
        $total = (int) ($totalMatch[1] ?? 0) * 1024;
        $available = (int) ($availableMatch[1] ?? 0) * 1024;
        $used = max(0, $total - $available);
        $memory = [
            'total' => $total,
            'used' => $used,
            'available' => $available,
            'used_percent' => $total > 0
                ? round(($used / $total) * 100, 1)
                : 0,
        ];
    }

    return [
        'uptime_seconds' => $uptime,
        'cpu_percent' => $cpuPercent,
        'cpu_cores' => $cores,
        'memory' => $memory,
        'server_time' => date(DATE_ATOM),
    ];
}

function mountedDisks(array $settings): array
{
    $candidates = ['/'];
    $mounts = @file('/proc/self/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($mounts)) {
        $ignored = [
            'proc', 'sysfs', 'tmpfs', 'devtmpfs', 'devpts', 'cgroup',
            'cgroup2', 'pstore', 'securityfs', 'debugfs', 'tracefs',
            'mqueue', 'hugetlbfs', 'fusectl', 'configfs', 'overlay',
            'binfmt_misc',
        ];
        foreach ($mounts as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 3 || in_array($parts[2], $ignored, true)) {
                continue;
            }
            $candidates[] = str_replace(
                ['\\040', '\\011', '\\134'],
                [' ', "\t", '\\'],
                $parts[1]
            );
        }
    }

    $disks = [];
    foreach (array_values(array_unique($candidates)) as $path) {
        if ($path === '' || !is_dir($path)) {
            continue;
        }
        $info = diskInfo($path);
        if (!$info['available'] || $info['total'] <= 0) {
            continue;
        }
        $key = $info['total'] . ':' . $info['free'];
        if (isset($disks[$key])) {
            continue;
        }
        $disks[$key] = $info;
    }
    return array_values($disks);
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

    if ($action === 'account_update') {
        requireMethod('POST');
        $payload = input();
        $timeout = validateSettings([
            'session_timeout_minutes' =>
                $payload['session_timeout_minutes'] ?? 30,
        ])['session_timeout_minutes'];
        $user = $auth->updateAccount(
            (string) ($payload['current_password'] ?? ''),
            (string) ($payload['username'] ?? ''),
            (string) ($payload['new_password'] ?? '')
        );
        $database->updateSettings([
            'session_timeout_minutes' => $timeout,
        ]);
        respond([
            'ok' => true,
            'user' => ['username' => $user['username']],
            'session_timeout_minutes' => $timeout,
        ]);
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
                +
                (SELECT COUNT(*) FROM path_tasks WHERE status = 'running')
            )"
        )->fetchColumn();
        if ($activeCount > 0) {
            throw new HttpException(
                'Reset ditolak karena worker sedang menjalankan pekerjaan.',
                409
            );
        }

        $settings = $database->settings();
        $connection = sshConnectionInfo($database, $settings);
        if ($connection['connected']) {
            $taskPayload = [
                'remote_host' => trim((string) $settings['remote_host']),
                'remote_port' => (int) $settings['remote_port'],
                'remote_user' => trim((string) $settings['remote_user']),
                'ssh_key_path' => trim((string) $settings['ssh_key_path']),
                'ssh_key_type' => (string) $settings['ssh_key_type'],
                'ssh_key_comment' => trim((string) $settings['ssh_key_comment']),
                'install_key' => false,
            ];
            respond([
                'ok' => false,
                'ssh_cleanup_required' => true,
                'message' => 'Public key SSH sedang dicabut dari server sumber sebelum reset.',
                'task' => $database->createSshTask(
                    'disconnect',
                    $taskPayload
                ),
            ], 202);
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
        $pathChecks = [
            'realtime' => $database->latestPathCheck('realtime'),
            'backup' => $database->latestPathCheck('backup'),
        ];
        $queueCount = (int) $database->pdo()->query(
            "SELECT COUNT(*) FROM jobs WHERE status = 'queued'"
        )->fetchColumn();
        respond([
            'version' => JBACKUP_VERSION,
            'user' => ['username' => $_SESSION['username']],
            'settings' => $settings,
            'path_checks' => $pathChecks,
            'sources' => $database->sources(),
            'schedules' => $database->schedules(),
            'jobs' => $database->jobs(150),
            'disk' => diskInfo($settings['backup_dir']),
            'system' => systemMetrics(),
            'active_job' => $database->activeJob(),
            'queue_count' => $queueCount,
            'worker_heartbeat' => $database->schedulerState('worker_heartbeat'),
        ]);
    }

    if ($action === 'disk_list') {
        requireMethod('GET');
        respond(['disks' => mountedDisks($database->settings())]);
    }

    if ($action === 'backup_list' || $action === 'realtime_list') {
        requireMethod('GET');
        $kind = str_starts_with($action, 'realtime') ? 'realtime' : 'backup';
        respond(storageListing(
            explorerRoot($database->settings(), $kind),
            (string) ($_GET['path'] ?? '')
        ));
    }

    if ($action === 'backup_download' || $action === 'realtime_download') {
        requireMethod('GET');
        $kind = str_starts_with($action, 'realtime') ? 'realtime' : 'backup';
        $file = storagePath(
            explorerRoot($database->settings(), $kind),
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

    if ($action === 'source_create' || $action === 'database_create') {
        requireMethod('POST');
        respond(['source' => $database->createSource(input())], 201);
    }

    if ($action === 'source_import') {
        requireMethod('POST');
        $upload = $_FILES['file'] ?? null;
        if (
            !is_array($upload)
            || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        ) {
            $code = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
            throw new RuntimeException(
                in_array(
                    $code,
                    [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE],
                    true
                )
                    ? 'File impor melebihi batas upload server.'
                    : 'File Excel belum dipilih atau tidak dapat diterima.'
            );
        }
        if ((int) ($upload['size'] ?? 0) > 10 * 1024 * 1024) {
            throw new RuntimeException('Ukuran file impor maksimal 10 MB.');
        }
        $name = basename((string) ($upload['name'] ?? ''));
        if (!preg_match('/\.(xlsx|csv)$/i', $name)) {
            throw new RuntimeException('Format file harus .xlsx atau .csv.');
        }

        require_once __DIR__ . '/src/SourceImport.php';
        $rows = \JBackup\SourceImport::normalize(
            \JBackup\SourceImport::read(
                (string) $upload['tmp_name'],
                $name
            )
        );
        $imported = [];
        $errors = [];
        foreach ($rows as $row) {
            $line = (int) $row['_row'];
            unset($row['_row']);
            try {
                $imported[] = $database->createSource($row);
            } catch (PDOException $error) {
                $errors[] = [
                    'row' => $line,
                    'name' => (string) ($row['name'] ?? ''),
                    'message' => $error->getCode() === '23000'
                        ? 'Nama sumber sudah terdaftar.'
                        : 'Database menolak baris ini.',
                ];
            } catch (Throwable $error) {
                $errors[] = [
                    'row' => $line,
                    'name' => (string) ($row['name'] ?? ''),
                    'message' => $error->getMessage(),
                ];
            }
        }
        respond([
            'ok' => $imported !== [],
            'imported_count' => count($imported),
            'failed_count' => count($errors),
            'sources' => $imported,
            'errors' => $errors,
        ], $imported !== [] ? 201 : 422);
    }

    if ($action === 'source_update' || $action === 'database_update') {
        requireMethod('POST');
        $payload = input();
        $id = (int) ($payload['id'] ?? 0);
        $source = $database->updateSource($id, $payload);
        if (!$source) {
            throw new HttpException('Sumber tidak ditemukan.', 404);
        }
        respond(['source' => $source]);
    }

    if ($action === 'source_delete' || $action === 'database_delete') {
        requireMethod('POST');
        $payload = input();
        if (!$database->deleteSource((int) ($payload['id'] ?? 0))) {
            throw new HttpException('Sumber tidak ditemukan.', 404);
        }
        respond(['ok' => true]);
    }

    if ($action === 'sources_delete') {
        requireMethod('POST');
        $payload = input();
        $ids = (array) ($payload['ids'] ?? []);
        if ($ids === [] || count($ids) > 5000) {
            throw new HttpException(
                'Pilih 1 sampai 5000 sumber untuk dihapus.',
                422
            );
        }
        $deleted = $database->deleteSources($ids);
        if ($deleted === 0) {
            throw new HttpException('Sumber terpilih tidak ditemukan.', 404);
        }
        respond([
            'ok' => true,
            'deleted_count' => $deleted,
        ]);
    }

    if ($action === 'settings_update') {
        requireMethod('POST');
        $payload = input();
        $previousSettings = $database->settings();
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
        foreach ([
            'realtime' => 'staging_dir',
            'backup' => 'backup_dir',
        ] as $kind => $setting) {
            if (
                array_key_exists($setting, $validatedSettings)
                && rtrim((string) $previousSettings[$setting], '/')
                    !== rtrim((string) $settings[$setting], '/')
            ) {
                $check = $database->latestPathCheck($kind);
                if (
                    !is_array($check)
                    || rtrim((string) ($check['path'] ?? ''), '/')
                        !== rtrim((string) $settings[$setting], '/')
                ) {
                    $database->deleteSchedulerState('path_check_' . $kind);
                }
            }
        }
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

    if ($action === 'path_task_create') {
        requireMethod('POST');
        $payload = input();
        $kind = (string) ($payload['kind'] ?? '');
        $path = trim((string) ($payload['path'] ?? ''));
        if (!in_array($kind, ['realtime', 'backup'], true)) {
            throw new HttpException('Jenis folder tidak dikenal.', 422);
        }
        validateSettings([
            $kind === 'realtime' ? 'staging_dir' : 'backup_dir' => $path,
        ]);
        respond([
            'task' => $database->createPathTask($kind, $path),
        ], 202);
    }

    if ($action === 'path_task_status') {
        requireMethod('GET');
        $task = $database->pathTask((string) ($_GET['id'] ?? ''));
        if (!$task) {
            throw new HttpException('Pengujian folder tidak ditemukan.', 404);
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
                (array) (
                    $payload['source_ids']
                    ?? $payload['database_ids']
                    ?? []
                )
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
