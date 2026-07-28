<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — Fase 2 (metade 2): snapshot/compare de INVARIANTES.
 *
 * Compara antes/depois da ressync SEM usar CanonicalHashing (que agora inclui as
 * 3 datas novas e por isso deve mudar). Gera um hash temporário só dos campos que
 * NÃO deveriam mudar numa ressync de datas.
 *
 * Invariantes por (company_id, pedido_id_omie):
 *   codigo_pedido, cliente (codigo+doc+nome), vendedor (codigo+nome),
 *   etapa_omie, status_comercial, valores, quantidade, operation_scope, data_emissao
 * Exclui: data_inclusao_omie/data_alteracao_omie/data_previsao_faturamento,
 *         sincronizado_em, hash_payload.
 *
 * READ-ONLY.
 *
 * Uso:
 *   php tools/dev/quote_agenda_invariant_snapshot.php --company=EXPERT --out=before.json
 *   php tools/dev/quote_agenda_invariant_snapshot.php --compare --before=before.json --after=after.json
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Support\TenantCliContext;

$opts = getopt('', ['company:', 'out:', 'compare', 'before:', 'after:']);

// ── Modo compare ─────────────────────────────────────────────────────────────────
if (isset($opts['compare'])) {
    $before = json_decode((string) file_get_contents((string) ($opts['before'] ?? '')), true);
    $after  = json_decode((string) file_get_contents((string) ($opts['after'] ?? '')), true);
    if (!is_array($before) || !is_array($after)) {
        fwrite(STDERR, "before/after inválidos.\n");
        exit(2);
    }
    quoteAgendaCompare($before, $after);
    exit(0);
}

// ── Modo snapshot ────────────────────────────────────────────────────────────────
$config = new Config();
$conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $conn);

$args = ['snapshot', '--company=' . ((string) ($opts['company'] ?? ''))];
$tenant = TenantCliContext::boot($pdo, $args);
$cid = (int) $tenant['company_id'];

