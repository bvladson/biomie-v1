<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

$cacheKey = 'biomie_live_stream_last_event_time';

while (true) {
    if (connection_aborted() !== 0) {
        break;
    }

    $lastEventTime = isset($cache) && $cache instanceof \BIomie\Cache ? $cache->get($cacheKey, 86400) : null;
    $clientSince = $_GET['since'] ?? '';
    
    if ($lastEventTime !== null && (string)$lastEventTime !== $clientSince) {
        echo "event: refresh_dashboard\n";
        echo "data: {\"timestamp\": \"$lastEventTime\"}\n\n";
        ob_flush();
        flush();
        break; 
    }
    
    echo "event: ping\n";
    echo "data: {\"time\": \"" . time() . "\"}\n\n";
    ob_flush();
    flush();

    sleep(3);
}
