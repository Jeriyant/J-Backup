<?php

declare(strict_types=1);

use JBackup\Database;
use JBackup\JobRunner;
use JBackup\SecretStore;

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/SecretStore.php';
require_once dirname(__DIR__) . '/src/JobRunner.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeTree(string $directory): void
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

$root = sys_get_temp_dir() . '/jbackup-test-' . bin2hex(random_bytes(6));
$staging = $root . '/staging';
$backup = $root . '/backup';
mkdir($staging, 0770, true);
mkdir($backup, 0770, true);

try {
    $legacyDatabase = new PDO('sqlite:' . $root . '/j-backup.sqlite');
    $legacyDatabase->exec(
        <<<'SQL'
        CREATE TABLE schedules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT NOT NULL UNIQUE,
            enabled INTEGER NOT NULL DEFAULT 0,
            time TEXT NOT NULL,
            days TEXT NOT NULL DEFAULT '[0,1,2,3,4,5,6]',
            updated_at TEXT NOT NULL
        );
        INSERT INTO schedules(type, enabled, time, days, updated_at)
        VALUES ('backup', 0, '01:00', '[1,3,5]', '2026-01-01T00:00:00+07:00');

        CREATE TABLE ssh_tasks (
            id TEXT PRIMARY KEY,
            type TEXT NOT NULL CHECK(type IN ('generate_key', 'test_connection')),
            status TEXT NOT NULL CHECK(status IN ('queued', 'running', 'success', 'failed')),
            payload TEXT NOT NULL DEFAULT '{}',
            result TEXT,
            error TEXT,
            created_at TEXT NOT NULL,
            started_at TEXT,
            finished_at TEXT
        );
        INSERT INTO ssh_tasks(
            id, type, status, payload, result, created_at, finished_at
        )
        VALUES (
            'legacy-ssh-task', 'test_connection', 'success', '{}',
            '{"connected":true}', '2026-01-01T00:00:00+07:00',
            '2026-01-01T00:00:01+07:00'
        );

        CREATE TABLE database_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE COLLATE NOCASE,
            include_sys INTEGER NOT NULL DEFAULT 1,
            enabled INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );
        INSERT INTO database_entries(
            name, include_sys, enabled, created_at, updated_at
        ) VALUES (
            'legacy_source', 1, 1,
            '2026-01-01T00:00:00+07:00',
            '2026-01-01T00:00:00+07:00'
        );
        SQL
    );
    $legacyDatabase = null;

    $database = new Database($root . '/j-backup.sqlite');
    assertTrue(
        $database->sshTask('legacy-ssh-task')['status'] === 'success',
        'Migrasi tipe task SSH menghilangkan riwayat lama.'
    );
    $legacySource = $database->sources()[0];
    assertTrue(
        $legacySource['source_code'] === 'LEGACY-SOURCE',
        'Migrasi tidak membuat kode sumber stabil untuk data lama.'
    );
    assertTrue(
        array_column($legacySource['paths'], 'path') === [
            '/var/lib/mysql/legacy_source',
            '/var/lib/mysql/legacy_source_sys',
        ],
        'Database lama tidak dimigrasikan menjadi sumber dengan path eksplisit.'
    );
    $legacyId = $legacySource['id'];
    $updatedLegacySource = $database->upsertSourceByCode([
        'source_code' => 'legacy-source',
        'name' => 'Legacy Source Updated',
        'archive_mode' => 'combined',
        'output_subdirectory' => '',
        'paths' => ['/srv/legacy-updated'],
        'enabled' => true,
    ]);
    assertTrue(
        $updatedLegacySource['id'] === $legacyId
            && $updatedLegacySource['name'] === 'Legacy Source Updated'
            && $updatedLegacySource['source_code'] === 'LEGACY-SOURCE',
        'Upsert kode sumber mengganti ID internal atau gagal memperbarui data.'
    );
    $database->deleteSource($legacyId);
    assertTrue(
        $database->settings()['remote_user'] === 'root'
            && $database->settings()['rsync_dir'] === $root . '/RSYNC'
            && $database->settings()['backup_dir'] === $root . '/BACKUP'
            && $database->settings()['ssh_key_path']
                === $root . '/.ssh/id_rsa',
        'Default user atau folder aplikasi pertama tidak benar.'
    );
    $database->updateSettings([
        'rsync_dir' => '/var/lib/j-backup/rsync',
        'ssh_key_path' => '/var/lib/j-backup/.ssh/id_ed25519',
    ]);
    $database = new Database($root . '/j-backup.sqlite');
    assertTrue(
        $database->settings()['rsync_dir']
            === $root . '/rsync'
        && $database->settings()['ssh_key_path']
            === '/var/lib/j-backup/.ssh/id_ed25519',
        'Path RSYNC lama tidak dimigrasikan atau path key kustom berubah.'
    );
    assertTrue(
        $database->schedulerState('runtime_data_directory') === $root,
        'Lokasi data runtime tidak tercatat untuk mendukung pemindahan aplikasi.'
    );
    $database->updateSettings([
        'rsync_dir' => '/srv/j-backup-lama/storage/rsync',
        'ssh_key_path' => '/srv/j-backup-lama/storage/.ssh/id_ed25519',
    ]);
    $database->setSchedulerState(
        'runtime_data_directory',
        '/srv/j-backup-lama/storage'
    );
    $database = new Database($root . '/j-backup.sqlite');
    assertTrue(
        $database->settings()['rsync_dir'] === $root . '/rsync'
        && $database->settings()['ssh_key_path']
            === '/srv/j-backup-lama/storage/.ssh/id_ed25519',
        'Path RSYNC tidak ikut berubah atau path key kustom ikut dipindahkan.'
    );
    $secretStore = new SecretStore($database, $root);
    $secretStore->set('ssh_password', 'rahasia-sementara');
    assertTrue(
        $secretStore->get('ssh_password') === 'rahasia-sementara',
        'Password SSH terenkripsi tidak dapat dibaca kembali.'
    );
    assertTrue(
        !str_contains(
            (string) $database->encryptedSecret('ssh_password'),
            'rahasia-sementara'
        ),
        'Password SSH tersimpan sebagai plaintext.'
    );
    $migratedSchedule = array_values(array_filter(
        $database->schedules(),
        static fn (array $item): bool => $item['type'] === 'backup'
    ))[0];
    assertTrue(
        $migratedSchedule['mode'] === 'daily'
            && $migratedSchedule['days'] === [0, 1, 2, 3, 4, 5, 6],
        'Jadwal hari tertentu lama tidak dimigrasikan menjadi harian.'
    );
    $created = $database->createDatabase([
        'name' => 'Sumber Universal',
        'archive_mode' => 'combined',
        'paths' => [
            '/var/lib/mysql/cusj_test/',
            'website=/var/www/example.com/',
            'config=/var/lib/mysql/cusj_test/file.txt',
        ],
    ]);
    assertTrue(
        $created['name'] === 'Sumber Universal'
            && count($created['paths']) === 3
            && $created['paths'][1]['alias'] === 'website'
            && $created['paths'][0]['path'] === '/var/lib/mysql/cusj_test/'
            && $created['paths'][2]['path'] === '/var/lib/mysql/cusj_test/file.txt',
        'Input sumber universal dan path gagal.'
    );
    assertTrue(count($database->sources()) === 1, 'Daftar sumber tidak tersimpan.');
    $stagedSource = $staging . '/' . $created['id'] . '-Sumber-Universal';
    mkdir($stagedSource . '/cusj_test', 0770, true);
    mkdir($stagedSource . '/website', 0770, true);
    file_put_contents($stagedSource . '/config', "file source\n");

    $database->updateSettings([
        'remote_host' => '127.0.0.1',
        'rsync_dir' => $staging,
        'backup_dir' => $backup,
        'minimum_free_bytes' => 0,
    ]);

    $runner = new JobRunner($database, $root, true, $secretStore);
    $archiveNameMethod = new ReflectionMethod($runner, 'archiveName');
    assertTrue(
        $archiveNameMethod->invoke(
            $runner,
            '{year}-{month_short}-{date}_{month_short}_{time}-{name}.7z',
            'Sumber Uji',
            new DateTimeImmutable('2026-08-17 09:08:07')
        ) === '2026-AGU-17_AGU_09-08-07-Sumber-Uji.7z',
        'Nama arsip tidak memakai singkatan bulan Indonesia.'
    );
    $blockedWithoutPathCheck = false;
    try {
        $database->enqueueJobs('backup', [$created['id']]);
    } catch (RuntimeException $error) {
        $blockedWithoutPathCheck = str_contains(
            $error->getMessage(),
            'Tes akses folder'
        );
    }
    assertTrue(
        $blockedWithoutPathCheck,
        'Pekerjaan tidak diblokir ketika folder belum diuji.'
    );
    $database->createPathTask('rsync', $staging);
    $database->createPathTask('backup', $backup);
    assertTrue(
        $runner->run() === 0,
        'Worker gagal menjalankan pengujian folder tujuan.'
    );
    assertTrue(
        $database->latestPathCheck('rsync')['ready'] === true
            && $database->latestPathCheck('backup')['ready'] === true,
        'Folder RSYNC dan backup tidak ditandai siap.'
    );
    $database->enqueueJobs('backup', [$created['id']]);

    assertTrue($runner->run() === 1, 'Worker tidak memproses antrean.');
    assertTrue(
        $database->schedulerState('worker_heartbeat') !== null,
        'Heartbeat worker tidak tercatat.'
    );

    $job = $database->jobs(1)[0];
    assertTrue($job['status'] === 'success', $job['error'] ?: 'Backup tidak sukses.');
    assertTrue(
        $job['verification'] === 'destination-present-and-readable',
        'Verifikasi tujuan tidak tercatat.'
    );
    assertTrue(is_file($job['output_path']), 'File final tidak ditemukan di tujuan.');
    assertTrue(filesize($job['output_path']) > 0, 'File final kosong.');
    assertTrue(
        count($job['outputs']) === 1
            && $job['outputs'][0]['source_alias'] === null,
        'Mode gabungan tidak mencatat satu output terverifikasi.'
    );

    $database->enqueueJobs('backup', [$created['id']]);
    $interruptedJob = $database->nextQueuedJob();
    assertTrue(
        $interruptedJob !== null && $interruptedJob['status'] === 'running',
        'Pekerjaan uji pemulihan tidak masuk status berjalan.'
    );
    $database->setSchedulerState(
        'worker_heartbeat',
        (new DateTimeImmutable('-2 minutes'))->format('Y-m-d H:i:s')
    );
    assertTrue(
        $runner->run() === 0,
        'Worker memproses pekerjaan lain saat memulihkan pekerjaan terputus.'
    );
    $interruptedJob = $database->job($interruptedJob['id']);
    assertTrue(
        $interruptedJob['status'] === 'failed'
            && $interruptedJob['finished_at'] !== null
            && str_contains($interruptedJob['error'], 'Worker berhenti'),
        'Pekerjaan yang ditinggalkan worker tidak dipulihkan menjadi gagal.'
    );

    $database->updateSource($created['id'], ['archive_mode' => 'separate']);
    $database->enqueueJobs('backup', [$created['id']]);
    assertTrue($runner->run() === 1, 'Backup terpisah tidak diproses.');
    $separateJob = array_values(array_filter(
        $database->jobs(10),
        static fn (array $item): bool => $item['archive_mode'] === 'separate'
    ))[0];
    assertTrue(
        $separateJob['status'] === 'success'
            && count($separateJob['outputs']) === 3
            && array_column($separateJob['outputs'], 'source_alias')
                === ['cusj_test', 'website', 'config'],
        $separateJob['error'] ?: 'Mode arsip terpisah tidak menghasilkan setiap path file atau folder.'
    );

    $schedule = $database->updateSchedule('backup', [
        'enabled' => true,
        'mode' => 'minutes',
        'interval_value' => 1,
    ]);
    assertTrue($schedule['mode'] === 'minutes', 'Mode interval menit tidak tersimpan.');
    assertTrue($schedule['interval_value'] === 1, 'Nilai interval tidak tersimpan.');

    $past = (new DateTimeImmutable('-2 minutes'))->format(DATE_ATOM);
    $statement = $database->pdo()->prepare(
        "UPDATE schedules SET updated_at = ? WHERE type = 'backup'"
    );
    $statement->execute([$past]);
    $database->setSchedulerState('last_backup', $past);

    assertTrue($runner->run() === 1, 'Jadwal setiap menit tidak membuat pekerjaan.');
    $scheduledJob = array_values(array_filter(
        $database->jobs(10),
        static fn (array $item): bool => $item['type'] === 'backup'
    ))[0];
    assertTrue($scheduledJob['type'] === 'backup', 'Jenis pekerjaan terjadwal tidak benar.');
    assertTrue(
        $scheduledJob['status'] === 'success' && $scheduledJob['size_bytes'] > 0,
        'Alur RSYNC & Backup terjadwal gagal atau ukuran hasil tidak tercatat.'
    );
    assertTrue($runner->run() === 0, 'Jadwal interval berjalan dua kali terlalu cepat.');

    $blockedParent = $root . '/parent-berupa-file';
    file_put_contents($blockedParent, 'blocked');
    $missingRsync = $blockedParent . '/folder-yang-belum-ada';
    $database->createPathTask('rsync', $missingRsync);
    assertTrue(
        $runner->run() === 0,
        'Worker gagal menyelesaikan diagnosis folder yang belum tersedia.'
    );
    $missingCheck = $database->latestPathCheck('rsync');
    assertTrue(
        $missingCheck['ready'] === false
            && $missingCheck['reason_code'] === 'not_found'
            && str_contains(implode("\n", $missingCheck['commands']), 'mkdir -p'),
        'Diagnosis dan rekomendasi administrator untuk folder gagal tidak lengkap.'
    );
    $database->createPathTask('rsync', $staging);
    assertTrue(
        $runner->run() === 0
            && $database->latestPathCheck('rsync')['ready'] === true,
        'Status folder RSYNC tidak pulih setelah diagnosis kegagalan.'
    );

    $keyPath = $root . '/custom-keys/id_ed25519';
    $keyTask = $database->createSshTask('generate_key', [
        'ssh_key_path' => $keyPath,
    ]);
    assertTrue($keyTask['status'] === 'queued', 'Pembuatan key tidak masuk antrean.');
    assertTrue($runner->run() === 0, 'Tindakan SSH dihitung sebagai job backup.');
    $keyTask = $database->sshTask($keyTask['id']);
    assertTrue($keyTask['status'] === 'success', $keyTask['error'] ?: 'Pembuatan key gagal.');
    assertTrue(
        str_contains($keyTask['log'], 'Worker mengambil tugas SSH.')
            && str_contains($keyTask['log'], 'tindakan SSH berhasil'),
        'Log progres tindakan SSH tidak tersimpan.'
    );
    assertTrue(is_file($keyPath), 'Private key simulasi tidak dibuat.');
    assertTrue(is_file($keyPath . '.pub'), 'Public key simulasi tidak dibuat.');
    assertTrue(
        str_starts_with($keyTask['result']['public_key'], 'ssh-rsa '),
        'Public key tidak valid.'
    );

    $connectionTask = $database->createSshTask('test_connection', [
        'remote_host' => '127.0.0.1',
        'remote_port' => 22,
        'remote_user' => 'backup',
        'ssh_key_path' => $keyPath,
    ]);
    $runner->run();
    $connectionTask = $database->sshTask($connectionTask['id']);
    assertTrue(
        $connectionTask['status'] === 'success'
            && $connectionTask['result']['connected'] === true,
        $connectionTask['error'] ?: 'Tes koneksi simulasi gagal.'
    );
    $connectionState = json_decode(
        (string) $database->schedulerState('ssh_connection'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    assertTrue(
        $connectionState['connected'] === true
            && $connectionState['target'] === 'backup@127.0.0.1'
            && $connectionState['key_path'] === $keyPath,
        'Status koneksi SSH sukses tidak tersimpan.'
    );

    $rsaPath = $root . '/.ssh/id_rsa';
    $installTask = $database->createSshTask('generate_key', [
        'remote_host' => '127.0.0.1',
        'remote_port' => 22,
        'remote_user' => 'backup',
        'ssh_key_path' => $rsaPath,
        'ssh_key_type' => 'rsa4096',
        'ssh_key_comment' => 'Jeriyant-Key-RSA',
        'install_key' => true,
    ]);
    $runner->run();
    $installTask = $database->sshTask($installTask['id']);
    assertTrue(
        $installTask['status'] === 'success'
            && $installTask['result']['installed'] === true,
        $installTask['error'] ?: 'Pemasangan public key simulasi gagal.'
    );
    assertTrue(
        str_starts_with($installTask['result']['public_key'], 'ssh-rsa '),
        'RSA 4096 tidak menghasilkan public key RSA.'
    );
    assertTrue(
        !array_key_exists('secret', $installTask),
        'Password SSH bocor melalui hasil task.'
    );
    $savedSshSettings = $database->settings();
    assertTrue(
        $savedSshSettings['remote_host'] === '127.0.0.1'
            && (int) $savedSshSettings['remote_port'] === 22
            && $savedSshSettings['remote_user'] === 'backup'
            && $savedSshSettings['ssh_key_path'] === $rsaPath
            && $savedSshSettings['ssh_key_type'] === 'rsa4096'
            && $savedSshSettings['ssh_key_comment'] === 'Jeriyant-Key-RSA',
        'Konfigurasi SSH sukses tidak tersimpan otomatis.'
    );

    $disconnectTask = $database->createSshTask('disconnect', [
        'remote_host' => '127.0.0.1',
        'remote_port' => 22,
        'remote_user' => 'backup',
        'ssh_key_path' => $rsaPath,
    ]);
    $runner->run();
    $disconnectTask = $database->sshTask($disconnectTask['id']);
    assertTrue(
        $disconnectTask['status'] === 'success'
            && $disconnectTask['result']['disconnected'] === true,
        $disconnectTask['error'] ?: 'Disconnect SSH simulasi gagal.'
    );
    assertTrue(
        !is_file($rsaPath) && !is_file($rsaPath . '.pub'),
        'Disconnect tidak menghapus pasangan key lokal.'
    );
    assertTrue(
        $database->schedulerState('ssh_connection') === null,
        'Status koneksi tidak dibersihkan setelah disconnect.'
    );
    assertTrue(
        $secretStore->get('ssh_password') === null,
        'Password SSH tersimpan tidak dihapus setelah disconnect.'
    );

    $recoveryKeyPath = $root . '/custom-keys/recovery_ed25519';
    $secretStore->set('ssh_password', 'rahasia-pemulihan');
    $recoveryConnectTask = $database->createSshTask('generate_key', [
        'remote_host' => '127.0.0.1',
        'remote_port' => 22,
        'remote_user' => 'backup',
        'ssh_key_path' => $recoveryKeyPath,
        'ssh_key_type' => 'ed25519',
        'ssh_key_comment' => 'J-BACKUP-Recovery',
        'install_key' => true,
    ]);
    $runner->run();
    $recoveryConnectTask = $database->sshTask($recoveryConnectTask['id']);
    assertTrue(
        $recoveryConnectTask['status'] === 'success',
        $recoveryConnectTask['error'] ?: 'Persiapan pemulihan key gagal.'
    );
    @unlink($recoveryKeyPath);
    @unlink($recoveryKeyPath . '.pub');
    $recoveryDisconnectTask = $database->createSshTask('disconnect', [
        'remote_host' => '127.0.0.1',
        'remote_port' => 22,
        'remote_user' => 'backup',
        'ssh_key_path' => $recoveryKeyPath,
    ]);
    $runner->run();
    $recoveryDisconnectTask = $database->sshTask(
        $recoveryDisconnectTask['id']
    );
    assertTrue(
        $recoveryDisconnectTask['status'] === 'success'
            && $recoveryDisconnectTask['result']['disconnected'] === true
            && $recoveryDisconnectTask['result']['remote_key_removed'] === false
            && $database->schedulerState('ssh_connection') === null,
        $recoveryDisconnectTask['error']
            ?: 'Key hilang masih mengunci status koneksi SSH.'
    );

    $cancelQueuedJobs = [
        ...$database->enqueueJobs('backup', [$created['id']]),
        ...$database->enqueueJobs('backup', [$created['id']]),
    ];
    $cancelRunningJob = $database->nextQueuedJob();
    assertTrue(
        $cancelRunningJob !== null && $cancelRunningJob['status'] === 'running',
        'Pekerjaan uji pembatalan tidak masuk status berjalan.'
    );
    $cancelResult = $database->cancelAllJobs();
    assertTrue(
        $cancelResult === [
            'queued_cancelled' => 1,
            'running_requested' => 1,
            'total' => 2,
        ],
        'Ringkasan pembatalan semua pekerjaan tidak benar.'
    );
    assertTrue(
        $database->job($cancelRunningJob['id'])['status'] === 'cancel_requested',
        'Pekerjaan berjalan tidak menerima permintaan pembatalan.'
    );
    $queuedCancellation = array_values(array_filter(
        $cancelQueuedJobs,
        static fn (array $item): bool => $item['id'] !== $cancelRunningJob['id']
    ))[0];
    assertTrue(
        $database->job($queuedCancellation['id'])['status'] === 'cancelled',
        'Pekerjaan antrean tidak langsung dibatalkan.'
    );
    $historyDeleted = $database->clearJobHistory();
    assertTrue(
        $historyDeleted >= 1,
        'Riwayat pekerjaan terminal tidak berhasil dihapus.'
    );
    assertTrue(
        $database->job($cancelRunningJob['id'])['status'] === 'cancel_requested',
        'Penghapusan riwayat ikut menghapus pekerjaan aktif.'
    );

    $database->createUser(
        'reset_admin',
        password_hash('rahasia-reset', PASSWORD_DEFAULT)
    );
    $secretStore->set('ssh_password', 'akan-dihapus');
    $database->resetApplication();
    assertTrue($database->userCount() === 0, 'Reset tidak menghapus administrator.');
    assertTrue($database->databases() === [], 'Reset tidak menghapus daftar database.');
    assertTrue($database->jobs() === [], 'Reset tidak menghapus riwayat pekerjaan.');
    assertTrue(
        $database->encryptedSecret('ssh_password') === null,
        'Reset tidak menghapus password SSH terenkripsi.'
    );
    assertTrue(
        $database->schedulerState('ssh_connection') === null,
        'Reset tidak menghapus status koneksi SSH.'
    );
    assertTrue(
        count($database->schedules()) === 1
            && array_reduce(
                $database->schedules(),
                static fn (bool $valid, array $item): bool =>
                    $valid
                        && $item['enabled'] === false
                        && $item['mode'] === 'daily'
                        && $item['time'] === '00:00',
                true
            ),
        'Reset tidak mengembalikan jadwal bawaan harian pukul 00:00 dalam keadaan nonaktif.'
    );
    assertTrue(
        $database->settings()['remote_host'] === ''
            && $database->settings()['remote_user'] === 'root'
            && $database->settings()['rsync_dir'] === $root . '/RSYNC'
            && $database->settings()['backup_dir'] === $root . '/BACKUP',
        'Reset tidak mengembalikan konfigurasi dan folder bawaan.'
    );
    assertTrue(
        is_file($job['output_path']),
        'Reset database ikut menghapus file hasil backup.'
    );

    fwrite(STDOUT, "Semua smoke test J-BACKUP lulus.\n");
} finally {
    removeTree($root);
}
