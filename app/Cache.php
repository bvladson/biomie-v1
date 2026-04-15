<?php

declare(strict_types=1);

namespace BIomie;

final class Cache
{
    public function __construct(private readonly string $cacheDir)
    {
    }

    private function filePath(string $key): string
    {
        return rtrim($this->cacheDir, '/\\') . '/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key) . '.json';
    }

    public function get(string $key, int $ttlSeconds): ?array
    {
        $path = $this->filePath($key);
        if (!is_file($path)) {
            return null;
        }

        $modified = filemtime($path) ?: 0;
        if ((time() - $modified) > $ttlSeconds) {
            return null;
        }

        return $this->read($key);
    }

    public function put(string $key, array $value): void
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }

        $path = $this->filePath($key);
        $temp = $path . '.tmp';
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        if (@file_put_contents($temp, $json, LOCK_EX) === false) {
            return;
        }
        @rename($temp, $path);
    }

    public function getMeta(string $key): array
    {
        $path = $this->filePath($key);
        if (!is_file($path)) {
            return ['exists' => false, 'modified_at' => null, 'age_seconds' => null];
        }

        $modified = filemtime($path) ?: null;
        return [
            'exists' => true,
            'modified_at' => $modified ? date(DATE_ATOM, $modified) : null,
            'age_seconds' => $modified ? time() - $modified : null,
        ];
    }

    public function read(string $key): ?array
    {
        $path = $this->filePath($key);
        if (!is_file($path)) return null;
        $json = @file_get_contents($path);
        if ($json === false) return null;
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    public function delete(string $key): void
    {
        $path = $this->filePath($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function invalidatePrefix(string $prefix): void
    {
        $safePrefix = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $prefix);
        if (!is_dir($this->cacheDir)) {
            return;
        }

        $files = glob(rtrim($this->cacheDir, '/\\') . '/' . $safePrefix . '*.json');
        if ($files) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }
}
