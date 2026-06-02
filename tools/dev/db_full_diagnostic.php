<?php
declare(strict_types=1);
/**
 * db_full_diagnostic.php — Diagnóstico completo do banco BIomie
 * Uso: php tools/dev/db_full_diagnostic.php > /tmp/diag.txt 2>&1
 */
$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require $projectRoot . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();
$config = new \BIomie\Config();
$pdo = \BIomie\Database::connect($config, 'db');
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

// ── helpers ──────────────────────────────────────────────────────────────────
function tbl($p, $t): bool { try { $p->query("SELECT 1 FROM `$t` LIMIT 1"); return true; } catch(\Throwable $e){ return false; } }
function cnt($p, $t): int  { try { return (int)$p->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); } catch(\Throwable $e){ return -1; } }
function cols($p, $t): array { try { return array_column($p->query("SHOW COLUMNS FROM `$t`")->fetchAll(\PDO::FETCH_ASSOC),'Field'); } catch(\Throwable $e){ return []; } }
function maxf($p, $t, $c): string { try { $v=$p->query("SELECT MAX(`$c`) FROM `$t`")->fetchColumn(); return $v ?? '-'; } catch(\Throwable $e){ return 'N/A'; } }
function q1($p, $sql, $params=[]): mixed { try { $s=$p->prepare($sql); $s->execute($params); return $s->fetchColumn(); } catch(\Throwable $e){ return null; } }
function q($p, $sql, $params=[]): array { try { $s=$p->prepare($sql); $s->execute($params); return $s->fetchAll(\PDO::FETCH_ASSOC); } catch(\Throwable $e){ return []; } }
function ok($v): string { return $v ? "\e[32m✔\e[0m" : "\e[31m✘\e[0m"; }
function warn($v): string { return $v ? "\e[33m⚠\e[0m" : "\e[32m✔\e[0m"; }
function h1($t): void { echo "\n\e[1;36m" . str_pad("── $t ", 62, "─") . "\e[0m\n"; }
function row($label, $val, $ok=null): void {
    $prefix = $ok === null ? '  ' : '  ' . ($ok ? "\e[32m✔\e[0m" : "\e[31m✘\e[0m") . ' ';
    echo $prefix . str_pad($label,38) . $val . "\n";
}
function pct(int $n, int $d): string { return $d > 0 ? round($n/$d*100,1).'%' : '0%'; }
function money(float $v): string { return 'R$ ' . number_format($v,2,',','.'); }

$line = str_repeat('═', 64);
echo "\n\e[1m$line\e[0m\n";
echo "\e[1m   DIAGNÓSTICO COMPLETO DO BANCO — BIomie\e[0m\n";
echo "   Data: " . date('Y-m-d H:i:s') . "\n";
echo "\e[1m$line\e[0m\n";

// ── 1. INVENTÁRIO COMPLETO ────────────────────────────────────────────────────
h1('INVENTÁRIO COMPLETO DE TABELAS');
$allTables = q($pdo, "SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH+INDEX_LENGTH AS size_bytes, CREATE_TIME, UPDATE_TIME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME");
$totalSize = 0;
foreach ($allTables as $t) {
    $n = cnt($pdo, $t['TABLE_NAME']);
    $sz = (int)$t['size_bytes'];
    $totalSize += $sz;
    $szStr = $sz > 1048576 ? round($sz/1048576,1).'MB' : round($sz/1024,0).'KB';
    $upd = $t['UPDATE_TIME'] ? substr($t['UPDATE_TIME'],0,10) : '-';
    printf("  %-48s %8s rows  %6s  upd:%s\n", $t['TABLE_NAME'], number_format($n), $szStr, $upd);
}
echo "\n  Total database size: " . round($totalSize/1048576,1) . " MB\n";

