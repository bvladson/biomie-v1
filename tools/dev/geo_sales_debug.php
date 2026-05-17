<?php

/**
 * tools/dev/geo_sales_debug.php — Diagnóstico completo do pipeline geo/vendas.
 *
 * Rastreia cada camada do pipeline para identificar onde os números somem:
 *   Layer 1: biomie_notas_fiscais_canonicas  (canonical)
 *   Layer 2: biomie_notas_fiscais_itens_canonicos (itens canonical)
 *   Layer 3: mart_cliente_periodo            (gold mart por competência)
 *   Layer 4: mart_compradores_por_geo        (geo mart — fonte do mapa)
 *   Layer 5: biomie_clientes_geo_dim         (cobertura geo dos clientes)
 *
 * Uso:
 *   php tools/dev/geo_sales_debug.php --start=2026-05-01 --end=2026-05-09
 *   php tools/dev/geo_sales_debug.php --start=2026-05-01 --end=2026-05-31 --city=Fortaleza --json
 *
 * Valores esperados (Omie Excel consolidado(3).xlsx — Maio 2026):
 *   items=121, unique_invoices=78, total_mercadoria=130109.81,
 *   raw_neighborhoods=62, normalized_neighborhoods=56
 */

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Support\Geo\GeoKeyNormalizer;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$root   = dirname(__DIR__, 2);
$config = new Config();
$logger = new Logger($root . '/storage/logs/biomie.log');

// ── Parse args ────────────────────────────────────────────────────────────────

$opts = getopt('', ['start:', 'end:', 'city:', 'city-key:', 'bairro:', 'bairro-key:', 'normalize-only', 'check-keys', 'json', 'explain', 'help']);

if (isset($opts['help'])) {
    echo "Usage: php tools/dev/geo_sales_debug.php [options]\n";
    echo "  --start=YYYY-MM-DD     Start date (default: 2026-05-01)\n";
    echo "  --end=YYYY-MM-DD       End date (default: 2026-05-31)\n";
    echo "  --city=Fortaleza       City display name\n";
    echo "  --city-key=fortaleza   City key (auto from --city if absent)\n";
    echo "  --bairro=Cocó          Neighborhood display name\n";
    echo "  --bairro-key=coco      Normalized key (auto from --bairro if absent)\n";
    echo "  --normalize-only       Only show normalization result, no DB queries\n";
    echo "  --check-keys           Show bairro_key/bairro_norm inconsistencies in mart\n";
    echo "  --json                 Output raw JSON\n";
    echo "  --explain              Show SQL EXPLAINs\n";
    exit(0);
}

$startDate    = $opts['start'] ?? '2026-05-01';
$endDate      = $opts['end']   ?? '2026-05-31';
$cityRaw      = trim((string) ($opts['city'] ?? 'Fortaleza'));
$bairroRaw    = trim((string) ($opts['bairro'] ?? ''));
$bairroKey    = trim((string) ($opts['bairro-key'] ?? ''));
$cityKey      = trim((string) ($opts['city-key'] ?? ''));
$jsonMode     = isset($opts['json']);
$explainMode  = isset($opts['explain']);
$normalizeOnly = isset($opts['normalize-only']);
$checkKeys    = isset($opts['check-keys']);

// Use GeoKeyNormalizer for consistent key computation.
$cityKeyResolved  = $cityKey !== '' ? $cityKey : GeoKeyNormalizer::makeCityKey($cityRaw);
$cityNorm         = GeoKeyNormalizer::normalizeCity($cityRaw);

if ($bairroKey !== '') {
    // Already supplied — re-normalize to guarantee underscore format.
    $bairroKey = GeoKeyNormalizer::makeNeighborhoodKey($bairroKey);
} elseif ($bairroRaw !== '') {
    $bairroKey = GeoKeyNormalizer::makeNeighborhoodKey($bairroRaw);
}
$bairroNorm = $bairroKey !== '' ? GeoKeyNormalizer::keyToNorm($bairroKey) : '';
$city = $cityNorm;

// Competência = year-month from start date
$startComp = substr($startDate, 0, 7);
$endComp   = substr($endDate, 0, 7);

// ── Normalize-only mode ───────────────────────────────────────────────────────

if ($normalizeOnly) {
    $explain = GeoKeyNormalizer::explain($bairroRaw ?: $bairroKey);
    $out = [
        'input'      => ['city' => $cityRaw, 'bairro' => $bairroRaw ?: $bairroKey],
        'normalized' => [
            'city_key'   => $cityKeyResolved,
            'bairro_key' => $bairroKey ?: $explain['bairro_key'],
            'bairro_norm' => $bairroNorm,
            'bairro_nome' => $explain['display_name'],
        ],
        'engine'       => $explain['engine'],
        'alias_applied' => $explain['alias_applied'],
    ];
    if ($jsonMode) {
        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "Input      : city={$cityRaw}, bairro=" . ($bairroRaw ?: $bairroKey) . "\n";
        echo "city_key   : {$cityKeyResolved}\n";
        echo "bairro_key : " . ($bairroKey ?: $explain['bairro_key']) . "\n";
        echo "bairro_norm: {$bairroNorm}\n";
        echo "bairro_nome: {$explain['display_name']}\n";
        echo "engine     : {$explain['engine']}\n";
    }
    exit(0);
}

// ── Connect ───────────────────────────────────────────────────────────────────

$report = [
    'params' => [
        'start'       => $startDate,
        'end'         => $endDate,
        'start_comp'  => $startComp,
        'end_comp'    => $endComp,
        'city'        => $city,
        'city_key'    => $cityKeyResolved,
        'bairro_raw'  => $bairroRaw ?: null,
        'bairro_key'  => $bairroKey ?: null,
        'bairro_norm' => $bairroNorm ?: null,
        'generated_at' => date('c'),
    ],
    'expected_from_omie_extract' => [
        'source'                 => 'Omie Excel consolidado(3).xlsx — Maio 2026',
        'rows_items'             => 121,
        'unique_invoices'        => 78,
        'total_merchandise'      => 130109.81,
        'raw_neighborhoods'      => 62,
        'normalized_neighborhoods' => 56,
        'note'                   => 'Valores de referência para validação do pipeline (1-9 Maio 2026)',
    ],
    'layers' => [],
    'pipeline_diagnosis' => [],
    'diagnostics' => [],
    'warnings' => [],
];

