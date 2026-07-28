<?php
declare(strict_types=1);
/**
 * db_diagnostic.php — Diagnóstico completo do banco de dados BIomie
 * Uso: php tools/dev/db_diagnostic.php
 */
$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require $projectRoot . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$config = new \BIomie\Config();
$pdo    = \BIomie\Database::connect($config, 'db');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function tbl($pdo, string $t): bool {
    try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); return true; }
    catch (\Throwable $e) { return false; }
}
function cnt($pdo, string $t): string {
    try { return number_format((int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn()); }
    catch (\Throwable $e) { return 'ERR'; }
}
function cols($pdo, string $t): array {
    try {
        $r = $pdo->query("SHOW COLUMNS FROM `$t`");
        return array_column($r->fetchAll(PDO::FETCH_ASSOC), 'Field');
    } catch (\Throwable $e) { return []; }
}
function ck(bool $v, string $label): string { return ($v ? "\033[32m✔\033[0m" : "\033[31m✘\033[0m") . " $label"; }
function maxVal($pdo, string $t, string $col): string {
    try { return (string)($pdo->query("SELECT MAX(`$col`) FROM `$t`")->fetchColumn() ?? '-'); }
    catch (\Throwable $e) { return 'N/A'; }
}

echo "\n\033[1m===== DIAGNÓSTICO DO BANCO BIOMIE =====\033[0m\n";
echo "Data: " . date('Y-m-d H:i:s') . "\n";

// ── 1. MART TABLES ───────────────────────────────────────────────────────────
echo "\n\033[1m── MART TABLES ──────────────────────────────────────────────\033[0m\n";
$marts = [
    // Core geo + commercial marts
    'mart_compradores_por_geo'       => ['competencia', 'refreshed_at'],
    'mart_vendas_por_cnae_cnpj'      => ['competencia', 'refreshed_at'],
    'mart_cliente_periodo'           => ['periodo',     'refreshed_at'],
    'mart_produto_periodo'           => ['periodo',     'refreshed_at'],
    'mart_fiscal_nfe'                => ['competencia', 'refreshed_at'],
    'mart_comercial_pedido'          => ['competencia', 'refreshed_at'],
    'mart_vendedor_dia'              => ['competencia', 'refreshed_at'],
    'mart_financeiro_titulo'         => ['competencia', 'refreshed_at'],
    'mart_bridge_pedido_nfe_titulo'  => ['competencia', 'refreshed_at'],
    // Opportunity / forecast
    'mart_oportunidades'             => ['refreshed_at', null],
    'mart_forecasts'                 => ['refreshed_at', null],
];
foreach ($marts as $t => [$periodCol, $refreshCol]) {
    if (!tbl($pdo, $t)) { echo "  \033[31m✘\033[0m $t — AUSENTE\n"; continue; }
    $c = cnt($pdo, $t);
    $mc = cols($pdo, $t);
    $extra = '';
    if ($periodCol && in_array($periodCol, $mc, true)) $extra .= ' | last_' . $periodCol . '=' . maxVal($pdo, $t, $periodCol);
    if ($refreshCol && in_array($refreshCol, $mc, true)) $extra .= ' | refreshed=' . maxVal($pdo, $t, $refreshCol);
    echo "  \033[32m✔\033[0m $t — $c rows$extra\n";
}

// ── 2. MART_COMPRADORES_POR_GEO COLUNAS ─────────────────────────────────────
echo "\n\033[1m── mart_compradores_por_geo COLUNAS CRÍTICAS ────────────────\033[0m\n";
if (tbl($pdo, 'mart_compradores_por_geo')) {
    $mc = cols($pdo, 'mart_compradores_por_geo');
    foreach (['cnae_codigo','cnae_descricao','bairro_key','cidade_key','codigo_vendedor_omie','nome_vendedor','bairro_norm','cidade_norm','codigo_cliente_omie','qtd_notas','faturamento'] as $col) {
        echo '  ' . ck(in_array($col, $mc), $col) . "\n";
    }
    echo "\n  Distribuição geográfica (sample):\n";
    try {
        $rows = $pdo->query("SELECT LOWER(TRIM(COALESCE(cidade_norm,''))) AS cid, COUNT(*) AS n FROM mart_compradores_por_geo GROUP BY cid ORDER BY n DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $cid = $r['cid'] === '' ? '(sem cidade)' : $r['cid'];
            echo "    " . str_pad($cid, 30) . $r['n'] . " rows\n";
        }
    } catch (\Throwable $e) { echo "  QUERY ERR: " . $e->getMessage() . "\n"; }
}

// ── 3. CANONICAL TABLES ─────────────────────────────────────────────────────
echo "\n\033[1m── TABELAS CANÔNICAS ────────────────────────────────────────\033[0m\n";
$canonicals = [
    'biomie_notas_fiscais_canonicas',
    'biomie_notas_fiscais_itens_canonicos',
    'biomie_clientes_geo_dim',
    'biomie_clientes_dim',
    'biomie_pedidos_canonicos',
    'biomie_titulos_financeiros_canonicos',
    'biomie_documentos_xml_canonicos',
    'biomie_vendedores_omie',
    'biomie_omie_bronze',
    'biomie_sync_runs',
];
foreach ($canonicals as $t) {
    if (!tbl($pdo, $t)) { echo "  \033[31m✘\033[0m $t — AUSENTE\n"; continue; }
    echo "  \033[32m✔\033[0m $t — " . cnt($pdo, $t) . " rows\n";
}

// ── 4. NF CANÔNICAS QUALITY ─────────────────────────────────────────────────
echo "\n\033[1m── biomie_notas_fiscais_canonicas QUALIDADE ─────────────────\033[0m\n";
if (tbl($pdo, 'biomie_notas_fiscais_canonicas')) {
    $mc = cols($pdo, 'biomie_notas_fiscais_canonicas');
    foreach (['valor_total','valor_nfe','nome_vendedor','cnae_codigo','data_competencia','data_emissao','status_fiscal','codigo_cliente_omie'] as $col) {
        echo '  ' . ck(in_array($col, $mc), $col) . "\n";
    }
    try {
        $total  = (int)$pdo->query("SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas")->fetchColumn();
        $auth   = (int)$pdo->query("SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas WHERE status_fiscal='autorizada'")->fetchColumn();
        $minD   = $pdo->query("SELECT MIN(COALESCE(data_competencia,data_emissao)) FROM biomie_notas_fiscais_canonicas WHERE status_fiscal='autorizada'")->fetchColumn();
        $maxD   = $pdo->query("SELECT MAX(COALESCE(data_competencia,data_emissao)) FROM biomie_notas_fiscais_canonicas WHERE status_fiscal='autorizada'")->fetchColumn();
        $semCli = (int)$pdo->query("SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas WHERE codigo_cliente_omie IS NULL")->fetchColumn();
        echo "\n  total NFs: " . number_format($total) . "\n";
        echo "  autorizadas: " . number_format($auth) . "\n";
        echo "  período: $minD → $maxD\n";
        echo "  sem codigo_cliente_omie: " . number_format($semCli) . " (" . ($total > 0 ? round($semCli/$total*100,1) : 0) . "%) — impacta JOIN geo_dim\n";
    } catch (\Throwable $e) { echo "  QUERY ERR: " . $e->getMessage() . "\n"; }
}

// ── 5. GEO_DIM QUALITY ──────────────────────────────────────────────────────
echo "\n\033[1m── biomie_clientes_geo_dim QUALIDADE ────────────────────────\033[0m\n";
if (tbl($pdo, 'biomie_clientes_geo_dim')) {
    $mc = cols($pdo, 'biomie_clientes_geo_dim');
    foreach (['bairro_norm','bairro_key','cidade_norm','cidade_key','bairro_oficial','codigo_cliente_omie'] as $col) {
        echo '  ' . ck(in_array($col, $mc), $col) . "\n";
    }
    try {
        $total     = (int)$pdo->query("SELECT COUNT(*) FROM biomie_clientes_geo_dim")->fetchColumn();
        $semBairro = (int)$pdo->query("SELECT COUNT(*) FROM biomie_clientes_geo_dim WHERE bairro_norm IS NULL OR TRIM(bairro_norm)=''")->fetchColumn();
        $semCidade = (int)$pdo->query("SELECT COUNT(*) FROM biomie_clientes_geo_dim WHERE cidade_norm IS NULL OR TRIM(cidade_norm)=''")->fetchColumn();
        echo "\n  total clientes geo: " . number_format($total) . "\n";
        echo "  sem bairro: " . number_format($semBairro) . " (" . ($total > 0 ? round($semBairro/$total*100,1) : 0) . "%)\n";
        echo "  sem cidade: " . number_format($semCidade) . " (" . ($total > 0 ? round($semCidade/$total*100,1) : 0) . "%)\n";
        echo "  mapeados com bairro: " . ($total > 0 ? round(($total-$semBairro)/$total*100,1) : 0) . "%\n";
    } catch (\Throwable $e) { echo "  QUERY ERR: " . $e->getMessage() . "\n"; }
}

// ── 6. ITENS NF ─────────────────────────────────────────────────────────────
echo "\n\033[1m── biomie_notas_fiscais_itens_canonicos ──────────────────────\033[0m\n";
if (!tbl($pdo, 'biomie_notas_fiscais_itens_canonicos')) {
    echo "  \033[31m✘\033[0m AUSENTE — expand NF items retornará vazio\n";
} else {
    $mc = cols($pdo, 'biomie_notas_fiscais_itens_canonicos');
    foreach (['nota_key','sequencia_item','codigo_produto','descricao_produto','quantidade','valor_total','valor_desconto'] as $col) {
        echo '  ' . ck(in_array($col, $mc), $col) . "\n";
    }
    echo "  rows: " . cnt($pdo, 'biomie_notas_fiscais_itens_canonicos') . "\n";
    // Check coverage: NFs with items vs total authorized NFs
    try {
        $nfTotal = (int)$pdo->query("SELECT COUNT(DISTINCT id) FROM biomie_notas_fiscais_canonicas WHERE status_fiscal='autorizada'")->fetchColumn();
        $nfWithItems = (int)$pdo->query("SELECT COUNT(DISTINCT n.id) FROM biomie_notas_fiscais_canonicas n INNER JOIN biomie_notas_fiscais_itens_canonicos i ON i.nota_key=n.nota_key WHERE n.status_fiscal='autorizada'")->fetchColumn();
        $pct = $nfTotal > 0 ? round($nfWithItems/$nfTotal*100,1) : 0;
        echo "  NFs com itens: $nfWithItems / $nfTotal ($pct%)\n";
    } catch (\Throwable $e) {}
}

// ── 7. SYNC STATUS ──────────────────────────────────────────────────────────
echo "\n\033[1m── SYNC / OMIE STATUS ───────────────────────────────────────\033[0m\n";
foreach (['biomie_sync_runs','biomie_sync_cursors','biomie_sync_state','biomie_operational_events'] as $t) {
    if (!tbl($pdo, $t)) { echo "  \033[33m—\033[0m $t — ausente\n"; continue; }
    $n = cnt($pdo, $t);
    try {
        $mc = cols($pdo, $t);
        $dateCol = array_intersect(['created_at','started_at','updated_at','event_at'], $mc);
        $dateCol = reset($dateCol);
        $last = $dateCol ? $pdo->query("SELECT MAX(`$dateCol`) FROM `$t`")->fetchColumn() : '-';
        echo "  \033[32m✔\033[0m $t — $n rows | last: $last\n";
    } catch (\Throwable $e) {
        echo "  \033[32m✔\033[0m $t — $n rows\n";
    }
}
// Bronze stats
if (tbl($pdo, 'biomie_omie_bronze')) {
    $n = cnt($pdo, 'biomie_omie_bronze');
    $types = $pdo->query("SELECT entity_type, COUNT(*) AS n FROM biomie_omie_bronze GROUP BY entity_type ORDER BY n DESC LIMIT 5")->fetchAll(\PDO::FETCH_ASSOC);
    echo "  \033[32m✔\033[0m biomie_omie_bronze — $n rows total\n";
    foreach ($types as $r) echo "    " . str_pad($r['entity_type'],30) . number_format($r['n']) . "\n";
}

// ── 8. COBERTURA GEO CROSS-CHECK ────────────────────────────────────────────
echo "\n\033[1m── COBERTURA GEO: NF ↔ geo_dim ─────────────────────────────\033[0m\n";
if (tbl($pdo, 'biomie_notas_fiscais_canonicas') && tbl($pdo, 'biomie_clientes_geo_dim')) {
    try {
        $valorCol = in_array('valor_total', cols($pdo, 'biomie_notas_fiscais_canonicas')) ? 'valor_total' : 'valor_nfe';
        // NFs with geo match
        $sql = "SELECT
            COUNT(DISTINCT nf.id) AS total_nfs,
            SUM(nf.$valorCol) AS total_val,
            COUNT(DISTINCT CASE WHEN geo.codigo_cliente_omie IS NOT NULL THEN nf.id END) AS nfs_com_geo,
            SUM(CASE WHEN geo.codigo_cliente_omie IS NOT NULL THEN nf.$valorCol ELSE 0 END) AS val_com_geo
        FROM biomie_notas_fiscais_canonicas nf
        LEFT JOIN biomie_clientes_geo_dim geo ON geo.codigo_cliente_omie = nf.codigo_cliente_omie
        WHERE nf.status_fiscal = 'autorizada'
          AND DATE(COALESCE(nf.data_competencia, nf.data_emissao)) >= '2026-01-01'";
        $r = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        $totalNfs   = (int)($r['total_nfs'] ?? 0);
        $nfsComGeo  = (int)($r['nfs_com_geo'] ?? 0);
        $totalVal   = (float)($r['total_val'] ?? 0);
        $valComGeo  = (float)($r['val_com_geo'] ?? 0);
        $pctNfs = $totalNfs > 0 ? round($nfsComGeo / $totalNfs * 100, 1) : 0;
        $pctVal = $totalVal > 0 ? round($valComGeo / $totalVal * 100, 1) : 0;
        echo "  NFs 2026 com join geo_dim: $nfsComGeo / $totalNfs ($pctNfs%)\n";
        echo "  Valor 2026 com join geo_dim: R$ " . number_format($valComGeo, 2, ',', '.') . " / R$ " . number_format($totalVal, 2, ',', '.') . " ($pctVal%)\n";
        if ($pctNfs < 80) {
            echo "  \033[33m⚠ menos de 80% das NFs têm match geo — compradores/CNAE podem ficar incompletos\033[0m\n";
        }
    } catch (\Throwable $e) { echo "  QUERY ERR: " . $e->getMessage() . "\n"; }
}

// ── 9. ESTOQUE ───────────────────────────────────────────────────────────────
echo "\n\033[1m── ESTOQUE ──────────────────────────────────────────────────\033[0m\n";
foreach (['biomie_estoque_locais_dim','biomie_produtos_estoque_saldos','biomie_produtos_estoque_movimentos'] as $t) {
    $n = tbl($pdo, $t) ? cnt($pdo, $t) : 'AUSENTE';
    $status = $n === 'AUSENTE' ? "\033[31m✘\033[0m" : ($n === '0' ? "\033[33m⚠\033[0m" : "\033[32m✔\033[0m");
    echo "  $status $t — $n rows\n";
}

// ── 10. GAPS / RECOMENDAÇÕES ─────────────────────────────────────────────────
echo "\n\033[1m── GAPS E RECOMENDAÇÕES ─────────────────────────────────────\033[0m\n";
$issues = [];
// NF items coverage
if (tbl($pdo, 'biomie_notas_fiscais_itens_canonicos')) {
    $nfT = (int)$pdo->query("SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas WHERE status_fiscal='autorizada'")->fetchColumn();
    $nfI = (int)$pdo->query("SELECT COUNT(DISTINCT n.id) FROM biomie_notas_fiscais_canonicas n INNER JOIN biomie_notas_fiscais_itens_canonicos i ON i.nota_key=n.nota_key WHERE n.status_fiscal='autorizada'")->fetchColumn();
    $pct = $nfT > 0 ? round($nfI/$nfT*100,1) : 0;
    if ($pct < 90) $issues[] = "Apenas $pct% das NFs autorizadas têm itens — re-sync fiscal para enriquecer restantes";
}
// Geo coverage
$nfTot = (int)$pdo->query("SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas WHERE status_fiscal='autorizada'")->fetchColumn();
$nfGeo = (int)$pdo->query("SELECT COUNT(DISTINCT n.id) FROM biomie_notas_fiscais_canonicas n INNER JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie=n.codigo_cliente_omie WHERE n.status_fiscal='autorizada'")->fetchColumn();
$pctGeo = $nfTot > 0 ? round($nfGeo/$nfTot*100,1) : 0;
if ($pctGeo < 90) $issues[] = "$pctGeo% NFs com match geo (target 90%) — {$nfTot} NFs, {$nfGeo} com geo. Enriquecer clientes via Omie.";
// Sem cidade in mart
$semC = (int)$pdo->query("SELECT COUNT(*) FROM mart_compradores_por_geo WHERE cidade_norm IS NULL OR TRIM(cidade_norm)=''")->fetchColumn();
if ($semC > 0) $issues[] = "$semC rows em mart sem cidade — valor total R$ " . number_format((float)$pdo->query("SELECT COALESCE(SUM(faturamento),0) FROM mart_compradores_por_geo WHERE cidade_norm IS NULL OR TRIM(cidade_norm)=''")->fetchColumn(), 2, ',', '.');
// Opp/forecast marts
if (tbl($pdo, 'mart_oportunidades') && cnt($pdo,'mart_oportunidades')==='0') $issues[] = "mart_oportunidades vazio — executar php bin/compute_opportunities.php";
// Stock
if (tbl($pdo,'biomie_produtos_estoque_saldos') && cnt($pdo,'biomie_produtos_estoque_saldos')==='0') $issues[] = "Estoque zerado — sync Omie estoque nunca rodou ou produto sem saldo";

if (empty($issues)) {
    echo "  \033[32m✔ Nenhum gap crítico encontrado\033[0m\n";
} else {
    foreach ($issues as $i) echo "  \033[33m⚠\033[0m $i\n";
}

echo "\n\033[1m===== FIM DO DIAGNÓSTICO =====\033[0m\n\n";
