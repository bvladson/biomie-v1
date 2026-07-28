<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — Snapshot VIVO da etapa de orçamento (baseline autorizado).
 *
 * Substitui o backfill histórico por data. A base da Agenda é o ESTADO VIVO do Omie:
 * os pedidos atualmente na coluna "Orçamento - PD" (etapa 10), obtidos por UMA
 * listagem paginada em lote — sem filtro de data e sem ConsultarPedido individual
 * (recomendação Omie: preferir listagem em lote a consultas unitárias).
 *
 * Dry-run por padrão (só lê e valida). Com --apply, atualiza EXCLUSIVAMENTE as três
 * colunas de data no canônico dos pedidos retornados:
 *   data_inclusao_omie · data_alteracao_omie · data_previsao_faturamento
 * NÃO faz DELETE, NÃO altera invariantes (cliente/vendedor/valor/etapa/data_emissao).
 *
 * Ausência do snapshot vivo significa apenas "não está atualmente em orçamento" —
 * NUNCA source_deleted por inferência.
 *
 * Uso:
 *   php tools/dev/quote_agenda_live_snapshot.php --company=MAG_FILIAL
 *   php tools/dev/quote_agenda_live_snapshot.php --company=MAG_FILIAL --apply
 *   php tools/dev/quote_agenda_live_snapshot.php --company=MAG_FILIAL --expect=37
 */

use BIomie\Bootstrap;
use BIomie\Cache;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Omie\OmieClientFactory;
use BIomie\SharedDatabaseConnection;
use BIomie\Support\OmieDateParser;
use BIomie\Support\TenantCliContext;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();
set_time_limit(0);

$opts     = getopt('', ['company:', 'apply', 'expect:', 'etapa:', 'param:', 'out:']);
$apply    = isset($opts['apply']);
$expect   = isset($opts['expect']) ? (int) $opts['expect'] : null;
$etapa    = (string) ($opts['etapa'] ?? '10');
// Contrato oficial pvpListarRequest publica o campo "etapa". Não usar
// filtrar_por_etapa (fora do contrato) — requisição inválida/filtro ignorado.
$filterKey = (string) ($opts['param'] ?? 'etapa');
if (($opts['company'] ?? '') === '') {
    fwrite(STDERR, "Uso: --company=SLUG [--apply] [--expect=37] [--param=etapa]\n");
    exit(2);
}

$config = new Config();
$logger = new Logger(dirname(__DIR__, 2) . '/storage/logs/biomie.log');
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$shared = new SharedDatabaseConnection($config, $connection);
$pdo = $shared->current();
$reconnect = static fn () => $shared->recover();
$tenant = TenantCliContext::boot($pdo, ['live', '--company=' . (string) $opts['company']]);
$cid = (int) $tenant['company_id'];
$cache = new Cache(dirname(__DIR__, 2) . '/storage/cache');
$client = (new OmieClientFactory($pdo, $logger, $config, $cache, $reconnect))->forCompany($cid);
$endpoint = (string) $config->get('omie.endpoints.orders');

echo str_repeat('═', 76), PHP_EOL;
echo "  SNAPSHOT VIVO — etapa {$etapa} · {$tenant['slug']} (company {$cid})", PHP_EOL;
echo "  filtro: {$filterKey}={$etapa} · sem filtro de data · modo: " . ($apply ? 'APPLY' : 'dry-run'), PHP_EOL;
echo str_repeat('═', 76), PHP_EOL;

// ── Listagem em lote, paginada ───────────────────────────────────────────────────
$page = 1;
$orders = [];
$totalRegistros = null;
$totalPaginas = null;

do {
    $resp = $client->post($endpoint, 'ListarPedidos', [
        'pagina' => $page,
        'registros_por_pagina' => 100,
        'apenas_importado_api' => 'N',
        $filterKey => $etapa,
    ]);

    $totalRegistros ??= (int) ($resp['total_de_registros'] ?? 0);
    $totalPaginas   ??= (int) ($resp['total_de_paginas'] ?? 1);

    foreach ((array) ($resp['pedido_venda_produto'] ?? []) as $o) {
        if (is_array($o)) { $orders[] = $o; }
    }
    $page++;
} while ($page <= (int) $totalPaginas);

