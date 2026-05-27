<?php

declare(strict_types=1);

/**
 * tools/dev/finance_payment_method_payload_audit.php — FIN.R4.2
 *
 * Diagnóstico do payload Omie para campos de método de pagamento.
 * Responde: o Omie envia cFormaPag/cTipoTitulo? O mapper descarta esses campos?
 * A tabela canônica já possui colunas para persistir?
 *
 * Usage:
 *   php tools/dev/finance_payment_method_payload_audit.php --start=2026-05-01 --end=2026-05-31 --pretty
 *   php tools/dev/finance_payment_method_payload_audit.php --pretty --sample-bronze=5
 *   php tools/dev/finance_payment_method_payload_audit.php --json
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;

Bootstrap::init();

$opts  = parseArgs($argv);
$json  = isset($opts['json']);
$pretty = isset($opts['pretty']) || !$json;
$start = (string) ($opts['start'] ?? date('Y-m-01'));
$end   = (string) ($opts['end'] ?? date('Y-m-t'));
$sampleBronze = (int) ($opts['sample-bronze'] ?? 3);

try {
    $config = new Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $report = buildReport($pdo, $start, $end, $sampleBronze);

    if ($json) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        printPretty($report);
    }
    exit(0);
} catch (Throwable $e) {
    $msg = ['ok' => false, 'error' => $e->getMessage()];
    echo ($json ? json_encode($msg) : 'ERRO: ' . $e->getMessage()) . PHP_EOL;
    exit(1);
}

/** @return array<string,mixed> */
function buildReport(PDO $pdo, string $start, string $end, int $sampleBronze): array
{
    // ── 1. Canonical field distribution ────────────────────────────────────────
    $canonDist = canonicalDistribution($pdo, $start, $end);

    // ── 2. Bronze payload analysis ─────────────────────────────────────────────
    $bronzeAnalysis = bronzeAnalysis($pdo, $sampleBronze);

    // ── 3. Omie method coverage per endpoint ──────────────────────────────────
    $endpointCoverage = endpointCoverage($pdo);

    // ── 4. Enrichment gap ─────────────────────────────────────────────────────
    $gap = computeGap($canonDist);

    // ── 5. Schema check ────────────────────────────────────────────────────────
    $schemaCheck = schemaCheck($pdo);

    return [
        'ok'                 => true,
        'periodo'            => ['start' => $start, 'end' => $end],
        'generated_at'       => date(DATE_ATOM),
        'canonical_distribution' => $canonDist,
        'bronze_analysis'    => $bronzeAnalysis,
        'endpoint_coverage'  => $endpointCoverage,
        'enrichment_gap'     => $gap,
        'schema_check'       => $schemaCheck,
        'diagnosis'          => buildDiagnosis($canonDist, $bronzeAnalysis, $gap, $schemaCheck),
        'recommended_action' => recommendedAction($canonDist, $bronzeAnalysis, $gap),
    ];
}