// ── 2. SYNC / OMIE ───────────────────────────────────────────────────────────
h1('SYNC E INTEGRAÇÃO OMIE');
$syncRuns = cnt($pdo,'biomie_sync_runs');
$lastSync = maxf($pdo,'biomie_sync_runs','started_at');
$failedRuns = (int)q1($pdo,"SELECT COUNT(*) FROM biomie_sync_runs WHERE status='failed' OR status='error'");
$successRuns = (int)q1($pdo,"SELECT COUNT(*) FROM biomie_sync_runs WHERE status='completed' OR status='success'");
row('Total sync runs', number_format($syncRuns));
row('Último sync', $lastSync);
row('Runs com sucesso', number_format($successRuns));
row('Runs com falha', number_format($failedRuns), $failedRuns === 0);
// Bronze breakdown
if (tbl($pdo,'biomie_omie_bronze')) {
    $bronze = q($pdo,"SELECT entity_type, COUNT(*) AS n, MAX(created_at) AS last FROM biomie_omie_bronze GROUP BY entity_type ORDER BY n DESC");
    echo "\n  \e[1mBronze (raw Omie):\e[0m " . number_format(cnt($pdo,'biomie_omie_bronze')) . " rows total\n";
    foreach ($bronze as $r) {
        printf("    %-40s %8s  last:%s\n", $r['entity_type'], number_format($r['n']), substr($r['last']??'-',0,10));
    }
}
// Last sync run details
$lastRuns = q($pdo,"SELECT sync_type, status, started_at, duration_ms, records_processed FROM biomie_sync_runs ORDER BY started_at DESC LIMIT 5");
if ($lastRuns) {
    echo "\n  \e[1mÚltimos 5 sync runs:\e[0m\n";
    foreach ($lastRuns as $r) {
        printf("    %-20s %-10s  %s  %sms  %s records\n",
            $r['sync_type']??'?', $r['status']??'?',
            substr($r['started_at']??'-',0,16),
            number_format($r['duration_ms']??0),
            number_format($r['records_processed']??0));
    }
}

// ── 3. NOTAS FISCAIS ─────────────────────────────────────────────────────────
h1('NOTAS FISCAIS CANÔNICAS');
if (!tbl($pdo,'biomie_notas_fiscais_canonicas')) { echo "  \e[31m✘ TABELA AUSENTE\e[0m\n"; }
else {
    $nfCols = cols($pdo,'biomie_notas_fiscais_canonicas');
    $total = cnt($pdo,'biomie_notas_fiscais_canonicas');
    $auth  = (int)q1($pdo,"SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas WHERE status_fiscal='autorizada'");
    $canc  = (int)q1($pdo,"SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas WHERE status_fiscal='cancelada'");
    $outros= $total - $auth - $canc;
    $minD  = q1($pdo,"SELECT MIN(COALESCE(data_competencia,data_emissao)) FROM biomie_notas_fiscais_canonicas WHERE status_fiscal='autorizada'");
    $maxD  = q1($pdo,"SELECT MAX(COALESCE(data_competencia,data_emissao)) FROM biomie_notas_fiscais_canonicas WHERE status_fiscal='autorizada'");
    $valCol= in_array('valor_total',$nfCols) ? 'valor_total' : (in_array('valor_nfe',$nfCols) ? 'valor_nfe' : null);
    $totalVal = $valCol ? (float)q1($pdo,"SELECT COALESCE(SUM($valCol),0) FROM biomie_notas_fiscais_canonicas WHERE status_fiscal='autorizada'") : 0;
    row('Total NFs', number_format($total));
    row('Autorizadas', number_format($auth) . ' (' . pct($auth,$total) . ')');
    row('Canceladas',  number_format($canc)  . ' (' . pct($canc,$total) . ')');
    row('Outras',      number_format($outros));
    row('Período (autorizadas)', "$minD → $maxD");
    if ($valCol) row('Valor total autorizado', money($totalVal));
    row('cnae_codigo na NF', in_array('cnae_codigo',$nfCols) ? 'presente' : 'AUSENTE — CNAE via clientes_dim', in_array('cnae_codigo',$nfCols));
    row('sem codigo_cliente_omie', (int)q1($pdo,"SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas WHERE codigo_cliente_omie IS NULL") . ' NFs', true);
    // By month
    $byMonth = q($pdo,"SELECT DATE_FORMAT(COALESCE(data_competencia,data_emissao),'%Y-%m') AS mes, COUNT(*) AS nfs, COALESCE(SUM($valCol),0) AS valor FROM biomie_notas_fiscais_canonicas WHERE status_fiscal='autorizada' GROUP BY mes ORDER BY mes DESC LIMIT 8");
    if ($byMonth) {
        echo "\n  \e[1mNFs por mês (últimos 8):\e[0m\n";
        foreach ($byMonth as $r) printf("    %s  %5d NFs  %s\n", $r['mes'], $r['nfs'], money((float)$r['valor']));
    }
    // By vendor
    if (in_array('nome_vendedor',$nfCols)) {
        $byVend = q($pdo,"SELECT COALESCE(nome_vendedor,'(sem vendedor)') AS v, COUNT(*) AS n, COALESCE(SUM($valCol),0) AS val FROM biomie_notas_fiscais_canonicas WHERE status_fiscal='autorizada' GROUP BY nome_vendedor ORDER BY val DESC LIMIT 5");
        echo "\n  \e[1mTop 5 vendedores:\e[0m\n";
        foreach ($byVend as $r) printf("    %-30s %4d NFs  %s\n", $r['v'], $r['n'], money((float)$r['val']));
    }
}

