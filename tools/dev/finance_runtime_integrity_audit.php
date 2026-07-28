<?php

declare(strict_types=1);

/**
 * Audita transporte SSL e integridade estrutural do read model financeiro.
 *
 * Usage:
 *   php tools/dev/finance_runtime_integrity_audit.php
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;

$config = new Config();
$report = [
    'ok' => true,
    'connections' => [],
    'same_read_model' => null,
    'generated_at' => date(DATE_ATOM),
];

foreach (['db', 'flight_plan_db'] as $connection) {
    if (!Database::isConfigured($config, $connection)) {
        continue;
    }

    try {
        $pdo = Database::connect($config, $connection);
        $scalar = static fn (\PDO $pdo, string $sql): int => (int) $pdo->query($sql)->fetchColumn();
        $ssl = $pdo->query("SHOW STATUS LIKE 'Ssl_cipher'")->fetch(\PDO::FETCH_ASSOC);
        $sslRequired = (bool) $config->get($connection . '.ssl_enabled', false);

        $metrics = [
            'database' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
            'ssl_required' => $sslRequired,
            'ssl_cipher' => trim((string) ($ssl['Value'] ?? '')),
            'titles_total' => $scalar($pdo, 'SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos'),
            'titles_distinct_keys' => $scalar($pdo, 'SELECT COUNT(DISTINCT titulo_key) FROM biomie_titulos_financeiros_canonicos'),
            'duplicate_omie_id_groups' => $scalar(
                $pdo,
                'SELECT COUNT(*) FROM (
                    SELECT titulo_id_omie
                    FROM biomie_titulos_financeiros_canonicos
                    WHERE titulo_id_omie IS NOT NULL
                    GROUP BY titulo_id_omie
                    HAVING COUNT(*) > 1
                ) duplicates'
            ),
            'bridge_items' => $scalar($pdo, 'SELECT COUNT(*) FROM biomie_bridge_nf_titulo_items'),
            'duplicate_bridge_pairs' => $scalar(
                $pdo,
                'SELECT COUNT(*) FROM (
                    SELECT nota_key, titulo_key
                    FROM biomie_bridge_nf_titulo_items
                    GROUP BY nota_key, titulo_key
                    HAVING COUNT(*) > 1
                ) duplicates'
            ),
            'orphan_bridge_titles' => $scalar(
                $pdo,
                'SELECT COUNT(*)
                 FROM biomie_bridge_nf_titulo_items bridge
                 LEFT JOIN biomie_titulos_financeiros_canonicos title
                   ON title.titulo_key = bridge.titulo_key
                 WHERE title.titulo_key IS NULL'
            ),
            'open_missing_vendor' => $scalar(
                $pdo,
                "SELECT COUNT(*)
                 FROM biomie_titulos_financeiros_canonicos
                 WHERE valor_aberto > 0
                   AND (codigo_vendedor_omie IS NULL OR codigo_vendedor_omie = '')"
            ),
        ];

        $connectionOk = (!$sslRequired || $metrics['ssl_cipher'] !== '')
            && $metrics['titles_total'] === $metrics['titles_distinct_keys']
            && $metrics['duplicate_omie_id_groups'] === 0
            && $metrics['duplicate_bridge_pairs'] === 0
            && $metrics['orphan_bridge_titles'] === 0;

        $report['connections'][$connection] = [
            'ok' => $connectionOk,
            'metrics' => $metrics,
        ];
        $report['ok'] = $report['ok'] && $connectionOk;
    } catch (\Throwable $exception) {
        $report['connections'][$connection] = [
            'ok' => false,
            'error' => $exception->getMessage(),
        ];
        $report['ok'] = false;
    }
}

$dbMetrics = $report['connections']['db']['metrics'] ?? null;
$flightMetrics = $report['connections']['flight_plan_db']['metrics'] ?? null;
if (is_array($dbMetrics) && is_array($flightMetrics)) {
    $report['same_read_model'] = $dbMetrics['database'] === $flightMetrics['database']
        && $dbMetrics['titles_total'] === $flightMetrics['titles_total']
        && $dbMetrics['bridge_items'] === $flightMetrics['bridge_items'];
    $report['ok'] = $report['ok'] && $report['same_read_model'];
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($report['ok'] ? 0 : 1);
