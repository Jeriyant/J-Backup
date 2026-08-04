<?php

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header(
    "Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; " .
    "img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; " .
    "frame-ancestors 'none'; form-action 'self'"
);

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    ? 'https'
    : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
    $host = 'localhost';
}
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$ogImage = sprintf('%s://%s%s/og.png', $scheme, $host, $basePath);
$assetVersion = (string) max(
    (int) @filemtime(__DIR__ . '/assets/app.css'),
    (int) @filemtime(__DIR__ . '/assets/app.js'),
    (int) @filemtime(__DIR__ . '/assets/favicon.svg')
);
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#050505">
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg?v=<?= $assetVersion ?>">
    <meta name="description" content="RSYNC, backup 7z terjadwal, verifikasi tujuan, dan monitor storage.">
    <meta property="og:type" content="website">
    <meta property="og:title" content="J-BACKUP">
    <meta property="og:description" content="RSYNC. BACKUP. Terverifikasi.">
    <meta property="og:image" content="<?= htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="J-BACKUP">
    <meta name="twitter:description" content="RSYNC. BACKUP. Terverifikasi.">
    <meta name="twitter:image" content="<?= htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8') ?>">
    <title>J-BACKUP</title>
    <link rel="preload" href="assets/fonts/Sora-Variable.ttf" as="font" type="font/ttf" crossorigin>
    <link rel="preload" href="assets/fonts/Oxanium-Variable.ttf" as="font" type="font/ttf" crossorigin>
    <link rel="preload" href="assets/fonts/JetBrainsMono-Variable.ttf" as="font" type="font/ttf" crossorigin>
    <link rel="stylesheet" href="assets/app.css?v=<?= $assetVersion ?>">
</head>
<body>
    <div id="app" aria-live="polite">
        <main class="loading-screen">
            <span class="brand-mark">J</span>
            <p>Menyiapkan J-BACKUP…</p>
        </main>
    </div>
    <div id="toast" class="toast" popover="manual" hidden></div>
    <dialog id="modal" class="modal"></dialog>
    <script src="assets/app.js?v=<?= $assetVersion ?>" defer></script>
</body>
</html>