// ── 4. ITENS DE NF ───────────────────────────────────────────────────────────
h1('ITENS DE NF (biomie_notas_fiscais_itens_canonicos)');
if (!tbl($pdo,'biomie_notas_fiscais_itens_canonicos')) { echo "  \e[31m✘ AUSENTE — expand NF items retornará vazio\e[0m\n"; }
else {
    $itens = cnt($pdo,'biomie_notas_fiscais_itens_canonicos');
    $nfsComItens = (int)q1($pdo,"SELECT COUNT(DISTINCT n.id) FROM biomie_notas_fiscais_canonicas n INNER JOIN biomie_notas_fiscais_itens_canonicos i ON i.nota_key=n.nota_key WHERE n.status_fiscal='autorizada'");
    $nfAuth = (int)q1($pdo,"SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas WHERE status_fiscal='autorizada'");
    $topProd = q($pdo,"SELECT COALESCE(descricao_produto,'sem desc') AS p, COUNT(*) AS n, COALESCE(SUM(valor_total),0) AS v FROM biomie_notas_fiscais_itens_canonicos GROUP BY descricao_produto ORDER BY v DESC LIMIT 8");
    row('Total itens',  number_format($itens));
    row('NFs com itens', "$nfsComItens / $nfAuth (" . pct($nfsComItens,$nfAuth) . ")", $nfsComItens === $nfAuth);
    row('Itens por NF (média)', $nfAuth > 0 ? round($itens/$nfAuth,1) : '-');
    $semCod = (int)q1($pdo,"SELECT COUNT(*) FROM biomie_notas_fiscais_itens_canonicos WHERE codigo_produto IS NULL OR TRIM(codigo_produto)=''");
    row('Sem código produto', number_format($semCod) . ' (' . pct($semCod,$itens) . ')', $semCod === 0);
    if ($topProd) {
        echo "\n  \e[1mTop 8 produtos por faturamento:\e[0m\n";
        foreach ($topProd as $r) printf("    %-40s %4dx  %s\n", substr($r['p'],0,40), $r['n'], money((float)$r['v']));
    }
}

// ── 5. CLIENTES E GEO ────────────────────────────────────────────────────────
h1('CLIENTES E COBERTURA GEOGRÁFICA');
$geoDim = cnt($pdo,'biomie_clientes_geo_dim');
$semBairro = (int)q1($pdo,"SELECT COUNT(*) FROM biomie_clientes_geo_dim WHERE bairro_norm IS NULL OR TRIM(bairro_norm)=''");
$semCidade = (int)q1($pdo,"SELECT COUNT(*) FROM biomie_clientes_geo_dim WHERE cidade_norm IS NULL OR TRIM(cidade_norm)=''");
row('Total clientes geo_dim', number_format($geoDim));
row('Mapeados com bairro', (string)($geoDim-$semBairro) . ' (' . pct($geoDim-$semBairro,$geoDim) . ')', $semBairro===0);
row('Sem bairro', number_format($semBairro) . ' (' . pct($semBairro,$geoDim) . ')', $semBairro===0);
row('Sem cidade', number_format($semCidade) . ' (' . pct($semCidade,$geoDim) . ')', $semCidade===0);

