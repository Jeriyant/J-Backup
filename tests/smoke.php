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
mkdir($staging . '/cusj_test', 0770, true);
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
        VALUES ('sync', 0, '01:00', '[1,3,5]', '2026-01-01T00:00:00+07:00');

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
        SQL
    );
    $legacyDatabase = null;

    $database = new Database($root . '/j-backup.sqlite');
    assertTrue(
        $database->sshTask('legacy-ssh-task')['status'] === 'success',
        'Migrasi tipe task SSH menghilangkan riwayat lama.'
    );
    $database->updateSettings([
        'staging_dir' => '/var/lib/j-backup/staging',
        'ssh_key_path' => '/var/lib/j-backup/.ssh/id_ed25519',
    ]);
    $database = new Database($root . '/j-backup.sqlite');
    assertTrue(
        $database->settings()['staging_dir']
            === $root . '/staging'
        && $database->settings()['ssh_key_path']
            === $root . '/.ssh/id_ed25519',
        'Path runtime versi lama tidak dimigrasikan ke folder aplikasi.'
    );
    assertTrue(
        $database->schedulerState('runtime_data_directory') === $root,
        'Lokasi data runtime tidak tercatat untuk mendukung pemindahan aplikasi.'
    );
    $database->updateSettings([
        'staging_dir' => '/srv/j-backup-lama/storage/staging',
        'ssh_key_path' => '/srv/j-backup-lama/storage/.ssh/id_ed25519',
    ]);
    $database->setSchedulerState(
        'runtime_data_directory',
        '/srv/j-backup-lama/storage'
    );
    $database = new Database($root . '/j-backup.sqlite');
    assertTrue(
        $database->settings()['staging_dir'] === $root . '/staging'
        && $database->settings()['ssh_key_path'] === $root . '/.ssh/id_ed25519',
        'Path runtime tidak ikut berubah setelah folder aplikasi dipindahkan.'
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
        static fn (array $item): bool => $item['type'] === 'sync'
    ))[0];
    assertTrue(
        $migratedSchedule['mode'] === 'daily'
            && $migratedSchedule['days'] === [0, 1, 2, 3, 4, 5, 6],
        'Jadwal hari tertentu lama tidak dimigrasikan menjadi harian.'
    );
    $created = $database->createDatabase([
        'name' => 'cusj_test',
        'include_sys' => false,
    ]);
    assertTrue($created['name'] === 'cusj_test', 'Input nama database gagal.');
    assertTrue(count($database->databases()) === 1, 'Daftar database tidak tersimpan.');

    $database->updateSettings([
        'remote_host' => '127.0.0.1',
        'staging_dir' => $staging,
        'backup_dir' => $backup,
        'minimum_free_bytes' => 0,
    ]);
    $database->enqueueJobs('backup', [$created['id']]);

    $runner = new JobRunner($database, $root, true, $secretStore);
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

    $schedule = $database->updateSchedule('sync', [
        'enabled' => true,
        'mode' => 'minutes',
        'interval_value' => 1,
    ]);
    assertTrue($schedule['mode'] === 'minutes', 'Mode interval menit tidak tersimpan.');
    assertTrue($schedule['interval_value'] === 1, 'Nilai interval tidak tersimpan.');

    $past = (new DateTimeImmutable('-2 minutes'))->format(DATE_ATOM);
    $statement = $database->pdo()->prepare(
        "UPDATE schedules SET updated_at = ? WHERE type = 'sync'"
    );
    $statement->execute([$past]);
    $database->setSchedulerState('last_sync', $past);

    assertTrue($runner->run() === 1, 'Jadwal setiap menit tidak membuat pekerjaan.');
    $scheduledJob = $database->jobs(1)[0];
    assertTrue($scheduledJob['type'] === 'sync', 'Jenis pekerjaan terjadwal tidak benar.');
    assertTrue($scheduledJob['status'] === 'success', 'Sinkronisasi terjadwal gagal.');
    assertTrue($runner->run() === 0, 'Jadwal interval berjalan dua kali terlalu cepat.');

    $keyPath = $root . '/.ssh/id_ed25519';
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
        str_starts_with($keyTask['result']['public_key'], 'ssh-ed25519 '),
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
            && $connectionState['target'] === 'backup@127.0.0.1',
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
        count($database->schedules()) === 2
            && array_reduce(
                $database->schedules(),
                static fn (bool $valid, array $item): bool =>
                    $valid && $item['enabled'] === false,
                true
            ),
        'Reset tidak mengembalikan jadwal bawaan nonaktif.'
    );
    assertTrue(
        $database->settings()['remote_host'] === '',
        'Reset tidak mengembalikan konfigurasi bawaan.'
    );
    assertTrue(
        is_file($job['output_path']),
        'Reset database ikut menghapus file hasil backup.'
    );

    fwrite(STDOUT, "Semua smoke test J-BACKUP lulus.\n");
} finally {
    removeTree($root);
}
