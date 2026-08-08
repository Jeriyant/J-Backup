<?php

declare(strict_types=1);

namespace JBackup;

use PDO;
use RuntimeException;

final class Database
{
    private PDO $pdo;
    private array $defaultSettings;

    /** Bump when schema/data migrations change so upgrades run once. */
    private const SCHEMA_VERSION = 1;

    private const DEFAULT_SETTINGS = [
        'remote_host' => '',
        'remote_port' => '22',
        'remote_user' => 'root',
        'remote_root' => '/var/lib/mysql',
        'rsync_dir' => '',
        'backup_dir' => '',
        'compression_level' => '9',
        'filename_template' => '{year}-{month_short}-{date}_{time}-{name}.7z',
        'ssh_key_path' => '',
        'ssh_key_type' => 'rsa4096',
        'ssh_key_comment' => 'J-Backup-Key-RSA',
        'rsync_binary' => '/usr/bin/rsync',
        'seven_zip_binary' => '/usr/bin/7z',
        'minimum_free_bytes' => '1073741824',
        'timezone' => 'Asia/Jakarta',
        'language' => 'id',
        'theme' => 'system',
        'github_repository' => 'Jeriyant/J-Backup',
        'session_timeout_minutes' => '30',
        'telegram_enabled' => '0',
        'telegram_bot_token' => '',
        'telegram_chat_id' => '',
        'anydesk_id' => '',
        'telegram_field_waktu' => '1',
        'telegram_field_cpu' => '1',
        'telegram_field_memory' => '1',
        'telegram_field_job' => '1',
        'telegram_field_tipe' => '1',
        'telegram_field_disk' => '1',
        'telegram_field_anydesk' => '1',
        'telegram_field_sumber' => '1',
        'telegram_field_info' => '1',
        'telegram_fields_order' => 'tipe,waktu,cpu,memory,job,disk,anydesk,sumber,info',
        'telegram_standby_enabled' => '1',
        'telegram_standby_interval' => '1',
        'telegram_standby_interval_unit' => 'minute',
        'telegram_standby_template' => '{{pesan_default}}',
        'telegram_rsync_enabled' => '1',
        'telegram_rsync_template' => '{{pesan_default}}',
        'telegram_backup_enabled' => '1',
        'telegram_backup_template' => '{{pesan_default}}',
        'telegram_backup_file_enabled' => '0',
        'telegram_backup_file_interval' => '60',
        'telegram_backup_file_interval_unit' => 'minute',
        'telegram_backup_file_start_time' => '00:00',
    ];

    public function __construct(
        string $file,
        ?string $applicationDirectory = null
    )
    {
        $directory = rtrim(dirname($file), '/\\');
        $applicationDirectory = rtrim(
            $applicationDirectory ?? $directory,
            '/\\'
        );
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException("Tidak dapat membuat direktori data: {$directory}");
        }

        $rsyncDirectory = getenv('JBACKUP_RSYNC_DIR');
        $backupDirectory = getenv('JBACKUP_BACKUP_DIR');
        $this->defaultSettings = self::DEFAULT_SETTINGS;
        $this->defaultSettings['rsync_dir'] =
            is_string($rsyncDirectory) && trim($rsyncDirectory) !== ''
                ? rtrim($rsyncDirectory, '/\\')
                : $applicationDirectory . '/RSYNC';
        $this->defaultSettings['backup_dir'] =
            $applicationDirectory . '/BACKUP';
        $this->defaultSettings['ssh_key_path'] =
            $directory . '/.ssh/id_rsa';
        if (is_string($backupDirectory) && trim($backupDirectory) !== '') {
            $this->defaultSettings['backup_dir'] = rtrim($backupDirectory, '/\\');
        }

        $this->pdo = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        // Web requests fail fast under writer contention; the CLI worker can wait longer.
        $busyTimeout = PHP_SAPI === 'cli' ? 30000 : 5000;
        $this->pdo->exec("PRAGMA busy_timeout = {$busyTimeout}");
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->migrate();
        $this->seed();
        @chmod($file, 0660);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function migrate(): void
    {
        $this->createBaseTables();

        $version = $this->schemaVersion();
        if ($version < self::SCHEMA_VERSION) {
            if ($version < 1) {
                $this->upgradeToSchemaV1();
            }
            $this->setSchemaVersion(self::SCHEMA_VERSION);
        }

        // Cheap read; writes only when the runtime data directory actually changes.
        $this->syncRuntimePathsIfNeeded();
    }

