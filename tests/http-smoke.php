<?php

declare(strict_types=1);

function assertHttp(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeHttpTree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($directory);
}

$root = sys_get_temp_dir() . '/jbackup-http-' . bin2hex(random_bytes(6));
mkdir($root, 0770, true);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
if (!$socket) {
    throw new RuntimeException($errorMessage, $errorNumber);
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$port = (int) substr(strrchr($address, ':'), 1);
$baseUrl = "http://127.0.0.1:{$port}";
$webDirectory = dirname(__DIR__);
$logFile = $root . '/server.log';

$process = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $webDirectory],
    [
        0 => ['pipe', 'r'],
        1 => ['file', $logFile, 'a'],
        2 => ['file', $logFile, 'a'],
    ],
    $pipes,
    dirname(__DIR__),
    ['JBACKUP_DATA_DIR' => $root . '/data'],
    ['bypass_shell' => true]
);
if (!is_resource($process)) {
    throw new RuntimeException('Server PHP test tidak dapat dimulai.');
}
fclose($pipes[0]);

$cookie = '';
$csrf = '';
$request = static function (
    string $action,
    string $method = 'GET',
    ?array $payload = null,
    array $query = []
) use (&$cookie, &$csrf, $baseUrl): array {
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        "Origin: {$baseUrl}",
    ];
    if ($cookie !== '') {
        $headers[] = "Cookie: {$cookie}";
    }
    if ($csrf !== '') {
        $headers[] = "X-CSRF-Token: {$csrf}";
    }
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $payload === null
                ? ''
                : json_encode($payload, JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
            'timeout' => 3,
        ],
    ]);
    $body = @file_get_contents(
        "{$baseUrl}/api.php?" . http_build_query([
            'action' => $action,
            ...$query,
        ]),
        false,
        $context
    );
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    foreach ($responseHeaders as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#i', $header, $matches)) {
            $status = (int) $matches[1];
        }
        if (stripos($header, 'Set-Cookie:') === 0) {
            $cookie = explode(';', trim(substr($header, 11)))[0];
        }
    }
    return [
        'status' => $status,
        'body' => is_string($body) ? json_decode($body, true) : null,
    ];
};

$rawRequest = static function (
    string $action,
    string $method = 'GET',
    string $content = '',
    string $contentType = 'application/octet-stream',
    array $query = []
) use (&$cookie, &$csrf, $baseUrl): array {
    $headers = [
        "Origin: {$baseUrl}",
        "Content-Type: {$contentType}",
    ];
    if ($cookie !== '') {
        $headers[] = "Cookie: {$cookie}";
    }
    if ($csrf !== '') {
        $headers[] = "X-CSRF-Token: {$csrf}";
    }
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'ignore_errors' => true,
            'timeout' => 3,
        ],
    ]);
    $body = @file_get_contents(
        "{$baseUrl}/api.php?" . http_build_query([
            'action' => $action,
            ...$query,
        ]),
        false,
        $context
    );
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#i', $header, $matches)) {
            $status = (int) $matches[1];
        }
    }
    return ['status' => $status, 'body' => $body];
};

