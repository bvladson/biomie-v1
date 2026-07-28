<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
\BIomie\Auth::requireAnyCapability(['jobs.view', 'sales.view'], true);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
header('X-BIOMIE-Context: ' . \BIomie\Support\TenantContext::contextId());
header('Vary: Accept, X-BIOMIE-Context');

$streamContextId = \BIomie\Support\TenantContext::contextId();

// Tell browser to wait 30s before auto-reconnect instead of the default 3s
echo "retry: 30000\n\n";
ob_flush();
flush();

$cacheKey = 'biomie_live_stream_last_event_time';
$deadline  = time() + 25;
$lastPing  = 0;

while (time() < $deadline) {
    if (connection_aborted() !== 0) {
        break;
    }

    $now           = time();
    $lastEventTime = isset($cache) && $cache instanceof \BIomie\Cache ? $cache->get($cacheKey, 86400) : null;
    $clientSince   = $_GET['since'] ?? '';

    if ($lastEventTime !== null && (string) $lastEventTime !== $clientSince) {
        echo "event: refresh_dashboard\n";
        echo 'data: ' . json_encode([
            'timestamp' => $lastEventTime,
            'meta' => ['context_id' => $streamContextId],
        ], JSON_UNESCAPED_SLASHES) . "\n\n";
        ob_flush();
        flush();
        break;
    }

    if ($now - $lastPing >= 15) {
        echo "event: ping\n";
        echo 'data: ' . json_encode([
            'time' => $now,
            'meta' => ['context_id' => $streamContextId],
        ], JSON_UNESCAPED_SLASHES) . "\n\n";
        ob_flush();
        flush();
        $lastPing = $now;
    }

    sleep(3);
}

echo "event: close\n";
echo 'data: ' . json_encode([
    'reason' => 'timeout',
    'meta' => ['context_id' => $streamContextId],
], JSON_UNESCAPED_SLASHES) . "\n\n";
ob_flush();
flush();