// NF <-> geo coverage
$nfAuth2 = (int)q1($pdo,"SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas WHERE status_fiscal='autorizada'");
$nfGeo   = (int)q1($pdo,"SELECT COUNT(DISTINCT n.id) FROM biomie_notas_fiscais_canonicas n INNER JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie=n.codigo_cliente_omie WHERE n.status_fiscal='autorizada'");
$valCol2 = tbl($pdo,'biomie_notas_fiscais_canonicas') && in_array('valor_total',cols($pdo,'biomie_notas_fiscais_canonicas')) ? 'valor_total' : 'valor_nfe';
$valGeo  = (float)q1($pdo,"SELECT COALESCE(SUM(n.$valCol2),0) FROM biomie_notas_fiscais_canonicas n INNER JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie=n.codigo_cliente_omie WHERE n.status_fiscal='autorizada'");
$valTot2 = (float)q1($pdo,"SELECT COALESCE(SUM($valCol2),0) FROM biomie_notas_fiscais_canonicas WHERE status_fiscal='autorizada'");
echo "\n  \e[1mCobertura NF ↔ geo_dim:\e[0m\n";
row('NFs com match geo', "$nfGeo / $nfAuth2 (" . pct($nfGeo,$nfAuth2) . ')', $nfGeo===$nfAuth2);
row('Valor com match geo', money($valGeo) . ' / ' . money($valTot2) . ' (' . pct((int)$valGeo,(int)$valTot2) . ')');
if ($nfGeo < $nfAuth2) echo "  \e[33m  ⚠ " . ($nfAuth2-$nfGeo) . " NFs sem match geo — clientes cadastrados no Omie sem endereço\e[0m\n";

// Top cidades
$topCidades = q($pdo,"SELECT LOWER(TRIM(COALESCE(cidade_norm,'sem_cidade'))) AS cidade, COUNT(*) AS clientes FROM biomie_clientes_geo_dim GROUP BY LOWER(TRIM(cidade_norm)) ORDER BY clientes DESC LIMIT 10");
echo "\n  \e[1mTop 10 cidades (clientes geo_dim):\e[0m\n";
foreach ($topCidades as $r) printf("    %-30s %d clientes\n", $r['cidade']??'sem_cidade', $r['clientes']);

// ── 6. MART COMPRADORES POR GEO ──────────────────────────────────────────────
h1('MART_COMPRADORES_POR_GEO');
if (!tbl($pdo,'mart_compradores_por_geo')) { echo "  \e[31m✘ AUSENTE\e[0m\n"; }
else {
    $mc = cols($pdo,'mart_compradores_por_geo');
    $martTotal = cnt($pdo,'mart_compradores_por_geo');
    $semCMart  = (int)q1($pdo,"SELECT COUNT(*) FROM mart_compradores_por_geo WHERE cidade_norm IS NULL OR TRIM(cidade_norm)=''");
    $semBMart  = (int)q1($pdo,"SELECT COUNT(*) FROM mart_compradores_por_geo WHERE bairro_norm IS NULL OR TRIM(bairro_norm)=''");
    $totalFat  = (float)q1($pdo,"SELECT COALESCE(SUM(faturamento),0) FROM mart_compradores_por_geo");
    $fatSemCid = (float)q1($pdo,"SELECT COALESCE(SUM(faturamento),0) FROM mart_compradores_por_geo WHERE cidade_norm IS NULL OR TRIM(cidade_norm)=''");
    $lastRef   = maxf($pdo,'mart_compradores_por_geo','refreshed_at');
    $lastComp  = maxf($pdo,'mart_compradores_por_geo','competencia');
    row('Total rows', number_format($martTotal));
    row('Última competência', $lastComp);
    row('Última atualização', $lastRef);
    row('Faturamento total', money($totalFat));
    row('Sem cidade (rows)',  number_format($semCMart) . ' (' . pct($semCMart,$martTotal) . ') = ' . money($fatSemCid), $semCMart===0);
    row('Sem bairro (rows)', number_format($semBMart) . ' (' . pct($semBMart,$martTotal) . ')', $semBMart===0);
    foreach (['cnae_codigo','bairro_key','cidade_key','codigo_vendedor_omie'] as $c) {
        row("col $c", in_array($c,$mc)?'presente':'AUSENTE', in_array($c,$mc));
    }
    // Top territories
    $topTerr = q($pdo,"SELECT LOWER(TRIM(COALESCE(cidade_norm,'sem_cidade'))) AS cidade, LOWER(TRIM(COALESCE(bairro_norm,''))) AS bairro, SUM(faturamento) AS fat, SUM(qtd_notas) AS nfs, COUNT(DISTINCT codigo_cliente_omie) AS clis FROM mart_compradores_por_geo WHERE cidade_norm IS NOT NULL AND TRIM(cidade_norm)!='' GROUP BY cidade, bairro ORDER BY fat DESC LIMIT 10");
    echo "\n  \e[1mTop 10 territórios por faturamento:\e[0m\n";
    echo "    " . str_pad('Território',36) . str_pad('Fat',16) . str_pad('NFs',8) . "Clientes\n";
    foreach ($topTerr as $r) {
        $terr = $r['bairro'] ? "{$r['bairro']} — {$r['cidade']}" : $r['cidade'];
        printf("    %-36s %-16s %-8d %d\n", substr($terr,0,36), money((float)$r['fat']), $r['nfs'], $r['clis']);
    }
}

