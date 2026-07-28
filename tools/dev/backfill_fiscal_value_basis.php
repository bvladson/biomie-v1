<?php

declare(strict_types=1);

/**
 * Fase O.1 — Backfill de base fiscal comercial (backfill_fiscal_value_basis.php)
 *
 * Popula os campos fiscais em biomie_notas_fiscais_canonicas a partir de:
 *   1. biomie_omie_bronze (invoice.ListarNF) — parse ICMSTot via nota_id_omie → JSON
 *   2. XML da NF-e (biomie_documentos_xml_canonicos.xml_payload) — parse ICMSTot
 *   3. Colunas canônicas já preenchidas pelo sync (valor_frete IS NOT NULL)
 *   4. Fallback: zeros + fiscal_basis_source = 'fallback_sem_componentes'
 *
 * INVARIANTES DE SEGURANÇA:
 *   - Nunca altera: operation_scope, operation_scope_rule,
 *                   operation_scope_confidence, operation_scope_source
 *   - Nunca deleta NFs, pedidos, XML bruto ou payload Omie
 *   - Idempotente: re-executar é seguro (usa fiscal_basis_checked_at IS NULL por padrão)
 *   - Toda alteração deve ter --dry-run antes de --apply
 *
 * Uso:
 *   php tools/dev/backfill_fiscal_value_basis.php --status
 *   php tools/dev/backfill_fiscal_value_basis.php --dry-run --start=2026-05-01 --end=2026-05-31
 *   php tools/dev/backfill_fiscal_value_basis.php --apply  --start=2026-05-01 --end=2026-05-31
 *   php tools/dev/backfill_fiscal_value_basis.php --apply  --start=2026-05-01 --end=2026-05-31 --limit=100
 *   php tools/dev/backfill_fiscal_value_basis.php --apply  --json --pretty
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

// ── Args ──────────────────────────────────────────────────────────────────────

$opts    = getopt('', ['status', 'dry-run', 'apply', 'start:', 'end:', 'limit:', 'json', 'pretty', 'force']);
$doStatus = isset($opts['status']) && !isset($opts['apply']) && !isset($opts['dry-run']);
$dryRun   = isset($opts['dry-run']) && !isset($opts['apply']);
$doApply  = isset($opts['apply']);
$isJson   = isset($opts['json']);
$isPretty = isset($opts['pretty']);
$force    = isset($opts['force']); // re-process even if fiscal_basis_checked_at IS NOT NULL
$startArg = $opts['start'] ?? null;
$endArg   = $opts['end']   ?? null;
$limit    = isset($opts['limit']) ? max(1, (int) $opts['limit']) : 0;

if (!$doStatus && !$dryRun && !$doApply) {
    $doStatus = true;
}

// ── DB ────────────────────────────────────────────────────────────────────────

$config = Config::fromEnvironment();
$conn   = \BIomie\Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo    = \BIomie\Database::connect($config, $conn);
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

$table = 'biomie_notas_fiscais_canonicas';

// ── Helper: column presence ───────────────────────────────────────────────────

function bfColExists(\PDO $pdo, string $table, string $col): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $stmt->execute(['t' => $table, 'c' => $col]);
    return (int) $stmt->fetchColumn() > 0;
}

// ── Schema guard ──────────────────────────────────────────────────────────────

$requiredCols = [
    'valor_ipi', 'valor_frete', 'valor_outras_despesas', 'valor_seguro',
    'valor_base_comercial_nf', 'has_frete', 'has_outras_despesas', 'has_seguro',
    'has_componentes_nao_comissionaveis', 'fiscal_basis_source', 'fiscal_basis_checked_at',
];

$missingSchema = [];
foreach ($requiredCols as $col) {
    if (!bfColExists($pdo, $table, $col)) {
        $missingSchema[] = $col;
    }
}

if ($missingSchema !== []) {
    $msg = "ERRO: Colunas ausentes em $table: " . implode(', ', $missingSchema) . "\n"
         . "Execute: php tools/dev/migrate_fiscal_value_basis.php --apply\n";
    if ($isJson) {
        echo json_encode(['error' => 'schema_incompleto', 'missing' => $missingSchema], JSON_PRETTY_PRINT) . "\n";
    } else {
        fwrite(STDERR, $msg);
    }
    exit(1);
}

// ── XML parser ────────────────────────────────────────────────────────────────

/**
 * Extrai ICMSTot da NF-e XML. Retorna array com as 4 chaves fiscais + fonte.
 *
 * @return array{valor_ipi:float,valor_frete:float,valor_outras_despesas:float,valor_seguro:float,source:string}
 */
