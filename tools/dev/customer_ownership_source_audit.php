<?php

declare(strict_types=1);

/**
 * COM.R1.3 FASE 5 — Diagnóstico de Carteirização de Clientes por Vendedor
 *
 * Investiga a melhor fonte para dizer "este cliente pertence a qual vendedor".
 * Compara fontes: biomie_clientes_dim, primeiro pedido, primeira NF, vendedor mais recorrente.
 * Detecta conflitos: cliente vendido por vendedor diferente do dono da carteira.
 *
 * Uso:
 *   php tools/dev/customer_ownership_source_audit.php --start=YYYY-MM-DD --end=YYYY-MM-DD
 *       [--customer=nome_ou_doc] [--vendor=nome] [--pretty] [--json] [--sample=50]
 *
 * Read-only. Sem escrita.
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;

$opts     = getopt('', ['start:', 'end:', 'customer:', 'vendor:', 'pretty', 'json', 'sample:']);
$start    = (string) ($opts['start']    ?? date('Y-01-01'));
$end      = (string) ($opts['end']      ?? date('Y-m-d'));
$customer = isset($opts['customer']) ? trim((string) $opts['customer']) : null;
$vendor   = isset($opts['vendor'])   ? trim((string) $opts['vendor'])   : null;
$sampleN  = max(1, min(200, (int) ($opts['sample'] ?? 50)));
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

function tblO(PDO $pdo, string $t): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t LIMIT 1");
    $s->execute([':t' => $t]);
    return (int)$s->fetchColumn() > 0;
}
function colExists(PDO $pdo, string $tbl, string $col): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c LIMIT 1");
    $s->execute([':t' => $tbl, ':c' => $col]);
    return (int)$s->fetchColumn() > 0;
}

$hasClientsDim = tblO($pdo, 'biomie_clientes_dim');
$hasNfs        = tblO($pdo, 'biomie_notas_fiscais_canonicas');
$hasOrders     = tblO($pdo, 'biomie_pedidos_canonicos');
$hasBronze     = tblO($pdo, 'biomie_omie_bronze');

// ── SECTION 1 — Source inventory ────────────────────────────────────────────
$sourceInventory = [
    'biomie_clientes_dim'         => $hasClientsDim,
    'biomie_notas_fiscais_canonicas' => $hasNfs,
    'biomie_pedidos_canonicos'    => $hasOrders,
    'biomie_omie_bronze (clientes)' => false,
];

$dimHasVendorField = false;
$dimHasConfidence  = false;
$dimHasInclPor     = false;
if ($hasClientsDim) {
    $dimHasVendorField = colExists($pdo, 'biomie_clientes_dim', 'vendedor_responsavel_nome');
    $dimHasConfidence  = colExists($pdo, 'biomie_clientes_dim', 'vendedor_responsavel_confidence');
    $dimHasInclPor     = colExists($pdo, 'biomie_clientes_dim', 'incluido_por');
}

// Check bronze for clients with uInc (user who included)
$bronzeClientUInc = false;
if ($hasBronze) {
    $sBr = $pdo->query("SELECT COUNT(*) FROM biomie_omie_bronze WHERE entity_name IN ('client','cliente') AND JSON_EXTRACT(payload_json,'$.infoCadastro.uInc') IS NOT NULL LIMIT 1");
    $bronzeClientUInc = (int)$sBr->fetchColumn() > 0;
    $sourceInventory['biomie_omie_bronze (clientes)'] = true;
}

// ── SECTION 2 — Client ownership from dim ────────────────────────────────────
$dimOwnership = [];
if ($hasClientsDim && $dimHasVendorField) {
    $vWhereD = $vendor   !== null ? " AND vendedor_responsavel_nome = :vendor"   : '';
    $cWhereD = $customer !== null ? " AND (nome_cliente = :cust OR cpf_cnpj = :cust)" : '';
    $dParams = [];
    if ($vendor)   $dParams[':vendor'] = $vendor;
    if ($customer) $dParams[':cust'] = $customer;

    $colConf = $dimHasConfidence ? 'vendedor_responsavel_confidence' : '0';
    $sD = $pdo->prepare(
        "SELECT codigo_cliente_omie, nome_cliente, COALESCE(cpf_cnpj,'') AS documento_cliente,
                vendedor_responsavel_nome, vendedor_responsavel_origem,
                {$colConf} AS confidence
         FROM biomie_clientes_dim
         WHERE 1=1{$vWhereD}{$cWhereD}
         ORDER BY confidence DESC, nome_cliente ASC
         LIMIT :lim"
    );
    $sD->bindValue(':lim', $sampleN, PDO::PARAM_INT);
    foreach ($dParams as $k => $v) $sD->bindValue($k, $v, PDO::PARAM_STR);
    $sD->execute();
    $dimOwnership = $sD->fetchAll(PDO::FETCH_ASSOC);
}

// ── SECTION 3 — First order vendor (by cliente) ──────────────────────────────
$firstOrderOwner = [];
if ($hasOrders) {
    $sOrd = $pdo->prepare(
        "SELECT p.codigo_cliente_omie, p.nome_cliente,
                p.codigo_vendedor_omie AS first_vendor_id,
                p.nome_vendedor        AS first_vendor_name,
                MIN(COALESCE(p.data_faturamento, p.data_emissao)) AS first_date
         FROM biomie_pedidos_canonicos p
         WHERE p.status_comercial IN ('faturado','autorizado')
           AND p.codigo_cliente_omie IS NOT NULL
         GROUP BY p.codigo_cliente_omie, p.nome_cliente, p.codigo_vendedor_omie, p.nome_vendedor
         ORDER BY first_date ASC
         LIMIT :lim"
    );
    $sOrd->bindValue(':lim', $sampleN, PDO::PARAM_INT);
    $sOrd->execute();
    foreach ($sOrd->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cid = (string) ($r['codigo_cliente_omie'] ?? '');
        if ($cid !== '' && !isset($firstOrderOwner[$cid])) {
            $firstOrderOwner[$cid] = $r;
        }
    }
}

// ── SECTION 4 — First invoice vendor (by cliente) ────────────────────────────
$firstNfOwner = [];
if ($hasNfs) {
    $sNf = $pdo->prepare(
        "SELECT nf.codigo_cliente_omie, nf.nome_cliente,
                nf.codigo_vendedor_omie AS first_vendor_id,
                nf.nome_vendedor        AS first_vendor_name,
                MIN(COALESCE(nf.data_competencia, nf.data_emissao)) AS first_date
         FROM biomie_notas_fiscais_canonicas nf
         WHERE nf.status_fiscal = 'autorizada'
           AND nf.codigo_cliente_omie IS NOT NULL
         GROUP BY nf.codigo_cliente_omie, nf.nome_cliente, nf.codigo_vendedor_omie, nf.nome_vendedor
         ORDER BY first_date ASC
         LIMIT :lim"
    );
    $sNf->bindValue(':lim', $sampleN, PDO::PARAM_INT);
    $sNf->execute();
    foreach ($sNf->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cid = (string) ($r['codigo_cliente_omie'] ?? '');
        if ($cid !== '' && !isset($firstNfOwner[$cid])) {
            $firstNfOwner[$cid] = $r;
        }
    }
}

// ── SECTION 5 — Conflict detection ───────────────────────────────────────────
// Clients sold by different vendor than dim owner
$conflicts = [];
$periodClauseN = "COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end";

if ($hasNfs && $hasClientsDim && $dimHasVendorField) {
    $sCon = $pdo->prepare(
        "SELECT nf.codigo_cliente_omie,
                nf.nome_cliente AS nome_cliente_nf,
                nf.nome_vendedor AS vendedor_na_venda,
                cd.vendedor_responsavel_nome AS vendedor_dono_carteira,
                cd.vendedor_responsavel_confidence AS confianca_dono,
                COUNT(DISTINCT nf.nota_key) AS qtd_nfs_fora,
                SUM(nf.valor_mercadorias) AS valor_fora
         FROM biomie_notas_fiscais_canonicas nf
         INNER JOIN biomie_clientes_dim cd ON cd.codigo_cliente_omie = nf.codigo_cliente_omie
         WHERE {$periodClauseN}
           AND nf.status_fiscal = 'autorizada'
           AND cd.vendedor_responsavel_nome IS NOT NULL
           AND cd.vendedor_responsavel_nome != ''
           AND cd.vendedor_responsavel_nome != 'Sem vendedor'
           AND nf.nome_vendedor != cd.vendedor_responsavel_nome
         GROUP BY nf.codigo_cliente_omie, nf.nome_cliente,
                  nf.nome_vendedor, cd.vendedor_responsavel_nome, cd.vendedor_responsavel_confidence
         ORDER BY valor_fora DESC
         LIMIT 30"
    );
    $sCon->execute([':start' => $start, ':end' => $end]);
    $conflicts = $sCon->fetchAll(PDO::FETCH_ASSOC);
}

// ── SECTION 6 — Bronze: uInc field investigation ─────────────────────────────
$bronzeUIncSample = [];
if ($hasBronze && $bronzeClientUInc) {
    $sBu = $pdo->query(
        "SELECT JSON_UNQUOTE(JSON_EXTRACT(b.payload_json, '$.codigo_cliente_omie')) AS cliente_id,
                JSON_UNQUOTE(JSON_EXTRACT(b.payload_json, '$.nome_fantasia'))       AS nome,
                JSON_UNQUOTE(JSON_EXTRACT(b.payload_json, '$.infoCadastro.uInc'))   AS usuario_inclusao,
                JSON_UNQUOTE(JSON_EXTRACT(b.payload_json, '$.infoCadastro.dInc'))   AS data_inclusao,
                JSON_UNQUOTE(JSON_EXTRACT(b.payload_json, '$.vendedores[0].codigo')) AS cod_vendedor_responsavel
         FROM biomie_omie_bronze b
         WHERE b.entity_name IN ('client','cliente')
           AND JSON_EXTRACT(b.payload_json,'$.infoCadastro.uInc') IS NOT NULL
         LIMIT 10"
    );
    $bronzeUIncSample = $sBu->fetchAll(PDO::FETCH_ASSOC);
}

// ── SECTION 7 — Proposed rule ─────────────────────────────────────────────────
$proposedRule = [
    'priority_1' => [
        'source'      => 'biomie_clientes_dim.vendedor_responsavel_nome',
        'confidence'  => 'high',
        'available'   => $hasClientsDim && $dimHasVendorField,
        'description' => 'Campo explícito de responsável já materializado pela dim. Origin: pedido ou NF.',
    ],
    'priority_2' => [
        'source'      => 'biomie_omie_bronze.infoCadastro.uInc',
        'confidence'  => 'high (se mapeado ao vendedor)',
        'available'   => $bronzeClientUInc,
        'description' => 'Usuário Omie que incluiu o cliente. Requer mapeamento uInc → vendedor via ListarVendedores.',
    ],
    'priority_3' => [
        'source'      => 'Primeira NF autorizada do cliente (biomie_notas_fiscais_canonicas)',
        'confidence'  => 'medium',
        'available'   => $hasNfs,
        'description' => 'Vendedor da primeira NF autorizada emitida para o cliente.',
    ],
    'priority_4' => [
        'source'      => 'Primeiro pedido autorizado do cliente (biomie_pedidos_canonicos)',
        'confidence'  => 'medium',
        'available'   => $hasOrders,
        'description' => 'Vendedor do primeiro pedido autorizado/faturado para o cliente.',
    ],
    'conflict_signal' => [
        'rule'        => 'Se NF.nome_vendedor ≠ dim.vendedor_responsavel_nome → sinalizar como "venda fora da carteira"',
        'action'      => 'Não bloquear venda; apenas registrar e reportar no painel do vendedor',
    ],
];

// Stats
$totalClients = count($dimOwnership);
$clientsWithOwner = count(array_filter($dimOwnership, fn($r) => !empty($r['vendedor_responsavel_nome']) && $r['vendedor_responsavel_nome'] !== 'Sem vendedor'));
$conflictCount = count($conflicts);
$conflictValue = array_sum(array_column($conflicts, 'valor_fora'));

$elapsed = round(microtime(true) - $t0, 2);

// ── JSON ──────────────────────────────────────────────────────────────────────
if ($jsonMode) {
    echo json_encode([
        'customer_ownership_source_audit' => [
            'version' => 'COM.R1.3', 'period' => ['start' => $start, 'end' => $end],
            'generated_at' => date('Y-m-d H:i:s'), 'elapsed_s' => $elapsed,
            'source_inventory'         => $sourceInventory,
            'dim_has_vendor_field'     => $dimHasVendorField,
            'dim_has_confidence'       => $dimHasConfidence,
            'bronze_has_u_inc'         => $bronzeClientUInc,
            'clients_sampled'          => $totalClients,
            'clients_with_owner'       => $clientsWithOwner,
            'conflicts_count'          => $conflictCount,
            'conflicts_valor'          => round($conflictValue, 2),
            'sample_dim_ownership'     => array_slice($dimOwnership, 0, 10),
            'sample_conflicts'         => array_slice($conflicts, 0, 10),
            'bronze_u_inc_sample'      => $bronzeUIncSample,
            'proposed_rule'            => $proposedRule,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

// ── Pretty ────────────────────────────────────────────────────────────────────
$hr = str_repeat('═', 72);
echo PHP_EOL . $hr . PHP_EOL;
echo '  COM.R1.3 — Diagnóstico de Carteirização de Clientes por Vendedor' . PHP_EOL;
echo '  Período : ' . $start . ' → ' . $end . PHP_EOL;
echo $hr . PHP_EOL . PHP_EOL;

// Source inventory
echo str_repeat('─', 72) . PHP_EOL . '  1 — Fontes Disponíveis' . PHP_EOL . str_repeat('─', 72) . PHP_EOL . PHP_EOL;
foreach ($sourceInventory as $src => $avail) {
    echo '  ' . ($avail ? '✓' : '✗') . ' ' . $src . PHP_EOL;
}
echo PHP_EOL;
echo '  biomie_clientes_dim.vendedor_responsavel_nome: ' . ($dimHasVendorField ? '✓ presente' : '✗ ausente') . PHP_EOL;
echo '  biomie_clientes_dim.vendedor_responsavel_confidence: ' . ($dimHasConfidence ? '✓ presente' : '✗ ausente') . PHP_EOL;
echo '  bronze cliente uInc (usuario inclusão): ' . ($bronzeClientUInc ? '✓ disponível' : '✗ sem dados') . PHP_EOL;

// Dim ownership sample
if ($dimOwnership) {
    echo PHP_EOL . str_repeat('─', 72) . PHP_EOL . '  2 — Amostra de Donos da Carteira (biomie_clientes_dim)' . PHP_EOL . str_repeat('─', 72) . PHP_EOL . PHP_EOL;
    echo '  ' . str_pad('cliente', 34) . str_pad('dono_carteira', 26) . str_pad('origem', 18) . 'confiança' . PHP_EOL;
    echo '  ' . str_repeat('-', 70) . PHP_EOL;
    foreach (array_slice($dimOwnership, 0, 15) as $r) {
        echo '  ' . str_pad(mb_substr((string)$r['nome_cliente'], 0, 32), 34)
            . str_pad(mb_substr((string)($r['vendedor_responsavel_nome'] ?? 'Sem dono'), 0, 24), 26)
            . str_pad((string)($r['vendedor_responsavel_origem'] ?? '-'), 18)
            . (string)($r['confidence'] ?? '-') . PHP_EOL;
    }
    echo PHP_EOL . '  Total na amostra: ' . $totalClients . '  |  Com dono: ' . $clientsWithOwner . PHP_EOL;
}

// Conflicts
echo PHP_EOL . str_repeat('─', 72) . PHP_EOL . '  3 — Conflitos: Venda Fora da Carteira no Período' . PHP_EOL . str_repeat('─', 72) . PHP_EOL . PHP_EOL;
if (!$conflicts) {
    echo '  Nenhum conflito detectado (ou dim não disponível).' . PHP_EOL;
} else {
    echo '  Total: ' . $conflictCount . ' caso(s)  |  Valor: R$ ' . number_format($conflictValue, 2, ',', '.') . PHP_EOL . PHP_EOL;
    echo '  ' . str_pad('cliente', 30) . str_pad('vendedor_venda', 22) . str_pad('dono_carteira', 22) . str_pad('NFs', 5) . 'valor' . PHP_EOL;
    echo '  ' . str_repeat('-', 72) . PHP_EOL;
    foreach (array_slice($conflicts, 0, 10) as $c) {
        echo '  ' . str_pad(mb_substr((string)$c['nome_cliente_nf'], 0, 28), 30)
            . str_pad(mb_substr((string)$c['vendedor_na_venda'], 0, 20), 22)
            . str_pad(mb_substr((string)$c['vendedor_dono_carteira'], 0, 20), 22)
            . str_pad((string)$c['qtd_nfs_fora'], 5)
            . 'R$ ' . number_format((float)$c['valor_fora'], 2, ',', '.') . PHP_EOL;
    }
}

// Bronze uInc sample
if ($bronzeUIncSample) {
    echo PHP_EOL . str_repeat('─', 72) . PHP_EOL . '  4 — Amostra uInc (usuário inclusão) no Bronze de Clientes' . PHP_EOL . str_repeat('─', 72) . PHP_EOL . PHP_EOL;
    foreach (array_slice($bronzeUIncSample, 0, 5) as $b) {
        echo '  cliente_id=' . ($b['cliente_id'] ?? '-') . '  nome=' . mb_substr((string)($b['nome'] ?? ''), 0, 25)
            . '  uInc=' . ($b['usuario_inclusao'] ?? '-')
            . '  dInc=' . ($b['data_inclusao'] ?? '-') . PHP_EOL;
    }
    echo PHP_EOL . '  Conclusão: campo uInc existe no bronze. Requer mapeamento uInc → nome_vendedor.' . PHP_EOL;
}

// Proposed rule
echo PHP_EOL . str_repeat('─', 72) . PHP_EOL . '  5 — Proposta de Regra de Dono da Carteira' . PHP_EOL . str_repeat('─', 72) . PHP_EOL . PHP_EOL;
foreach ($proposedRule as $key => $rule) {
    if ($key === 'conflict_signal') {
        echo '  CONFLITO: ' . $rule['rule'] . PHP_EOL;
        echo '  Ação: ' . $rule['action'] . PHP_EOL;
    } else {
        $avail = $rule['available'] ? '[disponível]' : '[indisponível]';
        echo '  ' . strtoupper($key) . ' ' . $avail . PHP_EOL;
        echo '    Fonte      : ' . $rule['source'] . PHP_EOL;
        echo '    Confiança  : ' . $rule['confidence'] . PHP_EOL;
        echo '    Descrição  : ' . $rule['description'] . PHP_EOL . PHP_EOL;
    }
}

echo $hr . PHP_EOL;
echo '  Concluído em ' . $elapsed . 's' . PHP_EOL;
echo $hr . PHP_EOL . PHP_EOL;
