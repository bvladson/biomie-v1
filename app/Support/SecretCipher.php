<?php

declare(strict_types=1);

namespace BIomie\Support;

use RuntimeException;

final class SecretCipher
{
    private const PREFIX = 'v1:';
    private const CIPHER = 'aes-256-gcm';

    public function __construct(private readonly string $configuredKey)
    {
    }

    public function isAvailable(): bool
    {
        return trim($this->configuredKey) !== '' && function_exists('openssl_encrypt');
    }

    public function encrypt(string $plainText): string
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException('BIOMIE_SECRET_KEY nao configurada.');
        }

        $iv = random_bytes(12);
        $tag = '';
        $cipherText = openssl_encrypt(
            $plainText,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );
        if (!is_string($cipherText)) {
            throw new RuntimeException('Falha ao criptografar segredo.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipherText);
    }

    public function decrypt(string $encoded): string
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException('BIOMIE_SECRET_KEY nao configurada.');
        }
        if (!str_starts_with($encoded, self::PREFIX)) {
            throw new RuntimeException('Formato de segredo criptografado invalido.');
        }

        $payload = base64_decode(substr($encoded, strlen(self::PREFIX)), true);
        if (!is_string($payload) || strlen($payload) < 29) {
            throw new RuntimeException('Payload de segredo criptografado invalido.');
        }

        $iv = substr($payload, 0, 12);
        $tag = substr($payload, 12, 16);
        $cipherText = substr($payload, 28);
        $plainText = openssl_decrypt(
            $cipherText,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if (!is_string($plainText)) {
            throw new RuntimeException('Falha ao descriptografar segredo.');
        }

        return $plainText;
    }

    private function key(): string
    {
        $key = trim($this->configuredKey);
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if (is_string($decoded) && strlen($decoded) >= 32) {
                return substr($decoded, 0, 32);
            }
        }
        if (str_starts_with($key, 'hex:')) {
            $decoded = hex2bin(substr($key, 4));
            if (is_string($decoded) && strlen($decoded) >= 32) {
                return substr($decoded, 0, 32);
            }
        }

        return hash('sha256', $key, true);
    }
}
