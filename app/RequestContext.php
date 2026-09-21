<?php

declare(strict_types=1);

namespace BIomie;

final class RequestContext
{
    private static bool $booted = false;
    private static string $requestId = '';
    private static string $correlationId = '';
    private static string $traceId = '';
    private static string $startedAt = '';
    /** @var list<string> */
    private static array $spanStack = [];

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        self::$startedAt = date(DATE_ATOM);

        $incoming = '';
        if (PHP_SAPI !== 'cli') {
            $incoming = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
        }

        self::$requestId = self::sanitize($incoming);
        if (self::$requestId === '') {
            try {
                self::$requestId = bin2hex(random_bytes(12));
            } catch (\Throwable) {
                self::$requestId = sha1(uniqid('biomie_', true));
            }
        }

        $incomingCorrelation = '';
        if (PHP_SAPI !== 'cli') {
            $incomingCorrelation = trim((string) ($_SERVER['HTTP_X_CORRELATION_ID'] ?? ''));
        }
        if ($incomingCorrelation === '' && PHP_SAPI === 'cli') {
            $incomingCorrelation = trim((string) ($_SERVER['BIOMIE_CORRELATION_ID'] ?? getenv('BIOMIE_CORRELATION_ID') ?: ''));
        }

        self::$correlationId = self::sanitize($incomingCorrelation);
        if (self::$correlationId === '') {
            self::$correlationId = self::$requestId;
        }

        self::$traceId = self::resolveTraceId();
    }

    public static function id(): string
    {
        self::boot();
        return self::$requestId;
    }

    public static function startedAt(): string
    {
        self::boot();
        return self::$startedAt;
    }

    public static function correlationId(): string
    {
        self::boot();
        return self::$correlationId;
    }

    public static function traceId(): string
    {
        self::boot();
        return self::$traceId;
    }

    public static function currentSpanId(): ?string
    {
        self::boot();
        if (self::$spanStack === []) {
            return null;
        }

        $current = self::$spanStack[array_key_last(self::$spanStack)];
        return is_string($current) && $current !== '' ? $current : null;
    }

    public static function pushSpan(string $spanId): void
    {
        self::boot();
        $normalized = self::sanitizeSpanId($spanId);
        if ($normalized === '') {
            return;
        }

        self::$spanStack[] = $normalized;
    }

    public static function popSpan(?string $spanId = null): void
    {
        self::boot();
        if (self::$spanStack === []) {
            return;
        }

        if ($spanId === null || $spanId === '') {
            array_pop(self::$spanStack);
            return;
        }

        $normalized = self::sanitizeSpanId($spanId);
        if ($normalized === '') {
            return;
        }

        $last = self::$spanStack[array_key_last(self::$spanStack)] ?? null;
        if ($last === $normalized) {
            array_pop(self::$spanStack);
            return;
        }

        foreach (array_reverse(self::$spanStack, true) as $index => $value) {
            if ($value === $normalized) {
                unset(self::$spanStack[$index]);
                self::$spanStack = array_values(self::$spanStack);
                return;
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    public static function traceContext(): array
    {
        self::boot();

        return [
            'request_id' => self::$requestId,
            'correlation_id' => self::$correlationId,
            'trace_id' => self::$traceId,
            'span_id' => self::currentSpanId(),
            'started_at' => self::$startedAt,
            'route' => self::route(),
        ];
    }

    public static function route(): string
    {
        if (PHP_SAPI === 'cli') {
            return self::maskSecrets(implode(' ', $_SERVER['argv'] ?? []));
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        return trim($method . ' ' . self::maskSecrets($uri));
    }

    /**
     * Mascara segredos que viajam na rota antes de ela chegar a spans, logs e
     * envelopes de erro. O token do webhook Omie é a própria credencial da URL
     * (/webhooks/omie/<empresa>/<token>): até 21/09/2026 ele era gravado
     * inteiro em biomie_operational_spans.route_name e no biomie.log. Fica só
     * o final (wh_****7a3c), o bastante para distinguir tokens entre rotações.
     */
    public static function maskSecrets(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $value = preg_replace_callback(
            '#(/webhooks/omie/[^/?\s]+/)([^/?\s]+)#i',
            static fn (array $m): string => $m[1] . self::maskedTail($m[2]),
            $value
        ) ?? $value;

        return preg_replace_callback(
            '/((?:[?&]|--)(?:token|path_token)=)([^&\s]*)/i',
            static fn (array $m): string => $m[1] . self::maskedTail($m[2]),
            $value
        ) ?? $value;
    }

    private static function maskedTail(string $secret): string
    {
        return 'wh_****' . (strlen($secret) > 8 ? substr($secret, -4) : '');
    }

    private static function sanitize(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return preg_replace('/[^a-zA-Z0-9._:-]/', '', $value) ?? '';
    }

    private static function resolveTraceId(): string
    {
        $incomingTrace = '';
        if (PHP_SAPI !== 'cli') {
            $incomingTrace = trim((string) ($_SERVER['HTTP_X_TRACE_ID'] ?? ''));
            if ($incomingTrace === '') {
                $incomingTrace = self::traceIdFromTraceParent((string) ($_SERVER['HTTP_TRACEPARENT'] ?? ''));
            }
        } else {
            $incomingTrace = trim((string) ($_SERVER['BIOMIE_TRACE_ID'] ?? getenv('BIOMIE_TRACE_ID') ?: ''));
        }

        $normalized = self::sanitizeTraceId($incomingTrace);
        if ($normalized !== '') {
            return $normalized;
        }

        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable) {
            return substr(hash('sha256', self::$requestId . '|' . microtime(true)), 0, 32);
        }
    }

    private static function traceIdFromTraceParent(string $traceParent): string
    {
        if ($traceParent === '') {
            return '';
        }

        if (preg_match('/^[\da-f]{2}-([\da-f]{32})-([\da-f]{16})-[\da-f]{2}$/i', trim($traceParent), $matches) === 1) {
            return strtolower((string) ($matches[1] ?? ''));
        }

        return '';
    }

    private static function sanitizeTraceId(string $value): string
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return '';
        }

        return preg_match('/^[a-f0-9]{32}$/', $normalized) === 1 ? $normalized : '';
    }

    private static function sanitizeSpanId(string $value): string
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return '';
        }

        return preg_match('/^[a-f0-9]{16}$/', $normalized) === 1 ? $normalized : '';
    }
}