function bfParseXml(string $xmlPayload): array
{
    $empty = ['valor_ipi' => 0.0, 'valor_frete' => 0.0, 'valor_outras_despesas' => 0.0, 'valor_seguro' => 0.0, 'source' => ''];

    if (trim($xmlPayload) === '') {
        return $empty;
    }

    // Strip namespaces to simplify XPath
    $clean = preg_replace('/\sxmlns[^=]*="[^"]*"/', '', $xmlPayload);
    if ($clean === null || trim($clean) === '') {
        return $empty;
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($clean);
    libxml_clear_errors();

    if ($xml === false) {
        return $empty;
    }

    // Probe common root paths: nfeProc > NFe or NFe directly
    $icms = null;
    foreach ([
        $xml->NFe->infNFe->total->ICMSTot ?? null,
        $xml->nfeProc->NFe->infNFe->total->ICMSTot ?? null,
        $xml->infNFe->total->ICMSTot ?? null,
    ] as $candidate) {
        if ($candidate !== null && isset($candidate->vFrete)) {
            $icms = $candidate;
            break;
        }
    }

    if ($icms === null) {
        return $empty;
    }

    return [
        'valor_ipi'             => (float) ($icms->vIPI   ?? 0),
        'valor_frete'           => (float) ($icms->vFrete ?? 0),
        'valor_outras_despesas' => (float) ($icms->vOutro ?? 0),
        'valor_seguro'          => (float) ($icms->vSeg   ?? 0),
        'source'                => 'xml_nfe_parsed',
    ];
}

// ── Status mode ───────────────────────────────────────────────────────────────

if ($doStatus) {
    $stmtStat = $pdo->query(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN fiscal_basis_checked_at IS NULL THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN fiscal_basis_checked_at IS NOT NULL THEN 1 ELSE 0 END) AS done,
            SUM(CASE WHEN fiscal_basis_source = 'omie_bronze' THEN 1 ELSE 0 END) AS from_bronze,
            SUM(CASE WHEN fiscal_basis_source = 'xml_nfe_parsed' THEN 1 ELSE 0 END) AS from_xml,
            SUM(CASE WHEN fiscal_basis_source = 'sync_canonical' THEN 1 ELSE 0 END) AS from_sync,
            SUM(CASE WHEN fiscal_basis_source = 'fallback_sem_componentes' THEN 1 ELSE 0 END) AS from_fallback,
            SUM(CASE WHEN fiscal_basis_source IS NOT NULL AND fiscal_basis_source NOT IN ('omie_bronze','xml_nfe_parsed','sync_canonical','fallback_sem_componentes') THEN 1 ELSE 0 END) AS from_other,
            SUM(CASE WHEN has_frete = 1 THEN 1 ELSE 0 END) AS nfs_com_frete,
            SUM(CASE WHEN has_outras_despesas = 1 THEN 1 ELSE 0 END) AS nfs_com_outras,
            SUM(CASE WHEN has_seguro = 1 THEN 1 ELSE 0 END) AS nfs_com_seguro,
            COALESCE(SUM(valor_total), 0) AS total_valor_total,
            COALESCE(SUM(valor_base_comercial_nf), 0) AS total_base_comercial
         FROM $table
         WHERE status_fiscal NOT IN ('cancelada', 'denegada')"
    );
    $s = $stmtStat->fetch(\PDO::FETCH_ASSOC);

    $total   = (int) $s['total'];
    $pending = (int) $s['pending'];
    $done    = (int) $s['done'];
    $pct     = $total > 0 ? round($done / $total * 100, 1) : 0.0;

    if ($isJson) {
        $out = [
            'total' => $total, 'pending' => $pending, 'done' => $done, 'pct_done' => $pct,
            'sources' => [
                'omie_bronze'              => (int) $s['from_bronze'],
                'xml_nfe_parsed'           => (int) $s['from_xml'],
                'sync_canonical'           => (int) $s['from_sync'],
                'fallback_sem_componentes' => (int) $s['from_fallback'],
                'other'                    => (int) $s['from_other'],
            ],
            'indicators' => [
                'nfs_com_frete'  => (int) $s['nfs_com_frete'],
                'nfs_com_outras' => (int) $s['nfs_com_outras'],
                'nfs_com_seguro' => (int) $s['nfs_com_seguro'],
            ],
            'totals' => [
                'valor_total'       => (float) $s['total_valor_total'],
                'base_comercial_nf' => (float) $s['total_base_comercial'],
            ],
        ];
        $flags = $isPretty ? JSON_PRETTY_PRINT : 0;
        echo json_encode($out, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        echo "\n\033[1mBackfill Fiscal Value Basis — Status\033[0m\n";
        echo "Tabela: $table\n\n";
        echo "--- Progresso ---\n";
        echo "  Total NFs ativas : $total\n";
        echo "  Com backfill     : $done ({$pct}%)\n";
        echo "  Pendentes        : $pending\n";
        echo "\n--- Por fonte ---\n";
        echo "  omie_bronze              : " . (int) $s['from_bronze']   . "\n";
        echo "  xml_nfe_parsed           : " . (int) $s['from_xml']      . "\n";
        echo "  sync_canonical           : " . (int) $s['from_sync']     . "\n";
        echo "  fallback_sem_componentes : " . (int) $s['from_fallback'] . "\n";
        echo "  outras                   : " . (int) $s['from_other']    . "\n";
        echo "\n--- Indicadores ---\n";
        echo "  NFs com frete  : " . (int) $s['nfs_com_frete']  . "\n";
        echo "  NFs com outras : " . (int) $s['nfs_com_outras'] . "\n";
        echo "  NFs com seguro : " . (int) $s['nfs_com_seguro'] . "\n";
        if ((float) $s['total_base_comercial'] > 0) {
            $ded = (float) $s['total_valor_total'] - (float) $s['total_base_comercial'];
            echo "\n--- Totais (NFs com backfill) ---\n";
            $fmt = fn(float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
            echo "  valor_total       : " . $fmt((float) $s['total_valor_total'])    . "\n";
            echo "  base_comercial_nf : " . $fmt((float) $s['total_base_comercial']) . "\n";
            echo "  deduções          : " . $fmt($ded)                               . "\n";
        }
        if ($pending > 0) {
            echo "\n\033[33m→ Execute --apply --start=YYYY-MM-DD --end=YYYY-MM-DD para processar NFs pendentes.\033[0m\n";
        } else {
            echo "\n\033[32m✔ Backfill completo.\033[0m\n";
        }
        echo "\n";
    }
    exit(0);
}

// ── Dry-run / Apply mode ──────────────────────────────────────────────────────

$modeLabel = $dryRun ? 'dry-run' : 'apply';

if (!$isJson) {
    echo "\n\033[1mBackfill Fiscal Value Basis — " . strtoupper($modeLabel) . "\033[0m\n";
    echo "Tabela: $table\n";
    if ($dryRun) {
        echo "\033[33m[DRY-RUN] Nenhum dado será alterado.\033[0m\n";
    }
    if ($startArg !== null) {
        echo "Período: $startArg → " . ($endArg ?? 'hoje') . "\n";
    }
    if ($limit > 0) {
        echo "Limite : $limit NFs\n";
    }
    echo "\n";
}

// Build query for NFs to process
$whereParts = ["status_fiscal NOT IN ('cancelada', 'denegada')"];
$params     = [];

if (!$force) {
    $whereParts[] = 'fiscal_basis_checked_at IS NULL';
}

if ($startArg !== null) {
    $whereParts[] = 'data_competencia >= :start';
    $params[':start'] = $startArg;
}
if ($endArg !== null) {
    $whereParts[] = 'data_competencia <= :end';
    $params[':end'] = $endArg;
}

$where   = implode(' AND ', $whereParts);
$limitSql = $limit > 0 ? "LIMIT $limit" : '';

// Count pending
$countSql = "SELECT COUNT(*) FROM $table WHERE $where";
$stmtCnt  = $pdo->prepare($countSql);
$stmtCnt->execute($params);
$totalPending = (int) $stmtCnt->fetchColumn();

if (!$isJson) {
    echo "NFs a processar: $totalPending" . ($limit > 0 && $limit < $totalPending ? " (limitado a $limit)" : '') . "\n\n";
}

if ($totalPending === 0) {
    if ($isJson) {
        echo json_encode(['processed' => 0, 'message' => 'Nenhuma NF pendente'], JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "\033[32m✔ Nenhuma NF pendente de backfill.\033[0m\n\n";
    }
    exit(0);
}

// Check if biomie_omie_bronze exists
$hasBronze = false;
try {
    $pdo->query("SELECT COUNT(*) FROM biomie_omie_bronze WHERE 1=0")->fetchColumn();
    $hasBronze = true;
} catch (\Throwable) {}

// Prepare bronze lookup statement (per NF by nota_id_omie)
$stmtBronze = null;
if ($hasBronze) {
    $stmtBronze = $pdo->prepare(
        "SELECT payload_json FROM biomie_omie_bronze
         WHERE entity_name = 'invoice' AND payload_json LIKE :search LIMIT 1"
    );
}

// Fetch NFs with their xml_payload joined
$fetchSql = <<<SQL
    SELECT
        nf.nota_key,
        nf.nota_id_omie,
        nf.numero_nf,
        nf.data_competencia,
        nf.valor_total,
        nf.valor_frete           AS canon_frete,
        nf.valor_outras_despesas AS canon_outras,
        nf.valor_seguro          AS canon_seguro,
        nf.valor_ipi             AS canon_ipi,
        xml.xml_payload
    FROM $table nf
    LEFT JOIN biomie_documentos_xml_canonicos xml
        ON xml.nota_key = nf.nota_key
    WHERE $where
    ORDER BY nf.data_competencia DESC, nf.nota_key ASC
    $limitSql
SQL;

$stmtFetch = $pdo->prepare($fetchSql);
$stmtFetch->execute($params);
$nfs = $stmtFetch->fetchAll(\PDO::FETCH_ASSOC);

// Prepare update statement (only in apply mode)
$stmtUpdate = null;
if ($doApply) {
    $stmtUpdate = $pdo->prepare(
        "UPDATE $table
         SET valor_ipi                          = :ipi,
             valor_frete                        = :frete,
             valor_outras_despesas              = :outras,
             valor_seguro                       = :seguro,
             valor_base_comercial_nf            = :base,
             has_frete                          = :hfrete,
             has_outras_despesas                = :houtras,
             has_seguro                         = :hseguro,
             has_componentes_nao_comissionaveis = :hcomp,
             fiscal_basis_source                = :src,
             fiscal_basis_checked_at            = NOW()
         WHERE nota_key = :nota_key"
    );
}

// ── Processing loop ───────────────────────────────────────────────────────────

$stats = [
    'processed'              => 0,
    'from_bronze'            => 0,
    'from_xml'               => 0,
    'from_sync'              => 0,
    'from_fallback'          => 0,
    'with_frete'             => 0,
    'with_outras'            => 0,
    'with_seguro'            => 0,
    'total_valor_total'      => 0.0,
    'total_base_comercial'   => 0.0,
    'total_deducoes'         => 0.0,
    'errors'                 => [],
];

$dryRunSamples = [];

foreach ($nfs as $nf) {
    $noteKey     = (string) $nf['nota_key'];
    $valorTotal  = (float) $nf['valor_total'];

    // Determine source and component values
    $xmlPayload  = (string) ($nf['xml_payload'] ?? '');
    $canonFrete  = $nf['canon_frete'] !== null ? (float) $nf['canon_frete'] : null;
    $canonOutras = $nf['canon_outras'] !== null ? (float) $nf['canon_outras'] : null;
    $canonSeguro = $nf['canon_seguro'] !== null ? (float) $nf['canon_seguro'] : null;
    $canonIpi    = $nf['canon_ipi'] !== null ? (float) $nf['canon_ipi'] : null;

    $ipi    = 0.0;
    $frete  = 0.0;
    $outras = 0.0;
    $seguro = 0.0;
    $source = '';

    // Priority 1: biomie_omie_bronze (invoice.ListarNF) via nota_id_omie
    $notaIdOmie = (string) ($nf['nota_id_omie'] ?? '');
    if ($source === '' && $notaIdOmie !== '' && $stmtBronze !== null) {
        try {
            $stmtBronze->execute(['search' => '%"nIdNF":' . $notaIdOmie . '%']);
            $bronzeRow = $stmtBronze->fetch(\PDO::FETCH_ASSOC);
            if ($bronzeRow) {
                $bjson   = json_decode((string) $bronzeRow['payload_json'], true);
                $icmsTot = is_array($bjson) ? ($bjson['total']['ICMSTot'] ?? null) : null;
                if ($icmsTot !== null) {
                    $ipi    = (float) ($icmsTot['vIPI']   ?? 0);
                    $frete  = (float) ($icmsTot['vFrete'] ?? 0);
                    $outras = (float) ($icmsTot['vOutro'] ?? 0);
                    $seguro = (float) ($icmsTot['vSeg']   ?? 0);
                    $source = 'omie_bronze';
                    $stats['from_bronze']++;
                }
            }
        } catch (\Throwable) {}
    }

    // Priority 2: XML NF-e (biomie_documentos_xml_canonicos)
    if ($source === '' && $xmlPayload !== '') {
        $parsed = bfParseXml($xmlPayload);
        if ($parsed['source'] === 'xml_nfe_parsed') {
            $ipi    = $parsed['valor_ipi'];
            $frete  = $parsed['valor_frete'];
            $outras = $parsed['valor_outras_despesas'];
            $seguro = $parsed['valor_seguro'];
            $source = 'xml_nfe_parsed';
            $stats['from_xml']++;
        }
    }

    // Priority 3: existing canonical values (from recent sync, non-null)
    if ($source === '' && $canonFrete !== null) {
        $ipi    = $canonIpi    ?? 0.0;
        $frete  = $canonFrete  ?? 0.0;
        $outras = $canonOutras ?? 0.0;
        $seguro = $canonSeguro ?? 0.0;
        $source = 'sync_canonical';
        $stats['from_sync']++;
    }

    // Priority 4: fallback zeros
    if ($source === '') {
        $source = 'fallback_sem_componentes';
        $stats['from_fallback']++;
    }

    $base  = max(0.0, $valorTotal - $frete - $outras - $seguro);
    $hasFrete  = $frete  > 0.009 ? 1 : 0;
    $hasOutras = $outras > 0.009 ? 1 : 0;
    $hasSeguro = $seguro > 0.009 ? 1 : 0;
    $hasComp   = ($hasFrete || $hasOutras || $hasSeguro) ? 1 : 0;

    $stats['processed']++;
    $stats['total_valor_total']    += $valorTotal;
    $stats['total_base_comercial'] += $base;
    $stats['total_deducoes']       += ($frete + $outras + $seguro);
    if ($hasFrete)  $stats['with_frete']++;
    if ($hasOutras) $stats['with_outras']++;
    if ($hasSeguro) $stats['with_seguro']++;

    if ($dryRun) {
        if (count($dryRunSamples) < 10 && $hasComp) {
            $dryRunSamples[] = [
                'nota_key'   => $noteKey,
                'numero_nf'  => $nf['numero_nf'],
                'competencia'=> $nf['data_competencia'],
                'source'     => $source,
                'valor_total'=> $valorTotal,
                'frete'      => $frete,
                'outras'     => $outras,
                'seguro'     => $seguro,
                'base'       => $base,
            ];
        }
        continue;
    }

    // Apply
    try {
        $stmtUpdate->execute([
            ':ipi'      => $ipi,
            ':frete'    => $frete,
            ':outras'   => $outras,
            ':seguro'   => $seguro,
            ':base'     => $base,
            ':hfrete'   => $hasFrete,
            ':houtras'  => $hasOutras,
            ':hseguro'  => $hasSeguro,
            ':hcomp'    => $hasComp,
            ':src'      => $source,
            ':nota_key' => $noteKey,
        ]);
    } catch (\Throwable $e) {
        $stats['errors'][] = $noteKey . ': ' . $e->getMessage();
        if (count($stats['errors']) >= 10) {
            break; // safety: stop after 10 errors
        }
    }
}

// ── Output ────────────────────────────────────────────────────────────────────

$fmt = fn(float $v): string => 'R$ ' . number_format($v, 2, ',', '.');

if ($isJson) {
    $out = [
        'mode'                  => $modeLabel,
        'processed'             => $stats['processed'],
        'sources'               => [
            'omie_bronze'              => $stats['from_bronze'],
            'xml_nfe_parsed'           => $stats['from_xml'],
            'sync_canonical'           => $stats['from_sync'],
            'fallback_sem_componentes' => $stats['from_fallback'],
        ],
        'indicators'            => [
            'with_frete'  => $stats['with_frete'],
            'with_outras' => $stats['with_outras'],
            'with_seguro' => $stats['with_seguro'],
        ],
        'totals'                => [
            'valor_total'      => $stats['total_valor_total'],
            'base_comercial'   => $stats['total_base_comercial'],
            'total_deducoes'   => $stats['total_deducoes'],
        ],
        'errors'                => $stats['errors'],
        'dry_run_samples'       => $dryRun ? $dryRunSamples : [],
    ];
    $flags = $isPretty ? JSON_PRETTY_PRINT : 0;
    echo json_encode($out, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo "--- Resultado ---\n";
    echo "  Processadas        : " . $stats['processed'] . "\n";
    echo "\n--- Por fonte ---\n";
    echo "  omie_bronze              : " . $stats['from_bronze']   . "\n";
    echo "  xml_nfe_parsed           : " . $stats['from_xml']      . "\n";
    echo "  sync_canonical           : " . $stats['from_sync']     . "\n";
    echo "  fallback_sem_componentes : " . $stats['from_fallback'] . "\n";
    echo "\n--- Indicadores ---\n";
    echo "  Com frete  : " . $stats['with_frete']  . "\n";
    echo "  Com outras : " . $stats['with_outras'] . "\n";
    echo "  Com seguro : " . $stats['with_seguro'] . "\n";
    echo "\n--- Totais ---\n";
    echo "  valor_total       : " . $fmt($stats['total_valor_total'])    . "\n";
    echo "  base_comercial_nf : " . $fmt($stats['total_base_comercial']) . "\n";
    echo "  deduções          : " . $fmt($stats['total_deducoes'])        . "\n";

    if ($dryRun && $dryRunSamples !== []) {
        echo "\n--- Amostra (NFs com deduções) ---\n";
        foreach ($dryRunSamples as $s) {
            echo "  NF " . $s['numero_nf'] . " (" . $s['competencia'] . ") src=" . $s['source'] . "\n";
            echo "    total=" . $fmt($s['valor_total']) . "  frete=" . $fmt($s['frete'])
               . "  outras=" . $fmt($s['outras']) . "  seguro=" . $fmt($s['seguro'])
               . "  base=" . $fmt($s['base']) . "\n";
        }
    }

    if ($stats['errors'] !== []) {
        echo "\n\033[31m--- Erros (" . count($stats['errors']) . ") ---\033[0m\n";
        foreach (array_slice($stats['errors'], 0, 5) as $err) {
            echo "  $err\n";
        }
    }

    echo "\n";

    if ($stats['errors'] !== []) {
        echo "\033[31m✘ Backfill com erros. Verifique os logs.\033[0m\n\n";
        exit(1);
    }

    if ($dryRun) {
        echo "\033[33m[DRY-RUN] Nada foi alterado. Execute com --apply para aplicar.\033[0m\n\n";
    } else {
        echo "\033[32m✔ Backfill concluído.\033[0m\n";
        echo "\nPróximos passos:\n";
        echo "  1. Validar  : php tools/dev/fiscal_value_basis_audit.php --start=" . ($startArg ?? date('Y-m-01')) . " --end=" . ($endArg ?? date('Y-m-t')) . "\n";
        echo "  2. Guardar  : php tools/dev/fiscal_value_basis_guard.php --start=" . ($startArg ?? date('Y-m-01')) . " --end=" . ($endArg ?? date('Y-m-t')) . "\n";
        echo "  3. Rematerializar: php artisan gold:materialize-commercial --start=" . ($startArg ?? date('Y-m-01')) . " --end=" . ($endArg ?? date('Y-m-t')) . "\n";
        echo "\n";
    }
}

exit($stats['errors'] !== [] ? 1 : 0);
