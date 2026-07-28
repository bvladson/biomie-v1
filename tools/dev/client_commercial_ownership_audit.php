<?php

declare(strict_types=1);

/**
 * tools/dev/client_commercial_ownership_audit.php
 *
 * Audit client portfolio ownership — detect who owns each client and flag
 * sales out of portfolio.
 *
 * Ownership priority chain:
 *   1. biomie_clientes_dim.vendedor_responsavel_nome  (confidence=100)
 *   2. Primeira NF autorizada do cliente — ever        (confidence=70)
 *   3. Sem dono                                        (confidence=0)
 *
 * Usage:
 *   php tools/dev/client_commercial_ownership_audit.php --start=2026-01-01 --end=2026-05-31
 *   php tools/dev/client_commercial_ownership_audit.php --start=2026-04-01 --end=2026-04-30 --sample=50
 *   php tools/dev/client_commercial_ownership_audit.php --json
 *
 * Exit codes:
 *   0 — no out-of-portfolio sales detected
 *   1 — out-of-portfolio sales detected (or DB error; details in output)
 *   2 — configuration error
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$opts      = getopt('', ['start:', 'end:', 'pretty', 'json', 'sample:']);
$startDate = isset($opts['start'])  ? trim((string) $opts['start'])  : date('Y-01-01');
$endDate   = isset($opts['end'])    ? trim((string) $opts['end'])    : date('Y-m-d');
$sample    = isset($opts['sample']) ? max(1, (int) $opts['sample'])  : 30;
$isJson    = isset($opts['json']);

// ── DB connection ─────────────────────────────────────────────────────────────

$config     = Config::fromEnvironment();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';

if (!Database::isConfigured($config, $connection)) {
    $msg = 'ERRO: Banco nao configurado.';
    if ($isJson) {
        echo json_encode(['error' => $msg]) . "\n";
    } else {
        fwrite(STDERR, $msg . "\n");
    }
    exit(2);
}

try {
    $pdo = Database::connect($config, $connection);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    $msg = 'ERRO: Falha ao conectar ao banco: ' . $e->getMessage();
    if ($isJson) {
        echo json_encode(['error' => $msg]) . "\n";
    } else {
        fwrite(STDERR, $msg . "\n");
    }
    exit(2);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function ccogTableExists(\PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (\Throwable) {
        return false;
    }
}

function ccogFmt(float $val): string
{
    return 'R$' . number_format($val, 2, ',', '.');
}

function ccogIsEmptyVendor(string $name): bool
{
    $empty = [
        '',
        'sem vendedor',
        'sem vendedor definido',
        'nao informado',
        'não informado',
        'enviado via api',
        'sem vendedor - licitacao',
        'licitação',
        'licitacao',
    ];
    $n = mb_strtolower(trim($name));
    foreach ($empty as $pat) {
        if ($n === $pat) {
            return true;
        }
    }
    return (bool) preg_match('/^\d{1,5}$/', $n);
}

function ccogSameVendor(string $a, string $b): bool
{
    return mb_strtoupper(trim($a)) === mb_strtoupper(trim($b));
}

// ── Step 1: Clients active in period with aggregates ─────────────────────────

try {
    $stmtPeriod = $pdo->prepare("
        SELECT
            COALESCE(NULLIF(nf.codigo_cliente_omie, 0), NULL)  AS codigo_cliente_omie,
            nf.documento_cliente,
            nf.nome_cliente,
            COUNT(*)                                            AS sales_count,
            COALESCE(SUM(
                COALESCE(nf.valor_base_comercial_nf,
                    nf.valor_mercadorias - COALESCE(nf.valor_descontos, 0)
                )
            ), 0)                                               AS sales_value,
            MAX(COALESCE(nf.data_competencia, nf.data_emissao)) AS last_date
        FROM biomie_notas_fiscais_canonicas nf
        WHERE nf.status_fiscal = 'autorizada'
          AND nf.operation_scope = 'commercial'
          AND COALESCE(nf.is_biomie_commercial_visible, 1) = 1
          AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
        GROUP BY
            COALESCE(NULLIF(nf.codigo_cliente_omie, 0), NULL),
            nf.documento_cliente,
            nf.nome_cliente
        ORDER BY sales_value DESC
        LIMIT :lim
    ");
    $stmtPeriod->bindValue(':start', $startDate, \PDO::PARAM_STR);
    $stmtPeriod->bindValue(':end',   $endDate,   \PDO::PARAM_STR);
    $stmtPeriod->bindValue(':lim',   $sample,    \PDO::PARAM_INT);
    $stmtPeriod->execute();
    $periodClients = $stmtPeriod->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $msg = 'ERRO ao carregar clientes do periodo: ' . $e->getMessage();
    if ($isJson) {
        echo json_encode(['error' => $msg]) . "\n";
    } else {
        fwrite(STDERR, $msg . "\n");
    }
    exit(1);
}

// ── Step 2: Current vendor per client in period (most recent NF) ──────────────

function ccogCurrentVendor(\PDO $pdo, ?int $codigoOmie, string $documento, string $start, string $end): string
{
    try {
        $stmt = $pdo->prepare("
            SELECT nome_vendedor
            FROM biomie_notas_fiscais_canonicas
            WHERE status_fiscal = 'autorizada'
              AND operation_scope = 'commercial'
              AND COALESCE(is_biomie_commercial_visible, 1) = 1
              AND COALESCE(data_competencia, data_emissao) BETWEEN :start AND :end
              AND (
                    (codigo_cliente_omie IS NOT NULL AND codigo_cliente_omie = :cod)
                    OR documento_cliente = :doc
                  )
            ORDER BY COALESCE(data_competencia, data_emissao) DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':start' => $start,
            ':end'   => $end,
            ':cod'   => $codigoOmie ?? 0,
            ':doc'   => $documento,
        ]);
        return trim((string) ($stmt->fetchColumn() ?: ''));
    } catch (\Throwable) {
        return '';
    }
}

// ── Step 3: First-ever vendor per client (no date filter) ─────────────────────

function ccogFirstEverVendor(\PDO $pdo, ?int $codigoOmie, string $documento): string
{
    try {
        $stmt = $pdo->prepare("
            SELECT nome_vendedor
            FROM biomie_notas_fiscais_canonicas
            WHERE status_fiscal = 'autorizada'
              AND operation_scope = 'commercial'
              AND (
                    (codigo_cliente_omie IS NOT NULL AND codigo_cliente_omie = :cod)
                    OR documento_cliente = :doc
                  )
            ORDER BY COALESCE(data_competencia, data_emissao) ASC
            LIMIT 1
        ");
        $stmt->execute([
            ':cod' => $codigoOmie ?? 0,
            ':doc' => $documento,
        ]);
        return trim((string) ($stmt->fetchColumn() ?: ''));
    } catch (\Throwable) {
        return '';
    }
}

// ── Step 4: Dim table (optional) ──────────────────────────────────────────────

$dimAvailable = ccogTableExists($pdo, 'biomie_clientes_dim');
$dimCache     = [];

function ccogDimVendor(\PDO $pdo, bool $available, array &$cache, ?int $codigoOmie, string $documento): string
{
    if (!$available) {
        return '';
    }
    $cacheKey = ($codigoOmie !== null ? (string) $codigoOmie : '') . '|' . $documento;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    try {
        $stmt = $pdo->prepare("
            SELECT vendedor_responsavel_nome
            FROM biomie_clientes_dim
            WHERE (codigo_cliente_omie IS NOT NULL AND codigo_cliente_omie = :cod)
               OR documento_cliente = :doc
            LIMIT 1
        ");
        $stmt->execute([':cod' => $codigoOmie ?? 0, ':doc' => $documento]);
        $val = trim((string) ($stmt->fetchColumn() ?: ''));
        $cache[$cacheKey] = $val;
        return $val;
    } catch (\Throwable) {
        $cache[$cacheKey] = '';
        return '';
    }
}

// ── Step 5: Period vendor list per client ─────────────────────────────────────

function ccogPeriodVendors(\PDO $pdo, ?int $codigoOmie, string $documento, string $start, string $end): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT nome_vendedor
            FROM biomie_notas_fiscais_canonicas
            WHERE status_fiscal = 'autorizada'
              AND operation_scope = 'commercial'
              AND COALESCE(is_biomie_commercial_visible, 1) = 1
              AND COALESCE(data_competencia, data_emissao) BETWEEN :start AND :end
              AND (
                    (codigo_cliente_omie IS NOT NULL AND codigo_cliente_omie = :cod)
                    OR documento_cliente = :doc
                  )
        ");
        $stmt->execute([
            ':start' => $start,
            ':end'   => $end,
            ':cod'   => $codigoOmie ?? 0,
            ':doc'   => $documento,
        ]);
        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'nome_vendedor');
    } catch (\Throwable) {
        return [];
    }
}

// ── Process each client ───────────────────────────────────────────────────────

$clientRows    = [];
$totalClientes = 0;
$comDono       = 0;
$semDono       = 0;
$dentroPf      = 0;
$foraPf        = 0;
$valorForaPf   = 0.0;

foreach ($periodClients as $client) {
    $totalClientes++;

    $codigoOmie  = $client['codigo_cliente_omie'] !== null
        ? (int) $client['codigo_cliente_omie']
        : null;
    $documento   = trim((string) ($client['documento_cliente'] ?? ''));
    $nomeCliente = trim((string) ($client['nome_cliente']      ?? ''));
    $salesCount  = (int) ($client['sales_count']  ?? 0);
    $salesValue  = round((float) ($client['sales_value'] ?? 0), 2);

    // Resolve ownership
    $dimVendor   = ccogDimVendor($pdo, $dimAvailable, $dimCache, $codigoOmie, $documento);
    $firstVendor = ccogFirstEverVendor($pdo, $codigoOmie, $documento);
    $curVendor   = ccogCurrentVendor($pdo, $codigoOmie, $documento, $startDate, $endDate);

    if (!ccogIsEmptyVendor($dimVendor)) {
        $ownerVendor     = $dimVendor;
        $ownerSource     = 'dim_responsavel';
        $ownerConfidence = 100;
    } elseif (!ccogIsEmptyVendor($firstVendor)) {
        $ownerVendor     = $firstVendor;
        $ownerSource     = 'primeira_nf';
        $ownerConfidence = 70;
    } else {
        $ownerVendor     = '';
        $ownerSource     = 'sem_dono';
        $ownerConfidence = 0;
    }

    if ($ownerConfidence > 0) {
        $comDono++;
    } else {
        $semDono++;
    }

    $periodVendors  = ccogPeriodVendors($pdo, $codigoOmie, $documento, $startDate, $endDate);
    $uniqueVendors  = array_values(array_unique(array_filter($periodVendors, fn($v) => !ccogIsEmptyVendor($v))));

    $isOutOfPortfolio = false;
    if ($ownerVendor !== '' && count($uniqueVendors) > 0) {
        $allSameAsOwner = true;
        foreach ($uniqueVendors as $pv) {
            if (!ccogSameVendor($pv, $ownerVendor)) {
                $allSameAsOwner = false;
                break;
            }
        }
        if (!$allSameAsOwner) {
            $isOutOfPortfolio = true;
        }
    }

    if ($isOutOfPortfolio) {
        $foraPf++;
        $valorForaPf += $salesValue;
    } else {
        $dentroPf++;
    }

    $clientRows[] = [
        'cliente_nome'         => $nomeCliente,
        'codigo_cliente_omie'  => $codigoOmie,
        'documento_cliente'    => $documento,
        'owner_vendor'         => $ownerVendor,
        'owner_source'         => $ownerSource,
        'owner_confidence'     => $ownerConfidence,
        'current_period_vendor'=> $curVendor,
        'period_vendors'       => $uniqueVendors,
        'is_out_of_portfolio'  => $isOutOfPortfolio,
        'sales_count'          => $salesCount,
        'sales_value'          => $salesValue,
    ];
}

// ── Top 5 fora da carteira ────────────────────────────────────────────────────

$outRows = array_filter($clientRows, fn($r) => $r['is_out_of_portfolio']);
usort($outRows, fn($a, $b) => $b['sales_value'] <=> $a['sales_value']);
$top5Out = array_slice(array_values($outRows), 0, 5);

// ── Build output ──────────────────────────────────────────────────────────────

$output = [
    'generated_at'   => date(DATE_ATOM),
    'period'         => ['start' => $startDate, 'end' => $endDate],
    'sample_limit'   => $sample,
    'dim_available'  => $dimAvailable,
    'summary'        => [
        'total_clientes'        => $totalClientes,
        'clientes_com_dono'     => $comDono,
        'clientes_sem_dono'     => $semDono,
        'vendas_dentro_carteira'=> $dentroPf,
        'vendas_fora_carteira'  => $foraPf,
        'valor_fora_carteira'   => round($valorForaPf, 2),
        'top5_fora_carteira'    => $top5Out,
    ],
    'clients'        => $clientRows,
];

if ($isJson) {
    echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit($foraPf > 0 ? 1 : 0);
}

// ── Pretty text output ────────────────────────────────────────────────────────

echo "\n=== client_commercial_ownership_audit ===\n";
echo "Periodo: {$startDate} a {$endDate}  (amostra: {$sample} clientes)\n";
echo 'Dim disponivel: ' . ($dimAvailable ? 'SIM' : 'NAO') . "\n\n";

echo "RESUMO:\n";
printf("  Total clientes (amostra):   %d\n", $totalClientes);
printf("  Com dono definido:          %d\n", $comDono);
printf("  Sem dono:                   %d\n", $semDono);
printf("  Vendas dentro da carteira:  %d\n", $dentroPf);
printf("  Vendas fora da carteira:    %d  (%s)\n\n", $foraPf, ccogFmt($valorForaPf));

if ($top5Out !== []) {
    echo "TOP 5 FORA DA CARTEIRA:\n";
    foreach ($top5Out as $idx => $r) {
        printf(
            "  %d. %-35s  dono=%-25s  vendedores_periodo=[%s]  valor=%s\n",
            $idx + 1,
            $r['cliente_nome'] ?: $r['documento_cliente'],
            $r['owner_vendor'] ?: '(sem dono)',
            implode(', ', $r['period_vendors']),
            ccogFmt($r['sales_value'])
        );
    }
    echo "\n";
}

echo "DETALHES (amostra):\n";
foreach ($clientRows as $r) {
    $icon = $r['is_out_of_portfolio'] ? '[FORA ]' : '[OK   ]';
    printf(
        "%s %-35s  dono=%-20s (%s/%d)  valor=%s\n",
        $icon,
        mb_substr($r['cliente_nome'] ?: $r['documento_cliente'], 0, 35),
        $r['owner_vendor'] ?: '(sem dono)',
        $r['owner_source'],
        $r['owner_confidence'],
        ccogFmt($r['sales_value'])
    );
}

echo "\n";
exit($foraPf > 0 ? 1 : 0);
