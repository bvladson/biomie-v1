<?php

declare(strict_types=1);

namespace BIomie;

final class Logger
{
    public function __construct(private readonly string $logFile)
    {
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    private function write(string $level, string $message, array $context = []): void
    {
        $entry = [
            'timestamp' => date(DATE_ATOM),
            'level' => $level,
            'message' => $message,
            'request_id' => RequestContext::id(),
            'correlation_id' => RequestContext::correlationId(),
            'trace_id' => RequestContext::traceId(),
            'span_id' => RequestContext::currentSpanId(),
            'route' => RequestContext::route(),
            'context' => $context,
        ];

        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents(
            $this->logFile,
            json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}