try {
    $home = null;
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $home = @file_get_contents($baseUrl . '/');
        if (is_string($home)) {
            break;
        }
        usleep(50000);
    }
    assertHttp(is_string($home), 'Halaman utama tidak merespons.');
    assertHttp(str_contains($home, '<title>J-BACKUP</title>'), 'Judul halaman tidak benar.');
    assertHttp(str_contains($home, '/og.png'), 'Social preview belum terhubung.');
    assertHttp(
        str_contains($home, 'assets/fonts/Sora-Variable.ttf')
            && str_contains($home, 'assets/fonts/Oxanium-Variable.ttf')
            && str_contains($home, 'assets/fonts/JetBrainsMono-Variable.ttf'),
        'Preload font lokal belum terhubung.'
    );
    $fontFile = @file_get_contents($baseUrl . '/assets/fonts/Sora-Variable.ttf');
    assertHttp(
        is_string($fontFile) && strlen($fontFile) > 10000,
        'Font lokal tidak dapat diakses.'
    );

    $status = null;
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $status = $request('status');
        if ($status['status'] === 200) {
            break;
        }
        usleep(50000);
    }
    assertHttp($status['status'] === 200, 'Endpoint status tidak merespons.');
    assertHttp($status['body']['setup_required'] === true, 'Setup awal tidak terdeteksi.');
    $csrf = $status['body']['csrf_token'];

    $setup = $request('setup', 'POST', [
        'username' => 'admin',
        'password' => 'x',
    ]);
    assertHttp(
        $setup['status'] === 201,
        'Setup administrator dengan password 1 karakter gagal.'
    );
    $csrf = $setup['body']['csrf_token'];

    $initialDashboard = $request('dashboard');
    $applicationRoot = str_replace('\\', '/', dirname(__DIR__));
    assertHttp(
        $initialDashboard['status'] === 200
            && $initialDashboard['body']['settings']['remote_user'] === 'root'
            && $initialDashboard['body']['settings']['staging_dir']
                === $applicationRoot . '/Realtime-Data'
            && $initialDashboard['body']['settings']['backup_dir']
                === $applicationRoot . '/Hasil-Backup'
            && array_key_exists('system', $initialDashboard['body'])
            && array_key_exists('memory', $initialDashboard['body']['system']),
        'Default user SSH dan folder aplikasi pertama tidak benar.'
    );

    $wrongAccountPassword = $request('account_update', 'POST', [
        'username' => 'operator',
        'current_password' => 'salah',
        'new_password' => '',
        'session_timeout_minutes' => 15,
    ]);
    assertHttp(
        $wrongAccountPassword['status'] === 400,
        'Pengaturan akun menerima password saat ini yang salah.'
    );
    $accountUpdate = $request('account_update', 'POST', [
        'username' => 'operator',
        'current_password' => 'x',
        'new_password' => 'y',
        'session_timeout_minutes' => 15,
    ]);
    assertHttp(
        $accountUpdate['status'] === 200
            && $accountUpdate['body']['user']['username'] === 'operator'
            && $accountUpdate['body']['session_timeout_minutes'] === 15,
        'Username, password, atau timeout sesi gagal diperbarui.'
    );
    $accountDashboard = $request('dashboard');
    assertHttp(
        $accountDashboard['status'] === 200
            && $accountDashboard['body']['user']['username'] === 'operator'
            && $accountDashboard['body']['settings']['session_timeout_minutes']
                === 15,
        'Dashboard tidak memuat pengaturan akun terbaru.'
    );

    $backupRoot = $root . '/backups';
    $realtimeRoot = $root . '/realtime';
    mkdir($backupRoot . '/2026/07/30', 0770, true);
    mkdir($realtimeRoot . '/source-one', 0770, true);
    file_put_contents(
        $backupRoot . '/2026/07/30/sample-backup.7z',
        'simulated-7z-content'
    );
    file_put_contents($realtimeRoot . '/source-one/data.txt', 'realtime-content');
    $settings = $request('settings_update', 'POST', [
        'backup_dir' => $backupRoot,
        'staging_dir' => $realtimeRoot,
        'remote_host' => '127.0.0.1',
        'remote_port' => 22,
        'remote_user' => 'backup',
        'ssh_key_path' => $root . '/data/.ssh/id_ed25519',
    ]);
    assertHttp($settings['status'] === 200, 'Folder backup untuk explorer gagal disimpan.');

    $pathTask = $request('path_task_create', 'POST', [
        'kind' => 'backup',
        'path' => $backupRoot,
    ]);
    assertHttp(
        $pathTask['status'] === 202
            && $pathTask['body']['task']['kind'] === 'backup'
            && $pathTask['body']['task']['status'] === 'queued',
        'Pengujian akses folder tidak dapat dimasukkan ke antrean worker.'
    );
    $pathTaskStatus = $request(
        'path_task_status',
        'GET',
        null,
        ['id' => $pathTask['body']['task']['id']]
    );
    assertHttp(
        $pathTaskStatus['status'] === 200
            && $pathTaskStatus['body']['task']['path'] === $backupRoot,
        'Status pengujian akses folder tidak dapat dibaca.'
    );

    $diskList = $request('disk_list');
    assertHttp(
        $diskList['status'] === 200 && is_array($diskList['body']['disks']),
        'Daftar disk host tidak dapat dibaca.'
    );
    foreach ($diskList['body']['disks'] as $disk) {
        assertHttp(
            !preg_match('#^/(?:proc|sys|dev|run|usr/lib/wsl)(?:/|$)#', (string) $disk['path']),
            'Daftar disk host masih memuat filesystem virtual.'
        );
    }

    $backupList = $request('backup_list');
    assertHttp(
        $backupList['status'] === 200
            && $backupList['body']['entries'][0]['name'] === '2026',
        'Explorer Backup baru tidak menampilkan folder tujuan.'
    );
    $realtimeList = $request(
        'realtime_list',
        'GET',
        null,
        ['path' => 'source-one']
    );
    assertHttp(
        $realtimeList['status'] === 200
            && $realtimeList['body']['entries'][0]['name'] === 'data.txt',
        'Explorer Realtime tidak menampilkan hasil sinkronisasi.'
    );

    $storageRoot = $request('storage_list');
    assertHttp(
        $storageRoot['status'] === 200
            && $storageRoot['body']['entries'][0]['name'] === '2026'
            && $storageRoot['body']['entries'][0]['type'] === 'directory',
        'File explorer tidak menampilkan folder backup.'
    );
    $storageDay = $request(
        'storage_list',
        'GET',
        null,
        ['path' => '2026/07/30']
    );
    assertHttp(
        $storageDay['status'] === 200
            && $storageDay['body']['entries'][0]['name'] === 'sample-backup.7z',
        'File explorer tidak menampilkan arsip backup.'
    );
    $blockedTraversal = $request(
        'storage_list',
        'GET',
        null,
        ['path' => '../']
    );
    assertHttp(
        $blockedTraversal['status'] === 400,
        'File explorer menerima path traversal.'
    );
    $download = $rawRequest(
        'storage_download',
        'GET',
        '',
        'application/octet-stream',
        ['path' => '2026/07/30/sample-backup.7z']
    );
    assertHttp(
        $download['status'] === 200
            && $download['body'] === 'simulated-7z-content',
        'Download file backup gagal.'
    );
    $folderDownload = $rawRequest(
        'realtime_download',
        'GET',
        '',
        'application/octet-stream',
        ['path' => 'source-one']
    );
    $downloadedZip = $root . '/downloaded-realtime.zip';
    file_put_contents($downloadedZip, $folderDownload['body']);
    $zip = new ZipArchive();
    $zipOpened = $folderDownload['status'] === 200
        && $zip->open($downloadedZip) === true;
    $zippedRealtime = $zipOpened
        ? $zip->getFromName('source-one/data.txt')
        : false;
    if ($zipOpened) {
        $zip->close();
    }
    assertHttp(
        $zipOpened && $zippedRealtime === 'realtime-content',
        'Download folder realtime sebagai ZIP gagal.'
    );

    $boundary = '----JBackupTest' . bin2hex(random_bytes(6));
    $uploadContent = 'uploaded-7z-content';
    $multipart = "--{$boundary}\r\n"
        . "Content-Disposition: form-data; name=\"path\"\r\n\r\n"
        . "2026/07/30\r\n"
        . "--{$boundary}\r\n"
        . "Content-Disposition: form-data; name=\"file\"; filename=\"uploaded-backup.7z\"\r\n"
        . "Content-Type: application/x-7z-compressed\r\n\r\n"
        . $uploadContent . "\r\n"
        . "--{$boundary}--\r\n";
    $upload = $rawRequest(
        'storage_upload',
        'POST',
        $multipart,
        "multipart/form-data; boundary={$boundary}"
    );
    assertHttp(
        $upload['status'] === 201
            && file_get_contents($backupRoot . '/2026/07/30/uploaded-backup.7z')
                === $uploadContent,
        'Upload file backup gagal.'
    );
    $realtimeUploadContent = 'uploaded-realtime-content';
    $realtimeMultipart = "--{$boundary}\r\n"
        . "Content-Disposition: form-data; name=\"kind\"\r\n\r\n"
        . "realtime\r\n"
        . "--{$boundary}\r\n"
        . "Content-Disposition: form-data; name=\"path\"\r\n\r\n"
        . "source-one\r\n"
        . "--{$boundary}\r\n"
        . "Content-Disposition: form-data; name=\"file\"; filename=\"uploaded.txt\"\r\n"
        . "Content-Type: text/plain\r\n\r\n"
        . $realtimeUploadContent . "\r\n"
        . "--{$boundary}--\r\n";
    $realtimeUpload = $rawRequest(
        'storage_upload',
        'POST',
        $realtimeMultipart,
        "multipart/form-data; boundary={$boundary}"
    );
    assertHttp(
        $realtimeUpload['status'] === 201
            && file_get_contents($realtimeRoot . '/source-one/uploaded.txt')
                === $realtimeUploadContent,
        'Upload file realtime gagal.'
    );

    $source = $request('source_create', 'POST', [
        'name' => 'Sumber HTTP',
        'archive_mode' => 'separate',
        'paths' => [
            '/var/lib/mysql/cusj_http_test',
            ['alias' => 'website', 'path' => '/var/www/example.com'],
        ],
    ]);
    assertHttp(
        $source['status'] === 201
            && count($source['body']['source']['paths']) === 2
            && $source['body']['source']['archive_mode'] === 'separate',
        'Input sumber universal melalui API gagal.'
    );
    $sourceUpdate = $request('source_update', 'POST', [
        'id' => $source['body']['source']['id'],
        'archive_mode' => 'combined',
        'paths' => ['/srv/data/dokumen'],
    ]);
    assertHttp(
        $sourceUpdate['status'] === 200
            && $sourceUpdate['body']['source']['archive_mode'] === 'combined'
            && $sourceUpdate['body']['source']['paths'][0]['path']
                === '/srv/data/dokumen',
        'Perubahan path sumber melalui API gagal.'
    );

    $schedule = $request('schedule_update', 'POST', [
        'type' => 'sync',
        'enabled' => true,
        'mode' => 'minutes',
        'interval_value' => 15,
    ]);
    assertHttp($schedule['status'] === 200, 'Jadwal interval gagal disimpan.');
    assertHttp(
        $schedule['body']['schedule']['mode'] === 'minutes'
            && $schedule['body']['schedule']['interval_value'] === 15,
        'Jadwal interval dari API tidak benar.'
    );

    $sshTask = $request('ssh_task_create', 'POST', [
        'type' => 'generate_key',
        'ssh_key_path' => $root . '/data/.ssh/id_ed25519',
        'ssh_key_type' => 'rsa4096',
        'ssh_key_comment' => 'Jeriyant-Key-RSA',
        'install_key' => true,
        'password' => 'password-sementara',
    ]);
    assertHttp($sshTask['status'] === 202, 'Tindakan SSH tidak masuk antrean.');
    assertHttp(
        $sshTask['body']['task']['status'] === 'queued',
        'Status awal tindakan SSH tidak benar.'
    );
    assertHttp(
        !array_key_exists('secret', $sshTask['body']['task'])
            && !array_key_exists('password', $sshTask['body']['task']['payload']),
        'Password SSH bocor melalui respons API.'
    );
    $secretDatabase = new PDO('sqlite:' . $root . '/data/j-backup.sqlite');
    $encryptedPassword = $secretDatabase->query(
        "SELECT ciphertext FROM encrypted_secrets WHERE name = 'ssh_password'"
    )->fetchColumn();
    assertHttp(
        is_string($encryptedPassword)
            && str_starts_with($encryptedPassword, 'secretbox-v1:')
            && !str_contains($encryptedPassword, 'password-sementara'),
        'Password SSH tidak tersimpan sebagai ciphertext.'
    );
    $revealedPassword = $request('ssh_password_reveal', 'POST', []);
    assertHttp(
        $revealedPassword['status'] === 200
            && $revealedPassword['body']['password'] === 'password-sementara',
        'Password SSH tersimpan tidak dapat ditampilkan kembali.'
    );
    $sshStatus = $request(
        'ssh_task_status',
        'GET',
        null,
        ['id' => $sshTask['body']['task']['id']]
    );
    assertHttp(
        $sshStatus['status'] === 200
            && $sshStatus['body']['task']['id'] === $sshTask['body']['task']['id'],
        'Status tindakan SSH tidak dapat dibaca.'
    );
    $disconnectTask = $request('ssh_task_create', 'POST', [
        'type' => 'disconnect',
        'remote_host' => '127.0.0.1',
        'remote_port' => 22,
        'remote_user' => 'backup',
        'ssh_key_path' => $root . '/data/.ssh/id_ed25519',
    ]);
    assertHttp(
        $disconnectTask['status'] === 202
            && $disconnectTask['body']['task']['type'] === 'disconnect',
        'Tindakan Disconnect SSH tidak diterima API.'
    );

    $dashboard = $request('dashboard');
    assertHttp($dashboard['status'] === 200, 'Dashboard tidak dapat dimuat.');
    assertHttp(
        count($dashboard['body']['sources']) === 1
            && count($dashboard['body']['sources'][0]['paths']) === 1,
        'Sumber input tidak muncul pada dashboard.'
    );
    assertHttp(
        $dashboard['body']['settings']['ssh_password_saved'] === true
            && !array_key_exists('ssh_password', $dashboard['body']['settings']),
        'Status password SSH tersimpan tidak aman.'
    );
    assertHttp(
        $dashboard['body']['settings']['ssh_connected'] === false,
        'Status koneksi SSH awal tidak benar.'
    );
    $syncSchedule = array_values(array_filter(
        $dashboard['body']['schedules'],
        static fn (array $item): bool => $item['type'] === 'sync'
    ))[0];
    assertHttp(
        $syncSchedule['mode'] === 'minutes'
            && $syncSchedule['interval_value'] === 15,
        'Dashboard tidak memuat pola jadwal baru.'
    );
    $weeklySchedule = $request('schedule_update', 'POST', [
        'type' => 'sync',
        'mode' => 'weekly',
    ]);
    assertHttp(
        $weeklySchedule['status'] === 400,
        'Mode hari tertentu masih diterima.'
    );

    $importBoundary = '----JBackupImport' . bin2hex(random_bytes(6));
    $importCsv = "nama_sumber,mode_arsip,subfolder_hasil,path_sumber,aktif\r\n"
        . "Import Dokumen,gabung,dokumen,\"/srv/dokumen|web=/var/www/html\",ya\r\n"
        . "Import Arsip,terpisah,,/srv/arsip,tidak\r\n";
    $importMultipart = "--{$importBoundary}\r\n"
        . "Content-Disposition: form-data; name=\"file\"; filename=\"sumber.csv\"\r\n"
        . "Content-Type: text/csv\r\n\r\n"
        . $importCsv . "\r\n"
        . "--{$importBoundary}--\r\n";
    $sourceImport = $rawRequest(
        'source_import',
        'POST',
        $importMultipart,
        "multipart/form-data; boundary={$importBoundary}"
    );
    $sourceImportBody = is_string($sourceImport['body'])
        ? json_decode($sourceImport['body'], true)
        : null;
    assertHttp(
        $sourceImport['status'] === 201
            && $sourceImportBody['imported_count'] === 2
            && $sourceImportBody['failed_count'] === 0,
        'Import sumber dari tabel CSV gagal.'
    );
    $importedDashboard = $request('dashboard');
    $importedSources = array_column(
        $importedDashboard['body']['sources'],
        null,
        'name'
    );
    assertHttp(
        count($importedSources['Import Dokumen']['paths']) === 2
            && $importedSources['Import Dokumen']['paths'][1]['alias'] === 'web'
            && $importedSources['Import Arsip']['archive_mode'] === 'separate'
            && $importedSources['Import Arsip']['enabled'] === false,
        'Hasil import tidak mengikuti mode, path, alias, atau status aktif.'
    );

    $xlsxFile = $root . '/sumber.xlsx';
    $xlsx = new ZipArchive();
    assertHttp(
        $xlsx->open($xlsxFile, ZipArchive::CREATE | ZipArchive::OVERWRITE)
            === true,
        'Workbook XLSX untuk pengujian tidak dapat dibuat.'
    );
    $xlsx->addFromString(
        'xl/worksheets/sheet1.xml',
        <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
          <sheetData>
            <row r="1">
              <c r="A1" t="inlineStr"><is><t>nama_sumber</t></is></c>
              <c r="B1" t="inlineStr"><is><t>mode_arsip</t></is></c>
              <c r="C1" t="inlineStr"><is><t>subfolder_hasil</t></is></c>
              <c r="D1" t="inlineStr"><is><t>path_sumber</t></is></c>
              <c r="E1" t="inlineStr"><is><t>aktif</t></is></c>
            </row>
            <row r="2">
              <c r="A2" t="inlineStr"><is><t>Import Excel</t></is></c>
              <c r="B2" t="inlineStr"><is><t>gabung</t></is></c>
              <c r="C2" t="inlineStr"><is><t>excel</t></is></c>
              <c r="D2" t="inlineStr"><is><t>/srv/excel&#10;config=/etc/excel</t></is></c>
              <c r="E2" t="inlineStr"><is><t>ya</t></is></c>
            </row>
          </sheetData>
        </worksheet>
        XML
    );
    $xlsx->close();
    $xlsxBoundary = '----JBackupXlsx' . bin2hex(random_bytes(6));
    $xlsxMultipart = "--{$xlsxBoundary}\r\n"
        . "Content-Disposition: form-data; name=\"file\"; filename=\"sumber.xlsx\"\r\n"
        . "Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet\r\n\r\n"
        . file_get_contents($xlsxFile) . "\r\n"
        . "--{$xlsxBoundary}--\r\n";
    $xlsxImport = $rawRequest(
        'source_import',
        'POST',
        $xlsxMultipart,
        "multipart/form-data; boundary={$xlsxBoundary}"
    );
    $xlsxImportBody = is_string($xlsxImport['body'])
        ? json_decode($xlsxImport['body'], true)
        : null;
    assertHttp(
        $xlsxImport['status'] === 201
            && $xlsxImportBody['imported_count'] === 1
            && count($xlsxImportBody['sources'][0]['paths']) === 2,
        'Import sumber dari workbook Excel XLSX gagal.'
    );
    $bulkIds = array_merge(
        array_column($sourceImportBody['sources'], 'id'),
        [$xlsxImportBody['sources'][0]['id']]
    );
    $bulkDelete = $request('sources_delete', 'POST', [
        'ids' => $bulkIds,
    ]);
    assertHttp(
        $bulkDelete['status'] === 200
            && $bulkDelete['body']['deleted_count'] === 3,
        'Penghapusan massal sumber gagal.'
    );
    $dashboardAfterBulkDelete = $request('dashboard');
    assertHttp(
        count($dashboardAfterBulkDelete['body']['sources']) === 1
            && $dashboardAfterBulkDelete['body']['sources'][0]['id']
                === $source['body']['source']['id'],
        'Penghapusan massal ikut menghapus sumber yang tidak dipilih.'
    );

    $managedKey = $root . '/data/.ssh/id_ed25519';
    if (!is_dir(dirname($managedKey))) {
        mkdir(dirname($managedKey), 0770, true);
    }
    file_put_contents($managedKey, 'private-key-test');
    file_put_contents($managedKey . '.pub', 'ssh-ed25519 test-key');
    file_put_contents(dirname($managedKey) . '/id_rsa', 'rsa-private-key-test');
    file_put_contents(dirname($managedKey) . '/id_rsa.pub', 'ssh-rsa test-key');
    file_put_contents(dirname($managedKey) . '/known_hosts', 'host-key-test');
    file_put_contents(
        dirname($managedKey) . '/ssh-copy-id.temporary',
        'temporary-key-test'
    );
    $connectedState = json_encode([
        'connected' => true,
        'host' => '127.0.0.1',
        'port' => 22,
        'user' => 'backup',
        'target' => 'backup@127.0.0.1',
        'connected_at' => date(DATE_ATOM),
    ], JSON_THROW_ON_ERROR);
    $connectedStatement = $secretDatabase->prepare(
        "INSERT INTO scheduler_state(key, value) VALUES ('ssh_connection', ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value"
    );
    $connectedStatement->execute([$connectedState]);
    $connectedDashboard = $request('dashboard');
    assertHttp(
        $connectedDashboard['status'] === 200
            && $connectedDashboard['body']['settings']['ssh_connected'] === true
            && $connectedDashboard['body']['settings']['ssh_connected_target']
                === 'backup@127.0.0.1',
        'Dashboard tidak menampilkan status SSH tersimpan sebagai terhubung.'
    );

    $resetRejected = $request('reset_database', 'POST', [
        'confirmation' => 'reset',
    ]);
    assertHttp(
        $resetRejected['status'] === 422,
        'Reset database menerima konfirmasi yang tidak tepat.'
    );

    $reset = $request('reset_database', 'POST', [
        'confirmation' => 'RESET',
    ]);
    assertHttp(
        $reset['status'] === 200
            && $reset['body']['setup_required'] === true,
        'Reset database melalui API gagal.'
    );
    assertHttp(
        !$secretDatabase->query('SELECT COUNT(*) FROM users')->fetchColumn()
            && !$secretDatabase->query(
                'SELECT COUNT(*) FROM database_entries'
            )->fetchColumn()
            && !$secretDatabase->query(
                'SELECT COUNT(*) FROM source_paths'
            )->fetchColumn()
            && !$secretDatabase->query('SELECT COUNT(*) FROM jobs')->fetchColumn()
            && !$secretDatabase->query('SELECT COUNT(*) FROM ssh_tasks')->fetchColumn()
            && !$secretDatabase->query('SELECT COUNT(*) FROM path_tasks')->fetchColumn()
            && !$secretDatabase->query(
                'SELECT COUNT(*) FROM encrypted_secrets'
            )->fetchColumn(),
        'Reset database tidak membersihkan seluruh data aplikasi.'
    );
    assertHttp(
        iterator_count(new FilesystemIterator(
            dirname($managedKey),
            FilesystemIterator::SKIP_DOTS
        )) === 0,
        'Reset database tidak menghapus seluruh file SSH lokal.'
    );
    assertHttp(
        is_file($backupRoot . '/2026/07/30/sample-backup.7z'),
        'Reset database ikut menghapus file hasil backup.'
    );

    $statusAfterReset = $request('status');
    assertHttp(
        $statusAfterReset['status'] === 200
            && $statusAfterReset['body']['setup_required'] === true
            && $statusAfterReset['body']['authenticated'] === false,
        'Aplikasi tidak kembali ke setup awal setelah reset.'
    );

    fwrite(STDOUT, "HTTP smoke test J-BACKUP lulus.\n");
} finally {
    proc_terminate($process);
    proc_close($process);
    removeHttpTree($root);
}
