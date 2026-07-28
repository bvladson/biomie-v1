<?php

declare(strict_types=1);

use BIomie\Config;
use BIomie\Database;

require dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require dirname(__DIR__) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$apply = in_array('--apply', $argv ?? [], true);
$pdo = Database::connect(new Config(), 'db');

$classification = "
    CASE
        WHEN cidade_norm IS NULL OR TRIM(cidade_norm) = '' THEN 'missing'
        WHEN bairro_norm IS NULL OR TRIM(bairro_norm) = ''
          OR LOWER(TRIM(bairro_norm)) IN ('nao informado', 'nao_informado', 'sem bairro', 'sem_bairro')
            THEN 'city_only'
        ELSE 'resolved'
    END
";

$before = $pdo->query(
    "SELECT {$classification} AS expected_status, COUNT(*) AS total
     FROM biomie_clientes_geo_dim
     GROUP BY expected_status
     ORDER BY expected_status"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$mismatches = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM biomie_clientes_geo_dim
     WHERE COALESCE(geo_status, '') <> {$classification}"
)->fetchColumn();

echo json_encode([
    'mode' => $apply ? 'apply' : 'dry-run',
    'expected' => array_map('intval', $before),
    'status_mismatches' => $mismatches,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

if (!$apply) {
    exit(0);
}

$pdo->beginTransaction();
try {
    $updated = $pdo->exec(
        "UPDATE biomie_clientes_geo_dim
         SET geo_status = {$classification}
         WHERE COALESCE(geo_status, '') <> {$classification}"
    );

    $queued = $pdo->exec(
        "INSERT INTO biomie_clientes_para_enriquecer
            (codigo_cliente_omie, source_origin, status, motivo)
         SELECT
            codigo_cliente_omie,
            'geo_status_reconcile',
            'pendente',
            CASE
                WHEN cidade_norm IS NULL OR TRIM(cidade_norm) = '' THEN 'cidade_ausente'
                ELSE 'bairro_ausente'
            END
         FROM biomie_clientes_geo_dim
         WHERE {$classification} IN ('missing', 'city_only')
         ON DUPLICATE KEY UPDATE
            source_origin = VALUES(source_origin),
            status = 'pendente',
            motivo = VALUES(motivo)"
    );

    $pdo->commit();
    echo json_encode([
        'updated_status_rows' => (int) $updated,
        'queue_affected_rows' => (int) $queued,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