$stmt = $pdo->prepare(
    "SELECT pedido_id_omie, codigo_pedido, codigo_integracao,
            codigo_vendedor_omie, nome_vendedor,
            codigo_cliente_omie, nome_cliente, documento_cliente,
            etapa_omie, status_comercial, operation_scope,
            valor_total, valor_mercadorias, valor_descontos, quantidade_itens,
            data_emissao, data_faturamento, data_cancelamento,
            data_inclusao_omie, data_alteracao_omie, data_previsao_faturamento
       FROM biomie_pedidos_canonicos
      WHERE company_id = :cid
      ORDER BY pedido_id_omie"
);
$stmt->execute(['cid' => $cid]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$orders = [];
$etapa10 = 0;
$valorTotal = 0.0;
$fillInclusao = 0; $fillPrevisao = 0; $fillAlteracao = 0;

foreach ($rows as $r) {
    $id = (string) $r['pedido_id_omie'];
    $invariant = [
        'codigo_pedido'        => (string) $r['codigo_pedido'],
        'codigo_vendedor_omie' => (string) $r['codigo_vendedor_omie'],
        'nome_vendedor'        => (string) $r['nome_vendedor'],
        'codigo_cliente_omie'  => (string) ($r['codigo_cliente_omie'] ?? ''),
        'documento_cliente'    => (string) ($r['documento_cliente'] ?? ''),
        'nome_cliente'         => (string) $r['nome_cliente'],
        'etapa_omie'           => (string) ($r['etapa_omie'] ?? ''),
        'status_comercial'     => (string) $r['status_comercial'],
        'operation_scope'      => (string) ($r['operation_scope'] ?? ''),
        'valor_total'          => number_format((float) $r['valor_total'], 2, '.', ''),
        'valor_mercadorias'    => number_format((float) $r['valor_mercadorias'], 2, '.', ''),
        'valor_descontos'      => number_format((float) $r['valor_descontos'], 2, '.', ''),
        'quantidade_itens'     => (int) $r['quantidade_itens'],
        'data_emissao'         => (string) ($r['data_emissao'] ?? ''),
        'data_faturamento'     => (string) ($r['data_faturamento'] ?? ''),
        'data_cancelamento'    => (string) ($r['data_cancelamento'] ?? ''),
    ];
    $orders[$id] = [
        'invariant_hash' => hash('sha256', json_encode($invariant, JSON_UNESCAPED_UNICODE)),
        'invariant'      => $invariant,
        // Datas novas — fora do hash, só para relatório de preenchimento.
        'data_inclusao_omie'        => (string) ($r['data_inclusao_omie'] ?? ''),
        'data_alteracao_omie'       => (string) ($r['data_alteracao_omie'] ?? ''),
        'data_previsao_faturamento' => (string) ($r['data_previsao_faturamento'] ?? ''),
    ];

    if ((string) ($r['etapa_omie'] ?? '') === '10') { $etapa10++; }
    $valorTotal += (float) $r['valor_total'];
    if (($r['data_inclusao_omie'] ?? null) !== null)        { $fillInclusao++; }
    if (($r['data_previsao_faturamento'] ?? null) !== null) { $fillPrevisao++; }
    if (($r['data_alteracao_omie'] ?? null) !== null)       { $fillAlteracao++; }
}

$snapshot = [
    'company_id'   => $cid,
    'company_slug' => $tenant['slug'],
    'taken_at'     => date(DATE_ATOM),
    'summary' => [
        'orders_total'   => count($orders),
        'etapa10'        => $etapa10,
        'valor_total'    => round($valorTotal, 2),
        'fill_inclusao'  => $fillInclusao,
        'fill_previsao'  => $fillPrevisao,
        'fill_alteracao' => $fillAlteracao,
    ],
    'orders' => $orders,
];

$json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (isset($opts['out']) && (string) $opts['out'] !== '') {
    file_put_contents((string) $opts['out'], $json);
    $s = $snapshot['summary'];
    fwrite(STDOUT, "snapshot {$tenant['slug']} → {$opts['out']}  (orders={$s['orders_total']} etapa10={$s['etapa10']} valor=" . number_format($s['valor_total'], 2, ',', '.') . " fill_inclusao={$s['fill_inclusao']})\n");
} else {
    echo $json, PHP_EOL;
}

// ── Compare ──────────────────────────────────────────────────────────────────────
/**
 * @param array<string,mixed> $before
 * @param array<string,mixed> $after
 */
function quoteAgendaCompare(array $before, array $after): void
{
    $bo = (array) ($before['orders'] ?? []);
    $ao = (array) ($after['orders'] ?? []);

    $missing = [];      // sumiram (before sem after) — perda de pedido
    $novos = [];        // novos (after sem before)
    $drifts = [];       // invariante mudou
    $dateFilled = 0;    // ganharam data_inclusao_omie

    foreach ($bo as $id => $b) {
        if (!isset($ao[$id])) {
            $missing[] = $id;
            continue;
        }
        $a = $ao[$id];
        if (($b['invariant_hash'] ?? '') !== ($a['invariant_hash'] ?? '')) {
            $changed = [];
            foreach ((array) ($b['invariant'] ?? []) as $k => $v) {
                $av = $a['invariant'][$k] ?? null;
                if ((string) $v !== (string) $av) {
                    $changed[$k] = ['before' => $v, 'after' => $av];
                }
            }
            $drifts[] = ['pedido_id_omie' => $id, 'changed' => $changed];
        }
        if (($b['data_inclusao_omie'] ?? '') === '' && ($a['data_inclusao_omie'] ?? '') !== '') {
            $dateFilled++;
        }
    }
    foreach ($ao as $id => $a) {
        if (!isset($bo[$id])) { $novos[] = $id; }
    }

    $bs = (array) ($before['summary'] ?? []);
    $as = (array) ($after['summary'] ?? []);

    echo str_repeat('═', 74), PHP_EOL;
    echo "  INVARIANT COMPARE — {$before['company_slug']} (company {$before['company_id']})", PHP_EOL;
    echo str_repeat('═', 74), PHP_EOL;
    printf("  pedidos totais : %s → %s%s", $bs['orders_total'] ?? '?', $as['orders_total'] ?? '?', PHP_EOL);
    printf("  etapa 10       : %s → %s%s", $bs['etapa10'] ?? '?', $as['etapa10'] ?? '?', PHP_EOL);
    printf("  valor total    : %s → %s%s", number_format((float) ($bs['valor_total'] ?? 0), 2, ',', '.'), number_format((float) ($as['valor_total'] ?? 0), 2, ',', '.'), PHP_EOL);
    printf("  fill inclusao  : %s → %s%s", $bs['fill_inclusao'] ?? '?', $as['fill_inclusao'] ?? '?', PHP_EOL);
    printf("  fill previsao  : %s → %s%s", $bs['fill_previsao'] ?? '?', $as['fill_previsao'] ?? '?', PHP_EOL);
    printf("  fill alteracao : %s → %s%s", $bs['fill_alteracao'] ?? '?', $as['fill_alteracao'] ?? '?', PHP_EOL);
    echo PHP_EOL;
    printf("  datas preenchidas nesta ressync : %d%s", $dateFilled, PHP_EOL);
    printf("  pedidos ausentes (perda?)       : %d  %s%s", count($missing), $missing ? implode(',', $missing) : '', PHP_EOL);
    printf("  pedidos novos                   : %d  %s%s", count($novos), $novos ? implode(',', $novos) : '', PHP_EOL);
    printf("  invariantes alterados (DRIFT)   : %d%s", count($drifts), PHP_EOL);

    foreach ($drifts as $d) {
        echo "    ⚠ pedido {$d['pedido_id_omie']}:", PHP_EOL;
        foreach ($d['changed'] as $field => $ba) {
            echo "        {$field}: '{$ba['before']}' → '{$ba['after']}'", PHP_EOL;
        }
    }

    echo PHP_EOL;
    $clean = $missing === [] && $drifts === [];
    echo $clean
        ? "  ✅ Nenhuma perda e nenhum invariante alterado. Só as datas foram preenchidas."
        : "  ⚠ Há drift/perda — classificar cada caso: source_drift (mudança real no Omie, com dAlt) vs mapper_regression.";
    echo PHP_EOL;
}
