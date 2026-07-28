<?php

declare(strict_types=1);

/**
 * COM.R1 FASE 5 — Diagnóstico de Carteirização de Clientes
 *
 * Identifica o dono da carteira de cada cliente e detecta vendas fora da carteira.
 * Fontes de dono em prioridade:
 *   1. biomie_clientes_dim.vendedor_responsavel_nome (confidence=100)
 *   2. Vendedor da primeira NF autorizada (confidence=70)
 *   3. Vendedor do primeiro pedido autorizado (confidence=60)
 *   4. Sem evidência (confidence=0)
 *
 * Uso:
 *   php tools/dev/client_portfolio_ownership_audit.php \
 *       --start=YYYY-MM-DD --end=YYYY-MM-DD \
 *       [--vendor=nome] [--pretty] [--json] [--sample=30]
 *
 * Read-only. Sem escrita.
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Support\ClientPortfolioOwnershipResolver;

$opts     = getopt('', ['start:', 'end:', 'vendor:', 'pretty', 'json', 'sample:']);
$start    = (string) ($opts['start']  ?? date('Y-01-01'));
$end      = (string) ($opts['end']    ?? date('Y-m-d'));
$vendor   = isset($opts['vendor'])  ? trim((string) $opts['vendor'])  : null;
$sampleN  = max(1, min(200, (int) ($opts['sample'] ?? 30)));
$pretty   = isset($opts['pretty']);
$jsonMode = isset($opts['json']);

$t0 = microtime(true);

// ── DB ────────────────────────────────────────────────────────────────────────
try {
    $config = new Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    fwrite(STDERR, 'DB: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

function cpoaTbl(PDO $pdo, string $t): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t LIMIT 1");
    $s->execute([':t' => $t]);
    return (int) $s->fetchColumn() > 0;
}

$hasNfs  = cpoaTbl($pdo, 'biomie_notas_fiscais_canonicas');
$hasDim  = cpoaTbl($pdo, 'biomie_clientes_dim');

$resolver = new ClientPortfolioOwnershipResolver();
$nfDateE  = "COALESCE(nf.data_competencia, nf.data_emissao)";
$vendorFilter = $vendor !== null ? ' AND nf.nome_vendedor = :vendor' : '';

// ── Load NF sample ────────────────────────────────────────────────────────────
$rows = [];
if ($hasNfs) {
    $sNf = $pdo->prepare(
        "SELECT
             nf.nota_key,
             nf.numero_nf,
             nf.codigo_cliente_omie,
             nf.nome_cliente,
             nf.documento_cliente,
             nf.codigo_vendedor_omie,
             nf.nome_vendedor               AS sale_vendor_name,
             nf.valor_mercadorias           AS sale_value,
             {$nfDateE}                      AS sale_date,
             nf.pedido_id_omie,
             COALESCE(cd.vendedor_responsavel_nome,'')       AS dim_vendor_name,
             COALESCE(cd.vendedor_responsavel_confidence,0)  AS dim_vendor_confidence,
             COALESCE(cd.vendedor_responsavel_origem,'')     AS dim_vendor_source
         FROM biomie_notas_fiscais_canonicas nf
         LEFT JOIN biomie_clientes_dim cd ON cd.codigo_cliente_omie = nf.codigo_cliente_omie
         WHERE {$nfDateE} BETWEEN :start AND :end
           AND nf.status_fiscal = 'autorizada'
           {$vendorFilter}
         ORDER BY nf.valor_mercadorias DESC
         LIMIT :lim"
    );
    $sNf->bindValue(':start', $start, PDO::PARAM_STR);
    $sNf->bindValue(':end',   $end,   PDO::PARAM_STR);
    $sNf->bindValue(':lim',   $sampleN, PDO::PARAM_INT);
    if ($vendor) $sNf->bindValue(':vendor', $vendor, PDO::PARAM_STR);
    $sNf->execute();
    $rows = $sNf->fetchAll(PDO::FETCH_ASSOC);
}

// ── Resolve ownership for each row ────────────────────────────────────────────
$resolved = array_map(function (array $row) use ($resolver): array {
    $result = $resolver->resolve([
        'cliente_id_omie'        => $row['codigo_cliente_omie'] ?? '',
        'documento_cliente'      => $row['documento_cliente']   ?? '',
        'nome_cliente'           => $row['nome_cliente']         ?? '',
        'dim_vendor_name'        => $row['dim_vendor_name']      ?? '',
        'dim_vendor_confidence'  => (int) ($row['dim_vendor_confidence'] ?? 0),
        'dim_vendor_source'      => $row['dim_vendor_source']    ?? '',
        'sale_vendor_name'       => $row['sale_vendor_name']     ?? '',
    ]);
    return array_merge($row, $result);
}, $rows);

// ── Aggregate stats ────────────────────────────────────────────────────────────
$totalNfs         = count($resolved);
$totalValor       = array_sum(array_column($resolved, 'sale_value'));
$withOwner        = count(array_filter($resolved, fn($r) => $r['owner_confidence'] > 0));
$outOfPortfolio   = array_filter($resolved, fn($r) => $r['is_out_of_portfolio']);
$noOwner          = count(array_filter($resolved, fn($r) => $r['owner_confidence'] === 0));

$conflictValue    = array_sum(array_column($outOfPortfolio, 'sale_value'));

// Top vendors selling out of portfolio
$sellerConflicts = [];
foreach ($outOfPortfolio as $r) {
    $sv = (string) ($r['sale_vendor_name'] ?? '');
    if ($sv === '') continue;
    $sellerConflicts[$sv] = ($sellerConflicts[$sv] ?? 0.0) + (float) ($r['sale_value'] ?? 0);
}
arsort($sellerConflicts);
$topSellersOut = array_slice($sellerConflicts, 0, 10, true);

// Top owners whose clients were sold by others
$ownerLost = [];
foreach ($outOfPortfolio as $r) {
    $ov = (string) ($r['owner_vendor_name'] ?? '');
    if ($ov === '') continue;
    $ownerLost[$ov] = ($ownerLost[$ov] ?? 0) + 1;
}
arsort($ownerLost);
$topOwnersLost = array_slice($ownerLost, 0, 10, true);

// Source distribution
$bySrc = [];
foreach ($resolved as $r) {
    $src = (string) ($r['owner_source'] ?? 'sem_dono');
    $bySrc[$src] = ($bySrc[$src] ?? 0) + 1;
}

// Sample conflicts (top by value)
$conflictSamples = array_values($outOfPortfolio);
usort($conflictSamples, fn($a, $b) => $b['sale_value'] <=> $a['sale_value']);
$conflictSamples = array_slice($conflictSamples, 0, 10);

$elapsed = round(microtime(true) - $t0, 2);

$report = [
    'client_portfolio_ownership_audit' => [
        'version'                   => 'COM.R1 FASE 5',
        'period'                    => ['start' => $start, 'end' => $end],
        'generated_at'              => date('Y-m-d H:i:s'),
        'elapsed_s'                 => $elapsed,
        'total_nfs_sample'          => $totalNfs,
        'total_valor_sample'        => round($totalValor, 2),
        'clientes_com_dono'         => $withOwner,
        'clientes_sem_dono'         => $noOwner,
        'vendas_fora_da_carteira'   => count($outOfPortfolio),
        'valor_fora_da_carteira'    => round($conflictValue, 2),
        'source_distribution'       => $bySrc,
        'top_vendedores_fora'       => $topSellersOut,
        'top_donos_com_perda'       => $topOwnersLost,
        'conflict_samples'          => array_map(fn($r) => [
            'cliente_id'       => $r['codigo_cliente_omie'] ?? '',
            'cliente_nome'     => $r['nome_cliente'] ?? '',
            'cliente_documento'=> $r['documento_cliente'] ?? '',
            'owner_vendor_code'=> $r['owner_vendor_code'] ?? '',
            'owner_vendor_name'=> $r['owner_vendor_name'] ?? '',
            'owner_source'     => $r['owner_source'] ?? '',
            'sale_vendor_code' => $r['sale_vendor_code'] ?? ($r['codigo_vendedor_omie'] ?? ''),
            'sale_vendor_name' => $r['sale_vendor_name'] ?? '',
            'sale_nf'          => $r['nota_key'] ?? '',
            'sale_order'       => $r['pedido_id_omie'] ?? '',
            'sale_value'       => round((float)($r['sale_value'] ?? 0), 2),
            'sale_date'        => $r['sale_date'] ?? '',
            'is_out_of_portfolio' => $r['is_out_of_portfolio'] ?? false,
            'owner_confidence' => $r['owner_confidence'] ?? 0,
            'reason'           => $r['reason'] ?? '',
        ], $conflictSamples),
        'sources'                   => [
            'hasDim' => $hasDim,
            'hasNfs' => $hasNfs,
        ],
    ],
];

if ($jsonMode) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

// ── Pretty output ─────────────────────────────────────────────────────────────
function cpoaFmtV(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }
function cpoaFmtN(int $n): string   { return number_format($n, 0, ',', '.'); }

$d   = $report['client_portfolio_ownership_audit'];
$hr  = str_repeat('═', 72);
$hr2 = str_repeat('─', 72);

echo PHP_EOL . $hr . PHP_EOL;
echo '  COM.R1 FASE 5 — Auditoria de Carteirização de Clientes' . PHP_EOL;
echo '  Período : ' . $d['period']['start'] . ' → ' . $d['period']['end'] . PHP_EOL;
if ($vendor) echo '  Filtro vendedor: ' . $vendor . PHP_EOL;
echo $hr . PHP_EOL . PHP_EOL;

echo $hr2 . PHP_EOL . '  Resumo (amostra)' . PHP_EOL . $hr2 . PHP_EOL . PHP_EOL;
echo '  NFs analisadas (amostra)      : ' . cpoaFmtN($d['total_nfs_sample']) . PHP_EOL;
echo '  Valor total analisado         : ' . cpoaFmtV($d['total_valor_sample']) . PHP_EOL;
echo '  Clientes com dono definido    : ' . cpoaFmtN($d['clientes_com_dono']) . PHP_EOL;
echo '  Clientes sem dono             : ' . cpoaFmtN($d['clientes_sem_dono']) . PHP_EOL;
echo '  Vendas fora da carteira       : ' . cpoaFmtN($d['vendas_fora_da_carteira']) . PHP_EOL;
echo '  Valor fora da carteira        : ' . cpoaFmtV($d['valor_fora_da_carteira']) . PHP_EOL;
echo PHP_EOL;

echo $hr2 . PHP_EOL . '  Fontes de dono do cliente' . PHP_EOL . $hr2 . PHP_EOL . PHP_EOL;
foreach ($d['source_distribution'] as $src => $cnt) {
    echo '  ' . str_pad($src, 35) . ': ' . $cnt . PHP_EOL;
}
echo PHP_EOL;

if ($d['top_vendedores_fora']) {
    echo $hr2 . PHP_EOL . '  Top Vendedores que venderam para clientes de outros' . PHP_EOL . $hr2 . PHP_EOL . PHP_EOL;
    foreach ($d['top_vendedores_fora'] as $vn => $val) {
        echo '  ' . str_pad(mb_substr($vn, 0, 32), 34) . cpoaFmtV($val) . PHP_EOL;
    }
    echo PHP_EOL;
}

if ($d['top_donos_com_perda']) {
    echo $hr2 . PHP_EOL . '  Top Donos que perderam vendas da própria carteira' . PHP_EOL . $hr2 . PHP_EOL . PHP_EOL;
    foreach ($d['top_donos_com_perda'] as $vn => $cnt) {
        echo '  ' . str_pad(mb_substr($vn, 0, 32), 34) . $cnt . ' venda(s)' . PHP_EOL;
    }
    echo PHP_EOL;
}

if ($d['conflict_samples']) {
    echo $hr2 . PHP_EOL . '  Amostras de conflito de carteira (top por valor)' . PHP_EOL . $hr2 . PHP_EOL . PHP_EOL;
    foreach ($d['conflict_samples'] as $s) {
        echo '  NF '  . str_pad((string)($s['sale_nf'] ?? '-'), 15)
           . str_pad(mb_substr((string)($s['cliente_nome'] ?? ''), 0, 28), 30)
           . cpoaFmtV((float)($s['sale_value'] ?? 0)) . PHP_EOL;
        echo '    dono=' . mb_substr((string)($s['owner_vendor_name'] ?? '-'), 0, 28)
           . '  vendeu=' . mb_substr((string)($s['sale_vendor_name'] ?? '-'), 0, 28) . PHP_EOL;
        echo '    src=' . ($s['owner_source'] ?? '-') . '  conf=' . ($s['owner_confidence'] ?? 0)
           . '  fora=' . ($s['is_out_of_portfolio'] ? 'sim' : 'não') . PHP_EOL . PHP_EOL;
    }
}

echo $hr . PHP_EOL;
echo '  Concluído em ' . $d['elapsed_s'] . 's' . PHP_EOL;
echo $hr . PHP_EOL . PHP_EOL;