$report['normalized'] = [
    'city_key'   => $cityKeyResolved,
    'bairro_key' => $bairroKey ?: null,
    'bairro_norm' => $bairroNorm ?: null,
];

$pdo = null;
$connName = null;
try {
    [$pdo, $connName] = Database::getGeoConnection($config);
    $report['connection'] = ['ok' => true, 'connection' => $connName];
} catch (\Throwable $e) {
    $report['connection'] = ['ok' => false, 'error' => $e->getMessage()];
    outputAndExit($report, $jsonMode);
}

// ── Helper functions ──────────────────────────────────────────────────────────

function safeQuery(PDO $pdo, string $sql, array $params = []): array|false
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return false;
    }
}

function safeQueryAll(PDO $pdo, string $sql, array $params = []): array|false
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return false;
    }
}

function tableExists(PDO $pdo, string $table): bool
{
    try {
        $pdo->query("SELECT 1 FROM `{$table}` LIMIT 1");
        return true;
    } catch (\Throwable) {
        return false;
    }
}

function colExists(PDO $pdo, string $table, string $col): bool
{
    try {
        return (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$col}'"
        )->fetchColumn() > 0;
    } catch (\Throwable) {
        return false;
    }
}

// ── Check-keys mode ───────────────────────────────────────────────────────────