/** @return array<string,mixed> */
function canonicalDistribution(PDO $pdo, string $start, string $end): array
{
    $totalRow = $pdo->prepare(
        'SELECT COUNT(*) as total,
                SUM(CASE WHEN forma_pagamento IS NOT NULL AND forma_pagamento != \'\' THEN 1 ELSE 0 END) as with_forma,
                SUM(CASE WHEN especie_documento IS NOT NULL AND especie_documento != \'\' THEN 1 ELSE 0 END) as with_especie,
                SUM(CASE WHEN codigo_conta_corrente_omie IS NOT NULL AND codigo_conta_corrente_omie != \'\' THEN 1 ELSE 0 END) as with_conta
         FROM biomie_titulos_financeiros_canonicos
         WHERE data_competencia BETWEEN :s AND :e'
    );
    $totalRow->execute(['s' => $start, 'e' => $end]);
    $totals = $totalRow->fetch(PDO::FETCH_ASSOC) ?: [];

    $formaDistStmt = $pdo->prepare(
        'SELECT COALESCE(forma_pagamento, \'[null]\') as val, COUNT(*) as cnt
         FROM biomie_titulos_financeiros_canonicos
         WHERE data_competencia BETWEEN :s AND :e
         GROUP BY forma_pagamento ORDER BY cnt DESC LIMIT 15'
    );
    $formaDistStmt->execute(['s' => $start, 'e' => $end]);
    $formaDist = $formaDistStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $especieDistStmt = $pdo->prepare(
        'SELECT COALESCE(especie_documento, \'[null]\') as val, COUNT(*) as cnt
         FROM biomie_titulos_financeiros_canonicos
         WHERE data_competencia BETWEEN :s AND :e
         GROUP BY especie_documento ORDER BY cnt DESC LIMIT 15'
    );
    $especieDistStmt->execute(['s' => $start, 'e' => $end]);
    $especieDist = $especieDistStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // All-time totals (not filtered by period)
    $allTimeRow = $pdo->query(
        'SELECT COUNT(*) as total,
                SUM(CASE WHEN forma_pagamento IS NOT NULL AND forma_pagamento != \'\' THEN 1 ELSE 0 END) as with_forma,
                SUM(CASE WHEN especie_documento IS NOT NULL AND especie_documento != \'\' THEN 1 ELSE 0 END) as with_especie
         FROM biomie_titulos_financeiros_canonicos'
    );
    $allTime = $allTimeRow ? ($allTimeRow->fetch(PDO::FETCH_ASSOC) ?: []) : [];

    return [
        'periodo'              => $totals,
        'all_time'             => $allTime,
        'forma_pagamento_dist' => $formaDist,
        'especie_documento_dist' => $especieDist,
    ];
}

/** @return array<string,mixed> */
function bronzeAnalysis(PDO $pdo, int $sampleSize): array
{
    $tableExistsStmt = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biomie_omie_bronze'"
    );
    $tableExists = (bool) ($tableExistsStmt ? $tableExistsStmt->fetchColumn() : false);

    if (!$tableExists) {
        return ['bronze_exists' => false, 'message' => 'Tabela biomie_omie_bronze nao encontrada'];
    }

    // Count finance/title rows in bronze
    $countStmt = $pdo->query(
        "SELECT omie_method, COUNT(*) as cnt
         FROM biomie_omie_bronze
         WHERE domain_name = 'finance' AND entity_name = 'title'
         GROUP BY omie_method ORDER BY cnt DESC LIMIT 10"
    );
    $methodCounts = $countStmt ? ($countStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    // Sample latest payloads per method
    $paymentFields = [
        'cFormaPag', 'cTipoTitulo', 'cEspecie', 'cMeioPagamento',
        'descricao_portador', 'forma_pagamento', 'especie_documento',
        'cCodBoleto', 'nCodBoleto', 'tipo_titulo', 'tipo_documento',
    ];

    $sampleRows = [];
    $fieldCoverage = [];

    foreach ($methodCounts as $methodRow) {
        $method = (string) $methodRow['omie_method'];
        $sampleStmt = $pdo->prepare(
            "SELECT payload_json FROM biomie_omie_bronze
             WHERE domain_name = 'finance' AND entity_name = 'title' AND omie_method = :m
             ORDER BY ingested_at DESC LIMIT :lim"
        );
        $sampleStmt->bindValue(':m', $method);
        $sampleStmt->bindValue(':lim', $sampleSize, PDO::PARAM_INT);
        $sampleStmt->execute();
        $rows = $sampleStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $fieldPresence = array_fill_keys($paymentFields, 0);
        $allKeys = [];
        $samples = [];

        foreach ($rows as $payloadJson) {
            $payload = json_decode((string) $payloadJson, true);
            if (!is_array($payload)) continue;

            $flatKeys = collectAllKeys($payload);
            foreach ($flatKeys as $k) {
                $allKeys[$k] = ($allKeys[$k] ?? 0) + 1;
            }

            foreach ($paymentFields as $f) {
                if (in_array($f, $flatKeys, true)) {
                    $fieldPresence[$f]++;
                }
            }

            if (count($samples) < 2) {
                $sample = extractPaymentRelatedFields($payload);
                if ($sample !== []) $samples[] = $sample;
            }
        }

        $fieldCoverage[$method] = [
            'rows_sampled'   => count($rows),
            'field_presence' => $fieldPresence,
            'top_keys'       => array_keys(array_slice($allKeys, 0, 30, true)),
            'payment_samples' => $samples,
        ];
        $sampleRows[$method] = array_slice($rows, 0, 1);
    }

    return [
        'bronze_exists'   => true,
        'method_counts'   => $methodCounts,
        'field_coverage'  => $fieldCoverage,
        'payment_fields_checked' => $paymentFields,
    ];
}

/** @return array<string,mixed> */
function endpointCoverage(PDO $pdo): array
{
    $tableExistsStmt = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biomie_omie_bronze'"
    );
    $tableExists = (bool) ($tableExistsStmt ? $tableExistsStmt->fetchColumn() : false);

    if (!$tableExists) {
        return ['bronze_exists' => false];
    }

    $stmt = $pdo->query(
        "SELECT omie_method, endpoint, COUNT(*) as calls,
                MAX(ingested_at) as last_ingested
         FROM biomie_omie_bronze
         WHERE domain_name = 'finance'
         GROUP BY omie_method, endpoint
         ORDER BY calls DESC LIMIT 20"
    );
    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    return ['endpoints_used' => $rows];
}

/** @return array<string,mixed> */
function computeGap(array $canonDist): array
{
    $periodo = $canonDist['periodo'] ?? [];
    $total = (int) ($periodo['total'] ?? 0);
    $withForma = (int) ($periodo['with_forma'] ?? 0);
    $withEspecie = (int) ($periodo['with_especie'] ?? 0);

    $allTime = $canonDist['all_time'] ?? [];
    $totalAllTime = (int) ($allTime['total'] ?? 0);
    $withFormaAllTime = (int) ($allTime['with_forma'] ?? 0);
    $withEspecieAllTime = (int) ($allTime['with_especie'] ?? 0);

    return [
        'periodo' => [
            'total'              => $total,
            'with_forma_pag'     => $withForma,
            'without_forma_pag'  => $total - $withForma,
            'cobertura_forma_pct' => $total > 0 ? round($withForma / $total * 100, 1) : 0,
            'with_especie_doc'   => $withEspecie,
            'without_especie_doc'=> $total - $withEspecie,
            'cobertura_especie_pct' => $total > 0 ? round($withEspecie / $total * 100, 1) : 0,
        ],
        'all_time' => [
            'total'               => $totalAllTime,
            'with_forma_pag'      => $withFormaAllTime,
            'cobertura_forma_pct' => $totalAllTime > 0 ? round($withFormaAllTime / $totalAllTime * 100, 1) : 0,
            'with_especie_doc'    => $withEspecieAllTime,
            'cobertura_especie_pct' => $totalAllTime > 0 ? round($withEspecieAllTime / $totalAllTime * 100, 1) : 0,
        ],
    ];
}

/** @return array<string,mixed> */
function schemaCheck(PDO $pdo): array
{
    $cols = $pdo->query(
        "SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'biomie_titulos_financeiros_canonicos'
         ORDER BY ORDINAL_POSITION"
    );
    $allCols = $cols ? ($cols->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    $interesting = ['forma_pagamento', 'especie_documento', 'codigo_conta_corrente_omie'];
    $found = [];
    foreach ($allCols as $col) {
        if (in_array($col['COLUMN_NAME'], $interesting, true)) {
            $found[$col['COLUMN_NAME']] = [
                'exists'     => true,
                'type'       => $col['DATA_TYPE'],
                'max_length' => $col['CHARACTER_MAXIMUM_LENGTH'],
            ];
        }
    }

    foreach ($interesting as $f) {
        if (!isset($found[$f])) {
            $found[$f] = ['exists' => false];
        }
    }

    $missingFields = ['codigo_lancamento_omie', 'meio_pagamento_omie', 'codigo_forma_pagamento'];
    $missingCheck = [];
    $existingNames = array_column($allCols, 'COLUMN_NAME');
    foreach ($missingFields as $f) {
        $missingCheck[$f] = in_array($f, $existingNames, true) ? 'EXISTE' : 'NAO_EXISTE';
    }

    return [
        'columns_of_interest' => $found,
        'fields_not_in_schema' => $missingCheck,
        'total_columns'        => count($allCols),
    ];
}

/** @return array<string,mixed> */
function buildDiagnosis(array $canonDist, array $bronzeAnalysis, array $gap, array $schemaCheck): array
{
    $lines = [];

    $total = (int) ($gap['periodo']['total'] ?? 0);
    $pctForma = (float) ($gap['periodo']['cobertura_forma_pct'] ?? 0);

    if ($pctForma === 0.0 && $total > 0) {
        $lines[] = 'ZERO cobertura de forma_pagamento no período — campo nunca preenchido no banco.';
    } elseif ($pctForma < 20.0) {
        $lines[] = sprintf('Baixa cobertura de forma_pagamento: %.1f%% — maioria dos títulos sem método.', $pctForma);
    }

    // Bronze analysis
    $fieldCoverage = $bronzeAnalysis['field_coverage'] ?? [];
    $formaPagBronze = 0;
    $tipoTituloBronze = 0;
    foreach ($fieldCoverage as $method => $cov) {
        $formaPagBronze += (int) ($cov['field_presence']['cFormaPag'] ?? 0);
        $tipoTituloBronze += (int) ($cov['field_presence']['cTipoTitulo'] ?? 0);
    }

    if ($bronzeAnalysis['bronze_exists'] ?? false) {
        if ($formaPagBronze === 0 && $tipoTituloBronze === 0) {
            $lines[] = 'Bronze: cFormaPag e cTipoTitulo AUSENTES em todos os payloads analisados.';
            $lines[] = 'Causa provável: PesquisarLancamentos não retorna campos de método de pagamento.';
            $lines[] = 'Ação necessária: enriquecer via ListarContasReceber (endpoint diferente).';
        } elseif ($formaPagBronze > 0) {
            $lines[] = sprintf('Bronze: cFormaPag PRESENTE em %d amostras — verificar se mapper está extraindo corretamente.', $formaPagBronze);
        }
    } else {
        $lines[] = 'Bronze não disponível — não foi possível analisar payloads Omie.';
        $lines[] = 'Hipótese principal: PesquisarLancamentos não retorna cFormaPag/cTipoTitulo.';
    }

    // Schema
    $schemaFields = $schemaCheck['columns_of_interest'] ?? [];
    if (($schemaFields['forma_pagamento']['exists'] ?? false) && ($schemaFields['especie_documento']['exists'] ?? false)) {
        $lines[] = 'Schema: forma_pagamento e especie_documento existem na tabela canônica — não é necessário migration.';
    }

    return ['findings' => $lines];
}

/** @return string */
function recommendedAction(array $canonDist, array $bronzeAnalysis, array $gap): string
{
    $total = (int) ($gap['periodo']['total'] ?? 0);
    if ($total === 0) return 'Nenhum título no período — sem ação.';

    $pctForma = (float) ($gap['periodo']['cobertura_forma_pct'] ?? 0);
    $fieldCoverage = $bronzeAnalysis['field_coverage'] ?? [];
    $formaPagBronze = 0;
    foreach ($fieldCoverage as $cov) {
        $formaPagBronze += (int) ($cov['field_presence']['cFormaPag'] ?? 0);
    }

    if ($pctForma === 0.0) {
        if ($formaPagBronze === 0) {
            return 'ENRIQUECER via ListarContasReceber: adicionar chamada ao endpoint /financas/contasreceber/ para obter cFormaPag e cTipoTitulo.';
        }
        return 'CORRIGIR MAPPER: bronze tem cFormaPag mas canonical está vazio — investigar extractFromPayload.';
    }

    if ($pctForma < 50.0) {
        return 'ENRIQUECER parcialmente: alguns títulos têm método, outros não — considerar enrichment via ListarContasReceber para os sem método.';
    }

    return 'OK: cobertura aceitável. Revisar títulos residuais sem método.';
}

/** @return list<string> */
function collectAllKeys(array $arr, string $prefix = ''): array
{
    $keys = [];
    foreach ($arr as $k => $v) {
        $keys[] = (string) $k;
        if (is_array($v) && count($v) < 50) {
            foreach (collectAllKeys($v) as $nested) {
                $keys[] = $nested;
            }
        }
    }
    return array_unique($keys);
}

/** @return array<string,mixed> */
function extractPaymentRelatedFields(array $payload): array
{
    $paymentKeys = [
        'cFormaPag', 'cTipoTitulo', 'cEspecie', 'cMeioPagamento', 'descricao_portador',
        'forma_pagamento', 'especie_documento', 'cCodBoleto', 'tipo_titulo', 'cNomeTitulo',
    ];
    $result = [];
    foreach ($paymentKeys as $k) {
        $val = findKeyRecursive($payload, $k);
        if ($val !== null) {
            $result[$k] = $val;
        }
    }
    return $result;
}

function findKeyRecursive(array $arr, string $key): mixed
{
    foreach ($arr as $k => $v) {
        if ((string) $k === $key) return $v;
        if (is_array($v)) {
            $found = findKeyRecursive($v, $key);
            if ($found !== null) return $found;
        }
    }
    return null;
}

/** @param array<string,mixed> $report */
function printPretty(array $report): void
{
    $sep = str_repeat('─', 72);

    echo PHP_EOL . '═══ Finance Payment Method Payload Audit — FIN.R4.2 ═══' . PHP_EOL;
    echo 'Período: ' . ($report['periodo']['start'] ?? '') . ' → ' . ($report['periodo']['end'] ?? '') . PHP_EOL;
    echo 'Gerado em: ' . ($report['generated_at'] ?? '') . PHP_EOL;

    // ── Canonical Distribution
    echo PHP_EOL . $sep . PHP_EOL . 'DISTRIBUIÇÃO CANÔNICA (período)' . PHP_EOL . $sep . PHP_EOL;
    $gap = $report['enrichment_gap']['periodo'] ?? [];
    echo sprintf("  Total de títulos                 : %d\n", $gap['total'] ?? 0);
    echo sprintf("  Com forma_pagamento              : %d (%.1f%%)\n", $gap['with_forma_pag'] ?? 0, $gap['cobertura_forma_pct'] ?? 0);
    echo sprintf("  Sem forma_pagamento              : %d\n", $gap['without_forma_pag'] ?? 0);
    echo sprintf("  Com especie_documento            : %d (%.1f%%)\n", $gap['with_especie_doc'] ?? 0, $gap['cobertura_especie_pct'] ?? 0);

    $allTime = $report['enrichment_gap']['all_time'] ?? [];
    echo PHP_EOL . '  ALL-TIME:' . PHP_EOL;
    echo sprintf("  Total canônico                   : %d\n", $allTime['total'] ?? 0);
    echo sprintf("  Com forma_pagamento              : %d (%.1f%%)\n", $allTime['with_forma_pag'] ?? 0, $allTime['cobertura_forma_pct'] ?? 0);

    // ── Distributions
    $dist = $report['canonical_distribution']['forma_pagamento_dist'] ?? [];
    if ($dist !== []) {
        echo PHP_EOL . '  Distribuição forma_pagamento:' . PHP_EOL;
        foreach ($dist as $row) {
            echo sprintf("    %-40s : %d\n", $row['val'], $row['cnt']);
        }
    }

    $distE = $report['canonical_distribution']['especie_documento_dist'] ?? [];
    if ($distE !== []) {
        echo PHP_EOL . '  Distribuição especie_documento:' . PHP_EOL;
        foreach ($distE as $row) {
            echo sprintf("    %-40s : %d\n", $row['val'], $row['cnt']);
        }
    }

    // ── Bronze Analysis
    $bronze = $report['bronze_analysis'] ?? [];
    echo PHP_EOL . $sep . PHP_EOL . 'ANÁLISE DO BRONZE (payloads Omie)' . PHP_EOL . $sep . PHP_EOL;
    if (!($bronze['bronze_exists'] ?? false)) {
        echo '  ' . ($bronze['message'] ?? 'Bronze não disponível') . PHP_EOL;
    } else {
        $methodCounts = $bronze['method_counts'] ?? [];
        echo '  Métodos Omie registrados:' . PHP_EOL;
        foreach ($methodCounts as $mc) {
            echo sprintf("    %-35s : %d chamadas\n", $mc['omie_method'], $mc['cnt']);
        }

        $fieldCov = $bronze['field_coverage'] ?? [];
        foreach ($fieldCov as $method => $cov) {
            echo PHP_EOL . "  [$method] — {$cov['rows_sampled']} amostras:" . PHP_EOL;
            echo '    Campos de pagamento encontrados:' . PHP_EOL;
            foreach ($cov['field_presence'] as $field => $count) {
                $icon = $count > 0 ? '[OK]' : '[--]';
                echo sprintf("      %s %-30s : %d amostras\n", $icon, $field, $count);
            }
            if ($cov['payment_samples'] !== []) {
                echo '    Amostra de campos de pagamento:' . PHP_EOL;
                foreach ($cov['payment_samples'] as $i => $sample) {
                    echo '      Amostra #' . ($i + 1) . ': ' . json_encode($sample, JSON_UNESCAPED_UNICODE) . PHP_EOL;
                }
            } else {
                echo '    Nenhum campo de pagamento encontrado nas amostras.' . PHP_EOL;
            }
        }
    }

    // ── Endpoints
    $eps = $report['endpoint_coverage']['endpoints_used'] ?? [];
    if ($eps !== []) {
        echo PHP_EOL . $sep . PHP_EOL . 'ENDPOINTS OMIE USADOS (financeiro)' . PHP_EOL . $sep . PHP_EOL;
        foreach ($eps as $ep) {
            echo sprintf("  %-35s %s\n", $ep['omie_method'], $ep['endpoint'] ?? '');
            echo sprintf("    %d chamadas | última: %s\n", $ep['calls'], $ep['last_ingested'] ?? '');
        }
    }

    // ── Schema
    echo PHP_EOL . $sep . PHP_EOL . 'SCHEMA biomie_titulos_financeiros_canonicos' . PHP_EOL . $sep . PHP_EOL;
    $schemaFields = $report['schema_check']['columns_of_interest'] ?? [];
    foreach ($schemaFields as $col => $info) {
        $icon = ($info['exists'] ?? false) ? '[OK]' : '[--]';
        $detail = ($info['exists'] ?? false)
            ? sprintf('%s(%s)', $info['type'], $info['max_length'] ?? '?')
            : 'NÃO EXISTE — migration necessária';
        echo sprintf("  %s %-35s : %s\n", $icon, $col, $detail);
    }
    $missing = $report['schema_check']['fields_not_in_schema'] ?? [];
    if ($missing !== []) {
        echo PHP_EOL . '  Campos NOT no schema (sem migration necessária):' . PHP_EOL;
        foreach ($missing as $f => $status) {
            echo sprintf("    %-35s : %s\n", $f, $status);
        }
    }

    // ── Diagnosis
    echo PHP_EOL . $sep . PHP_EOL . 'DIAGNÓSTICO' . PHP_EOL . $sep . PHP_EOL;
    $findings = $report['diagnosis']['findings'] ?? [];
    foreach ($findings as $line) {
        echo '  → ' . $line . PHP_EOL;
    }

    // ── Recommended Action
    echo PHP_EOL . $sep . PHP_EOL . 'AÇÃO RECOMENDADA' . PHP_EOL . $sep . PHP_EOL;
    echo '  ' . ($report['recommended_action'] ?? '') . PHP_EOL . PHP_EOL;
}

/** @return array<string,mixed> */
function parseArgs(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) continue;
        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
        $out[$key] = $value;
    }
    return $out;
}