// ── 7. PEDIDOS ───────────────────────────────────────────────────────────────
h1('PEDIDOS CANÔNICOS');
if (!tbl($pdo,'biomie_pedidos_canonicos')) { echo "  \e[31m✘ AUSENTE\e[0m\n"; }
else {
    $ped = cnt($pdo,'biomie_pedidos_canonicos');
    $pedCols = cols($pdo,'biomie_pedidos_canonicos');
    row('Total pedidos', number_format($ped));
    if (in_array('etapa_faturamento',$pedCols)) {
        $etapas = q($pdo,"SELECT COALESCE(etapa_faturamento,'?') AS e, COUNT(*) AS n FROM biomie_pedidos_canonicos GROUP BY e ORDER BY n DESC LIMIT 8");
        echo "  Etapas:\n";
        foreach ($etapas as $r) printf("    etapa %-6s: %d pedidos\n", $r['e'], $r['n']);
    }
    if (in_array('valor_total',$pedCols)) {
        $valPed = (float)q1($pdo,"SELECT COALESCE(SUM(valor_total),0) FROM biomie_pedidos_canonicos");
        row('Valor total pedidos', money($valPed));
    }
    $minPed = q1($pdo,"SELECT MIN(data_pedido) FROM biomie_pedidos_canonicos");
    $maxPed = q1($pdo,"SELECT MAX(data_pedido) FROM biomie_pedidos_canonicos");
    row('Período pedidos', "$minPed → $maxPed");
}

// ── 8. FINANCEIRO ────────────────────────────────────────────────────────────
h1('TÍTULOS FINANCEIROS');
if (!tbl($pdo,'biomie_titulos_financeiros_canonicos')) { echo "  \e[31m✘ AUSENTE\e[0m\n"; }
else {
    $tit = cnt($pdo,'biomie_titulos_financeiros_canonicos');
    $titCols = cols($pdo,'biomie_titulos_financeiros_canonicos');
    row('Total títulos', number_format($tit));
    if (in_array('status_titulo',$titCols)) {
        $status = q($pdo,"SELECT COALESCE(status_titulo,'?') AS s, COUNT(*) AS n, COALESCE(SUM(valor_titulo),0) AS v FROM biomie_titulos_financeiros_canonicos GROUP BY s ORDER BY v DESC");
        foreach ($status as $r) printf("    %-20s %5d  %s\n", $r['s'], $r['n'], money((float)$r['v']));
    }
    if (in_array('tipo_pagamento',$titCols)) {
        $metodo = q($pdo,"SELECT COALESCE(tipo_pagamento,'?') AS m, COUNT(*) AS n FROM biomie_titulos_financeiros_canonicos GROUP BY m ORDER BY n DESC LIMIT 5");
        echo "  Métodos:\n";
        foreach ($metodo as $r) printf("    %-20s %d\n", $r['m'], $r['n']);
    }
}