if ($checkKeys) {
    $ck = [
        'bairro_key_col_exists'      => false,
        'cidade_key_col_exists'      => false,
        'rows_without_bairro_key'    => 0,
        'bairro_norm_uppercase_count' => 0,
        'bairro_norm_accent_count'   => 0,
        'multi_name_conflicts'       => [],
        'sample_bairro_keys'         => [],
        'issues'                     => [],
    ];

    if (!tableExists($pdo, 'mart_compradores_por_geo')) {
        $ck['issues'][] = ['severity' => 'critical', 'issue' => 'Tabela mart_compradores_por_geo nao existe.', 'action' => 'Execute geo:materialize primeiro.'];
    } else {
        $ck['bairro_key_col_exists'] = colExists($pdo, 'mart_compradores_por_geo', 'bairro_key');
        $ck['cidade_key_col_exists'] = colExists($pdo, 'mart_compradores_por_geo', 'cidade_key');

        if (!$ck['bairro_key_col_exists']) {
            $ck['issues'][] = ['severity' => 'critical', 'issue' => 'Coluna bairro_key nao existe em mart_compradores_por_geo.', 'action' => 'Execute: php bin/migrate.php (migration Version20260517000002)'];
        } else {
            $nullKeys = safeQuery($pdo,
                "SELECT COUNT(*) AS cnt FROM mart_compradores_por_geo
                 WHERE (bairro_key IS NULL OR bairro_key = '') AND COALESCE(faturamento, 0) > 0"
            );
            $ck['rows_without_bairro_key'] = (int) ($nullKeys['cnt'] ?? 0);
            if ($ck['rows_without_bairro_key'] > 0) {
                $ck['issues'][] = ['severity' => 'critical', 'issue' => $ck['rows_without_bairro_key'] . ' linhas com faturamento sem bairro_key.', 'action' => 'Execute: php tools/dev/geo_keys_backfill.php --apply --table=mart_compradores_por_geo'];
            }

            $multiNames = safeQueryAll($pdo,
                "SELECT bairro_key, COUNT(DISTINCT COALESCE(bairro_nome, bairro_norm, '')) AS name_variants
                 FROM mart_compradores_por_geo
                 WHERE bairro_key IS NOT NULL AND bairro_key != ''
                 GROUP BY bairro_key HAVING name_variants > 1
                 LIMIT 10"
            );
            $ck['multi_name_conflicts'] = $multiNames ?: [];

            $sample = safeQueryAll($pdo,
                "SELECT bairro_key, bairro_norm, cidade_key, cidade_norm,
                        COUNT(*) AS rows, ROUND(SUM(faturamento), 2) AS rev
                 FROM mart_compradores_por_geo
                 WHERE bairro_key IS NOT NULL AND bairro_key != ''
                 GROUP BY bairro_key, bairro_norm, cidade_key, cidade_norm
                 ORDER BY rev DESC LIMIT 20"
            );
            $ck['sample_bairro_keys'] = $sample ?: [];
        }

        $caseMis = safeQuery($pdo,
            "SELECT COUNT(DISTINCT bairro_norm) AS cnt FROM mart_compradores_por_geo
             WHERE bairro_norm IS NOT NULL AND bairro_norm != ''
               AND bairro_norm COLLATE utf8mb4_bin != LOWER(bairro_norm)"
        );
        $ck['bairro_norm_uppercase_count'] = (int) ($caseMis['cnt'] ?? 0);
        if ($ck['bairro_norm_uppercase_count'] > 0) {
            $ck['issues'][] = ['severity' => 'warning', 'issue' => $ck['bairro_norm_uppercase_count'] . ' bairro_norm com maiusculas.', 'action' => 'Rematerialize: os builders normalizam para lowercase.'];
        }

        try {
            $accentMis = safeQuery($pdo,
                "SELECT COUNT(DISTINCT bairro_norm) AS cnt FROM mart_compradores_por_geo
                 WHERE bairro_norm IS NOT NULL AND bairro_norm != ''
                   AND LOWER(bairro_norm) REGEXP '[^a-z0-9 _-]'"
            );
            $ck['bairro_norm_accent_count'] = (int) ($accentMis['cnt'] ?? 0);
            if ($ck['bairro_norm_accent_count'] > 0) {
                $ck['issues'][] = ['severity' => 'warning', 'issue' => $ck['bairro_norm_accent_count'] . ' bairro_norm com acentos/caracteres especiais.', 'action' => 'Rematerialize: backfill recalcula bairro_key via PHP (NFD strip).'];
            }
        } catch (\Throwable) {
            $ck['bairro_norm_accent_count'] = -1;
        }

        if ($ck['bairro_norm_uppercase_count'] > 0 || $ck['bairro_norm_accent_count'] > 0) {
            $bad = safeQueryAll($pdo,
                "SELECT DISTINCT bairro_norm FROM mart_compradores_por_geo
                 WHERE bairro_norm IS NOT NULL AND bairro_norm != ''
                   AND (bairro_norm COLLATE utf8mb4_bin != LOWER(bairro_norm)
                        OR LOWER(bairro_norm) REGEXP '[^a-z0-9 _-]')
                 LIMIT 20"
            );
            $ck['bad_bairro_norm_samples'] = array_column($bad ?: [], 'bairro_norm');
        }
    }

    $ckOut = ['check_keys' => $ck];
    if ($jsonMode) {
        echo json_encode($ckOut, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "\n=== CHECK-KEYS: mart_compradores_por_geo ===\n";
        echo "bairro_key col : " . ($ck['bairro_key_col_exists'] ? 'SIM' : 'NAO') . "\n";
        echo "cidade_key col : " . ($ck['cidade_key_col_exists'] ? 'SIM' : 'NAO') . "\n";
        if ($ck['rows_without_bairro_key'] !== 0) {
            echo "rows sem bairro_key (com faturamento): " . $ck['rows_without_bairro_key'] . "\n";
        }
        echo "bairro_norm uppercase : " . ($ck['bairro_norm_uppercase_count'] ?? 'N/A') . "\n";
        echo "bairro_norm acentos   : " . ($ck['bairro_norm_accent_count'] ?? 'N/A') . "\n";
        if (!empty($ck['issues'])) {
            echo "\nISSUES:\n";
            foreach ($ck['issues'] as $issue) {
                echo "  [{$issue['severity']}] {$issue['issue']}\n";
                if (!empty($issue['action'])) echo "      → {$issue['action']}\n";
            }
        }
        if (!empty($ck['bad_bairro_norm_samples'])) {
            echo "\nAmostras de bairro_norm com problema:\n";
            foreach ($ck['bad_bairro_norm_samples'] as $s) {
                echo '  ' . json_encode($s, JSON_UNESCAPED_UNICODE) . "\n";
            }
        }
        if (!empty($ck['multi_name_conflicts'])) {
            echo "\nConflitos de nome por bairro_key:\n";
            foreach ($ck['multi_name_conflicts'] as $row) {
                echo "  " . ($row['bairro_key'] ?? '?') . " — " . ($row['name_variants'] ?? 0) . " nomes distintos\n";
            }
        }
        if (!empty($ck['sample_bairro_keys'])) {
            echo "\nTop bairro_keys (por faturamento):\n";
            printf("  %-32s  %-32s  %s\n", 'bairro_key', 'bairro_norm', 'faturamento');
            echo "  " . str_repeat('-', 78) . "\n";
            foreach ($ck['sample_bairro_keys'] as $row) {
                printf("  %-32s  %-32s  R$ %s\n",
                    substr($row['bairro_key'] ?? '?', 0, 32),
                    substr($row['bairro_norm'] ?? '?', 0, 32),
                    number_format((float) ($row['rev'] ?? 0), 2, ',', '.')
                );
            }
        }
    }
    exit(0);
}

// ── Layer 1: biomie_notas_fiscais_canonicas ───────────────────────────────────

$layer1 = ['name' => 'biomie_notas_fiscais_canonicas', 'exists' => false];

if (tableExists($pdo, 'biomie_notas_fiscais_canonicas')) {
    $layer1['exists'] = true;

    // Fiscal domain: data_referencia = COALESCE(data_competencia, data_emissao)
    $r = safeQuery($pdo,
        "SELECT
            COUNT(*)                                              AS total_nfs,
            SUM(status_fiscal = 'autorizada')                    AS nfs_autorizadas,
            COUNT(DISTINCT numero_nf)                            AS distinct_numeros,
            COUNT(DISTINCT codigo_cliente_omie)                  AS distinct_clientes,
            COALESCE(SUM(CASE WHEN status_fiscal = 'autorizada' THEN valor_total ELSE 0 END), 0)        AS valor_total_autorizada,
            COALESCE(SUM(CASE WHEN status_fiscal = 'autorizada' THEN valor_mercadorias ELSE 0 END), 0)  AS valor_mercadorias_autorizada,
            MIN(COALESCE(data_competencia, data_emissao)) AS min_data,
            MAX(COALESCE(data_competencia, data_emissao)) AS max_data
         FROM biomie_notas_fiscais_canonicas
         WHERE COALESCE(data_competencia, data_emissao) BETWEEN :start AND :end",
        ['start' => $startDate, 'end' => $endDate]
    );

    if ($r !== false) {
        $layer1['period_filter'] = [
            'total_nfs'                   => (int) ($r['total_nfs'] ?? 0),
            'nfs_autorizadas'             => (int) ($r['nfs_autorizadas'] ?? 0),
            'distinct_numeros_nf'         => (int) ($r['distinct_numeros'] ?? 0),
            'distinct_clientes'           => (int) ($r['distinct_clientes'] ?? 0),
            'valor_total_autorizada'      => (float) ($r['valor_total_autorizada'] ?? 0),
            'valor_mercadorias_autorizada' => (float) ($r['valor_mercadorias_autorizada'] ?? 0),
            'min_data'                    => $r['min_data'] ?? null,
            'max_data'                    => $r['max_data'] ?? null,
        ];
    } else {
        $layer1['period_filter'] = ['error' => 'Query falhou'];
    }

    // Check all-time counts for reference
    $rAll = safeQuery($pdo,
        "SELECT COUNT(*) AS total, SUM(status_fiscal = 'autorizada') AS autorizadas
         FROM biomie_notas_fiscais_canonicas"
    );
    $layer1['all_time'] = $rAll !== false ? [
        'total'       => (int) ($rAll['total'] ?? 0),
        'autorizadas' => (int) ($rAll['autorizadas'] ?? 0),
    ] : ['error' => 'Query falhou'];

    // Sample of NFs in period for sanity
    $sample = safeQueryAll($pdo,
        "SELECT numero_nf, serie_nf, status_fiscal,
                COALESCE(data_competencia, data_emissao) AS data_ref,
                valor_total, valor_mercadorias
         FROM biomie_notas_fiscais_canonicas
         WHERE COALESCE(data_competencia, data_emissao) BETWEEN :start AND :end
           AND status_fiscal = 'autorizada'
         ORDER BY data_ref DESC LIMIT 5",
        ['start' => $startDate, 'end' => $endDate]
    );
    $layer1['sample_authorized'] = $sample ?: [];
} else {
    $layer1['error'] = 'Tabela não existe';
}

$report['layers']['layer1_canonical_nf'] = $layer1;

// ── Layer 2: biomie_notas_fiscais_itens_canonicos ─────────────────────────────

$layer2 = ['name' => 'biomie_notas_fiscais_itens_canonicos', 'exists' => false];

if (tableExists($pdo, 'biomie_notas_fiscais_itens_canonicos')) {
    $layer2['exists'] = true;

    // Join with NF header for period filter
    $r = safeQuery($pdo,
        "SELECT
            COUNT(i.id)                                                 AS total_items,
            COUNT(DISTINCT i.nota_key)                                  AS distinct_notas,
            COALESCE(SUM(i.valor_total_item), 0)                        AS soma_valor_item,
            COUNT(DISTINCT n.codigo_cliente_omie)                       AS distinct_clientes
         FROM biomie_notas_fiscais_itens_canonicos i
         JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key
         WHERE COALESCE(n.data_competencia, n.data_emissao) BETWEEN :start AND :end
           AND n.status_fiscal = 'autorizada'",
        ['start' => $startDate, 'end' => $endDate]
    );

    if ($r !== false) {
        $layer2['period_filter'] = [
            'total_items'    => (int) ($r['total_items'] ?? 0),
            'distinct_notas' => (int) ($r['distinct_notas'] ?? 0),
            'soma_valor_item' => (float) ($r['soma_valor_item'] ?? 0),
            'distinct_clientes' => (int) ($r['distinct_clientes'] ?? 0),
        ];
    } else {
        $layer2['period_filter'] = ['error' => 'Query falhou — tabela de itens pode não existir'];
    }
} else {
    // Try alternate table name
    if (tableExists($pdo, 'biomie_itens_nf_canonicos')) {
        $layer2['alternate_table'] = 'biomie_itens_nf_canonicos';
    }
    $layer2['error'] = 'Tabela biomie_notas_fiscais_itens_canonicos não existe';
}

$report['layers']['layer2_nf_items'] = $layer2;

// ── Layer 3: mart_cliente_periodo ─────────────────────────────────────────────

$layer3 = ['name' => 'mart_cliente_periodo', 'exists' => false];

if (tableExists($pdo, 'mart_cliente_periodo')) {
    $layer3['exists'] = true;

    $r = safeQuery($pdo,
        "SELECT
            COUNT(*)                            AS total_rows,
            COUNT(DISTINCT codigo_cliente_omie) AS distinct_clientes,
            COALESCE(SUM(nfes_emitidas), 0)     AS soma_nfes,
            COALESCE(SUM(valor_nfe), 0)         AS soma_valor_nfe,
            COALESCE(SUM(pedidos_faturados), 0) AS soma_pedidos,
            COALESCE(SUM(valor_faturado), 0)    AS soma_faturado,
            MIN(competencia)                    AS min_comp,
            MAX(competencia)                    AS max_comp
         FROM mart_cliente_periodo
         WHERE competencia BETWEEN :sc AND :ec",
        ['sc' => $startComp, 'ec' => $endComp]
    );

    if ($r !== false) {
        $layer3['period_filter'] = [
            'total_rows'        => (int) ($r['total_rows'] ?? 0),
            'distinct_clientes' => (int) ($r['distinct_clientes'] ?? 0),
            'soma_nfes'         => (int) ($r['soma_nfes'] ?? 0),
            'soma_valor_nfe'    => (float) ($r['soma_valor_nfe'] ?? 0),
            'soma_pedidos'      => (int) ($r['soma_pedidos'] ?? 0),
            'soma_faturado'     => (float) ($r['soma_faturado'] ?? 0),
            'competencias'      => [$r['min_comp'] ?? null, $r['max_comp'] ?? null],
        ];
    } else {
        $layer3['period_filter'] = ['error' => 'Query falhou'];
    }

    // All-time check
    $rAll = safeQuery($pdo, "SELECT COUNT(*) AS total, COUNT(DISTINCT competencia) AS competencias FROM mart_cliente_periodo");
    $layer3['all_time'] = $rAll !== false ? [
        'total_rows'         => (int) ($rAll['total'] ?? 0),
        'distinct_competencias' => (int) ($rAll['competencias'] ?? 0),
    ] : ['error' => 'Query falhou'];

    // List available competencias
    $comps = safeQueryAll($pdo,
        "SELECT competencia, COUNT(*) AS clientes, SUM(nfes_emitidas) AS nfes, SUM(valor_nfe) AS valor_nfe
         FROM mart_cliente_periodo GROUP BY competencia ORDER BY competencia DESC LIMIT 12"
    );
    $layer3['available_competencias'] = $comps ?: [];
} else {
    $layer3['error'] = 'Tabela mart_cliente_periodo não existe — execute materialize:gold primeiro';
}

$report['layers']['layer3_mart_cliente_periodo'] = $layer3;

// ── Layer 4: mart_compradores_por_geo ─────────────────────────────────────────

$layer4 = ['name' => 'mart_compradores_por_geo', 'exists' => false];

if (tableExists($pdo, 'mart_compradores_por_geo')) {
    $layer4['exists'] = true;

    // Full period, no city filter (to see total data)
    $r = safeQuery($pdo,
        "SELECT
            COUNT(*)                                                         AS total_rows,
            COUNT(DISTINCT codigo_cliente_omie)                              AS distinct_clientes,
            COALESCE(SUM(qtd_notas), 0)                                      AS soma_notas,
            COALESCE(SUM(faturamento), 0)                                    AS soma_faturamento,
            COUNT(DISTINCT CASE WHEN bairro_norm IS NOT NULL AND bairro_norm != '' AND bairro_norm != 'NAO INFORMADO'
                                THEN bairro_norm END)                        AS bairros_com_venda,
            SUM(bairro_norm IS NULL OR bairro_norm = '')                     AS sem_bairro,
            COUNT(DISTINCT cidade_norm)                                      AS distinct_cidades,
            MAX(refreshed_at)                                                AS last_refreshed
         FROM mart_compradores_por_geo
         WHERE competencia BETWEEN :sc AND :ec",
        ['sc' => $startComp, 'ec' => $endComp]
    );

    if ($r !== false) {
        $layer4['period_filter_all_cities'] = [
            'total_rows'        => (int) ($r['total_rows'] ?? 0),
            'distinct_clientes' => (int) ($r['distinct_clientes'] ?? 0),
            'soma_notas'        => (int) ($r['soma_notas'] ?? 0),
            'soma_faturamento'  => (float) ($r['soma_faturamento'] ?? 0),
            'bairros_com_venda' => (int) ($r['bairros_com_venda'] ?? 0),
            'sem_bairro'        => (int) ($r['sem_bairro'] ?? 0),
            'distinct_cidades'  => (int) ($r['distinct_cidades'] ?? 0),
            'last_refreshed'    => $r['last_refreshed'] ?? null,
        ];
    } else {
        $layer4['period_filter_all_cities'] = ['error' => 'Query falhou'];
    }

    // City-filtered (Fortaleza)
    $rCity = safeQuery($pdo,
        "SELECT
            COUNT(*)                            AS total_rows,
            COALESCE(SUM(qtd_notas), 0)         AS soma_notas,
            COALESCE(SUM(faturamento), 0)       AS soma_faturamento,
            COUNT(DISTINCT CASE WHEN bairro_norm IS NOT NULL AND bairro_norm != '' AND bairro_norm != 'NAO INFORMADO'
                                THEN bairro_norm END) AS bairros_com_venda,
            SUM(bairro_norm IS NULL OR bairro_norm = '') AS sem_bairro
         FROM mart_compradores_por_geo
         WHERE competencia BETWEEN :sc AND :ec
           AND (LOWER(cidade_norm) = :city OR cidade_norm IS NULL OR TRIM(cidade_norm) = '')",
        ['sc' => $startComp, 'ec' => $endComp, 'city' => $city]
    );

    if ($rCity !== false) {
        $layer4['period_filter_city_' . $city] = [
            'total_rows'        => (int) ($rCity['total_rows'] ?? 0),
            'soma_notas'        => (int) ($rCity['soma_notas'] ?? 0),
            'soma_faturamento'  => (float) ($rCity['soma_faturamento'] ?? 0),
            'bairros_com_venda' => (int) ($rCity['bairros_com_venda'] ?? 0),
            'sem_bairro'        => (int) ($rCity['sem_bairro'] ?? 0),
        ];
    }

    // All-time totals
    $rAll = safeQuery($pdo,
        "SELECT COUNT(*) AS total, SUM(faturamento) AS rev, MAX(refreshed_at) AS refreshed
         FROM mart_compradores_por_geo"
    );
    $layer4['all_time'] = $rAll !== false ? [
        'total_rows'   => (int) ($rAll['total'] ?? 0),
        'soma_faturamento' => (float) ($rAll['rev'] ?? 0),
        'last_refreshed' => $rAll['refreshed'] ?? null,
    ] : ['error' => 'Query falhou'];

    // Top bairros in period
    $topBairros = safeQueryAll($pdo,
        "SELECT bairro_norm, cidade_norm, SUM(faturamento) AS faturamento, SUM(qtd_notas) AS notas,
                COUNT(DISTINCT codigo_cliente_omie) AS clientes
         FROM mart_compradores_por_geo
         WHERE competencia BETWEEN :sc AND :ec
         GROUP BY bairro_norm, cidade_norm
         ORDER BY faturamento DESC LIMIT 10",
        ['sc' => $startComp, 'ec' => $endComp]
    );
    $layer4['top_bairros'] = $topBairros ?: [];

    // Cities distribution
    $cities = safeQueryAll($pdo,
        "SELECT COALESCE(NULLIF(cidade_norm,''), 'NAO INFORMADO') AS cidade,
                COUNT(*) AS clientes, SUM(faturamento) AS faturamento
         FROM mart_compradores_por_geo
         WHERE competencia BETWEEN :sc AND :ec
         GROUP BY cidade_norm ORDER BY faturamento DESC LIMIT 10",
        ['sc' => $startComp, 'ec' => $endComp]
    );
    $layer4['cities_distribution'] = $cities ?: [];
} else {
    $layer4['error'] = 'mart_compradores_por_geo não existe — execute geo:materialize primeiro';
}

$report['layers']['layer4_mart_compradores_por_geo'] = $layer4;

// ── Layer 5: geo enrichment coverage ──────────────────────────────────────────

$layer5 = ['name' => 'geo_enrichment_coverage'];

$clientesDim = tableExists($pdo, 'biomie_clientes_dim');
$clientesGeo = tableExists($pdo, 'biomie_clientes_geo_dim');
$bairrosDim  = tableExists($pdo, 'biomie_bairros_fortaleza_dim');

$layer5['tables'] = [
    'biomie_clientes_dim'         => $clientesDim,
    'biomie_clientes_geo_dim'     => $clientesGeo,
    'biomie_bairros_fortaleza_dim' => $bairrosDim,
];

if ($clientesDim) {
    $r = safeQuery($pdo,
        "SELECT COUNT(*) AS total,
                SUM(cidade IS NOT NULL AND cidade != '') AS com_cidade,
                SUM(bairro IS NOT NULL AND bairro != '') AS com_bairro
         FROM biomie_clientes_dim"
    );
    $layer5['clientes_dim'] = $r !== false ? [
        'total'     => (int) ($r['total'] ?? 0),
        'com_cidade' => (int) ($r['com_cidade'] ?? 0),
        'com_bairro' => (int) ($r['com_bairro'] ?? 0),
    ] : ['error' => 'Query falhou'];
}

if ($clientesGeo) {
    $r = safeQuery($pdo,
        "SELECT COUNT(*) AS total,
                SUM(bairro_norm IS NOT NULL AND bairro_norm != '') AS com_bairro_norm,
                SUM(bairro_oficial_id_fortaleza IS NOT NULL)       AS com_match_oficial,
                SUM(uf = 'CE')                                     AS clientes_ce
         FROM biomie_clientes_geo_dim"
    );
    $layer5['clientes_geo_dim'] = $r !== false ? [
        'total'             => (int) ($r['total'] ?? 0),
        'com_bairro_norm'   => (int) ($r['com_bairro_norm'] ?? 0),
        'com_match_oficial' => (int) ($r['com_match_oficial'] ?? 0),
        'clientes_ce'       => (int) ($r['clientes_ce'] ?? 0),
    ] : ['error' => 'Query falhou'];
}

if ($bairrosDim) {
    $r = safeQuery($pdo, "SELECT COUNT(*) AS total, SUM(ativo = 1) AS ativos FROM biomie_bairros_fortaleza_dim");
    $layer5['bairros_fortaleza_dim'] = $r !== false ? [
        'total'  => (int) ($r['total'] ?? 0),
        'ativos' => (int) ($r['ativos'] ?? 0),
    ] : ['error' => 'Query falhou'];
}

$report['layers']['layer5_geo_enrichment'] = $layer5;

// ── Pipeline diagnosis ────────────────────────────────────────────────────────

$expected = $report['expected_from_omie_extract'];
$l1 = $report['layers']['layer1_canonical_nf']['period_filter'] ?? [];
$l3 = $report['layers']['layer3_mart_cliente_periodo']['period_filter'] ?? [];
$l4 = $report['layers']['layer4_mart_compradores_por_geo']['period_filter_all_cities'] ?? [];

$diag = [];

// Check L1 vs expected
$nfsL1 = (int) ($l1['nfs_autorizadas'] ?? 0);
$mercL1 = (float) ($l1['valor_mercadorias_autorizada'] ?? 0);
if ($nfsL1 === 0) {
    $diag[] = [
        'layer'   => 'L1',
        'issue'   => 'CRITICO: Nenhuma NF autorizada no período em biomie_notas_fiscais_canonicas.',
        'action'  => 'Verificar se o sync de NFs foi executado. Checar omie_fiscal_sync.',
    ];
} elseif ($nfsL1 < $expected['unique_invoices'] * 0.8) {
    $diag[] = [
        'layer'   => 'L1',
        'issue'   => "AVISO: {$nfsL1} NFs no período vs {$expected['unique_invoices']} esperadas pelo Excel.",
        'action'  => 'Pode haver NFs não sincronizadas ou fora do intervalo de datas.',
    ];
}

// Check L3 vs L1
$nfsL3 = (int) ($l3['soma_nfes'] ?? -1);
if ($nfsL1 > 0 && $nfsL3 === 0) {
    $diag[] = [
        'layer'   => 'L3',
        'issue'   => "CRITICO: mart_cliente_periodo vazio para competência {$startComp}–{$endComp} mesmo com {$nfsL1} NFs em L1.",
        'action'  => 'Execute: php cron/materialize_competencia_snapsh.php ou equivalente para materializar o gold mart.',
    ];
}

// Check L4 vs L3
$revL4 = (float) ($l4['soma_faturamento'] ?? -1);
if ($nfsL3 > 0 && $revL4 === 0.0) {
    $diag[] = [
        'layer'   => 'L4',
        'issue'   => "CRITICO: mart_compradores_por_geo sem dados para o período mesmo com L3 preenchido.",
        'action'  => 'Execute: php cron/geo_materialize.php ou equivalente para reconstruir o geo mart.',
    ];
}

// Geo coverage
$geoTotal  = (int) ($layer5['clientes_geo_dim']['total'] ?? 0);
$geoNorm   = (int) ($layer5['clientes_geo_dim']['com_bairro_norm'] ?? 0);
$dimTotal  = (int) ($layer5['clientes_dim']['total'] ?? 0);
if ($dimTotal > 0 && $geoTotal < $dimTotal * 0.5) {
    $diag[] = [
        'layer'   => 'L5',
        'issue'   => "AVISO: Apenas {$geoTotal}/{$dimTotal} clientes têm registro em biomie_clientes_geo_dim.",
        'action'  => 'Execute: php cron/geo_enrich_clients.php para enriquecer clientes sem geo.',
    ];
}

if ($geoTotal > 0 && $geoNorm < $geoTotal * 0.5) {
    $diag[] = [
        'layer'   => 'L5',
        'issue'   => "AVISO: Apenas {$geoNorm}/{$geoTotal} clientes geo têm bairro_norm preenchido.",
        'action'  => 'Bairros sem normalização não aparecem no mapa. Executar pipeline de normalização.',
    ];
}

if (count($diag) === 0) {
    $diag[] = ['layer' => 'ALL', 'issue' => 'Pipeline parece saudável — dados presentes em todas as camadas.', 'action' => null];
}

$report['pipeline_diagnosis'] = $diag;

// ── Key consistency diagnostics ───────────────────────────────────────────────

$hasBkColDiag      = tableExists($pdo, 'mart_compradores_por_geo') && colExists($pdo, 'mart_compradores_por_geo', 'bairro_key');
$caseMismatchDiag  = false;
$accentMismatchDiag = false;

if (tableExists($pdo, 'mart_compradores_por_geo')) {
    $cm = safeQuery($pdo,
        "SELECT COUNT(*) AS cnt FROM mart_compradores_por_geo
         WHERE bairro_norm IS NOT NULL AND bairro_norm != ''
           AND bairro_norm COLLATE utf8mb4_bin != LOWER(bairro_norm)"
    );
    $caseMismatchDiag = ((int) ($cm['cnt'] ?? 0)) > 0;
    try {
        $am = safeQuery($pdo,
            "SELECT COUNT(*) AS cnt FROM mart_compradores_por_geo
             WHERE bairro_norm IS NOT NULL AND bairro_norm != ''
               AND LOWER(bairro_norm) REGEXP '[^a-z0-9 _-]'"
        );
        $accentMismatchDiag = ((int) ($am['cnt'] ?? 0)) > 0;
    } catch (\Throwable) {}
}

$report['diagnostics'] = [
    'bairro_key_missing' => !$hasBkColDiag,
    'case_mismatch'      => $caseMismatchDiag,
    'accent_mismatch'    => $accentMismatchDiag,
];

// ── Excel vs DB comparison ────────────────────────────────────────────────────

$dbInvoices = (int) ($l1['nfs_autorizadas'] ?? 0);
$dbMerc     = (float) ($l1['valor_mercadorias_autorizada'] ?? 0);
$dbMartNfes = (int) ($l3['soma_nfes'] ?? 0);
$dbMartRev  = (float) ($l3['soma_valor_nfe'] ?? 0);
$dbGeoRev   = (float) ($l4['soma_faturamento'] ?? 0);
$dbGeoBairros = (int) ($l4['bairros_com_venda'] ?? 0);

$report['excel_vs_db'] = [
    'unique_invoices'        => ['excel' => $expected['unique_invoices'], 'db_l1' => $dbInvoices, 'delta' => $dbInvoices - $expected['unique_invoices']],
    'total_merchandise'      => ['excel' => $expected['total_merchandise'], 'db_l1_merc' => $dbMerc, 'delta' => round($dbMerc - $expected['total_merchandise'], 2)],
    'mart_nfes_emitidas'     => ['excel' => $expected['unique_invoices'], 'db_l3' => $dbMartNfes, 'delta' => $dbMartNfes - $expected['unique_invoices']],
    'mart_valor_nfe'         => ['excel' => $expected['total_merchandise'], 'db_l3' => $dbMartRev, 'delta' => round($dbMartRev - $expected['total_merchandise'], 2)],
    'geo_mart_faturamento'   => ['excel' => $expected['total_merchandise'], 'db_l4' => $dbGeoRev, 'delta' => round($dbGeoRev - $expected['total_merchandise'], 2)],
    'normalized_neighborhoods' => ['excel' => $expected['normalized_neighborhoods'], 'db_l4' => $dbGeoBairros, 'delta' => $dbGeoBairros - $expected['normalized_neighborhoods']],
];

// ── Bairro-specific audit (when --bairro or --bairro-key is given) ────────────

if ($bairroKey !== '' && $pdo !== null) {
    $bairroAudit = ['bairro_key' => $bairroKey, 'bairro_raw' => $bairroRaw ?: $bairroKey];

    $hasBkCol = tableExists($pdo, 'mart_compradores_por_geo')
        && colExists($pdo, 'mart_compradores_por_geo', 'bairro_key');
    $bairroAudit['using_bairro_key_col'] = $hasBkCol;

    // Build filter: prefer bairro_key col; fall back to COLLATE on bairro_norm
    if ($hasBkCol) {
        $bkWhere  = "(bairro_key = :bk OR (bairro_key IS NULL AND bairro_norm COLLATE utf8mb4_general_ci = :bk_fb))";
        $bkBase   = ['bk' => $bairroKey, 'bk_fb' => $bairroKey];
    } else {
        $bkWhere  = "bairro_norm COLLATE utf8mb4_general_ci = :bk";
        $bkBase   = ['bk' => $bairroKey];
    }
    $bkPeriod = array_merge($bkBase, ['start_comp' => $startComp, 'end_comp' => $endComp]);

    // 1. Mart compradores por geo
    $martRows = safeQuery($pdo,
        "SELECT COUNT(*) AS row_count,
                SUM(faturamento) AS total_rev,
                SUM(qtd_notas) AS total_invoices,
                COUNT(DISTINCT COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome)) AS total_clientes,
                MIN(competencia) AS min_comp,
                MAX(competencia) AS max_comp
         FROM mart_compradores_por_geo
         WHERE {$bkWhere}
           AND competencia BETWEEN :start_comp AND :end_comp",
        $bkPeriod
    );
    $bairroAudit['mart_compradores_por_geo'] = $martRows !== false ? [
        'row_count'         => (int) ($martRows['row_count'] ?? 0),
        'total_revenue'     => (float) ($martRows['total_rev'] ?? 0),
        'total_invoices'    => (int) ($martRows['total_invoices'] ?? 0),
        'total_clientes'    => (int) ($martRows['total_clientes'] ?? 0),
        'competencia_range' => ($martRows['min_comp'] ?? 'N/A') . ' – ' . ($martRows['max_comp'] ?? 'N/A'),
    ] : ['error' => 'query_failed'];

    // 2. Top buyers from mart
    $topBuyers = safeQueryAll($pdo,
        "SELECT MAX(bairro_norm) AS bairro_norm_stored,
                MAX(cliente_nome) AS cliente_nome,
                SUM(faturamento) AS revenue,
                SUM(qtd_notas) AS invoices
         FROM mart_compradores_por_geo
         WHERE {$bkWhere}
           AND competencia BETWEEN :start_comp AND :end_comp
         GROUP BY COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome)
         ORDER BY revenue DESC
         LIMIT 5",
        $bkPeriod
    );
    $bairroAudit['top_buyers_mart'] = $topBuyers ?: [];

    // 3. Distinct bairro_norm/bairro_key variants stored (shows case/accent drift)
    $bkExtra = $hasBkCol ? ', bairro_key' : '';
    $normVariants = safeQueryAll($pdo,
        "SELECT DISTINCT bairro_norm{$bkExtra}, COUNT(*) AS rows
         FROM mart_compradores_por_geo
         WHERE {$bkWhere}
         GROUP BY bairro_norm{$bkExtra}",
        $bkBase
    );
    $bairroAudit['bairro_norm_variants_in_mart'] = $normVariants ?: [];

    // 4. Geo dim coverage for this bairro
    if (tableExists($pdo, 'biomie_clientes_geo_dim')) {
        $hasBkGeo = colExists($pdo, 'biomie_clientes_geo_dim', 'bairro_key');
        if ($hasBkGeo) {
            $geoWhere = "(bairro_key = :bk OR (bairro_key IS NULL AND bairro_norm COLLATE utf8mb4_general_ci = :bk_fb))";
            $geoBase  = ['bk' => $bairroKey, 'bk_fb' => $bairroKey];
        } else {
            $geoWhere = "bairro_norm COLLATE utf8mb4_general_ci = :bk";
            $geoBase  = ['bk' => $bairroKey];
        }
        $geoDim = safeQuery($pdo,
            "SELECT COUNT(*) AS clientes_com_geo,
                    COUNT(DISTINCT bairro_norm) AS bairro_norm_variants
             FROM biomie_clientes_geo_dim
             WHERE {$geoWhere}",
            $geoBase
        );
        $bairroAudit['clientes_geo_dim'] = $geoDim !== false ? [
            'clientes_com_geo'     => (int) ($geoDim['clientes_com_geo'] ?? 0),
            'bairro_norm_variants' => (int) ($geoDim['bairro_norm_variants'] ?? 0),
        ] : ['error' => 'query_failed'];
    }

    // 5. EXPLAIN the mart query (if --explain)
    if ($explainMode && tableExists($pdo, 'mart_compradores_por_geo')) {
        $explainRows = safeQueryAll($pdo,
            "EXPLAIN SELECT COUNT(*) FROM mart_compradores_por_geo
             WHERE {$bkWhere}
               AND competencia BETWEEN :start_comp AND :end_comp",
            $bkPeriod
        );
        $bairroAudit['explain_mart_query'] = $explainRows ?: [];
    }

    $report['bairro_audit'] = $bairroAudit;
}