// ── Validação do snapshot ────────────────────────────────────────────────────────
$byId = [];
$etapasVistas = [];
foreach ($orders as $o) {
    $id = (string) (\BIomie\Support::getByPaths($o, ['cabecalho.codigo_pedido'], ''));
    $e  = (string) (\BIomie\Support::getByPaths($o, ['cabecalho.etapa'], ''));
    $etapasVistas[$e] = ($etapasVistas[$e] ?? 0) + 1;
    if ($id !== '') { $byId[$id] = $o; }
}

$uniq = count($byId);
$foraDaEtapa = array_filter($etapasVistas, static fn (string $k): bool => $k !== $etapa, ARRAY_FILTER_USE_KEY);

echo "  total_de_registros (API) : " . var_export($totalRegistros, true) . PHP_EOL;
echo "  total_de_paginas   (API) : " . var_export($totalPaginas, true) . PHP_EOL;
echo "  pedidos recebidos        : " . count($orders) . PHP_EOL;
echo "  IDs únicos               : {$uniq}" . PHP_EOL;
echo "  etapas no retorno        : " . json_encode($etapasVistas) . PHP_EOL;

$problemas = [];
if ($totalRegistros !== null && count($orders) !== $totalRegistros) {
    $problemas[] = "paginação incompleta: recebidos " . count($orders) . " ≠ total_de_registros {$totalRegistros}";
}
if ($foraDaEtapa !== []) {
    $problemas[] = 'retorno contém etapas fora de ' . $etapa . ': ' . json_encode($foraDaEtapa) . ' (filtro pode ter sido ignorado)';
}
if ($uniq !== count($orders)) {
    $problemas[] = "IDs duplicados: {$uniq} únicos de " . count($orders);
}
if ($expect !== null && $uniq !== $expect) {
    $problemas[] = "esperado {$expect}, obtido {$uniq} — verificar se entrou/saiu pedido desde a captura";
}

if ($problemas !== []) {
    echo PHP_EOL . "  ⚠ PROBLEMAS:" . PHP_EOL;
    foreach ($problemas as $p) { echo "    · {$p}" . PHP_EOL; }
}

// ── Mapeamento das datas (parser estrito) ────────────────────────────────────────
$rows = [];
$semDinc = 0;
$divergentes = 0;
foreach ($byId as $id => $o) {
    $dInc = OmieDateParser::parseDateTime(
        (string) \BIomie\Support::getByPaths($o, ['infoCadastro.dInc'], ''),
        (string) \BIomie\Support::getByPaths($o, ['infoCadastro.hInc'], '')
    );
    $dAlt = OmieDateParser::parseDateTime(
        (string) \BIomie\Support::getByPaths($o, ['infoCadastro.dAlt'], ''),
        (string) \BIomie\Support::getByPaths($o, ['infoCadastro.hAlt'], '')
    );
    $prev = OmieDateParser::parseDate(
        (string) \BIomie\Support::getByPaths($o, ['cabecalho.data_previsao'], '')
    );
    if ($dInc === null) { $semDinc++; }
    if ($dInc !== null && $prev !== null && substr($dInc, 0, 10) !== $prev) { $divergentes++; }
    $rows[$id] = ['dInc' => $dInc, 'dAlt' => $dAlt, 'prev' => $prev];
}

echo PHP_EOL;
echo "  com dInc válido          : " . ($uniq - $semDinc) . " de {$uniq}" . PHP_EOL;
echo "  sem dInc                 : {$semDinc}" . PHP_EOL;
echo "  dInc ≠ previsão          : {$divergentes}" . PHP_EOL;

// ── Presença no canônico ─────────────────────────────────────────────────────────
$ids = array_keys($rows);
$presentes = [];
if ($ids !== []) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT pedido_id_omie FROM biomie_pedidos_canonicos WHERE company_id = ? AND pedido_id_omie IN ($ph)");
    $st->execute([$cid, ...$ids]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) { $presentes[(string) $p] = true; }
}
$canonicalMissing = array_values(array_diff($ids, array_keys($presentes)));
$liveTotal = $uniq;
$canonicalExisting = count($presentes);

echo PHP_EOL . "  ── Reconciliação vivo × canônico ──" . PHP_EOL;
echo "  live_total         : {$liveTotal}" . PHP_EOL;
echo "  canonical_existing : {$canonicalExisting}" . PHP_EOL;
echo "  canonical_missing  : " . count($canonicalMissing)
    . ($canonicalMissing ? ' → ' . implode(',', array_slice($canonicalMissing, 0, 15)) : '') . PHP_EOL;