// ── 9. MARTS GOLD ────────────────────────────────────────────────────────────
h1('MARTS GOLD — ESTADO COMPLETO');
$goldMarts = [
    'mart_compradores_por_geo'      => ['competencia','refreshed_at'],
    'mart_vendas_por_cnae_cnpj'     => ['competencia','refreshed_at'],
    'mart_cliente_periodo'          => ['periodo','refreshed_at'],
    'mart_produto_periodo'          => ['periodo','refreshed_at'],
    'mart_fiscal_nfe'               => ['competencia','refreshed_at'],
    'mart_comercial_pedido'         => ['competencia','refreshed_at'],
    'mart_vendedor_dia'             => ['competencia','refreshed_at'],
    'mart_financeiro_titulo'        => ['competencia','refreshed_at'],
    'mart_bridge_pedido_nfe_titulo' => ['competencia','refreshed_at'],
    'mart_oportunidades'            => ['refreshed_at',null],
    'mart_forecasts'                => ['refreshed_at',null],
];
foreach ($goldMarts as $t => [$pc,$rc]) {
    if (!tbl($pdo,$t)) { echo "  \e[31m✘\e[0m $t — AUSENTE\n"; continue; }
    $n = cnt($pdo,$t);
    $mc2 = cols($pdo,$t);
    $extra='';
    if ($pc && in_array($pc,$mc2)) $extra .= "  last_$pc=" . maxf($pdo,$t,$pc);
    if ($rc && in_array($rc,$mc2)) $extra .= "  refreshed=" . substr(maxf($pdo,$t,$rc),0,16);
    $sym = $n > 0 ? "\e[32m✔\e[0m" : "\e[33m⚠\e[0m";
    printf("  $sym %-40s %8s rows%s\n", $t, number_format($n), $extra);
}

// ── 10. ESTOQUE ──────────────────────────────────────────────────────────────
h1('ESTOQUE');
$estoqueTabelas = ['biomie_estoque_locais_dim','biomie_produtos_estoque_saldos','biomie_produtos_estoque_movimentos'];
foreach ($estoqueTabelas as $t) {
    $n = tbl($pdo,$t) ? cnt($pdo,$t) : -1;
    $sym = $n > 0 ? "\e[32m✔\e[0m" : ($n === 0 ? "\e[33m⚠\e[0m" : "\e[31m✘\e[0m");
    $label = $n < 0 ? 'AUSENTE' : number_format($n) . ' rows';
    echo "  $sym $t — $label\n";
}
if (tbl($pdo,'mart_produto_periodo')) {
    $prods = cnt($pdo,'mart_produto_periodo');
    $topP = q($pdo,"SELECT codigo_produto, COALESCE(descricao_produto,'?') AS desc, SUM(faturamento_total) AS fat, SUM(qtd_vendida) AS qtd FROM mart_produto_periodo GROUP BY codigo_produto ORDER BY fat DESC LIMIT 8");
    echo "\n  \e[1mmort_produto_periodo:\e[0m $prods rows\n";
    echo "  " . str_pad('Produto',40) . str_pad('Faturamento',18) . "Qtd\n";
    foreach ($topP as $r) printf("  %-40s %-18s %s\n", substr($r['desc'],0,39), money((float)$r['fat']), number_format((float)$r['qtd'],0,',','.'));
}

// ── 11. VENDEDORES ───────────────────────────────────────────────────────────
h1('VENDEDORES');
if (tbl($pdo,'biomie_vendedores_omie')) {
    $vends = q($pdo,"SELECT nome_vendedor, COALESCE(ativo,1) AS ativo FROM biomie_vendedores_omie ORDER BY nome_vendedor");
    row('Total vendedores', (string)count($vends));
    $ativos = array_filter($vends, fn($v) => $v['ativo']);
    row('Ativos', (string)count($ativos));
    foreach ($vends as $v) {
        $sym = $v['ativo'] ? "\e[32m●\e[0m" : "\e[31m○\e[0m";
        echo "    $sym " . ($v['nome_vendedor'] ?? 'sem nome') . "\n";
    }
}

