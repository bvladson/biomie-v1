<?php

declare(strict_types=1);

namespace BIomie;

final class Telemetry
{
    private static ?self $instance = null;
    private static bool $shutdownRegistered = false;

    private ?TelemetrySpan $requestSpan = null;

    private function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly ?TelemetryRepository $repository = null,
        private readonly bool $enabled = true,
    ) {
    }

    public static function boot(Config $config, Logger $logger, ?TelemetryRepository $repository = null): self
    {
        if (self::$instance instanceof self) {
            return self::$instance;
        }

        self::registerShutdown();

        $enabled = (bool) $config->get('observability.enabled', true);
        self::$instance = new self(
            $config,
            $logger,
            $repository ?? TelemetryRepository::boot($config, $logger),
            $enabled
        );

        return self::$instance;
    }

    public static function global(): ?self
    {
        return self::$instance;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function startRequestSpan(?string $name = null, array $attributes = []): ?TelemetrySpan
    {
        if (!$this->enabled) {
            return null;
        }

        if ($this->requestSpan instanceof TelemetrySpan) {
            return $this->requestSpan;
        }

        $resolvedName = $name;
        if ($resolvedName === null || trim($resolvedName) === '') {
            $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'request'));
            $script = preg_replace('/\.php$/', '', $script) ?? $script;
            $resolvedName = PHP_SAPI === 'cli' ? 'job.' . $script : 'http.' . $script;
        }

        $domain = (string) ($attributes['domain_name'] ?? (PHP_SAPI === 'cli' ? 'job' : 'http'));
        $source = (string) ($attributes['source_name'] ?? (PHP_SAPI === 'cli' ? 'cli' : 'http'));
        $this->requestSpan = $this->startSpan($resolvedName, $attributes, $domain, $source);

        return $this->requestSpan;
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function startSpan(
        string $name,
        array $attributes = [],
        ?string $domainName = null,
        ?string $sourceName = null,
    ): TelemetrySpan {
        $traceId = RequestContext::traceId();
        $parentSpanId = RequestContext::currentSpanId();
        $spanId = $this->randomHex(8);

        return new TelemetrySpan(
            $this->repository,
            $this->logger,
            $traceId,
            $spanId,
            $parentSpanId,
            $name,
            $domainName,
            $sourceName,
            RequestContext::route(),
            $attributes
        );
    }

    /**
     * @param array<string,mixed> $context
     */
    public function recordEvent(
        string $level,
        string $eventName,
        string $summary,
        array $context = [],
        ?string $domainName = null,
        ?string $sourceName = null,
    ): void {
        if (!$this->enabled) {
            return;
        }

        try {
            $this->repository?->recordEvent([
                'level_name' => strtoupper($level),
                'event_name' => $eventName,
                'domain_name' => $domainName,
                'source_name' => $sourceName,
                'request_id' => RequestContext::id(),
                'correlation_id' => RequestContext::correlationId(),
                'trace_id' => RequestContext::traceId(),
                'span_id' => RequestContext::currentSpanId(),
                'summary' => $summary,
                'context' => $context,
                'occurred_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $exception) {
            $this->logger->warning('Falha ao persistir evento de telemetria.', [
                'event_name' => $eventName,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string,mixed> $labels
     */
    public function recordMetric(
        string $metricName,
        float|int $value,
        string $metricKind = 'gauge',
        array $labels = [],
        ?string $domainName = null,
        ?string $sourceName = null,
    ): void {
        if (!$this->enabled) {
            return;
        }

        try {
            $this->repository?->recordMetric([
                'metric_name' => $metricName,
                'metric_kind' => $metricKind,
                'domain_name' => $domainName,
                'source_name' => $sourceName,
                'request_id' => RequestContext::id(),
                'correlation_id' => RequestContext::correlationId(),
                'trace_id' => RequestContext::traceId(),
                'labels' => $labels,
                'numeric_value' => $value,
                'observed_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $exception) {
            $this->logger->warning('Falha ao persistir metrica operacional.', [
                'metric_name' => $metricName,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function context(): array
    {
        return RequestContext::traceContext();
    }

    private static function registerShutdown(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;

        register_shutdown_function(static function (): void {
            $instance = self::$instance;
            if (!$instance instanceof self || !$instance->requestSpan instanceof TelemetrySpan) {
                return;
            }

            $span = $instance->requestSpan;
            $instance->requestSpan = null;

            $status = 'ok';
            if (error_get_last() !== null) {
                $status = 'fatal';
            } elseif (PHP_SAPI !== 'cli' && http_response_code() >= 400) {
                $status = 'http_' . http_response_code();
            }

            $span->finish($status, [
                'response_code' => PHP_SAPI === 'cli' ? 0 : http_response_code(),
            ]);
        });
    }

    private function randomHex(int $bytes): string
    {
        try {
            return bin2hex(random_bytes($bytes));
        } catch (\Throwable) {
            return substr(hash('sha256', RequestContext::id() . '|' . microtime(true) . '|' . mt_rand()), 0, $bytes * 2);
        }
    }
}
