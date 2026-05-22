<?php

declare(strict_types=1);

namespace BIomie\Support\Cache;

final class FileCache
{
    public function __construct(private readonly string $baseDir)
    {
        if (!is_dir($this->baseDir)) {
            @mkdir($this->baseDir, 0775, true);
        }
    }

    /** @return array<string,mixed>|null */
    public function get(string $key): ?array
    {
        $file = $this->file($key);
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $expiresAt = (int) ($decoded['_cache']['expires_at_ts'] ?? 0);
        if ($expiresAt > 0 && $expiresAt < time()) {
            return null;
        }
        return is_array($decoded['payload'] ?? null) ? $decoded['payload'] : $decoded;
    }

    public function set(string $key, array $payload, int $ttlSeconds): void
    {
        $file = $this->file($key);
        $tmp = $file . '.' . getmypid() . '.tmp';
        $body = [
            '_cache' => [
                'key' => $key,
                'generated_at' => date(DATE_ATOM),
                'expires_at' => date(DATE_ATOM, time() + max(1, $ttlSeconds)),
                'expires_at_ts' => time() + max(1, $ttlSeconds),
            ],
            'payload' => $payload,
        ];
        @file_put_contents($tmp, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        @rename($tmp, $file);
    }

    public function delete(string $key): void
    {
        @unlink($this->file($key));
    }

    /** @return list<string> */
    public function clearPrefix(string $prefix): array
    {
        $removed = [];
        foreach (glob($this->baseDir . '/*.json') ?: [] as $file) {
            $raw = @file_get_contents($file);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            $key = is_array($decoded) ? (string) ($decoded['_cache']['key'] ?? '') : '';
            if ($key === '' || str_starts_with($key, $prefix)) {
                @unlink($file);
                $removed[] = basename($file);
            }
        }
        return $removed;
    }

    private function file(string $key): string
    {
        return rtrim($this->baseDir, '/\\') . '/' . hash('sha256', $key) . '.json';
    }
}
