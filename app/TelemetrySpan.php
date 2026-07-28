<?php

declare(strict_types=1);

namespace BIomie;

final class TelemetrySpan
{
    private bool $finished = false;
    private readonly float $startedAtMicrotime;
    private readonly string $startedAt;

    /**
     * @param array<string,mixed> $attributes
     */
    public function __construct(
        private readonly ?TelemetryRepository $repository,
        private readonly Logger $logger,
        private readonly string $traceId,
        private readonly string $spanId,
        private readonly ?string $parentSpanId,
        private readonly string $spanName,
        private readonly ?string $domainName,
        private readonly ?string $sourceName,
        private readonly string $routeName,
        private readonly array $attributes = [],
    ) {
        $this->startedAtMicrotime = microtime(true);
        $this->startedAt = date('Y-m-d H:i:s');
        RequestContext::pushSpan($this->spanId);
    }

    public function id(): string
    {
        return $this->spanId;
    }

    /**
     * @param array<string,mixed> $result
     */
    public function finish(string $status = 'ok', array $result = []): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;

        $endedAtMicrotime = microtime(true);
        $durationMs = max(0, (int) round(($endedAtMicrotime - $this->startedAtMicrotime) * 1000));
        $endedAt = date('Y-m-d H:i:s');

        try {
            $this->repository?->recordSpan([
                'trace_id' => $this->traceId,
                'span_id' => $this->spanId,
                'parent_span_id' => $this->parentSpanId,
                'request_id' => RequestContext::id(),
                'correlation_id' => RequestContext::correlationId(),
                'span_name' => $this->spanName,
                'domain_name' => $this->domainName,
                'source_name' => $this->sourceName,
                'route_name' => $this->routeName,
                'status_name' => $status,
                'attributes' => $this->attributes,
                'result' => $result,
                'started_at' => $this->startedAt,
                'ended_at' => $endedAt,
                'duration_ms' => $durationMs,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->warning('Falha ao persistir span de telemetria.', [
                'span_name' => $this->spanName,
                'message' => $exception->getMessage(),
            ]);
        } finally {
            RequestContext::popSpan($this->spanId);
        }
    }
}
