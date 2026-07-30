<?php

declare(strict_types=1);

namespace JBackup;

use RuntimeException;

final class UpdateChecker
{
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
        return [
            'current_version' => $currentVersion,
            'latest_version' => $latest,
            'update_available' => version_compare($latest, $currentVersion, '>'),
            'release_url' => $payload['html_url'] ?? null,
            'published_at' => $payload['published_at'] ?? null,
        ];
    }
}
