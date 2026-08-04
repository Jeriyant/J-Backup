<?php

declare(strict_types=1);

namespace JBackup;

use RuntimeException;

final class UpdateChecker
{
    public const ASSET_NAME = 'j-backup-dist.zip';

    public static function latest(string $repository, string $currentVersion): array
    {
        $repository = trim($repository);
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository)) {
            throw new RuntimeException('Repository GitHub harus menggunakan format owner/repository.');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 8,
                'header' => [
                    'Accept: application/vnd.github+json',
                    'User-Agent: J-BACKUP-Update-Checker',
                ],
                'ignore_errors' => true,
            ],
        ]);
        $content = @file_get_contents(
            "https://api.github.com/repos/{$repository}/releases/latest",
            false,
            $context
        );
        if ($content === false) {
            throw new RuntimeException('GitHub tidak dapat dihubungi.');
        }
        $payload = json_decode($content, true);
        if (!is_array($payload) || empty($payload['tag_name'])) {
            throw new RuntimeException('Release terbaru tidak ditemukan.');
        }

        $latest = ltrim((string) $payload['tag_name'], 'vV');
        $downloadUrl = null;
        $checksumUrl = null;
        $assetDigest = null;
        foreach ((array) ($payload['assets'] ?? []) as $asset) {
            $name = (string) ($asset['name'] ?? '');
            if ($name === self::ASSET_NAME) {
                $downloadUrl = (string) ($asset['browser_download_url'] ?? '');
                $digest = (string) ($asset['digest'] ?? '');
                if (preg_match('/^sha256:([a-f0-9]{64})$/i', $digest, $match)) {
                    $assetDigest = strtolower($match[1]);
                }
            }
            if ($name === self::ASSET_NAME . '.sha256') {
                $checksumUrl = (string) ($asset['browser_download_url'] ?? '');
            }
        }
        return [
            'repository' => $repository,
            'tag' => (string) $payload['tag_name'],
            'current_version' => $currentVersion,
            'latest_version' => $latest,
            'update_available' => version_compare($latest, $currentVersion, '>'),
            'release_url' => $payload['html_url'] ?? null,
            'published_at' => $payload['published_at'] ?? null,
            'notes' => (string) ($payload['body'] ?? ''),
            'download_url' => $downloadUrl ?: null,
            'checksum_url' => $checksumUrl ?: null,
            'asset_sha256' => $assetDigest,
            'installable' => $downloadUrl !== null
                && ($checksumUrl !== null || $assetDigest !== null),
        ];
    }
}
