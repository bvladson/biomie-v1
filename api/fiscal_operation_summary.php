<?php

declare(strict_types=1);

/**
 * Fase H — H7: Resumo fiscal por escopo de operação Omie
 *
 * GET api/fiscal_operation_summary.php?start=YYYY-MM-DD&end=YYYY-MM-DD
 *
 * Retorna:
 * - Totais separados por operation_scope (commercial, remittance, unknown)
 * - Contagem de NFs por escopo
 * - Lista de nomes de operação únicos encontrados
 * - Percentual de cobertura de scope
 *
 * Requer: sales.view
 */

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\Support\OmieOperationScope;

require __DIR__ . '/bootstrap.php';
Auth::requireCapability('sales.view', true);

try {
    $range = Http::resolveDateRangeOrCompetencia();
    Http::validateDateRange($range['start'], $range['end']);

    $start = $range['start'];
    $end   = $range['end'];

    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $connection)) {
        Http::json(['ok' => false, 'error' => 'database_not_configured'], 503);
        return;
    }

    $pdo = Database::connect($config, $connection);

    // Verificar se colunas existem
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biomie_notas_fiscais_canonicas'
         AND COLUMN_NAME = 'operation_scope'"
    );
    $stmt->execute();
    $scopeColumnExists = (int) $stmt->fetchColumn() > 0;

    if (!$scopeColumnExists) {
        Http::json([
            'ok'         => false,
            'error'      => 'migration_pending',
            'message'    => 'Coluna operation_scope ainda não existe. Execute backfill_operation_scope.php --migrate.',
            'reason_code' => 'h3_migration_not_applied',
        ], 503);
        return;
    }

    // Totais por escopo
    $scopeStmt = $pdo->prepare(
        "SELECT
            operation_scope,
            COUNT(*) AS nf_count,
            COALESCE(SUM(valor_total), 0) AS total_valor,
            COALESCE(SUM(valor_mercadorias), 0) AS total_mercadorias
         FROM biomie_notas_fiscais_canonicas
         WHERE status_fiscal = 'autorizada'
           AND data_competencia BETWEEN :start AND :end
         GROUP BY operation_scope
         ORDER BY total_valor DESC"
    );
    $scopeStmt->execute([':start' => $start, ':end' => $end]);
    $scopeRows = $scopeStmt->fetchAll(\PDO::FETCH_ASSOC);

    $byScope = [];
    $totals  = ['nf_count' => 0, 'total_valor' => 0.0, 'total_mercadorias' => 0.0];
    foreach ($scopeRows as $row) {
        $scope = $row['operation_scope'];
        $byScope[$scope] = [
            'scope'             => $scope,
            'scope_label'       => OmieOperationScope::label($scope),
            'nf_count'          => (int) $row['nf_count'],
            'total_valor'       => round((float) $row['total_valor'], 2),
            'total_mercadorias' => round((float) $row['total_mercadorias'], 2),
        ];
        $totals['nf_count']          += (int) $row['nf_count'];
        $totals['total_valor']        += (float) $row['total_valor'];
        $totals['total_mercadorias']  += (float) $row['total_mercadorias'];
    }

    // Nomes de operação distintos
    $namesStmt = $pdo->prepare(
        "SELECT DISTINCT omie_operation_name, operation_scope, COUNT(*) as n
         FROM biomie_notas_fiscais_canonicas
         WHERE status_fiscal = 'autorizada'
           AND data_competencia BETWEEN :start AND :end
           AND omie_operation_name IS NOT NULL
         GROUP BY omie_operation_name, operation_scope
         ORDER BY n DESC
         LIMIT 30"
    );
    $namesStmt->execute([':start' => $start, ':end' => $end]);
    $operationNames = $namesStmt->fetchAll(\PDO::FETCH_ASSOC);

    // Cobertura de scope
    $total        = (int) ($totals['nf_count'] ?? 0);
    $unknownCount = (int) ($byScope[OmieOperationScope::UNKNOWN]['nf_count'] ?? 0);
    $coveragePct  = $total > 0 ? round((($total - $unknownCount) / $total) * 100, 1) : 100.0;

    Http::json([
        'ok'      => true,
        'period'  => [
            'start' => $start,
            'end'   => $end,
            'label' => $range['label'],
        ],
        'summary' => [
            'total_nf_authorized' => $total,
            'total_valor'         => round($totals['total_valor'], 2),
            'total_mercadorias'   => round($totals['total_mercadorias'], 2),
            'scope_coverage_pct'  => $coveragePct,
            'commercial_valor'    => round((float) ($byScope[OmieOperationScope::COMMERCIAL]['total_valor'] ?? 0), 2),
            'remittance_valor'    => round((float) ($byScope[OmieOperationScope::REMITTANCE]['total_valor'] ?? 0), 2),
            'unknown_count'       => $unknownCount,
        ],
        'by_scope'        => array_values($byScope),
        'operation_names' => $operationNames,
        'diagnostics'     => [
            'scope_column_exists' => true,
            'coverage_pct'        => $coveragePct,
            'needs_backfill'      => $unknownCount > 0,
            'backfill_cmd'        => $unknownCount > 0
                ? 'php tools/dev/backfill_operation_scope.php --apply'
                : null,
        ],
    ]);

} catch (\Throwable $e) {
    $logger->error('api/fiscal_operation_summary.php: erro.', ['message' => $e->getMessage()]);
    Http::json([
        'ok'      => false,
        'error'   => 'internal_error',
        'message' => 'Erro ao gerar resumo de operação fiscal.',
    ], 500);
}