// Persiste o snapshot vivo (baseline autorizado da Agenda / futuro seed de followups).
if (isset($opts['out']) && (string) $opts['out'] !== '') {
    $dump = [];
    foreach ($byId as $id => $o) {
        $dump[$id] = [
            'pedido_id_omie' => $id,
            'numero_pedido'  => (string) \BIomie\Support::getByPaths($o, ['cabecalho.numero_pedido'], ''),
            'etapa'          => (string) \BIomie\Support::getByPaths($o, ['cabecalho.etapa'], ''),
            'cliente'        => (string) \BIomie\Support::getByPaths($o, ['cabecalho.codigo_cliente'], ''),
            'vendedor'       => (string) \BIomie\Support::getByPaths($o, ['informacoes_adicionais.codVend', 'cabecalho.codVend'], ''),
            'valor_total'    => (float) \BIomie\Support::getByPaths($o, ['total_pedido.valor_total_pedido'], 0),
            'data_inclusao_omie'        => $rows[$id]['dInc'],
            'data_alteracao_omie'       => $rows[$id]['dAlt'],
            'data_previsao_faturamento' => $rows[$id]['prev'],
            'in_canonical'   => isset($presentes[$id]),
        ];
    }
    uasort($dump, static fn (array $a, array $b): int => strcmp((string) $b['data_inclusao_omie'], (string) $a['data_inclusao_omie']));
    file_put_contents((string) $opts['out'], json_encode([
        'company_id' => $cid, 'company_slug' => $tenant['slug'], 'etapa' => $etapa,
        'taken_at' => date(DATE_ATOM),
        'live_total' => $liveTotal, 'canonical_existing' => $canonicalExisting,
        'canonical_missing' => $canonicalMissing,
        'orders' => $dump,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo "  snapshot salvo em: " . $opts['out'] . PHP_EOL;
}

if (!$apply) {
    echo PHP_EOL . "  (dry-run — nada escrito. Use --apply para gravar SOMENTE as 3 datas.)" . PHP_EOL;
    if ($canonicalMissing !== []) {
        echo "  ⚠ Há vivos fora do canônico → --apply será RECUSADO (nada de UPDATE parcial)." . PHP_EOL;
        echo "    Esses IDs precisam entrar pelo writer/upsert canônico normal antes." . PHP_EOL;
    }
    exit($problemas === [] ? 0 : 1);
}

if ($problemas !== []) {
    fwrite(STDERR, PHP_EOL . "APPLY abortado: resolva os problemas acima antes de gravar.\n");
    exit(1);
}

// Regra do checkpoint: se algum vivo não existir no canônico, NÃO fazer UPDATE
// parcial nem declarar a fase concluída. Os ausentes entram pelo writer normal.
if ($canonicalMissing !== []) {
    fwrite(STDERR, PHP_EOL . 'APPLY RECUSADO: ' . count($canonicalMissing)
        . " pedido(s) vivo(s) fora do canônico. UPDATE parcial não é permitido.\n"
        . "IDs: " . implode(',', $canonicalMissing) . "\n"
        . "Traga-os pelo writer/upsert canônico normal e rode novamente.\n");
    exit(1);
}

// ── APPLY: só as 3 colunas de data, só nos IDs vivos ─────────────────────────────
$upd = $pdo->prepare(
    "UPDATE biomie_pedidos_canonicos
        SET data_inclusao_omie = :dinc,
            data_alteracao_omie = :dalt,
            data_previsao_faturamento = :prev
      WHERE company_id = :cid AND pedido_id_omie = :pid"
);
$updated = 0; $naoEncontrados = 0;
$pdo->beginTransaction();
try {
    foreach ($rows as $id => $d) {
        $upd->execute([
            'dinc' => $d['dInc'], 'dalt' => $d['dAlt'], 'prev' => $d['prev'],
            'cid' => $cid, 'pid' => $id,
        ]);
        if ($upd->rowCount() > 0) { $updated++; } elseif (!isset($presentes[$id])) { $naoEncontrados++; }
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'ERRO no apply (rollback): ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo PHP_EOL . "  ✅ APPLY: {$updated} pedidos com as 3 datas atualizadas · {$naoEncontrados} vivos ainda fora do canônico" . PHP_EOL;
echo "  (nenhum DELETE · nenhum invariante alterado · nenhuma NF/financeiro tocado)" . PHP_EOL;
