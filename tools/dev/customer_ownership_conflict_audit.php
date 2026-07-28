<?php

declare(strict_types=1);

/**
 * COM.R1.4 FASE 2 — Auditoria de Conflitos de Carteirização
 *
 * Identifica vendas onde o vendedor da NF é diferente do dono da carteira do cliente.
 * Usa CustomerOwnershipResolver para classificação.
 *
 * Uso:
 *   php tools/dev/customer_ownership_conflict_audit.php \
 *       --start=YYYY-MM-DD --end=YYYY-MM-DD [--vendor=nome] [--owner=nome]
 *       [--pretty] [--json] [--sample=50]
 *
 * Read-only.
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Support\ActiveVendorResolver;
use BIomie\Support\CustomerOwnershipResolver;
use BIomie\Support\VendorAliasResolver;

$opts     = getopt('', ['start:', 'end:', 'vendor:', 'owner:', 'pretty', 'json', 'sample:']);
$start    = (string) ($opts['start']  ?? date('Y-01-01'));
$end      = (string) ($opts['end']    ?? date('Y-m-d'));
$vendor   = isset($opts['vendor']) ? trim((string) $opts['vendor']) : null;
$owner    = isset($opts['owner'])  ? trim((string) $opts['owner'])  : null;
$sampleN  = max(1, min(200, (int) ($opts['sample'] ?? 50)));
$pretty   = isset($opts['pretty']);
$jsonMode = isset($opts['json']);

$t0  = microtime(true);
$res = new CustomerOwnershipResolver();

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

function tblC(PDO $pdo, string $t): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t LIMIT 1");
    $s->execute([':t' => $t]);
    return (int)$s->fetchColumn() > 0;
}

$hasNfs     = tblC($pdo, 'biomie_notas_fiscais_canonicas');
$hasDim     = tblC($pdo, 'biomie_clientes_dim');
$activeResolver = new ActiveVendorResolver($pdo, new VendorAliasResolver());
$activeResolver->ensureLoaded();

// ── Query NFs + dim join ──────────────────────────────────────────────────────
$rows    = [];
$nfDateE = "COALESCE(nf.data_competencia, nf.data_emissao)";
$vFilter = $vendor !== null ? " AND nf.nome_vendedor = :vendor" : '';
$oFilter = $owner  !== null ? " AND cd.vendedor_responsavel_nome = :owner"  : '';

if ($hasNfs && $hasDim) {
    $sNf = $pdo->prepare(
        "SELECT
             nf.nota_key,
             nf.numero_nf,
             nf.codigo_cliente_omie,
             nf.nome_cliente,
             nf.documento_cliente,
             nf.codigo_vendedor_omie,
             nf.nome_vendedor           AS sale_vendor_name,
             nf.valor_mercadorias       AS valor,
             {$nfDateE}                  AS data_nf,
             nf.pedido_id_omie,
             COALESCE(cd.vendedor_responsavel_nome,'')       AS dim_vendor_name,
             COALESCE(cd.vendedor_responsavel_confidence,0)  AS dim_vendor_confidence,
             COALESCE(cd.vendedor_responsavel_origem,'')     AS dim_vendor_source
         FROM biomie_notas_fiscais_canonicas nf
         LEFT JOIN biomie_clientes_dim cd ON cd.codigo_cliente_omie = nf.codigo_cliente_omie
         WHERE {$nfDateE} BETWEEN :start AND :end
           AND nf.status_fiscal = 'autorizada'
           {$vFilter}{$oFilter}
         ORDER BY nf.valor_mercadorias DESC
         LIMIT :lim"
    );
    $sNf->bindValue(':start', $start, PDO::PARAM_STR);
    $sNf->bindValue(':end',   $end,   PDO::PARAM_STR);
    $sNf->bindValue(':lim',   $sampleN, PDO::PARAM_INT);
    if ($vendor) $sNf->bindValue(':vendor', $vendor, PDO::PARAM_STR);
    if ($owner)  $sNf->bindValue(':owner',  $owner,  PDO::PARAM_STR);
    $sNf->execute();
    $rows = $sNf->fetchAll(PDO::FETCH_ASSOC);
}

foreach ($rows as &$row) {
    $ownerStatus = $activeResolver->resolve('', (string) ($row['dim_vendor_name'] ?? ''));
    $saleStatus = $activeResolver->resolve((string) ($row['codigo_vendedor_omie'] ?? ''), (string) ($row['sale_vendor_name'] ?? ''));
    $row['owner_is_active'] = (int) ($ownerStatus['active_status_confidence'] ?? 0) >= 80
        ? (bool) ($ownerStatus['is_active'] ?? true)
        : null;
    $row['sale_vendor_is_active'] = (int) ($saleStatus['active_status_confidence'] ?? 0) >= 80
        ? (bool) ($saleStatus['is_active'] ?? true)
        : null;
}
unset($row);

// ── Resolve ownership for each row ───────────────────────────────────────────
$resolved    = $res->resolveBatch($rows);
$summary     = $res->buildVendorConflictSummary($resolved);

// ── Aggregate stats ───────────────────────────────────────────────────────────
$totalNfs        = count($resolved);
$totalValor      = array_sum(array_column($resolved, 'valor'));
$totalConflicts  = count(array_filter($resolved, fn($r) => $r['is_out_of_portfolio']));
$conflictValue   = array_sum(array_column(array_filter($resolved, fn($r) => $r['is_out_of_portfolio']), 'valor'));
$noOwner         = count(array_filter($resolved, fn($r) => $r['conflict_type'] === CustomerOwnershipResolver::CONFLICT_NO_OWNER));
$withOwner       = count(array_filter($resolved, fn($r) => !$res->isEmptyVendor($r['owner_vendor_name'])));
// COM.R1.5 — inactive metrics
$ownerInactiveReviews     = count(array_filter($resolved, fn($r) => $r['conflict_type'] === CustomerOwnershipResolver::CONFLICT_OWNER_INACTIVE_REVIEW));
$saleVendorInactiveWarns  = count(array_filter($resolved, fn($r) => $r['conflict_type'] === CustomerOwnershipResolver::CONFLICT_SALE_VENDOR_INACTIVE));
$crossChannelConflicts    = count(array_filter($resolved, fn($r) => $r['conflict_type'] === CustomerOwnershipResolver::CONFLICT_CROSS_CHANNEL));

$conflictsOnly = array_filter($resolved, fn($r) => $r['is_out_of_portfolio']);
usort($conflictsOnly, fn($a,$b) => $b['valor'] <=> $a['valor']);
$conflictsOnly = array_values($conflictsOnly);

// Top vendors that sold out of portfolio
uasort($summary, fn($a,$b) => $b['conflicts_out'] <=> $a['conflicts_out']);
$topSellers = array_slice($summary, 0, 10, true);

// Top owners whose clients were invaded
uasort($summary, fn($a,$b) => $b['clients_invaded'] <=> $a['clients_invaded']);
$topOwners = array_slice($summary, 0, 10, true);

// Top clients in conflict
$clientConflicts = [];
foreach ($conflictsOnly as $r) {
    $key = (string) ($r['codigo_cliente_omie'] ?? $r['nome_cliente'] ?? '');
    if (!isset($clientConflicts[$key])) {
        $clientConflicts[$key] = [
            'nome_cliente'  => (string) ($r['nome_cliente']       ?? ''),
            'documento'     => (string) ($r['documento_cliente']  ?? ''),
            'owner'         => (string) ($r['owner_vendor_name']  ?? ''),
            'sale_vendor'   => (string) ($r['sale_vendor_name']   ?? ''),
            'qtd'           => 0,
            'valor'         => 0.0,
        ];
    }
    $clientConflicts[$key]['qtd']++;
    $clientConflicts[$key]['valor'] += (float) ($r['valor'] ?? 0);
}
uasort($clientConflicts, fn($a,$b) => $b['valor'] <=> $a['valor']);
$topClients = array_slice(array_values($clientConflicts), 0, 10);

$elapsed = round(microtime(true) - $t0, 2);

// ── JSON ──────────────────────────────────────────────────────────────────────
if ($jsonMode) {
    echo json_encode([
        'customer_ownership_conflict_audit' => [
            'version'         => 'COM.R1.4',
            'period'          => ['start' => $start, 'end' => $end],
            'generated_at'    => date('Y-m-d H:i:s'),
            'elapsed_s'       => $elapsed,
            'total_nfs'       => $totalNfs,
            'total_valor'     => round($totalValor, 2),
            'with_owner'      => $withOwner,
            'total_conflicts' => $totalConflicts,
            'conflict_value'  => round($conflictValue, 2),
            'no_owner'        => $noOwner,
            'top_sellers_out' => $topSellers,
            'top_owners_invaded' => $topOwners,
            'top_clients_conflict' => $topClients,
            'conflict_samples'              => array_slice($conflictsOnly, 0, 20),
            'owner_inactive_reviews'        => $ownerInactiveReviews,
            'sale_vendor_inactive_warnings' => $saleVendorInactiveWarns,
            'cross_channel_conflicts'       => $crossChannelConflicts,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

// ── Pretty ────────────────────────────────────────────────────────────────────
function fmtCV(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }
function fmtCN(int $n): string   { return number_format($n, 0, ',', '.'); }

$hr = str_repeat('═', 72);
echo PHP_EOL . $hr . PHP_EOL;
echo '  COM.R1.4 — Auditoria de Conflitos de Carteirização' . PHP_EOL;
echo '  Período : ' . $start . ' → ' . $end . PHP_EOL;
if ($vendor) echo '  Filtro vendedor: ' . $vendor . PHP_EOL;
if ($owner)  echo '  Filtro dono    : ' . $owner  . PHP_EOL;
echo $hr . PHP_EOL . PHP_EOL;

// Summary
echo str_repeat('─', 72) . PHP_EOL . '  Resumo' . PHP_EOL . str_repeat('─', 72) . PHP_EOL . PHP_EOL;
echo '  NFs analisadas (amostra)      : ' . fmtCN($totalNfs) . PHP_EOL;
echo '  Valor total analisado         : ' . fmtCV($totalValor) . PHP_EOL;
echo '  Com dono da carteira definido : ' . fmtCN($withOwner) . PHP_EOL;
echo '  Sem dono definido             : ' . fmtCN($noOwner) . PHP_EOL;
echo '  CONFLITOS (fora da carteira)  : ' . fmtCN($totalConflicts) . PHP_EOL;
echo '  Valor fora da carteira        : ' . fmtCV($conflictValue) . PHP_EOL;
echo '  % do valor em conflito        : ' . ($totalValor > 0 ? number_format($conflictValue / $totalValor * 100, 1, ',', '.') . '%' : '0%') . PHP_EOL;
echo '  Conflitos cross-channel       : ' . fmtCN($crossChannelConflicts) . PHP_EOL;
echo '  Donos inativos (revisão)      : ' . fmtCN($ownerInactiveReviews) . PHP_EOL;
echo '  Vendedor venda inativo (warn) : ' . fmtCN($saleVendorInactiveWarns) . PHP_EOL;

// Top sellers out
echo PHP_EOL . str_repeat('─', 72) . PHP_EOL . '  Top Vendedores que Venderam Fora da Carteira' . PHP_EOL . str_repeat('─', 72) . PHP_EOL . PHP_EOL;
foreach (array_slice($topSellers, 0, 8, true) as $vName => $info) {
    if ($info['conflicts_out'] === 0) continue;
    echo '  ' . str_pad(mb_substr($vName, 0, 30), 32) . ' conflitos=' . $info['conflicts_out'] . '  valor_fora=' . fmtCV($info['value_out']) . PHP_EOL;
}

// Top owners invaded
echo PHP_EOL . str_repeat('─', 72) . PHP_EOL . '  Top Donos com Clientes Vendidos por Outros' . PHP_EOL . str_repeat('─', 72) . PHP_EOL . PHP_EOL;
foreach (array_slice($topOwners, 0, 8, true) as $vName => $info) {
    if ($info['clients_invaded'] === 0) continue;
    echo '  ' . str_pad(mb_substr($vName, 0, 30), 32) . ' clientes_invadidos=' . $info['clients_invaded'] . PHP_EOL;
}

// Top clients in conflict
echo PHP_EOL . str_repeat('─', 72) . PHP_EOL . '  Top Clientes em Conflito' . PHP_EOL . str_repeat('─', 72) . PHP_EOL . PHP_EOL;
foreach (array_slice($topClients, 0, 10) as $c) {
    echo '  ' . str_pad(mb_substr($c['nome_cliente'], 0, 32), 34)
        . 'dono=' . str_pad(mb_substr($c['owner'], 0, 20), 22)
        . 'vendeu=' . mb_substr($c['sale_vendor'], 0, 20) . PHP_EOL;
    echo '    qtd=' . $c['qtd'] . '  valor=' . fmtCV($c['valor']) . PHP_EOL;
}

// Sample conflicts
if ($conflictsOnly) {
    echo PHP_EOL . str_repeat('─', 72) . PHP_EOL . '  Amostras de Conflito (top por valor)' . PHP_EOL . str_repeat('─', 72) . PHP_EOL . PHP_EOL;
    foreach (array_slice($conflictsOnly, 0, 10) as $r) {
        echo '  NF ' . str_pad((string)($r['numero_nf'] ?? '-'), 10)
            . str_pad(mb_substr((string)($r['nome_cliente'] ?? ''), 0, 30), 32)
            . fmtCV((float)($r['valor'] ?? 0)) . PHP_EOL;
        echo '    dono=' . mb_substr((string)($r['owner_vendor_name'] ?? '-'), 0, 28)
            . '  vendeu=' . mb_substr((string)($r['sale_vendor_name'] ?? '-'), 0, 28)
            . '  conf=' . $r['owner_confidence'] . PHP_EOL;
        echo '    badge: [' . $r['display_badge'] . ']  severity=' . $r['severity'] . PHP_EOL . PHP_EOL;
    }
}

echo $hr . PHP_EOL;
echo '  Concluído em ' . $elapsed . 's  |  hasDim=' . ($hasDim ? 'sim' : 'não') . '  hasNfs=' . ($hasNfs ? 'sim' : 'não') . PHP_EOL;
echo $hr . PHP_EOL . PHP_EOL;