// ── 12. GEO DIMENSION ────────────────────────────────────────────────────────
h1('DIMENSÃO GEO CEARÁ');
$geoTables = [
    'biomie_geo_municipios_ce'  => 'Municípios CE',
    'biomie_geo_regioes_ce'     => 'Regiões CE',
    'biomie_bairros_fortaleza_dim' => 'Bairros Fortaleza dim',
    'biomie_geo_bairros_rmf'    => 'Bairros RMF',
    'biomie_geo_bairro_aliases' => 'Aliases bairro',
    'biomie_geo_vendas_diaria'  => 'Vendas geo diária',
];
foreach ($geoTables as $t => $label) {
    $n = tbl($pdo,$t) ? cnt($pdo,$t) : -1;
    $sym = $n > 0 ? "\e[32m✔\e[0m" : ($n === 0 ? "\e[33m⚠\e[0m" : "\e[31m✘\e[0m");
    echo "  $sym $label ($t) — " . ($n<0?'AUSENTE':number_format($n).' rows') . "\n";
}

// ── 13. CNAE DIMENSION ───────────────────────────────────────────────────────
h1('DIMENSÃO CNAE');
$cnaeDim = tbl($pdo,'biomie_cnae_dim') ? cnt($pdo,'biomie_cnae_dim') : -1;
if ($cnaeDim > 0) {
    row('biomie_cnae_dim', number_format($cnaeDim) . ' rows', true);
    $topCnae = q($pdo,"SELECT m.cnae_codigo, d.cnae_descricao, SUM(m.faturamento) AS fat FROM mart_compradores_por_geo m LEFT JOIN biomie_cnae_dim d ON d.codigo_cnae=m.cnae_codigo WHERE m.cnae_codigo IS NOT NULL AND TRIM(m.cnae_codigo)!='' GROUP BY m.cnae_codigo ORDER BY fat DESC LIMIT 8");
} else {
    $topCnae = q($pdo,"SELECT cnae_codigo, cnae_descricao, SUM(faturamento) AS fat, SUM(qtd_notas) AS nfs, COUNT(DISTINCT codigo_cliente_omie) AS clis FROM mart_compradores_por_geo WHERE cnae_codigo IS NOT NULL AND TRIM(cnae_codigo)!='' GROUP BY cnae_codigo, cnae_descricao ORDER BY fat DESC LIMIT 8");
    echo "  \e[33m⚠\e[0m biomie_cnae_dim — " . ($cnaeDim<0?'AUSENTE':'vazio') . " — usando mart diretamente\n";
}
if ($topCnae) {
    echo "\n  \e[1mTop 8 CNAEs (por faturamento):\e[0m\n";
    foreach ($topCnae as $r) {
        $desc = $r['cnae_descricao'] ?? $r['cnae_codigo'] ?? '-';
        printf("    %-12s %-36s %s\n", $r['cnae_codigo']??'-', substr($desc,0,35), money((float)$r['fat']));
    }
}

// ── 14. QUALIDADE DOS DADOS ──────────────────────────────────────────────────
h1('QUALIDADE DOS DADOS — ANÁLISE CRUZADA');
// NF sem geo
$nfSemGeo = $nfAuth2 - $nfGeo;
$valSemGeo = $valTot2 - $valGeo;
echo "  \e[1mCruzamento NF ↔ clientes_geo_dim:\e[0m\n";
printf("    NFs com geo:    %5d (%s) — %s\n", $nfGeo, pct($nfGeo,$nfAuth2), money($valGeo));
printf("    NFs sem geo:    %5d (%s) — %s\n", $nfSemGeo, pct($nfSemGeo,$nfAuth2), money($valSemGeo));
if ($nfSemGeo > 0) {
    $top5SemGeo = q($pdo,"SELECT nf.nome_cliente, COUNT(*) AS n, COALESCE(SUM(nf.$valCol2),0) AS v FROM biomie_notas_fiscais_canonicas nf LEFT JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie=nf.codigo_cliente_omie WHERE nf.status_fiscal='autorizada' AND g.codigo_cliente_omie IS NULL GROUP BY nf.nome_cliente ORDER BY v DESC LIMIT 5");
    echo "    Top clientes sem geo:\n";
    foreach ($top5SemGeo as $r) printf("      %-40s %3d NFs  %s\n", substr($r['nome_cliente']??'?',0,39), $r['n'], money((float)$r['v']));
}
// Sem cidade no mart
echo "\n  \e[1mSem cidade no mart (compradores_geo):\e[0m\n";
$semCidadeClients = q($pdo,"SELECT nome_cliente, SUM(qtd_notas) AS nfs, SUM(faturamento) AS fat FROM mart_compradores_por_geo WHERE cidade_norm IS NULL OR TRIM(cidade_norm)='' GROUP BY nome_cliente ORDER BY fat DESC LIMIT 5");
printf("    Total sem cidade: %d rows = %s\n", $semCMart??0, money($fatSemCid??0.0));
foreach ($semCidadeClients as $r) printf("      %-40s %3d NFs  %s\n", substr($r['nome_cliente']??'?',0,39), $r['nfs'], money((float)$r['fat']));

