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

const JBACKUP_VERSION = '2.7.0';
const JBACKUP_GITHUB_REPOSITORY = 'Jeriyant/J-Backup';

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

function sendSourceExport(array $sources): never
{
    require_once __DIR__ . '/src/SourceExport.php';
    $file = \JBackup\SourceExport::create($sources);
    try {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="J-Backup-Sumber.xlsx"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
    } finally {
        @unlink($file);
    }
    exit;
}

function requireMethod(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        throw new HttpException('Metode tidak diizinkan.', 405);
    }
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
        'rsync_dir',
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
    if (isset($values['language']) && !in_array($values['language'], ['id', 'en'], true)) {
        throw new RuntimeException('Bahasa aplikasi tidak valid.');
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
    if (isset($values['telegram_enabled'])) {
        $values['telegram_enabled'] = filter_var(
            $values['telegram_enabled'],
            FILTER_VALIDATE_BOOLEAN
        ) ? '1' : '0';
    }
    foreach ([
        'telegram_field_waktu',
        'telegram_field_cpu',
        'telegram_field_memory',
        'telegram_field_job',
        'telegram_field_tipe',
        'telegram_field_disk',
        'telegram_field_anydesk',
        'telegram_field_sumber',
        'telegram_field_info',
    ] as $fieldKey) {
        if (isset($values[$fieldKey])) {
            $values[$fieldKey] = filter_var($values[$fieldKey], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }
    }
    if (isset($values['telegram_fields_order'])) {
        $allowed = ['tipe', 'waktu', 'cpu', 'memory', 'job', 'disk', 'anydesk', 'sumber', 'info'];
        $raw = explode(',', (string) $values['telegram_fields_order']);
        $clean = array_values(array_filter($raw, fn($k) => in_array($k, $allowed, true)));
        foreach ($allowed as $k) {
            if (!in_array($k, $clean, true)) {
                $clean[] = $k;
            }
        }
        $values['telegram_fields_order'] = implode(',', $clean);
    }
    if (isset($values['telegram_standby_enabled'])) {
        $values['telegram_standby_enabled'] = filter_var($values['telegram_standby_enabled'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
    }
    if (isset($values['telegram_standby_interval'])) {
        $val = (int) $values['telegram_standby_interval'];
        $values['telegram_standby_interval'] = (string) max(1, min(9999, $val));
    }
    if (isset($values['telegram_backup_file_enabled'])) {
        $values['telegram_backup_file_enabled'] = filter_var($values['telegram_backup_file_enabled'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
    }
    if (isset($values['telegram_backup_file_interval'])) {
        $val = (int) $values['telegram_backup_file_interval'];
        $values['telegram_backup_file_interval'] = (string) max(1, min(9999, $val));
    }
    if (isset($values['telegram_backup_file_start_time'])) {
        $time = trim((string) $values['telegram_backup_file_start_time']);
        if (!preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $time)) {
            throw new RuntimeException('Waktu mulai pengiriman BACKUP tidak valid.');
        }
        $values['telegram_backup_file_start_time'] = $time;
    }
    foreach (['telegram_standby_interval_unit', 'telegram_backup_file_interval_unit'] as $unitKey) {
        if (isset($values[$unitKey]) && !in_array($values[$unitKey], ['minute', 'hour', 'day'], true)) {
            throw new RuntimeException('Satuan interval notifikasi tidak valid.');
        }
    }
    foreach (['telegram_standby_enabled', 'telegram_rsync_enabled', 'telegram_backup_enabled'] as $enabledKey) {
        if (isset($values[$enabledKey])) {
            $values[$enabledKey] = filter_var($values[$enabledKey], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }
    }
    foreach (['telegram_standby_template', 'telegram_rsync_template', 'telegram_backup_template'] as $templateKey) {
        if (isset($values[$templateKey])) {
            $values[$templateKey] = substr(trim((string) $values[$templateKey]), 0, 4096);
        }
    }
    if (isset($values['anydesk_id'])) {
        $anydesk = trim((string) $values['anydesk_id']);
        if ($anydesk !== '' && !preg_match('/^[A-Za-z0-9 _-]{1,64}$/', $anydesk)) {
            throw new RuntimeException('AnyDesk ID tidak valid.');
        }
        $values['anydesk_id'] = $anydesk;
    }
    if (
        isset($values['telegram_chat_id'])
        && trim((string) $values['telegram_chat_id']) !== ''
        && !preg_match('/^-?[0-9]{1,24}$/', trim((string) $values['telegram_chat_id']))
    ) {
        throw new RuntimeException('Chat ID Telegram tidak valid.');
    }
    if (
        isset($values['telegram_bot_token'])
        && trim((string) $values['telegram_bot_token']) !== ''
        && !preg_match('/^[0-9]+:[A-Za-z0-9_-]+$/', trim((string) $values['telegram_bot_token']))
    ) {
        throw new RuntimeException('Token bot Telegram tidak valid.');
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
        && hash_equals(
            (string) ($state['key_path'] ?? $keyPath),
            $keyPath
        );

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
    $setting = $kind === 'rsync' ? 'rsync_dir' : 'backup_dir';
    $label = $kind === 'rsync' ? 'RSYNC' : 'backup';
    $configured = rtrim((string) ($settings[$setting] ?? ''), '/');
    $root = realpath($configured);
    if ($root === false || !is_dir($root)) {
        throw new HttpException(
            "Folder {$label} tidak tersedia atau tidak dapat diakses oleh proses web.",
            404
        );
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

function storageEntryPath(string $root, string $relative): string
{
    $relative = storageRelativePath($relative);
    if ($relative === '') {
        throw new HttpException('Pilih file atau folder yang akan didownload.', 400);
    }
    $candidate = $root . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $resolved = realpath($candidate);
    if (
        $resolved === false
        || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)
        || (!is_file($resolved) && !is_dir($resolved))
        || is_link($candidate)
    ) {
        throw new HttpException('File atau folder tidak ditemukan.', 404);
    }
    return $resolved;
}

function sendStorageDownload(string $entry): never
{
    $download = $entry;
    $temporary = null;
    $filename = preg_replace(
        '/[\x00-\x1F\x7F"\\\\]/',
        '_',
        basename($entry)
    ) ?: 'download';

    if (is_dir($entry)) {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException(
                'Ekstensi PHP Zip belum tersedia. Jalankan kembali script install.'
            );
        }
        $temporaryBase = tempnam(sys_get_temp_dir(), 'jbackup-download-');
        if ($temporaryBase === false) {
            throw new RuntimeException('File ZIP sementara tidak dapat dibuat.');
        }
        @unlink($temporaryBase);
        $temporary = $temporaryBase . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Arsip ZIP sementara tidak dapat dibuka.');
        }

        $prefix = basename($entry);
        $zip->addEmptyDir($prefix);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $entry,
                FilesystemIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                continue;
            }
            $relative = substr($item->getPathname(), strlen($entry) + 1);
            $archivePath = $prefix . '/'
                . str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            if ($item->isDir()) {
                $zip->addEmptyDir($archivePath);
            } elseif (!$zip->addFile($item->getPathname(), $archivePath)) {
                $zip->close();
                @unlink($temporary);
                throw new RuntimeException(
                    'Salah satu file di dalam folder tidak dapat dimasukkan ke ZIP.'
                );
            }
        }
        if (!$zip->close()) {
            @unlink($temporary);
            throw new RuntimeException('Arsip ZIP sementara tidak dapat diselesaikan.');
        }
        $download = $temporary;
        $filename .= '.zip';
    }

    header_remove('Content-Type');
    header(
        'Content-Type: '
        . (is_dir($entry) ? 'application/zip' : 'application/octet-stream')
    );
    header(
        'Content-Disposition: attachment; filename="'
        . addcslashes($filename, "\\\"")
        . '"'
    );
    header('Content-Length: ' . filesize($download));
    header('Cache-Control: private, no-store');
    readfile($download);
    if ($temporary !== null) {
        @unlink($temporary);
    }
    exit;
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
            'size' => $item->isFile()
                ? $item->getSize()
                : storageDirectorySize($real),
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

function storageDirectorySize(string $directory): int
{
    $size = 0;
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $item) {
            if (!$item->isLink() && $item->isFile()) {
                $size += $item->getSize();
            }
        }
    } catch (UnexpectedValueException) {
        // Folder yang sebagian tidak dapat dibaca tetap dapat ditampilkan.
    }
    return $size;
}

function deleteStorageEntry(string $entry): void
{
    if (is_file($entry) || is_link($entry)) {
        if (!unlink($entry)) {
            throw new RuntimeException('File tidak dapat dihapus.');
        }
        return;
    }
    if (!is_dir($entry)) {
        throw new RuntimeException('File atau folder tidak ditemukan.');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($entry, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $path = $item->getPathname();
        if ($item->isDir() && !$item->isLink()) {
            if (!rmdir($path)) {
                throw new RuntimeException('Salah satu folder tidak dapat dihapus.');
            }
        } elseif (!unlink($path)) {
            throw new RuntimeException('Salah satu file tidak dapat dihapus.');
        }
    }
    if (!rmdir($entry)) {
        throw new RuntimeException('Folder tidak dapat dihapus.');
    }
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
            $source = str_replace(
                ['\\040', '\\011', '\\134'],
                [' ', "\t", '\\'],
                $parts[0]
            );
            $mountPoint = str_replace(
                ['\\040', '\\011', '\\134'],
                [' ', "\t", '\\'],
                $parts[1]
            );
            $physicalBlockDevice = str_starts_with($source, '/dev/');
            $wslPhysicalDrive = preg_match(
                '#^/mnt/[a-z]$#i',
                $mountPoint
            ) === 1 && (
                in_array($parts[2], ['9p', 'drvfs'], true)
                || preg_match('/^[a-z]:/i', $source) === 1
            );
            if ($physicalBlockDevice || $wslPhysicalDrive) {
                $candidates[] = $mountPoint;
            }
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
        if (empty($settings['telegram_bot_token'])) {
            $settings['telegram_bot_token'] = (string) ($secretStore->get('telegram_bot_token') ?? '');
        }
        $connection = sshConnectionInfo($database, $settings);
        $settings['ssh_password_saved'] = $secretStore->has('ssh_password');
        $settings['telegram_bot_token_saved'] = $secretStore->has('telegram_bot_token');
        $settings['ssh_connected'] = $connection['connected'];
        $settings['ssh_connected_target'] = $connection['target'];
        $settings['ssh_connected_at'] = $connection['connected_at'];
        $pathChecks = [
            'rsync' => $database->latestPathCheck('rsync'),
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
            'telegram_backup_file_next_run' => $database->telegramBackupFileNextRun(),
            'worker_heartbeat' => $database->schedulerState('worker_heartbeat'),
        ]);
    }

    if ($action === 'disk_list') {
        requireMethod('GET');
        respond(['disks' => mountedDisks($database->settings())]);
    }

    if ($action === 'backup_list' || $action === 'rsync_list') {
        requireMethod('GET');
        $kind = str_starts_with($action, 'rsync') ? 'rsync' : 'backup';
        respond(storageListing(
            explorerRoot($database->settings(), $kind),
            (string) ($_GET['path'] ?? '')
        ));
    }

    if ($action === 'backup_download' || $action === 'rsync_download') {
        requireMethod('GET');
        $kind = str_starts_with($action, 'rsync') ? 'rsync' : 'backup';
        $entry = storageEntryPath(
            explorerRoot($database->settings(), $kind),
            (string) ($_GET['path'] ?? '')
        );
        sendStorageDownload($entry);
    }

    if ($action === 'backup_delete' || $action === 'rsync_delete') {
        requireMethod('POST');
        $payload = input();
        $kind = str_starts_with($action, 'rsync') ? 'rsync' : 'backup';
        $entry = storageEntryPath(
            explorerRoot($database->settings(), $kind),
            (string) ($payload['path'] ?? '')
        );
        $name = basename($entry);
        deleteStorageEntry($entry);
        respond(['deleted' => $name]);
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
        $entry = storageEntryPath(
            storageRoot($settings),
            (string) ($_GET['path'] ?? '')
        );
        sendStorageDownload($entry);
    }

    if ($action === 'storage_upload') {
        requireMethod('POST');
        $settings = $database->settings();
        $kind = (string) ($_POST['kind'] ?? 'backup');
        if (!in_array($kind, ['backup', 'rsync'], true)) {
            throw new HttpException('Jenis folder upload tidak valid.', 400);
        }
        $root = explorerRoot($settings, $kind);
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
            || preg_match('/[\x00-\x1F\x7F]/', $name)
        ) {
            throw new RuntimeException('Nama file upload tidak valid.');
        }
        if ($kind === 'backup' && !preg_match('/\.7z$/i', $name)) {
            throw new RuntimeException(
                'Hanya file BACKUP .7z yang dapat diupload ke folder BACKUP.'
            );
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
            @chmod($destination, 0777);
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

    if ($action === 'source_export') {
        requireMethod('GET');
        sendSourceExport($database->sources());
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
        $createdCount = 0;
        $updatedCount = 0;
        $errors = [];
        foreach ($rows as $row) {
            $line = (int) $row['_row'];
            unset($row['_row']);
            try {
                $existing = $database->sourceByName((string) ($row['name'] ?? ''));
                $imported[] = $database->upsertSourceByName($row);
                $existing === null ? $createdCount++ : $updatedCount++;
            } catch (PDOException $error) {
                $msg = $error->getMessage();
                $message = 'Database menolak baris ini.';
                if ($error->getCode() === '23000') {
                    if (str_contains($msg, 'database_entries.name')) {
                        $message = 'Nama sumber sudah terdaftar.';
                    } elseif (str_contains($msg, 'source_paths.remote_path')) {
                        $message = 'Path remote duplikat pada sumber ini.';
                    } elseif (str_contains($msg, 'source_paths.alias')) {
                        $message = 'Alias path duplikat pada sumber ini.';
                    } else {
                        $message = 'Data atau kode sumber sudah terdaftar.';
                    }
                }
                $errors[] = [
                    'row' => $line,
                    'name' => (string) ($row['name'] ?? ''),
                    'message' => $message,
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
            'created_count' => $createdCount,
            'updated_count' => $updatedCount,
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
        $telegramToken = (string) ($payload['telegram_bot_token'] ?? '');
        if (isset($payload['telegram_enabled'])) {
            $payload['telegram_enabled'] = (isset($payload['telegram_enabled']) && in_array(strtolower((string) $payload['telegram_enabled']), ['1', 'true', 'on', 'yes'], true)) ? '1' : '0';
        }
        if (isset($payload['telegram_standby_enabled'])) {
            $payload['telegram_standby_enabled'] = (isset($payload['telegram_standby_enabled']) && in_array(strtolower((string) $payload['telegram_standby_enabled']), ['1', 'true', 'on', 'yes'], true)) ? '1' : '0';
        }
        foreach ([
            'telegram_field_waktu',
            'telegram_field_cpu',
            'telegram_field_memory',
            'telegram_field_job',
            'telegram_field_tipe',
            'telegram_field_disk',
            'telegram_field_anydesk',
            'telegram_field_sumber',
            'telegram_field_info',
        ] as $fieldKey) {
            if (isset($payload[$fieldKey])) {
                $payload[$fieldKey] = (isset($payload[$fieldKey]) && in_array(strtolower((string) $payload[$fieldKey]), ['1', 'true', 'on', 'yes'], true)) ? '1' : '0';
            }
        }
        if (isset($payload['telegram_backup_file_enabled'])) {
            $payload['telegram_backup_file_enabled'] = in_array(
                strtolower((string) $payload['telegram_backup_file_enabled']),
                ['1', 'true', 'on', 'yes'],
                true
            ) ? '1' : '0';
        }
        unset(
            $payload['ssh_password'],
            $payload['ssh_password_saved'],
            $payload['telegram_bot_token_saved']
        );
        $validatedSettings = validateSettings($payload);
        if ($sshPassword !== '') {
            if (strlen($sshPassword) > 1024) {
                throw new RuntimeException('Password SSH terlalu panjang.');
            }
            $secretStore->set('ssh_password', $sshPassword);
        }
        if ($telegramToken !== '') {
            if (
                strlen($telegramToken) > 256
                || !preg_match('/^[0-9]+:[A-Za-z0-9_-]+$/', $telegramToken)
            ) {
                throw new RuntimeException('Token bot Telegram tidak valid.');
            }
            $secretStore->set('telegram_bot_token', $telegramToken);
        }
        $settings = publicSettings(
            $database->updateSettings($validatedSettings)
        );
        if (array_intersect(
            array_keys($validatedSettings),
            [
                'telegram_backup_file_enabled',
                'telegram_backup_file_interval',
                'telegram_backup_file_interval_unit',
                'telegram_backup_file_start_time',
            ]
        ) !== []) {
            $database->deleteSchedulerState('telegram_backup_file_last_sent');
        }
        foreach ([
            'rsync' => 'rsync_dir',
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

    if ($action === 'telegram_token_delete') {
        requireMethod('POST');
        $secretStore->delete('telegram_bot_token');
        respond(['ok' => true]);
    }

    if ($action === 'telegram_chat_id_lookup') {
        requireMethod('POST');
        $payload = input();
        $token = trim((string) ($payload['telegram_bot_token'] ?? ''));
        if ($token === '') {
            $token = (string) ($secretStore->get('telegram_bot_token') ?? '');
        }
        if (
            $token === ''
            || strlen($token) > 256
            || !preg_match('/^[0-9]+:[A-Za-z0-9_-]+$/', $token)
        ) {
            throw new RuntimeException('Bot Token Telegram wajib diisi dan harus valid.');
        }

        $curl = curl_init('https://api.telegram.org/bot' . $token . '/getUpdates');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($body === false || $error !== '') {
            throw new RuntimeException('Gagal menghubungi Telegram: ' . ($error ?: 'respons kosong'));
        }
        $decoded = json_decode((string) $body, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            throw new RuntimeException('Telegram menolak permintaan: ' . (string) ($decoded['description'] ?? "HTTP {$status}"));
        }
        $updates = array_reverse((array) ($decoded['result'] ?? []));
        foreach ($updates as $update) {
            $chat = $update['message']['chat'] ?? $update['channel_post']['chat'] ?? null;
            if (is_array($chat) && isset($chat['id'])) {
                respond([
                    'chat_id' => (string) $chat['id'],
                    'chat_name' => (string) ($chat['title'] ?? $chat['username'] ?? $chat['first_name'] ?? ''),
                ]);
            }
        }
        throw new RuntimeException('Belum ada pesan ke bot. Buka chat bot Telegram, tekan Start atau kirim pesan, lalu coba lagi.');
    }

    if ($action === 'telegram_test') {
        requireMethod('POST');
        $payload = input();
        $token = trim((string) ($payload['telegram_bot_token'] ?? ''));
        $chatId = trim((string) ($payload['telegram_chat_id'] ?? ''));
        if ($chatId === '') {
            throw new RuntimeException('Chat ID Telegram wajib diisi.');
        }
        if ($token !== '') {
            if (
                strlen($token) > 256
                || !preg_match('/^[0-9]+:[A-Za-z0-9_-]+$/', $token)
            ) {
                throw new RuntimeException('Token bot Telegram tidak valid.');
            }
            $secretStore->set('telegram_bot_token', $token);
        } else {
            $token = (string) ($secretStore->get('telegram_bot_token') ?? '');
        }
        if ($token === '') {
            throw new RuntimeException('Token bot Telegram wajib diisi.');
        }
        $database->updateSettings(validateSettings([
            'telegram_enabled' => true,
            'telegram_chat_id' => $chatId,
        ]));

        $stateKey = 'telegram_msg_active';
        $lastMessageId = $database->schedulerState($stateKey);
        $formattedChatId = is_numeric($chatId) ? (int) $chatId : $chatId;

        $testText = implode("\n", [
            'J-BACKUP v.2.7.0',
            '=================================',
            'Waktu     : ' . date('d-m-Y H:i'),
            'Tipe          : Uji Coba Telegram',
            'Info          : Telegram Berhasil Terhubung',
            '=================================',
        ]);

        if ($lastMessageId !== null && trim($lastMessageId) !== '') {
            $editCurl = curl_init('https://api.telegram.org/bot' . trim($token) . '/editMessageText');
            curl_setopt_array($editCurl, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode([
                    'chat_id' => $formattedChatId,
                    'message_id' => (int) $lastMessageId,
                    'text' => $testText,
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
                    respond(['ok' => true]);
                    return;
                }
                if (str_contains(strtolower((string) $editResponse), 'message is not modified')) {
                    respond(['ok' => true]);
                    return;
                }
            }
        }

        $curl = curl_init('https://api.telegram.org/bot' . trim($token) . '/sendMessage');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'chat_id' => $formattedChatId,
                'text' => $testText,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($body === false || $error !== '') {
            throw new RuntimeException('Gagal menghubungi Telegram: ' . ($error ?: 'respons kosong'));
        }
        $decoded = json_decode((string) $body, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            throw new RuntimeException('Telegram menolak tes: ' . (string) ($decoded['description'] ?? "HTTP {$status}"));
        }
        $newMessageId = $decoded['result']['message_id'] ?? null;
        if ($newMessageId !== null) {
            $database->setSchedulerState($stateKey, (string) $newMessageId);
        }
        respond(['ok' => true]);
    }

    if ($action === 'ssh_password_reveal') {
        requireMethod('POST');
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
        if (!in_array($kind, ['rsync', 'backup'], true)) {
            throw new HttpException('Jenis folder tidak dikenal.', 422);
        }
        validateSettings([
            $kind === 'rsync' ? 'rsync_dir' : 'backup_dir' => $path,
        ]);
        $task = $database->createPathTask($kind, $path);
        $lastHeartbeat = $database->schedulerState('worker_heartbeat');
        $ts = is_string($lastHeartbeat) ? strtotime($lastHeartbeat) : false;
        if ($ts === false || (time() - $ts) > 45) {
            require_once __DIR__ . '/src/JobRunner.php';
            $runner = new \JBackup\JobRunner(
                $database,
                $container['data_directory'],
                false,
                $secretStore
            );
            $runner->run();
            $task = $database->pathTask((string) $task['id']) ?? $task;
        }
        respond([
            'task' => $task,
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

    if ($action === 'job_status') {
        requireMethod('GET');
        $job = $database->job((string) ($_GET['id'] ?? ''));
        if (!$job) {
            throw new HttpException('Pekerjaan tidak ditemukan.', 404);
        }
        respond(['job' => $job]);
    }

    if ($action === 'job_cancel') {
        requireMethod('POST');
        $payload = input();
        if (!$database->cancelJob((string) ($payload['id'] ?? ''))) {
            throw new HttpException('Pekerjaan aktif atau antrean tidak ditemukan.', 404);
        }
        respond(['ok' => true]);
    }

    if ($action === 'jobs_cancel_all') {
        requireMethod('POST');
        respond([
            'ok' => true,
            ...$database->cancelAllJobs(),
        ]);
    }

    if ($action === 'jobs_history_clear') {
        requireMethod('POST');
        $payload = input();
        respond([
            'ok' => true,
            'deleted' => $database->clearJobHistory((string) ($payload['status'] ?? 'all')),
        ]);
    }

    if ($action === 'update_check') {
        requireMethod('GET');
        require_once __DIR__ . '/src/UpdateChecker.php';
        respond(UpdateChecker::latest(
            JBACKUP_GITHUB_REPOSITORY,
            JBACKUP_VERSION
        ));
    }

    if ($action === 'update_progress') {
        requireMethod('GET');
        $progressFile = rtrim((string) $container['data_directory'], '/\\')
            . '/update-progress.json';
        $progress = is_file($progressFile)
            ? json_decode((string) file_get_contents($progressFile), true)
            : null;
        respond(is_array($progress) ? $progress : [
            'stage' => 'idle',
            'percent' => 0,
            'message' => '',
            'bytes_received' => 0,
            'bytes_total' => 0,
        ]);
    }

    if ($action === 'update_apply') {
        requireMethod('POST');
        $activeCount = (int) $database->pdo()->query(
            "SELECT (
                (SELECT COUNT(*) FROM jobs
                 WHERE status IN ('queued', 'running', 'cancel_requested'))
                + (SELECT COUNT(*) FROM ssh_tasks
                   WHERE status IN ('queued', 'running'))
                + (SELECT COUNT(*) FROM path_tasks
                   WHERE status IN ('queued', 'running'))
            )"
        )->fetchColumn();
        if ($activeCount > 0) {
            throw new HttpException(
                'Update ditolak karena masih ada pekerjaan aktif atau antrean.',
                409
            );
        }
        $script = __DIR__ . '/scripts/update-linux.sh';
        if (PHP_OS_FAMILY !== 'Linux') {
            throw new HttpException(
                'Pemasangan update otomatis hanya tersedia pada server Linux.',
                409
            );
        }
        if (!is_file($script)) {
            throw new RuntimeException('Skrip update-linux.sh tidak ditemukan.');
        }
        if (!function_exists('exec')) {
            throw new RuntimeException('Fungsi exec PHP tidak tersedia untuk menjalankan updater.');
        }
        @chmod($script, 0755);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        ignore_user_abort(true);
        @set_time_limit(0);
        $command = sprintf(
            'JBACKUP_DATA_DIR=%s JBACKUP_GITHUB_REPOSITORY=%s /bin/bash %s 2>&1',
            escapeshellarg((string) $container['data_directory']),
            escapeshellarg(JBACKUP_GITHUB_REPOSITORY),
            escapeshellarg($script)
        );
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            $detail = trim(implode("\n", $output));
            throw new RuntimeException(
                $detail !== ''
                    ? 'Update gagal: ' . substr($detail, -1500)
                    : "Update gagal dengan kode {$exitCode}."
            );
        }
        $installedVersion = JBACKUP_VERSION;
        $versionFile = __DIR__ . '/version.json';
        if (is_file($versionFile)) {
            $versionPayload = json_decode((string) file_get_contents($versionFile), true);
            $installedVersion = (string) ($versionPayload['version'] ?? $installedVersion);
        }
        respond([
            'ok' => true,
            'version' => $installedVersion,
            'log' => implode("\n", $output),
        ]);
    }

    throw new HttpException('Endpoint tidak ditemukan.', 404);
} catch (HttpException $error) {
    respond(['error' => $error->getMessage()], $error->status);
} catch (PDOException $error) {
    error_log(sprintf(
        '[J-BACKUP] database error action=%s code=%s message=%s',
        isset($action) ? (string) $action : 'unknown',
        (string) $error->getCode(),
        $error->getMessage()
    ));
    $status = $error->getCode() === '23000' ? 409 : 500;
    $message = 'Database internal mengalami kesalahan.';
    if ($status === 409) {
        $msg = $error->getMessage();
        if (str_contains($msg, 'database_entries.name')) {
            $message = 'Nama sumber tersebut sudah terdaftar.';
        } elseif (str_contains($msg, 'database_entries.source_code')) {
            $message = 'Kode sumber tersebut sudah terdaftar.';
        } elseif (str_contains($msg, 'source_paths.remote_path')) {
            $message = 'Path remote duplikat pada sumber ini.';
        } elseif (str_contains($msg, 'source_paths.alias')) {
            $message = 'Alias path duplikat pada sumber ini.';
        } elseif (str_contains($msg, 'users.username')) {
            $message = 'Username administrator tersebut sudah terdaftar.';
        } else {
            $message = 'Gagal menyimpan ke database: ' . $error->getMessage();
        }
    }
    respond(['error' => $message], $status);
} catch (Throwable $error) {
    respond(['error' => $error->getMessage()], 400);
}