// ── Output ────────────────────────────────────────────────────────────────────

function outputAndExit(array $report, bool $jsonMode): never
{
    if ($jsonMode) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        exit(0);
    }

    $params = $report['params'];
    echo "\n";
    echo "=== GEO SALES DEBUG — {$params['start']} a {$params['end']} ===\n";
    echo "City filter: {$params['city']} | Competencia: {$params['start_comp']}–{$params['end_comp']}\n";
    echo "\n";

    if (!($report['connection']['ok'] ?? false)) {
        echo "ERRO DE CONEXAO: " . ($report['connection']['error'] ?? 'desconhecido') . "\n";
        exit(1);
    }

    if (isset($report['normalized'])) {
        $norm = $report['normalized'];
        echo "city_key   : " . ($norm['city_key'] ?? '-') . "\n";
        echo "bairro_key : " . ($norm['bairro_key'] ?? '-') . "\n";
        echo "bairro_norm: " . ($norm['bairro_norm'] ?? '-') . "\n";
        echo "\n";
    }

    foreach ($report['layers'] as $layerKey => $layer) {
        echo "── " . strtoupper($layerKey) . " ──\n";
        echo "  Tabela: " . ($layer['name'] ?? $layerKey) . " — " . ($layer['exists'] ?? $layer['tables'] ?? false ? 'OK' : 'NAO EXISTE') . "\n";
        if (isset($layer['period_filter']) && is_array($layer['period_filter'])) {
            foreach ($layer['period_filter'] as $k => $v) {
                echo "    {$k}: " . (is_float($v) ? number_format($v, 2, ',', '.') : ($v ?? 'NULL')) . "\n";
            }
        }
        if (isset($layer['period_filter_all_cities']) && is_array($layer['period_filter_all_cities'])) {
            echo "  (todas as cidades):\n";
            foreach ($layer['period_filter_all_cities'] as $k => $v) {
                echo "    {$k}: " . (is_float($v) ? number_format($v, 2, ',', '.') : ($v ?? 'NULL')) . "\n";
            }
        }
        if (isset($layer['error'])) {
            echo "  ERRO: " . $layer['error'] . "\n";
        }
        echo "\n";
    }

    echo "── EXCEL VS DB ──\n";
    foreach ($report['excel_vs_db'] as $metric => $comp) {
        $delta = $comp['delta'] ?? 0;
        $ok    = abs($delta) < (is_float($delta) ? 1000 : 5) ? 'OK' : 'DIVERGENCIA';
        echo "  {$metric}: Excel=" . ($comp['excel'] ?? '?') . " | DB=" . (array_values(array_filter(array_values($comp), fn($k) => str_starts_with((string)$k, 'db'), ARRAY_FILTER_USE_BOTH))[0] ?? '?') . " | delta={$delta} [{$ok}]\n";
    }

    echo "\n── DIAGNÓSTICO DO PIPELINE ──\n";
    foreach ($report['pipeline_diagnosis'] as $d) {
        echo "  [{$d['layer']}] {$d['issue']}\n";
        if ($d['action']) echo "        → {$d['action']}\n";
    }

    if (isset($report['bairro_audit'])) {
        $ba = $report['bairro_audit'];
        echo "\n── AUDITORIA DO BAIRRO: {$ba['bairro_key']} ──\n";
        $mart = $ba['mart_compradores_por_geo'] ?? [];
        echo "  Mart compradores por geo:\n";
        echo "    row_count:     " . ($mart['row_count'] ?? 'N/A') . "\n";
        echo "    total_revenue: " . number_format((float)($mart['total_revenue'] ?? 0), 2, ',', '.') . "\n";
        echo "    total_invoices: " . ($mart['total_invoices'] ?? 0) . "\n";
        echo "    total_clientes: " . ($mart['total_clientes'] ?? 0) . "\n";
        echo "    competencia_range: " . ($mart['competencia_range'] ?? 'N/A') . "\n";
        if (!empty($ba['bairro_norm_variants_in_mart'])) {
            echo "  bairro_norm variants stored: ";
            foreach ($ba['bairro_norm_variants_in_mart'] as $v) {
                echo "[" . ($v['bairro_norm'] ?? '?') . " ×" . ($v['rows'] ?? 0) . "] ";
            }
            echo "\n";
        }
        if (!empty($ba['top_buyers_mart'])) {
            echo "  Top compradores (mart):\n";
            foreach ($ba['top_buyers_mart'] as $buyer) {
                echo "    " . ($buyer['cliente_nome'] ?? '?') . " — R$ " . number_format((float)($buyer['revenue'] ?? 0), 2, ',', '.') . "\n";
            }
        }
        if (isset($ba['clientes_geo_dim'])) {
            echo "  biomie_clientes_geo_dim: " . ($ba['clientes_geo_dim']['clientes_com_geo'] ?? 0) . " clientes, " . ($ba['clientes_geo_dim']['bairro_norm_variants'] ?? 0) . " variante(s) de bairro_norm\n";
        }
        echo "\n";
    }

    if (isset($report['diagnostics'])) {
        $d = $report['diagnostics'];
        echo "\n── DIAGNOSTICS ──\n";
        echo "  bairro_key_missing : " . ($d['bairro_key_missing'] ? 'SIM — execute php bin/migrate.php' : 'nao') . "\n";
        echo "  case_mismatch      : " . ($d['case_mismatch'] ? 'SIM — bairro_norm tem maiusculas (rematerialize)' : 'nao') . "\n";
        echo "  accent_mismatch    : " . ($d['accent_mismatch'] ? 'SIM — bairro_norm tem acentos (backfill + rematerialize)' : 'nao') . "\n";
    }

    echo "\nPara saída JSON completa: php tools/dev/geo_sales_debug.php --start={$params['start']} --end={$params['end']} --json\n\n";
    exit(0);
}

outputAndExit($report, $jsonMode);
