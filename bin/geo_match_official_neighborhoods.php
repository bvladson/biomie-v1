<?php

declare(strict_types=1);

/**
 * Reaplica o vinculo de bairros de Fortaleza com a malha oficial.
 *
 * Uso:
 *   php bin/geo_match_official_neighborhoods.php
 *   php bin/geo_match_official_neighborhoods.php --json
 */

use BIomie\Config;
use BIomie\Database;
use BIomie\Modules\Geo\Infrastructure\OfficialNeighborhoodResolver;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$json = in_array('--json', $argv ?? [], true);

try {
    $pdo = Database::connect(new Config(), 'db');
    $pdo->beginTransaction();
    $matched = (new OfficialNeighborhoodResolver($pdo))->matchBairrosOfficials();
    $pdo->commit();

    $payload = [
        'ok' => true,
        'status' => 'ok',
        'matched_or_refreshed' => $matched,
        'message' => 'Vinculos oficiais de bairros reaplicados.',
    ];
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $payload = [
        'ok' => false,
        'status' => 'failed',
        'matched_or_refreshed' => 0,
        'message' => $exception->getMessage(),
    ];
}

echo $json
    ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL
    : sprintf(
        "[%s] %s Registros vinculados/atualizados: %d\n",
        $payload['ok'] ? 'OK' : 'ERRO',
        $payload['message'],
        $payload['matched_or_refreshed']
    );

exit($payload['ok'] ? 0 : 1);
