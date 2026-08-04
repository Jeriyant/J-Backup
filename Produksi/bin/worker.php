<?php

declare(strict_types=1);

use JBackup\JobRunner;

$container = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/JobRunner.php';

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "[PERINGATAN] Worker tidak dijalankan sebagai root (UID " . posix_geteuid() . "). Disarankan menjalankan dengan sudo: sudo php bin/worker.php\n");
}

$simulate = in_array(
    strtolower((string) getenv('JBACKUP_SIMULATE')),
    ['1', 'true', 'yes'],
    true
);
$runner = new JobRunner(
    $container['database'],
    $container['data_directory'],
    $simulate,
    $container['secret_store']
);

try {
    $processed = $runner->run();
    fwrite(STDOUT, "J-BACKUP worker selesai. {$processed} job diproses.\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "Worker gagal: {$error->getMessage()}\n");
    exit(1);
}
