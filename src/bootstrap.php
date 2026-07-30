<?php

declare(strict_types=1);

use JBackup\Auth;
use JBackup\Database;
use JBackup\SecretStore;

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/SecretStore.php';

$projectRoot = dirname(__DIR__);
$dataDirectory = getenv('JBACKUP_DATA_DIR') ?: $projectRoot . '/storage';
umask(0007);
if (!is_dir($dataDirectory)) {
    mkdir($dataDirectory, 0770, true);
}

$database = new Database(
    $dataDirectory . '/j-backup.sqlite',
    $projectRoot
);
$secretStore = new SecretStore($database, $dataDirectory);
$auth = new Auth($database);
if (PHP_SAPI !== 'cli') {
    Auth::startSession();
}

return [
    'project_root' => $projectRoot,
    'data_directory' => $dataDirectory,
    'database' => $database,
    'secret_store' => $secretStore,
    'auth' => $auth,
];
