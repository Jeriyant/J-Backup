<?php

declare(strict_types=1);

namespace JBackup;

use RuntimeException;

final class SecretStore
{
    private const PREFIX = 'secretbox-v1:';
    private string $key;

    public function __construct(
        private readonly Database $database,
        string $dataDirectory
    ) {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('Extension PHP sodium diperlukan untuk enkripsi password.');
        }
        $keyPath = rtrim($dataDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'secret.key';
        $this->key = $this->loadOrCreateKey($keyPath);
    }

    public function has(string $name): bool
    {
        return $this->database->encryptedSecret($name) !== null;
    }

    public function set(string $name, string $value): void
    {
        if ($value === '' || strlen($value) > 1024) {
            throw new RuntimeException('Password SSH tidak valid.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($value, $nonce, $this->key);
        $this->database->setEncryptedSecret(
            $name,
            self::PREFIX . base64_encode($nonce . $ciphertext)
        );
        sodium_memzero($value);
    }

    public function get(string $name): ?string
    {
        $stored = $this->database->encryptedSecret($name);
        if ($stored === null) {
            return null;
        }
        if (!str_starts_with($stored, self::PREFIX)) {
            throw new RuntimeException('Format secret tersimpan tidak dikenali.');
        }
        $decoded = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if (
            $decoded === false
            || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
        ) {
            throw new RuntimeException('Secret tersimpan rusak.');
        }
        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        if ($plaintext === false) {
            throw new RuntimeException('Password SSH tersimpan tidak dapat didekripsi.');
        }
        return $plaintext;
    }

    public function delete(string $name): void
    {
        $this->database->deleteEncryptedSecret($name);
    }

    private function loadOrCreateKey(string $path): string
    {
        if (!is_file($path)) {
            $handle = @fopen($path, 'x+b');
            if (is_resource($handle)) {
                try {
                    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
                    if (fwrite($handle, $key) !== strlen($key)) {
                        throw new RuntimeException('Key enkripsi tidak dapat ditulis.');
                    }
                    fflush($handle);
                } finally {
                    fclose($handle);
                }
                @chgrp($path, 'jbackup');
                @chmod($path, 0640);
            }
        }
        $key = @file_get_contents($path);
        if (
            !is_string($key)
            || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES
        ) {
            throw new RuntimeException(
                "Key enkripsi tidak tersedia atau tidak valid: {$path}"
            );
        }
        return $key;
    }
}
