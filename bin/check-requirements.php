#!/usr/bin/env php
<?php

declare(strict_types=1);

$errors = [];
if (PHP_VERSION_ID < 80200) {
    $errors[] = 'PHP 8.2 atau lebih baru diperlukan.';
}
foreach (['pdo', 'pdo_sqlite', 'sodium', 'zip', 'SimpleXML'] as $extension) {
    if (!extension_loaded($extension)) {
        $errors[] = "Extension {$extension} belum aktif.";
    }
}
foreach (['proc_open', 'disk_free_space', 'hash_file'] as $function) {
    if (!function_exists($function)) {
        $errors[] = "Fungsi PHP {$function} tidak tersedia.";
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Kebutuhan PHP J-BACKUP terpenuhi.\n");