// ── 15. HEALTH CHECKS ────────────────────────────────────────────────────────
h1('HEALTH CHECKS E ALERTAS');
$alerts = q($pdo,"SELECT type, message, created_at FROM biomie_health_alerts ORDER BY created_at DESC LIMIT 10");
if ($alerts) {
    echo "  \e[1mÚltimos alertas:\e[0m\n";
    foreach ($alerts as $a) printf("    [%s] %-12s %s\n", substr($a['created_at'],0,10), $a['type']??'?', $a['message']??'');
} else {
    echo "  \e[32m✔ Sem alertas ativos\e[0m\n";
}
// Dead letter jobs
$deadLetters = cnt($pdo,'biomie_dead_letter_jobs');
row('Dead letter jobs', number_format($deadLetters), $deadLetters===0);
// Schema migrations
$migTotal = cnt($pdo,'biomie_schema_migrations');
row('Migrações aplicadas', number_format($migTotal));

// ── 16. RESUMO EXECUTIVO ─────────────────────────────────────────────────────
h1('RESUMO EXECUTIVO');
$issues = [];
$ok_items = [];
// NF items
$nfI2 = (int)q1($pdo,"SELECT COUNT(DISTINCT n.id) FROM biomie_notas_fiscais_canonicas n INNER JOIN biomie_notas_fiscais_itens_canonicos i ON i.nota_key=n.nota_key WHERE n.status_fiscal='autorizada'");
if ($nfI2 === $nfAuth2) $ok_items[] = "NF itens: 100% cobertura ($nfI2 NFs com itens)";
// Mart freshness
$martFresh = maxf($pdo,'mart_compradores_por_geo','refreshed_at');
if ($martFresh >= date('Y-m-d', strtotime('-2 hours'))) $ok_items[] = "Marts frescos: última atualização $martFresh";
// Vendedores
$nVends = cnt($pdo,'biomie_vendedores_omie');
if ($nVends > 0) $ok_items[] = "$nVends vendedores sincronizados";
// Sync running
$recentSync = (int)q1($pdo,"SELECT COUNT(*) FROM biomie_sync_runs WHERE started_at >= NOW() - INTERVAL 2 HOUR");
if ($recentSync > 0) $ok_items[] = "Sync Omie ativo: $recentSync runs nas últimas 2h";
// Gaps
if ($nfSemGeo > 0) $issues[] = "$nfSemGeo NFs sem cobertura geo (" . pct($nfSemGeo,$nfAuth2) . ") — " . money($valSemGeo) . " não georreferenciado";
if (($semCMart??0) > 0) $issues[] = ($semCMart??0) . " clientes sem cidade no mart = " . money($fatSemCid??0.0) . " em sem_cidade";
if (!tbl($pdo,'biomie_produtos_estoque_saldos') || cnt($pdo,'biomie_produtos_estoque_saldos')===0) $issues[] = "Estoque: biomie_produtos_estoque_saldos vazio — sync estoque nunca rodou";
if (cnt($pdo,'mart_oportunidades')===0) $issues[] = "mart_oportunidades vazio — compute_opportunities sem histórico suficiente";
if ($deadLetters > 0) $issues[] = "$deadLetters dead letter jobs — verificar fila";

echo "\n  \e[32m✔ OK:\e[0m\n";
foreach ($ok_items as $i) echo "    ✔ $i\n";
echo "\n  \e[33m⚠ ATENÇÃO:\e[0m\n";
if (empty($issues)) echo "    ✔ Nenhum gap crítico.\n";
else foreach ($issues as $i) echo "    ⚠ $i\n";

echo "\n\e[1m$line\e[0m\n";
echo "\e[1m   FIM DO DIAGNÓSTICO — " . date('Y-m-d H:i:s') . "\e[0m\n";
echo "\e[1m$line\e[0m\n\n";