    private function schemaVersion(): int
    {
        try {
            $value = $this->pdo->query(
                "SELECT value FROM scheduler_state WHERE key = 'schema_version'"
            )->fetchColumn();
            return is_string($value) || is_int($value) ? (int) $value : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function setSchemaVersion(int $version): void
    {
        $statement = $this->pdo->prepare(
            'INSERT OR REPLACE INTO scheduler_state(key, value) VALUES (?, ?)'
        );
        $statement->execute(['schema_version', (string) $version]);
    }

    private function createBaseTables(): void
    {
        $this->pdo->exec(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE COLLATE NOCASE,
                password_hash TEXT NOT NULL,
                created_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS database_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_code TEXT NOT NULL DEFAULT '',
                name TEXT NOT NULL UNIQUE COLLATE NOCASE,
                include_sys INTEGER NOT NULL DEFAULT 1,
                archive_mode TEXT NOT NULL DEFAULT 'combined',
                output_subdirectory TEXT NOT NULL DEFAULT '',
                enabled INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS source_paths (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_id INTEGER NOT NULL REFERENCES database_entries(id)
                    ON DELETE CASCADE,
                remote_path TEXT NOT NULL,
                alias TEXT NOT NULL,
                position INTEGER NOT NULL DEFAULT 0,
                UNIQUE(source_id, remote_path),
                UNIQUE(source_id, alias)
            );

            CREATE TABLE IF NOT EXISTS schedules (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL UNIQUE CHECK(type IN ('backup')),
                enabled INTEGER NOT NULL DEFAULT 0,
                schedule_mode TEXT NOT NULL DEFAULT 'daily',
                interval_value INTEGER NOT NULL DEFAULT 1,
                time TEXT NOT NULL,
                days TEXT NOT NULL DEFAULT '[0,1,2,3,4,5,6]',
                updated_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS jobs (
                id TEXT PRIMARY KEY,
                batch_id TEXT,
                type TEXT NOT NULL CHECK(type IN ('backup')),
                database_id INTEGER REFERENCES database_entries(id) ON DELETE SET NULL,
                database_name TEXT NOT NULL,
                include_sys INTEGER NOT NULL DEFAULT 1,
                archive_mode TEXT NOT NULL DEFAULT 'combined',
                output_subdirectory TEXT NOT NULL DEFAULT '',
                source_paths TEXT NOT NULL DEFAULT '[]',
                status TEXT NOT NULL,
                output_path TEXT,
                size_bytes INTEGER NOT NULL DEFAULT 0,
                progress INTEGER NOT NULL DEFAULT 0,
                verification TEXT,
                checksum TEXT,
                log TEXT NOT NULL DEFAULT '',
                error TEXT,
                queued_at TEXT NOT NULL,
                started_at TEXT,
                finished_at TEXT
            );

            CREATE INDEX IF NOT EXISTS jobs_queued_at_idx ON jobs(queued_at DESC);
            CREATE INDEX IF NOT EXISTS jobs_status_idx ON jobs(status);
            CREATE INDEX IF NOT EXISTS jobs_batch_id_idx ON jobs(batch_id);

            CREATE TABLE IF NOT EXISTS job_outputs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id TEXT NOT NULL REFERENCES jobs(id) ON DELETE CASCADE,
                source_alias TEXT,
                archive_path TEXT NOT NULL,
                size_bytes INTEGER NOT NULL DEFAULT 0,
                checksum TEXT,
                verification TEXT NOT NULL,
                UNIQUE(job_id, archive_path)
            );

            CREATE TABLE IF NOT EXISTS scheduler_state (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS encrypted_secrets (
                name TEXT PRIMARY KEY,
                ciphertext TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS ssh_tasks (
                id TEXT PRIMARY KEY,
                type TEXT NOT NULL CHECK(type IN (
                    'generate_key', 'test_connection', 'disconnect'
                )),
                status TEXT NOT NULL CHECK(status IN ('queued', 'running', 'success', 'failed')),
                payload TEXT NOT NULL DEFAULT '{}',
                secret TEXT,
                result TEXT,
                log TEXT NOT NULL DEFAULT '',
                error TEXT,
                created_at TEXT NOT NULL,
                started_at TEXT,
                finished_at TEXT
            );

            CREATE INDEX IF NOT EXISTS ssh_tasks_status_idx
            ON ssh_tasks(status, created_at);

            CREATE TABLE IF NOT EXISTS path_tasks (
                id TEXT PRIMARY KEY,
                kind TEXT NOT NULL CHECK(kind IN ('rsync', 'backup')),
                path TEXT NOT NULL,
                status TEXT NOT NULL CHECK(status IN (
                    'queued', 'running', 'success', 'failed'
                )),
                result TEXT,
                error TEXT,
                created_at TEXT NOT NULL,
                started_at TEXT,
                finished_at TEXT
            );

            CREATE INDEX IF NOT EXISTS path_tasks_status_idx
            ON path_tasks(status, created_at);
            SQL
        );
    }

    private function upgradeToSchemaV1(): void
    {
        $sourceColumns = array_column(
            $this->pdo->query('PRAGMA table_info(database_entries)')->fetchAll(),
            'name'
        );
        if (!in_array('archive_mode', $sourceColumns, true)) {
            $this->pdo->exec(
                "ALTER TABLE database_entries
                 ADD COLUMN archive_mode TEXT NOT NULL DEFAULT 'combined'"
            );
        }
        if (!in_array('output_subdirectory', $sourceColumns, true)) {
            $this->pdo->exec(
                "ALTER TABLE database_entries
                 ADD COLUMN output_subdirectory TEXT NOT NULL DEFAULT ''"
            );
        }
        if (!in_array('source_code', $sourceColumns, true)) {
            $this->pdo->exec(
                "ALTER TABLE database_entries
                 ADD COLUMN source_code TEXT NOT NULL DEFAULT ''"
            );
        }
        $this->migrateSourceCodes();
        $this->pdo->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS database_entries_source_code_idx
             ON database_entries(source_code COLLATE NOCASE)'
        );

        $jobColumns = array_column(
            $this->pdo->query('PRAGMA table_info(jobs)')->fetchAll(),
            'name'
        );
        if (!in_array('archive_mode', $jobColumns, true)) {
            $this->pdo->exec(
                "ALTER TABLE jobs
                 ADD COLUMN archive_mode TEXT NOT NULL DEFAULT 'combined'"
            );
        }
        if (!in_array('source_paths', $jobColumns, true)) {
            $this->pdo->exec(
                "ALTER TABLE jobs
                 ADD COLUMN source_paths TEXT NOT NULL DEFAULT '[]'"
            );
        }
        if (!in_array('output_subdirectory', $jobColumns, true)) {
            $this->pdo->exec(
                "ALTER TABLE jobs
                 ADD COLUMN output_subdirectory TEXT NOT NULL DEFAULT ''"
            );
        }
        if (!in_array('batch_id', $jobColumns, true)) {
            $this->pdo->exec("ALTER TABLE jobs ADD COLUMN batch_id TEXT");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS jobs_batch_id_idx ON jobs(batch_id)");
        }
        if (!in_array('progress', $jobColumns, true)) {
            $this->pdo->exec(
                'ALTER TABLE jobs ADD COLUMN progress INTEGER NOT NULL DEFAULT 0'
            );
        }
        $this->pdo->exec("UPDATE jobs SET progress = 100 WHERE status = 'success'");
        $this->migrateLegacySources();
        $this->migrateLegacyJobs();

        $scheduleColumns = array_column(
            $this->pdo->query('PRAGMA table_info(schedules)')->fetchAll(),
            'name'
        );
        if (!in_array('schedule_mode', $scheduleColumns, true)) {
            $this->pdo->exec(
                "ALTER TABLE schedules ADD COLUMN schedule_mode TEXT NOT NULL DEFAULT 'daily'"
            );
        }
        if (!in_array('interval_value', $scheduleColumns, true)) {
            $this->pdo->exec(
                'ALTER TABLE schedules ADD COLUMN interval_value INTEGER NOT NULL DEFAULT 1'
            );
        }
        $this->pdo->exec(
            "UPDATE schedules
             SET schedule_mode = 'daily', days = '[0,1,2,3,4,5,6]'
             WHERE schedule_mode = 'weekly'"
        );
        $this->pdo->exec(
            "UPDATE schedules
             SET days = '[0,1,2,3,4,5,6]'
             WHERE schedule_mode = 'daily'"
        );

        $sshTaskColumns = array_column(
            $this->pdo->query('PRAGMA table_info(ssh_tasks)')->fetchAll(),
            'name'
        );
        if (!in_array('secret', $sshTaskColumns, true)) {
            $this->pdo->exec('ALTER TABLE ssh_tasks ADD COLUMN secret TEXT');
        }
        if (!in_array('log', $sshTaskColumns, true)) {
            $this->pdo->exec(
                "ALTER TABLE ssh_tasks ADD COLUMN log TEXT NOT NULL DEFAULT ''"
            );
        }
        $this->migrateSshTaskTypes();
        $this->migratePathTasksTable();
        $this->migrateRuntimePaths();
    }

    private function syncRuntimePathsIfNeeded(): void
    {
        $runtimeDirectory = rtrim(dirname(
            (string) $this->pdo->query('PRAGMA database_list')->fetch()['file']
        ), '/\\');
        $previous = $this->schedulerState('runtime_data_directory');
        if (is_string($previous) && $previous === $runtimeDirectory) {
            return;
        }
        $this->migrateRuntimePaths();
    }

    private function migratePathTasksTable(): void
    {
        $schema = (string) $this->pdo->query(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name='path_tasks'"
        )->fetchColumn();
        if (str_contains($schema, "'realtime'")) {
            $this->pdo->exec(
                <<<'SQL'
                CREATE TABLE path_tasks_new (
                    id TEXT PRIMARY KEY,
                    kind TEXT NOT NULL CHECK(kind IN ('rsync', 'backup')),
                    path TEXT NOT NULL,
                    status TEXT NOT NULL CHECK(status IN ('queued', 'running', 'success', 'failed')),
                    result TEXT,
                    error TEXT,
                    created_at TEXT NOT NULL,
                    started_at TEXT,
                    finished_at TEXT
                );
                INSERT INTO path_tasks_new (id, kind, path, status, result, error, created_at, started_at, finished_at)
                SELECT id, CASE WHEN kind = 'realtime' THEN 'rsync' ELSE kind END, path, status, result, error, created_at, started_at, finished_at
                FROM path_tasks;
                DROP TABLE path_tasks;
                ALTER TABLE path_tasks_new RENAME TO path_tasks;
                CREATE INDEX IF NOT EXISTS path_tasks_status_idx ON path_tasks(status, created_at);
                SQL
            );
        }
    }

    private function migrateLegacySources(): void
    {
        $root = (string) (
            $this->pdo->query(
                "SELECT value FROM settings WHERE key = 'remote_root'"
            )->fetchColumn() ?: self::DEFAULT_SETTINGS['remote_root']
        );
        $sources = $this->pdo->query(
            'SELECT id, name, include_sys FROM database_entries'
        )->fetchAll();
        $count = $this->pdo->prepare(
            'SELECT COUNT(*) FROM source_paths WHERE source_id = ?'
        );
        $insert = $this->pdo->prepare(
            'INSERT OR IGNORE INTO source_paths
             (source_id, remote_path, alias, position) VALUES (?, ?, ?, ?)'
        );
        foreach ($sources as $source) {
            $count->execute([$source['id']]);
            if ((int) $count->fetchColumn() > 0) {
                continue;
            }
            $names = [(string) $source['name']];
            if ((bool) $source['include_sys']) {
                $names[] = $source['name'] . '_sys';
            }
            foreach ($names as $position => $name) {
                $insert->execute([
                    $source['id'],
                    rtrim($root, '/') . '/' . $name,
                    $name,
                    $position,
                ]);
            }
        }
    }

    private function migrateSourceCodes(): void
    {
        $rows = $this->pdo->query(
            "SELECT id, name FROM database_entries
             WHERE source_code = '' OR source_code IS NULL
             ORDER BY id"
        )->fetchAll();
        if ($rows === []) {
            return;
        }
        $used = array_fill_keys(array_map(
            static fn (string $code): string => strtoupper($code),
            $this->pdo->query(
                "SELECT source_code FROM database_entries
                 WHERE source_code <> ''"
            )->fetchAll(PDO::FETCH_COLUMN)
        ), true);
        $update = $this->pdo->prepare(
            'UPDATE database_entries SET source_code = ? WHERE id = ?'
        );
        foreach ($rows as $row) {
            $base = self::sourceCodeFromName((string) $row['name']);
            $code = $base;
            $suffix = 2;
            while (isset($used[$code])) {
                $code = substr($base, 0, 55) . '-' . $suffix++;
            }
            $update->execute([$code, $row['id']]);
            $used[$code] = true;
        }
    }

    private function migrateLegacyJobs(): void
    {
        $rows = $this->pdo->query(
            "SELECT jobs.id, database_entries.id AS source_id,
                    database_entries.archive_mode,
                    database_entries.output_subdirectory
             FROM jobs
             JOIN database_entries
               ON database_entries.id = jobs.database_id
             WHERE jobs.source_paths = '[]'"
        )->fetchAll();
        $update = $this->pdo->prepare(
            'UPDATE jobs
             SET archive_mode = ?, output_subdirectory = ?, source_paths = ?
             WHERE id = ?'
        );
        foreach ($rows as $row) {
            $update->execute([
                $row['archive_mode'],
                $row['output_subdirectory'],
                json_encode(
                    $this->sourcePaths((int) $row['source_id']),
                    JSON_THROW_ON_ERROR
                ),
                $row['id'],
            ]);
        }
    }

    private function migrateSshTaskTypes(): void
    {
        $schema = (string) $this->pdo->query(
            "SELECT sql FROM sqlite_master
             WHERE type = 'table' AND name = 'ssh_tasks'"
        )->fetchColumn();
        if (str_contains($schema, "'disconnect'")) {
            return;
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec(
                <<<'SQL'
                ALTER TABLE ssh_tasks RENAME TO ssh_tasks_legacy;

                CREATE TABLE ssh_tasks (
                    id TEXT PRIMARY KEY,
                    type TEXT NOT NULL CHECK(type IN (
                        'generate_key', 'test_connection', 'disconnect'
                    )),
                    status TEXT NOT NULL CHECK(status IN (
                        'queued', 'running', 'success', 'failed'
                    )),
                    payload TEXT NOT NULL DEFAULT '{}',
                    secret TEXT,
                    result TEXT,
                    log TEXT NOT NULL DEFAULT '',
                    error TEXT,
                    created_at TEXT NOT NULL,
                    started_at TEXT,
                    finished_at TEXT
                );

                INSERT INTO ssh_tasks(
                    id, type, status, payload, secret, result, log, error,
                    created_at, started_at, finished_at
                )
                SELECT
                    id, type, status, payload, secret, result, log, error,
                    created_at, started_at, finished_at
                FROM ssh_tasks_legacy;

                DROP TABLE ssh_tasks_legacy;

                CREATE INDEX ssh_tasks_status_idx
                ON ssh_tasks(status, created_at);
                SQL
            );
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function migrateRuntimePaths(): void
    {
        $runtimeDirectory = rtrim(dirname(
            (string) $this->pdo->query('PRAGMA database_list')->fetch()['file']
        ), '/\\');
        $runtimePrefix = $runtimeDirectory . '/';
        $previousRuntime = $this->pdo->prepare(
            "SELECT value FROM scheduler_state WHERE key = 'runtime_data_directory'"
        );
        $previousRuntime->execute();
        $previousDirectory = $previousRuntime->fetchColumn();
        $legacyPrefixes = [
            '/var/lib/j-backup/',
            '/var/www/html/J-Backup/storage/',
        ];
        if (is_string($previousDirectory) && trim($previousDirectory) !== '') {
            array_unshift(
                $legacyPrefixes,
                rtrim($previousDirectory, '/\\') . '/'
            );
        }

        $migrateRuntimePath = $this->pdo->prepare(
            "UPDATE settings
             SET value = ? || substr(value, ?)
             WHERE key = 'rsync_dir'
               AND value LIKE ?"
        );
        foreach (array_unique($legacyPrefixes) as $legacyPrefix) {
            if ($legacyPrefix === $runtimePrefix) {
                continue;
            }
            $migrateRuntimePath->execute([
                $runtimePrefix,
                strlen($legacyPrefix) + 1,
                $legacyPrefix . '%',
            ]);
        }

        $runtimeState = $this->pdo->prepare(
            'INSERT OR REPLACE INTO scheduler_state(key, value) VALUES (\'runtime_data_directory\', ?)'
        );
        $runtimeState->execute([$runtimeDirectory]);

        $migrateDefaultKeyPath = $this->pdo->prepare(
            "UPDATE settings
             SET value = ?
             WHERE key = 'ssh_key_path'
               AND value = '/root/.ssh/j-backup/id_ed25519'"
        );
        $migrateDefaultKeyPath->execute([$runtimeDirectory . '/.ssh/id_rsa']);
        $this->pdo->exec(
            "UPDATE settings
             SET value = 'rsa4096'
             WHERE key = 'ssh_key_type'
               AND value = 'ed25519'"
        );
        $this->pdo->exec(
            "UPDATE settings
             SET value = 'J-Backup-Key-RSA'
             WHERE key = 'ssh_key_comment'
               AND value = 'J-BACKUP-Key'"
        );
        $this->repairTemporarySshKeyPath($runtimeDirectory);
    }

    private function repairTemporarySshKeyPath(string $runtimeDirectory): void
    {
        $statement = $this->pdo->prepare(
            "SELECT value FROM settings WHERE key = 'ssh_key_path'"
        );
        $statement->execute();
        $temporaryPath = (string) ($statement->fetchColumn() ?: '');
        if (!str_starts_with($temporaryPath, '/tmp/j-backup-ssh/')) {
            return;
        }

        $filename = basename($temporaryPath);
        if (!preg_match('/^(?:[a-f0-9]{32}_)?(id_(?:rsa|ed25519))$/', $filename, $matches)) {
            return;
        }
        $managedPath = $runtimeDirectory . '/.ssh/' . $matches[1];
        if (!is_file($managedPath)) {
            return;
        }

        $update = $this->pdo->prepare(
            "UPDATE settings SET value = ? WHERE key = 'ssh_key_path'"
        );
        $update->execute([$managedPath]);

        $connection = $this->schedulerState('ssh_connection');
        $state = is_string($connection) ? json_decode($connection, true) : null;
        if (
            is_array($state)
            && ($state['key_path'] ?? null) === $temporaryPath
        ) {
            $state['key_path'] = $managedPath;
            $this->setSchedulerState(
                'ssh_connection',
                json_encode($state, JSON_THROW_ON_ERROR)
            );
        }
    }

    private function seed(): void
    {
        $keys = array_keys($this->defaultSettings);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM settings WHERE key IN ({$placeholders})"
        );
        $statement->execute($keys);
        $settingsReady = (int) $statement->fetchColumn() === count($keys);
        $scheduleReady = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM schedules WHERE type = 'backup'"
        )->fetchColumn() > 0;
        if ($settingsReady && $scheduleReady) {
            return;
        }

        $statement = $this->pdo->prepare(
            'INSERT OR IGNORE INTO settings(key, value) VALUES (:key, :value)'
        );
        foreach ($this->defaultSettings as $key => $value) {
            $statement->execute(['key' => $key, 'value' => $value]);
        }

        $schedule = $this->pdo->prepare(
            <<<'SQL'
            INSERT OR IGNORE INTO schedules
            (type, enabled, schedule_mode, interval_value, time, days, updated_at)
            VALUES (:type, 0, 'daily', 1, :time, '[0,1,2,3,4,5,6]', :updated_at)
            SQL
        );
        $schedule->execute([
            'type' => 'backup',
            'time' => '00:00',
            'updated_at' => self::now(),
        ]);
    }

    public static function now(): string
    {
        return (new \DateTimeImmutable())->format(DATE_ATOM);
    }

    public function userCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    public function resetApplication(): void
    {
        $this->pdo->beginTransaction();
        try {
            foreach ([
                'job_outputs',
                'jobs',
                'source_paths',
                'database_entries',
                'schedules',
                'ssh_tasks',
                'path_tasks',
                'encrypted_secrets',
                'scheduler_state',
                'settings',
                'users',
            ] as $table) {
                $this->pdo->exec("DELETE FROM {$table}");
            }
            $this->pdo->exec(
                "DELETE FROM sqlite_sequence
                 WHERE name IN (
                    'users', 'database_entries', 'source_paths',
                    'job_outputs', 'schedules'
                 )"
            );
            $this->seed();
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function createUser(string $username, string $passwordHash): int
    {
        $username = trim($username);
        if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $username)) {
            throw new RuntimeException('Username harus berisi 1–64 karakter yang valid.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO users(username, password_hash, created_at) VALUES (?, ?, ?)'
        );
        $statement->execute([$username, $passwordHash, self::now()]);
        return (int) $this->pdo->lastInsertId();
    }

    public function findUser(string $username): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, username, password_hash FROM users WHERE username = ?'
        );
        $statement->execute([trim($username)]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function findUserById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, username, password_hash FROM users WHERE id = ?'
        );
        $statement->execute([$id]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function findFirstUser(): ?array
    {
        $statement = $this->pdo->query(
            'SELECT id, username, password_hash FROM users ORDER BY id ASC LIMIT 1'
        );
        $row = $statement->fetch();
        return $row ?: null;
    }

    public function updateUser(
        int $id,
        string $username,
        ?string $passwordHash = null
    ): array {
        $username = trim($username);
        if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $username)) {
            throw new RuntimeException(
                'Username harus berisi 1–64 karakter yang valid.'
            );
        }
        $statement = $passwordHash === null
            ? $this->pdo->prepare(
                'UPDATE users SET username = ? WHERE id = ?'
            )
            : $this->pdo->prepare(
                'UPDATE users SET username = ?, password_hash = ? WHERE id = ?'
            );
        $passwordHash === null
            ? $statement->execute([$username, $id])
            : $statement->execute([$username, $passwordHash, $id]);

        $user = $this->findUserById($id);
        if (!$user) {
            throw new RuntimeException('Akun administrator tidak ditemukan.');
        }
        return $user;
    }

    public function settings(): array
    {
        $rows = $this->pdo->query('SELECT key, value FROM settings')->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[$row['key']] = $row['value'];
        }
        return $result;
    }

    public function updateSettings(array $values): array
    {
        $allowed = array_keys(self::DEFAULT_SETTINGS);
        $statement = $this->pdo->prepare(
            'INSERT OR REPLACE INTO settings(key, value) VALUES (:key, :value)'
        );

        $this->pdo->beginTransaction();
        try {
            foreach ($values as $key => $value) {
                if (in_array($key, $allowed, true)) {
                    $statement->execute([
                        'key' => $key,
                        'value' => (string) $value,
                    ]);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }

        return $this->settings();
    }

    public static function validateDatabaseName(string $name): string
    {
        return self::validateSourceAlias($name);
    }

    public static function validateSourceName(string $name): string
    {
        $name = trim($name);
        if (
            $name === ''
            || strlen($name) > 128
            || preg_match('/[\x00-\x1F\x7F\/\\\\]/', $name)
        ) {
            throw new RuntimeException(
                'Nama sumber harus berisi 1–128 karakter dan tidak boleh mengandung slash.'
            );
        }
        return $name;
    }

    public static function validateSourceCode(string $code): string
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z0-9][A-Z0-9_.-]{0,63}$/', $code)) {
            throw new RuntimeException(
                'Kode sumber harus berisi 1–64 huruf, angka, titik, garis bawah, atau tanda hubung.'
            );
        }
        return $code;
    }

