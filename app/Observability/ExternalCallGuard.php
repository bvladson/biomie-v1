<?php

declare(strict_types=1);

namespace BIomie\Observability;

use BIomie\Logger;
use BIomie\Omie\OmieExecutionContext;
use BIomie\RequestContext;
use RuntimeException;

final class ExternalCallGuard
{
    /**
     * Records and optionally blocks external calls made during a web GET.
     *
     * Modes:
     * - off: records the external call only.
     * - observe: records the violation and logs it.
     * - enforce: records, logs and blocks before network I/O.
     */
    public static function beforeNetworkCall(
        string $provider,
        string $operation,
        string $executionContext,
        string $mode,
        Logger $logger,
    ): void {
        RequestPerformanceContext::increment('external_calls');
        RequestPerformanceContext::increment($provider . '_network_calls');

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? (PHP_SAPI === 'cli' ? 'CLI' : 'GET')));
        if (!OmieExecutionContext::isWeb($executionContext) || !in_array($method, ['GET', 'HEAD'], true)) {
            return;
        }

        $mode = self::normalizeMode($mode);
        if ($mode === 'off') {
            return;
        }

        $count = RequestPerformanceContext::increment('external_get_violations');
        RequestPerformanceContext::setTag('external_get_guard_mode', $mode);
        RequestPerformanceContext::addEvent('external_get_violation', [
            'provider' => $provider,
            'operation' => $operation,
            'mode' => $mode,
        ]);

        $context = [
            'provider' => $provider,
            'operation' => $operation,
            'method' => $method,
            'mode' => $mode,
            'route' => RequestContext::route(),
            'request_id' => RequestContext::id(),
        ];

        if ($count <= 3) {
            $logger->warning('Chamada externa detectada durante request GET.', $context);
        }

        if ($mode === 'enforce') {
            throw new RuntimeException(
                sprintf('Chamada externa %s/%s bloqueada durante request GET; use cache, leitura local ou job.', $provider, $operation)
            );
        }
    }

    public static function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        return in_array($mode, ['off', 'observe', 'enforce'], true) ? $mode : 'observe';
    }
}
