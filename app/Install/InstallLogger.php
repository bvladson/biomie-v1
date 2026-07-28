<?php

declare(strict_types=1);

namespace BIomie\Install;

use BIomie\Bootstrap;

final class InstallLogger
{
    private string $logFile;

    public function __construct(?string $logFile = null)
    {
        $preferredDir = Bootstrap::rootPath('storage/logs');
        if (is_dir($preferredDir) || @mkdir($preferredDir, 0775, true)) {
            $this->logFile = $logFile ?? $preferredDir . '/install.log';
            return;
        }

        $this->logFile = $logFile ?? Bootstrap::rootPath('config/install.log');
    }

    /**
     * @param array<string,mixed> $context
     */
    public function info(string $event, array $context = []): void
    {
        $this->write('INFO', $event, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function warning(string $event, array $context = []): void
    {
        $this->write('WARNING', $event, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function error(string $event, array $context = []): void
    {
        $this->write('ERROR', $event, $context);
    }

    public function path(): string
    {
        return $this->logFile;
    }

    public function tail(int $lines = 20): array
    {
        if (!is_file($this->logFile) || !is_readable($this->logFile)) {
            return [];
        }

        $content = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($content === false) {
            return [];
        }

        return array_slice($content, -max(1, min(100, $lines)));
    }

    /**
     * @param array<string,mixed> $context
     */
    private function write(string $level, string $event, array $context): void
    {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $payload = [
            'time' => date(DATE_ATOM),
            'level' => $level,
            'event' => $event,
            'context' => $this->sanitize($context),
        ];

        @file_put_contents(
            $this->logFile,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    private function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                $keyText = strtolower((string) $key);
                if (preg_match('/pass|password|secret|token|cookie|authorization|app_key|app_secret|db_pass/', $keyText) === 1) {
                    $clean[$key] = '[redacted]';
                    continue;
                }

                $clean[$key] = $this->sanitize($item);
            }
            return $clean;
        }

        if (is_string($value)) {
            return preg_replace('/(password|pass|secret|token|app_key|app_secret)=([^&\s]+)/i', '$1=[redacted]', $value) ?? $value;
        }

        return $value;
    }
}