    public static function sourceCodeFromName(string $name): string
    {
        $code = strtoupper(trim($name));
        $code = preg_replace('/[^A-Z0-9]+/', '-', $code) ?? '';
        $code = trim($code, '-');
        return substr($code !== '' ? $code : 'SUMBER', 0, 64);
    }

    public static function validateSourceAlias(string $alias): string
    {
        $alias = trim($alias);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $alias)) {
            throw new RuntimeException(
                'Alias path hanya boleh berisi huruf, angka, titik, garis bawah, dan tanda hubung.'
            );
        }
        return $alias;
    }

    public static function validateRemotePath(string $path): string
    {
        $path = trim($path);
        // Akhiran "/" disengaja: rsync menggunakannya untuk menyalin isi folder
        // ke alias tujuan, bukan membuat satu folder bersarang lagi.
        if ($path !== '/') {
            $path = rtrim($path, '/') . (str_ends_with($path, '/') ? '/' : '');
        }
        if (
            $path === ''
            || !str_starts_with($path, '/')
            || str_contains($path, "\0")
            || strlen($path) > 2048
        ) {
            throw new RuntimeException('Path sumber harus berupa path absolut Linux.');
        }
        return $path;
    }

    public function databases(bool $enabledOnly = false): array
    {
        $where = $enabledOnly ? 'WHERE enabled = 1' : '';
        $rows = $this->pdo->query(
            "SELECT id, source_code, name, include_sys, archive_mode, output_subdirectory,
                    enabled, created_at, updated_at
             FROM database_entries {$where} ORDER BY name COLLATE NOCASE"
        )->fetchAll();

        return array_map([$this, 'normalizeDatabase'], $rows);
    }

    public function sources(bool $enabledOnly = false): array
    {
        return $this->databases($enabledOnly);
    }

    public function database(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, source_code, name, include_sys, archive_mode, output_subdirectory,
                    enabled, created_at, updated_at
             FROM database_entries WHERE id = ?'
        );
        $statement->execute([$id]);
        $row = $statement->fetch();
        return $row ? $this->normalizeDatabase($row) : null;
    }

    public function createDatabase(array $input): array
    {
        $name = self::validateSourceName((string) ($input['name'] ?? ''));
        $baseCode = self::validateSourceCode((string) (
            $input['source_code'] ?? self::sourceCodeFromName($name)
        ));
        $sourceCode = $baseCode;
        if (!array_key_exists('source_code', $input)) {
            $suffix = 1;
            while ($this->sourceByCode($sourceCode) !== null) {
                $suffix++;
                $sourceCode = substr($baseCode, 0, 58) . '-' . $suffix;
            }
        }
        $archiveMode = $this->validateArchiveMode(
            (string) ($input['archive_mode'] ?? 'combined')
        );
        $outputSubdirectory = $this->validateOutputSubdirectory(
            (string) ($input['output_subdirectory'] ?? '')
        );
        $paths = $this->normalizeSourcePaths($input['paths'] ?? []);
        $now = self::now();
        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO database_entries(
                source_code, name, include_sys, archive_mode, output_subdirectory,
                enabled, created_at, updated_at
            )
            VALUES (?, ?, 0, ?, ?, ?, ?, ?)
            SQL
        );
        $this->pdo->beginTransaction();
        try {
            $statement->execute([
                $sourceCode,
                $name,
                $archiveMode,
                $outputSubdirectory,
                ($input['enabled'] ?? true) ? 1 : 0,
                $now,
                $now,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->replaceSourcePaths($id, $paths);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
        return $this->database($id);
    }

    public function createSource(array $input): array
    {
        return $this->createDatabase($input);
    }

    public function updateDatabase(int $id, array $input): ?array
    {
        $current = $this->database($id);
        if (!$current) {
            return null;
        }

        $sourceCode = array_key_exists('source_code', $input)
            ? self::validateSourceCode((string) $input['source_code'])
            : $current['source_code'];
        $name = array_key_exists('name', $input)
            ? self::validateSourceName((string) $input['name'])
            : $current['name'];
        $archiveMode = array_key_exists('archive_mode', $input)
            ? $this->validateArchiveMode((string) $input['archive_mode'])
            : $current['archive_mode'];
        $outputSubdirectory = array_key_exists('output_subdirectory', $input)
            ? $this->validateOutputSubdirectory(
                (string) $input['output_subdirectory']
            )
            : $current['output_subdirectory'];
        $enabled = array_key_exists('enabled', $input)
            ? (bool) $input['enabled']
            : $current['enabled'];

        $statement = $this->pdo->prepare(
            <<<'SQL'
            UPDATE database_entries
            SET source_code = ?, name = ?, include_sys = 0, archive_mode = ?,
                output_subdirectory = ?, enabled = ?, updated_at = ?
            WHERE id = ?
            SQL
        );
        $this->pdo->beginTransaction();
        try {
            $statement->execute([
                $sourceCode,
                $name,
                $archiveMode,
                $outputSubdirectory,
                $enabled ? 1 : 0,
                self::now(),
                $id,
            ]);
            if (array_key_exists('paths', $input)) {
                $this->replaceSourcePaths(
                    $id,
                    $this->normalizeSourcePaths($input['paths'])
                );
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
        return $this->database($id);
    }

    public function sourceByCode(string $code): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM database_entries WHERE source_code = ? COLLATE NOCASE'
        );
        $statement->execute([self::validateSourceCode($code)]);
        $id = $statement->fetchColumn();
        return $id === false ? null : $this->database((int) $id);
    }

    public function sourceByName(string $name): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM database_entries WHERE name = ? COLLATE NOCASE'
        );
        $statement->execute([self::validateSourceName($name)]);
        $id = $statement->fetchColumn();
        return $id === false ? null : $this->database((int) $id);
    }

    public function upsertSourceByName(array $input): array
    {
        $name = self::validateSourceName((string) ($input['name'] ?? ''));
        $existing = $this->sourceByName($name);
        if ($existing !== null) {
            return $this->updateDatabase((int) $existing['id'], $input);
        }
        return $this->createDatabase($input);
    }

    public function upsertSourceByCode(array $input): array
    {
        $code = self::validateSourceCode((string) (
            $input['source_code'] ?? ''
        ));
        $existing = $this->sourceByCode($code);
        if ($existing !== null) {
            return $this->updateDatabase((int) $existing['id'], $input);
        }
        return $this->createDatabase($input);
    }

    public function updateSource(int $id, array $input): ?array
    {
        return $this->updateDatabase($id, $input);
    }

    public function deleteDatabase(int $id): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM database_entries WHERE id = ?');
        $statement->execute([$id]);
        return $statement->rowCount() > 0;
    }

    public function deleteSource(int $id): bool
    {
        return $this->deleteDatabase($id);
    }

    public function deleteSources(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return 0;
        }

        $statement = $this->pdo->prepare(
            'DELETE FROM database_entries WHERE id = ?'
        );
        $deleted = 0;
        $this->pdo->beginTransaction();
        try {
            foreach ($ids as $id) {
                $statement->execute([$id]);
                $deleted += $statement->rowCount();
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
        return $deleted;
    }

    private function validateArchiveMode(string $mode): string
    {
        if (!in_array($mode, ['combined', 'separate'], true)) {
            throw new RuntimeException('Mode arsip sumber tidak valid.');
        }
        return $mode;
    }

    private function validateOutputSubdirectory(string $directory): string
    {
        $directory = trim($directory);
        if (
            $directory !== ''
            && (
                $directory !== basename($directory)
                || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $directory)
            )
        ) {
            throw new RuntimeException('Subfolder hasil tidak valid.');
        }
        return $directory;
    }

    private function normalizeSourcePaths(mixed $input): array
    {
        if (is_string($input)) {
            $input = preg_split('/\R+/', $input, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($input) || $input === []) {
            throw new RuntimeException('Minimal satu path sumber harus diisi.');
        }
        $paths = [];
        $aliases = [];
        $remotePaths = [];
        foreach ($input as $position => $item) {
            if (is_string($item)) {
                $raw = trim($item);
                $alias = '';
                $path = $raw;
                if (str_contains($raw, '=')) {
                    [$alias, $path] = array_map('trim', explode('=', $raw, 2));
                }
            } elseif (is_array($item)) {
                $path = (string) ($item['path'] ?? $item['remote_path'] ?? '');
                $alias = (string) ($item['alias'] ?? '');
            } else {
                throw new RuntimeException('Format path sumber tidak valid.');
            }
            $path = self::validateRemotePath($path);
            $alias = self::validateSourceAlias(
                $alias !== '' ? $alias : basename($path)
            );
            if (isset($aliases[strtolower($alias)])) {
                throw new RuntimeException("Alias path duplikat: {$alias}");
            }
            if (isset($remotePaths[strtolower($path)])) {
                throw new RuntimeException("Path remote duplikat: {$path}");
            }
            $aliases[strtolower($alias)] = true;
            $remotePaths[strtolower($path)] = true;
            $paths[] = [
                'path' => $path,
                'alias' => $alias,
                'position' => (int) $position,
            ];
        }
        return $paths;
    }

    private function replaceSourcePaths(int $sourceId, array $paths): void
    {
        $delete = $this->pdo->prepare(
            'DELETE FROM source_paths WHERE source_id = ?'
        );
        $delete->execute([$sourceId]);
        $insert = $this->pdo->prepare(
            'INSERT INTO source_paths
             (source_id, remote_path, alias, position) VALUES (?, ?, ?, ?)'
        );
        foreach ($paths as $path) {
            $insert->execute([
                $sourceId,
                $path['path'],
                $path['alias'],
                $path['position'],
            ]);
        }
    }

    private function sourcePaths(int $sourceId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, remote_path, alias, position
             FROM source_paths WHERE source_id = ? ORDER BY position, id'
        );
        $statement->execute([$sourceId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'path' => $row['remote_path'],
            'alias' => $row['alias'],
            'position' => (int) $row['position'],
        ], $statement->fetchAll());
    }

    public function schedules(): array
    {
        $rows = $this->pdo->query(
            'SELECT id, type, enabled, schedule_mode, interval_value,
                    time, days, updated_at
             FROM schedules ORDER BY type DESC'
        )->fetchAll();

        $settings = $this->settings();
        $timezone = $settings['timezone'] ?? 'Asia/Jakarta';

        return array_map(function (array $row) use ($timezone): array {
            $lastRun = $this->schedulerState('last_' . $row['type']);
            $schedule = [
                'id' => (int) $row['id'],
                'type' => $row['type'],
                'enabled' => (bool) $row['enabled'],
                'mode' => $row['schedule_mode'],
                'interval_value' => (int) $row['interval_value'],
                'time' => $row['time'],
                'days' => json_decode($row['days'], true, 512, JSON_THROW_ON_ERROR),
                'updated_at' => $row['updated_at'],
                'last_run' => $lastRun,
            ];
            $schedule['next_run'] = $this->calculateNextRun($schedule, $lastRun, $timezone);
            return $schedule;
        }, $rows);
    }

    private function calculateNextRun(array $schedule, ?string $lastRun, string $timezone = 'Asia/Jakarta'): ?string
    {
        if (!$schedule['enabled']) {
            return null;
        }

        try {
            $tz = new \DateTimeZone($timezone ?: 'Asia/Jakarta');
            $now = new \DateTimeImmutable('now', $tz);
            $mode = $schedule['mode'] ?? 'minutes';

            if (in_array($mode, ['minutes', 'hours'], true)) {
                $seconds = (int) $schedule['interval_value'] * ($mode === 'minutes' ? 60 : 3600);
                $anchor = (new \DateTimeImmutable(
                    (string) ($schedule['updated_at'] ?? 'now'),
                    $tz
                ))->getTimestamp();
                if ($lastRun !== null && $lastRun !== '') {
                    $lastTs = (new \DateTimeImmutable($lastRun, $tz))->getTimestamp();
                    $anchor = max($anchor, $lastTs);
                }
                if ($anchor <= 0) {
                    $anchor = $now->getTimestamp();
                }
                $nextTs = $anchor + $seconds;
                if ($nextTs <= $now->getTimestamp()) {
                    return 'Sesuai giliran';
                }
                $nextDt = (new \DateTimeImmutable('@' . $nextTs))->setTimezone($tz);
                return $nextDt->format('d-m-Y H:i');
            }

            if ($mode === 'daily') {
                $timeStr = (string) ($schedule['time'] ?? '00:00');
                if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $timeStr, $m)) {
                    return null;
                }
                $hour = (int) $m[1];
                $minute = (int) $m[2];

                $todayRun = $now->setTime($hour, $minute, 0);
                if ($todayRun->getTimestamp() > $now->getTimestamp()) {
                    return $todayRun->format('d-m-Y H:i');
                }
                return $todayRun->modify('+1 day')->format('d-m-Y H:i');
            }
        } catch (\Throwable) {
        }

        return null;
    }

    public function updateSchedule(string $type, array $input): array
    {
        if ($type !== 'backup') {
            throw new RuntimeException('Jenis jadwal tidak dikenal.');
        }
        $current = array_values(array_filter(
            $this->schedules(),
            static fn (array $row): bool => $row['type'] === $type
        ))[0];

        $mode = (string) ($input['mode'] ?? $current['mode']);
        if (!in_array($mode, ['minutes', 'hours', 'daily'], true)) {
            throw new RuntimeException('Pola jadwal tidak dikenal.');
        }
        $intervalValue = (int) ($input['interval_value'] ?? $current['interval_value']);
        $maximumInterval = $mode === 'minutes' ? 1440 : 168;
        if (
            in_array($mode, ['minutes', 'hours'], true)
            && ($intervalValue < 1 || $intervalValue > $maximumInterval)
        ) {
            throw new RuntimeException(
                $mode === 'minutes'
                    ? 'Interval menit harus antara 1 dan 1440.'
                    : 'Interval jam harus antara 1 dan 168.'
            );
        }
        $time = (string) ($input['time'] ?? $current['time']);
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new RuntimeException('Waktu harus menggunakan format HH:mm.');
        }
        $days = [0, 1, 2, 3, 4, 5, 6];

        $statement = $this->pdo->prepare(
            'UPDATE schedules
             SET enabled = ?, schedule_mode = ?, interval_value = ?,
                 time = ?, days = ?, updated_at = ?
             WHERE type = ?'
        );
        $statement->execute([
            ($input['enabled'] ?? $current['enabled']) ? 1 : 0,
            $mode,
            $intervalValue,
            $time,
            json_encode($days, JSON_THROW_ON_ERROR),
            self::now(),
            $type,
        ]);

        return array_values(array_filter(
            $this->schedules(),
            static fn (array $row): bool => $row['type'] === $type
        ))[0];
    }

    public function enqueueJobs(string $type, array $databaseIds = []): array
    {
        if ($type !== 'backup') {
            throw new RuntimeException('Pekerjaan harus berupa RSYNC & BACKUP.');
        }
        $settings = $this->settings();
        foreach (['rsync' => 'rsync_dir', 'backup' => 'backup_dir'] as $kind => $setting) {
            $check = $this->latestPathCheck($kind);
            if (
                !is_array($check)
                || ($check['ready'] ?? false) !== true
                || !hash_equals(
                    rtrim((string) ($settings[$setting] ?? ''), '/'),
                    rtrim((string) ($check['path'] ?? ''), '/')
                )
            ) {
                throw new RuntimeException(
                    'Jalankan Tes akses folder '
                    . ($kind === 'rsync' ? 'RSYNC' : 'BACKUP')
                    . ' hingga berhasil sebelum membuat pekerjaan.'
                );
            }
        }

        $databases = $this->databases(true);
        if ($databaseIds !== []) {
            $selected = array_flip(array_map('intval', $databaseIds));
            $databases = array_values(array_filter(
                $databases,
                static fn (array $database): bool => isset($selected[$database['id']])
            ));
        }
        if ($databases === []) {
            throw new RuntimeException('Tidak ada sumber aktif yang dipilih.');
        }

        $batchId = self::uuid();
        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO jobs
            (
                id, batch_id, type, database_id, database_name, include_sys,
                archive_mode, output_subdirectory, source_paths,
                status, queued_at
            )
            VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, 'queued', ?)
            SQL
        );
        $jobs = [];
        $this->pdo->beginTransaction();
        try {
            foreach ($databases as $database) {
                $id = self::uuid();
                $statement->execute([
                    $id,
                    $batchId,
                    $type,
                    $database['id'],
                    $database['name'],
                    $database['archive_mode'],
                    $database['output_subdirectory'],
                    json_encode($database['paths'], JSON_THROW_ON_ERROR),
                    self::now(),
                ]);
                $jobs[] = $this->job($id);
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
        return $jobs;
    }

    public function jobs(int $limit = 100, bool $includeDetails = true): array
    {
        $limit = max(1, min($limit, 500));
        $select = $includeDetails
            ? '*'
            : "id, batch_id, type, database_id, database_name, include_sys,
               archive_mode, output_subdirectory, source_paths, status,
               output_path, size_bytes, progress, verification, checksum,
               '' AS log, error, queued_at, started_at, finished_at";
        $statement = $this->pdo->prepare(
            "SELECT {$select} FROM jobs ORDER BY queued_at DESC LIMIT :limit"
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return array_map(
            fn (array $row): array => $this->normalizeJob($row, $includeDetails),
            $statement->fetchAll()
        );
    }

    public function job(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM jobs WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();
        return $row ? $this->normalizeJob($row, true) : null;
    }

    public function nextQueuedJob(): ?array
    {
        $this->beginImmediateTransaction();
        try {
            $row = $this->pdo->query(
                "SELECT * FROM jobs WHERE status = 'queued' ORDER BY queued_at ASC LIMIT 1"
            )->fetch();
            if (!$row) {
                $this->commitTransaction();
                return null;
            }
            $statement = $this->pdo->prepare(
                "UPDATE jobs SET status = 'running', started_at = ? WHERE id = ? AND status = 'queued'"
            );
            $statement->execute([self::now(), $row['id']]);
            $this->commitTransaction();
            return $this->job($row['id']);
        } catch (\Throwable $error) {
            $this->rollbackTransaction();
            throw $error;
        }
    }

    public function updateJob(string $id, array $values): ?array
    {
        $allowed = [
            'status',
            'output_path',
            'size_bytes',
            'progress',
            'verification',
            'checksum',
            'log',
            'error',
            'started_at',
            'finished_at',
        ];
        $set = [];
        $parameters = [];
        foreach ($values as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $set[] = "{$key} = ?";
                $parameters[] = $value;
            }
        }
        if ($set !== []) {
            $parameters[] = $id;
            $statement = $this->pdo->prepare(
                'UPDATE jobs SET ' . implode(', ', $set) . ' WHERE id = ?'
            );
            $statement->execute($parameters);
        }
        return $this->job($id);
    }

    public function appendJobLog(string $id, string $text): void
    {
        $job = $this->job($id);
        if (!$job) {
            return;
        }
        $log = substr($job['log'] . $text, -200000);
        $this->updateJob($id, ['log' => $log]);
    }

    public function cancelJob(string $id): bool
    {
        $job = $this->job($id);
        if (!$job || !in_array($job['status'], ['queued', 'running'], true)) {
            return false;
        }
        $status = $job['status'] === 'queued' ? 'cancelled' : 'cancel_requested';
        $this->updateJob($id, [
            'status' => $status,
            'finished_at' => $status === 'cancelled' ? self::now() : null,
            'error' => 'Dibatalkan oleh pengguna.',
        ]);
        return true;
    }

    public function cancelAllJobs(): array
    {
        $this->beginImmediateTransaction();
        try {
            $now = self::now();
            $queued = $this->pdo->prepare(
                "UPDATE jobs
                 SET status = 'cancelled', finished_at = ?, error = ?
                 WHERE status = 'queued'"
            );
            $queued->execute([$now, 'Dibatalkan oleh pengguna.']);
            $queuedCount = $queued->rowCount();

            $running = $this->pdo->prepare(
                "UPDATE jobs
                 SET status = 'cancel_requested', error = ?
                 WHERE status = 'running'"
            );
            $running->execute(['Dibatalkan oleh pengguna.']);
            $runningCount = $running->rowCount();
            $this->commitTransaction();

            return [
                'queued_cancelled' => $queuedCount,
                'running_requested' => $runningCount,
                'total' => $queuedCount + $runningCount,
            ];
        } catch (\Throwable $error) {
            $this->rollbackTransaction();
            throw $error;
        }
    }

    public function clearJobHistory(string $status = 'all'): int
    {
        $allowed = ['success', 'failed', 'cancelled'];
        if ($status !== 'all' && !in_array($status, $allowed, true)) {
            throw new RuntimeException('Jenis riwayat tidak dikenal.');
        }
        if ($status === 'all') {
            $statement = $this->pdo->prepare("DELETE FROM jobs WHERE status NOT IN ('queued', 'running', 'cancel_requested')");
            $statement->execute();
        } else {
            $statement = $this->pdo->prepare('DELETE FROM jobs WHERE status = ?');
            $statement->execute([$status]);
        }
        return $statement->rowCount();
    }

    public function schedulerState(string $key): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT value FROM scheduler_state WHERE key = ?'
        );
        $statement->execute([$key]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    public function telegramBackupFileNextRun(): ?string
    {
        $settings = $this->settings();
        if ((string) ($settings['telegram_backup_file_enabled'] ?? '0') !== '1') {
            return null;
        }

        try {
            $timezone = new \DateTimeZone($settings['timezone'] ?? 'Asia/Jakarta');
        } catch (\Throwable) {
            $timezone = new \DateTimeZone('Asia/Jakarta');
        }
        $now = new \DateTimeImmutable('now', $timezone);
        $unit = (string) ($settings['telegram_backup_file_interval_unit'] ?? 'minute');
        $lastSent = (int) ($this->schedulerState('telegram_backup_file_last_sent') ?? 0);

        if ($unit === 'day') {
            $time = (string) ($settings['telegram_backup_file_start_time'] ?? '00:00');
            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
                $time = '00:00';
            }
            $next = new \DateTimeImmutable($now->format('Y-m-d') . ' ' . $time, $timezone);
            if ($lastSent > 0 && $lastSent >= $next->getTimestamp()) {
                $next = $next->modify('+1 day');
            } elseif ($lastSent === 0 && $next < $now) {
                $next = $now;
            }
        } else {
            $value = max(1, min(9999, (int) ($settings['telegram_backup_file_interval'] ?? 60)));
            $seconds = $unit === 'hour' ? $value * 3600 : $value * 60;
            $next = $lastSent > 0
                ? (new \DateTimeImmutable('@' . ($lastSent + $seconds)))->setTimezone($timezone)
                : $now;
        }

        return $next->format('d M Y, H:i');
    }

    public function setSchedulerState(string $key, string $value): void
    {
        $statement = $this->pdo->prepare(
            'INSERT OR REPLACE INTO scheduler_state(key, value) VALUES (?, ?)'
        );
        $statement->execute([$key, $value]);
    }

    public function updateSchedulerState(string $key, string $value): void
    {
        $this->setSchedulerState($key, $value);
    }

    public function deleteSchedulerState(string $key): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM scheduler_state WHERE key = ?'
        );
        $statement->execute([$key]);
    }

    public function latestPathCheck(string $kind): ?array
    {
        if (!in_array($kind, ['rsync', 'backup'], true)) {
            throw new RuntimeException('Jenis folder tidak dikenal.');
        }
        $value = $this->schedulerState('path_check_' . $kind);
        if ($value === null) {
            return null;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function encryptedSecret(string $name): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT ciphertext FROM encrypted_secrets WHERE name = ?'
        );
        $statement->execute([$name]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    public function setEncryptedSecret(string $name, string $ciphertext): void
    {
        $statement = $this->pdo->prepare(
            'INSERT OR REPLACE INTO encrypted_secrets(name, ciphertext, updated_at) VALUES (?, ?, ?)'
        );
        $statement->execute([$name, $ciphertext, self::now()]);
    }

    public function deleteEncryptedSecret(string $name): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM encrypted_secrets WHERE name = ?'
        );
        $statement->execute([$name]);
    }

    public function createSshTask(
        string $type,
        array $payload,
        array $secret = []
    ): array
    {
        if (!in_array(
            $type,
            ['generate_key', 'test_connection', 'disconnect'],
            true
        )) {
            throw new RuntimeException('Jenis tindakan SSH tidak dikenal.');
        }
        $existing = $this->pdo->prepare(
            "SELECT * FROM ssh_tasks
             WHERE type = ? AND status IN ('queued', 'running')
             ORDER BY created_at ASC"
        );
        $existing->execute([$type]);
        foreach ($existing->fetchAll() as $row) {
            if (
                json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR)
                === $payload
            ) {
                return $this->normalizeSshTask($row);
            }
        }

        $id = self::uuid();
        $statement = $this->pdo->prepare(
            "INSERT INTO ssh_tasks(id, type, status, payload, secret, created_at)
             VALUES (?, ?, 'queued', ?, ?, ?)"
        );
        $statement->execute([
            $id,
            $type,
            json_encode($payload, JSON_THROW_ON_ERROR),
            $secret === []
                ? null
                : json_encode($secret, JSON_THROW_ON_ERROR),
            self::now(),
        ]);
        return $this->sshTask($id);
    }

    public function sshTask(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM ssh_tasks WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();
        return $row ? $this->normalizeSshTask($row) : null;
    }

    public function nextQueuedSshTask(): ?array
    {
        $this->beginImmediateTransaction();
        try {
            $row = $this->pdo->query(
                "SELECT * FROM ssh_tasks
                 WHERE status = 'queued' ORDER BY created_at ASC LIMIT 1"
            )->fetch();
            if (!$row) {
                $this->commitTransaction();
                return null;
            }
            $statement = $this->pdo->prepare(
                "UPDATE ssh_tasks
                 SET status = 'running', started_at = ?, secret = NULL
                 WHERE id = ? AND status = 'queued'"
            );
            $statement->execute([self::now(), $row['id']]);
            $this->commitTransaction();
            $task = $this->normalizeSshTask($row);
            $task['secret'] = $row['secret'] === null
                ? []
                : json_decode($row['secret'], true, 512, JSON_THROW_ON_ERROR);
            $task['status'] = 'running';
            $task['started_at'] = self::now();
            return $task;
        } catch (\Throwable $error) {
            $this->rollbackTransaction();
            throw $error;
        }
    }

    public function updateSshTask(string $id, array $values): ?array
    {
        $allowed = ['status', 'result', 'error', 'started_at', 'finished_at'];
        $set = [];
        $parameters = [];
        foreach ($values as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $set[] = "{$key} = ?";
            $parameters[] = $key === 'result' && is_array($value)
                ? json_encode($value, JSON_THROW_ON_ERROR)
                : $value;
        }
        if ($set !== []) {
            $parameters[] = $id;
            $statement = $this->pdo->prepare(
                'UPDATE ssh_tasks SET ' . implode(', ', $set) . ' WHERE id = ?'
            );
            $statement->execute($parameters);
        }
        return $this->sshTask($id);
    }

    public function appendSshTaskLog(string $id, string $message): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE ssh_tasks SET log = substr(log || ?, -200000) WHERE id = ?"
        );
        $statement->execute([$message, $id]);
    }

    public function createPathTask(string $kind, string $path): array
    {
        if (!in_array($kind, ['rsync', 'backup'], true)) {
            throw new RuntimeException('Jenis folder tidak dikenal.');
        }
        $path = rtrim(trim($path), '/');
        if (
            $path === ''
            || !str_starts_with($path, '/')
            || str_contains($path, "\0")
        ) {
            throw new RuntimeException('Folder harus berupa path absolut Linux.');
        }

        $existing = $this->pdo->prepare(
            "SELECT * FROM path_tasks
             WHERE kind = ? AND path = ? AND status IN ('queued', 'running')
             ORDER BY created_at ASC LIMIT 1"
        );
        $existing->execute([$kind, $path]);
        $row = $existing->fetch();
        if ($row) {
            return $this->normalizePathTask($row);
        }

        $id = self::uuid();
        $statement = $this->pdo->prepare(
            "INSERT INTO path_tasks(id, kind, path, status, created_at)
             VALUES (?, ?, ?, 'queued', ?)"
        );
        $statement->execute([$id, $kind, $path, self::now()]);
        return $this->pathTask($id);
    }

    public function pathTask(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM path_tasks WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();
        return $row ? $this->normalizePathTask($row) : null;
    }

    public function nextQueuedPathTask(): ?array
    {
        $this->beginImmediateTransaction();
        try {
            $row = $this->pdo->query(
                "SELECT * FROM path_tasks
                 WHERE status = 'queued' ORDER BY created_at ASC LIMIT 1"
            )->fetch();
            if (!$row) {
                $this->commitTransaction();
                return null;
            }
            $startedAt = self::now();
            $statement = $this->pdo->prepare(
                "UPDATE path_tasks
                 SET status = 'running', started_at = ?
                 WHERE id = ? AND status = 'queued'"
            );
            $statement->execute([$startedAt, $row['id']]);
            $this->commitTransaction();
            $task = $this->normalizePathTask($row);
            $task['status'] = 'running';
            $task['started_at'] = $startedAt;
            return $task;
        } catch (\Throwable $error) {
            $this->rollbackTransaction();
            throw $error;
        }
    }

    public function updatePathTask(string $id, array $values): ?array
    {
        $allowed = ['status', 'result', 'error', 'started_at', 'finished_at'];
        $set = [];
        $parameters = [];
        foreach ($values as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $set[] = "{$key} = ?";
            $parameters[] = $key === 'result' && is_array($value)
                ? json_encode($value, JSON_THROW_ON_ERROR)
                : $value;
        }
        if ($set !== []) {
            $parameters[] = $id;
            $statement = $this->pdo->prepare(
                'UPDATE path_tasks SET ' . implode(', ', $set) . ' WHERE id = ?'
            );
            $statement->execute($parameters);
        }
        return $this->pathTask($id);
    }

    public function activeJob(bool $includeDetails = true): ?array
    {
        $select = $includeDetails
            ? '*'
            : "id, batch_id, type, database_id, database_name, include_sys,
               archive_mode, output_subdirectory, source_paths, status,
               output_path, size_bytes, progress, verification, checksum,
               '' AS log, error, queued_at, started_at, finished_at";
        $row = $this->pdo->query(
            "SELECT {$select} FROM jobs
             WHERE status IN ('running', 'cancel_requested')
             ORDER BY started_at ASC LIMIT 1"
        )->fetch();
        return $row ? $this->normalizeJob($row, $includeDetails) : null;
    }

    private function normalizeDatabase(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'source_code' => (string) ($row['source_code'] ?? ''),
            'name' => $row['name'],
            'archive_mode' => $row['archive_mode'] ?? 'combined',
            'output_subdirectory' => $row['output_subdirectory'] ?? '',
            'paths' => $this->sourcePaths((int) $row['id']),
            'enabled' => (bool) $row['enabled'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function normalizeJob(array $row, bool $includeDetails = true): array
    {
        return [
            'id' => $row['id'],
            'type' => $row['type'],
            'database_id' => $row['database_id'] === null ? null : (int) $row['database_id'],
            'database_name' => $row['database_name'],
            'source_id' => $row['database_id'] === null ? null : (int) $row['database_id'],
            'source_name' => $row['database_name'],
            'archive_mode' => $row['archive_mode'] ?? 'combined',
            'output_subdirectory' => $row['output_subdirectory'] ?? '',
            'paths' => json_decode(
                $row['source_paths'] ?? '[]',
                true,
                512,
                JSON_THROW_ON_ERROR
            ),
            'outputs' => $includeDetails ? $this->jobOutputs($row['id']) : [],
            'status' => $row['status'],
            'output_path' => $row['output_path'],
            'size_bytes' => (int) $row['size_bytes'],
            'progress' => max(0, min(100, (int) ($row['progress'] ?? 0))),
            'verification' => $row['verification'],
            'checksum' => $row['checksum'],
            'log' => $includeDetails ? (string) ($row['log'] ?? '') : '',
            'error' => $row['error'],
            'queued_at' => $row['queued_at'],
            'started_at' => $row['started_at'],
            'finished_at' => $row['finished_at'],
        ];
    }

    private function normalizePathTask(array $row): array
    {
        return [
            'id' => $row['id'],
            'kind' => $row['kind'],
            'path' => $row['path'],
            'status' => $row['status'],
            'result' => $row['result'] === null
                ? null
                : json_decode($row['result'], true, 512, JSON_THROW_ON_ERROR),
            'error' => $row['error'],
            'created_at' => $row['created_at'],
            'started_at' => $row['started_at'],
            'finished_at' => $row['finished_at'],
        ];
    }

    public function replaceJobOutputs(string $jobId, array $outputs): void
    {
        $delete = $this->pdo->prepare(
            'DELETE FROM job_outputs WHERE job_id = ?'
        );
        $insert = $this->pdo->prepare(
            'INSERT INTO job_outputs(
                job_id, source_alias, archive_path, size_bytes,
                checksum, verification
             ) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $this->pdo->beginTransaction();
        try {
            $delete->execute([$jobId]);
            foreach ($outputs as $output) {
                $insert->execute([
                    $jobId,
                    $output['source_alias'] ?? null,
                    $output['archive_path'],
                    (int) ($output['size_bytes'] ?? 0),
                    $output['checksum'] ?? null,
                    $output['verification'],
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    private function jobOutputs(string $jobId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT source_alias, archive_path, size_bytes, checksum, verification
             FROM job_outputs WHERE job_id = ? ORDER BY id'
        );
        $statement->execute([$jobId]);
        return array_map(static fn (array $row): array => [
            'source_alias' => $row['source_alias'],
            'archive_path' => $row['archive_path'],
            'size_bytes' => (int) $row['size_bytes'],
            'checksum' => $row['checksum'],
            'verification' => $row['verification'],
        ], $statement->fetchAll());
    }

    private function normalizeSshTask(array $row): array
    {
        return [
            'id' => $row['id'],
            'type' => $row['type'],
            'status' => $row['status'],
            'payload' => json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR),
            'result' => $row['result'] === null
                ? null
                : json_decode($row['result'], true, 512, JSON_THROW_ON_ERROR),
            'log' => $row['log'] ?? '',
            'error' => $row['error'],
            'created_at' => $row['created_at'],
            'started_at' => $row['started_at'],
            'finished_at' => $row['finished_at'],
        ];
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }

    private function beginImmediateTransaction(): void
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
    }

    private function commitTransaction(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        } else {
            try {
                $this->pdo->exec('COMMIT');
            } catch (\Throwable) {
            }
        }
    }

    private function rollbackTransaction(): void
    {
        if ($this->pdo->inTransaction()) {
            try {
                $this->pdo->rollBack();
            } catch (\Throwable) {
            }
        } else {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (\Throwable) {
            }
        }
    }
}
