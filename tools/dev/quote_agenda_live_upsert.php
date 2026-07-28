<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — Upsert do snapshot VIVO (etapa 10) pelo writer canônico.
 *
 * Corrige o blind spot do sync por janela de previsão: a etapa comercial e a
 * data_previsao são dimensões INDEPENDENTES. A Agenda usa etapa=10 como critério;
 * data_previsao é só prazo/urgência. Filtrar por previsão oculta orçamentos vivos.
 *
 * Fluxo:
 *   1. ListarPedidos(etapa=10) — listagem em lote, paginação COMPLETA antes de tudo.
 *   2. Normaliza pelo FiscalCanonicalMapper (mesmo clientMap/vendorMap do sync normal).
 *   3. DIFF read-only do que JÁ existe no canônico (gate obrigatório).
 *   4. Só com --apply: UMA transação → CanonicalWriteRepository::upsertOrder, SEM DELETE.
 *
 * Não toca nos pedidos fora do snapshot vivo (ficam fora da Agenda, sem serem
 * apagados e sem receber source_deleted). Não escreve NF nem financeiro.
 *
 * Uso:
 *   php tools/dev/quote_agenda_live_upsert.php --company=MAG_FILIAL
 *   php tools/dev/quote_agenda_live_upsert.php --company=MAG_FILIAL --apply
 */

use BIomie\Bootstrap;
use BIomie\BronzeRepository;
use BIomie\Cache;
use BIomie\CanonicalRepository;
use BIomie\Config;
use BIomie\CronLock;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Omie\OmieClientFactory;
use BIomie\OmieRepository;
use BIomie\SharedDatabaseConnection;
use BIomie\Support;
use BIomie\Support\TenantCliContext;
use BIomie\Sync\ChunkedWindowRunner;
use BIomie\Sync\FiscalSyncCoordinator;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();
set_time_limit(0);

$opts  = getopt('', ['company:', 'apply', 'etapa:', 'out:']);
$apply = isset($opts['apply']);
$etapa = (string) ($opts['etapa'] ?? '10');
if (($opts['company'] ?? '') === '') {
    fwrite(STDERR, "Uso: --company=SLUG [--apply] [--etapa=10] [--out=run.json]\n");
    exit(2);
}

/** Campos comparados no diff (invariantes + as 3 datas novas). */
const DIFF_FIELDS = [
    'codigo_pedido', 'codigo_vendedor_omie', 'nome_vendedor',
    'codigo_cliente_omie', 'nome_cliente', 'documento_cliente',
    'etapa_omie', 'status_comercial', 'operation_scope',
    'valor_total', 'valor_mercadorias', 'valor_descontos', 'quantidade_itens',
    'data_emissao', 'data_faturamento', 'data_cancelamento',
    'data_inclusao_omie', 'data_alteracao_omie', 'data_previsao_faturamento',
];

$config = new Config();
$logger = new Logger(dirname(__DIR__, 2) . '/storage/logs/biomie.log');
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$shared = new SharedDatabaseConnection($config, $connection);
$pdo = $shared->current();
$reconnect = static fn () => $shared->recover();
$tenant = TenantCliContext::boot($pdo, ['upsert', '--company=' . (string) $opts['company']]);
$cid = (int) $tenant['company_id'];

$canonical = new CanonicalRepository($pdo, $logger, $reconnect, $cid, $tenant['canal']);
$canonical->ensureFiscalSchema();
$bronze = new BronzeRepository($pdo, $logger, $cid, $reconnect);
$bronze->ensureSchema();
$cache = new Cache(dirname(__DIR__, 2) . '/storage/cache');

// Espera na fila do mesmo lock do pipeline (cadência delta = 5 min). Nunca roda
// concorrente com o cron: ou pega o lock, ou desiste sem tocar em nada.
$lock = CronLock::acquire($pdo, 'biomie:realtime_pipeline:company:' . $cid, $logger, 240);
if ($lock === null) {
    fwrite(STDERR, "Lock ocupado após 240s de espera (cron em execução). Abortando sem tocar em nada.\n");
    exit(0);
}

echo str_repeat('═', 78), PHP_EOL;
echo "  UPSERT DO SNAPSHOT VIVO — etapa {$etapa} · {$tenant['slug']} (company {$cid})", PHP_EOL;
echo '  modo: ' . ($apply ? 'APPLY (transacional, sem DELETE)' : 'DIFF read-only (nada escrito)'), PHP_EOL;
echo str_repeat('═', 78), PHP_EOL;

try {
    $client = (new OmieClientFactory($pdo, $logger, $config, $cache, $reconnect))->forCompany($cid);
    $omie = new OmieRepository($client, $config, $bronze, 'quote-agenda-live');
    $coordinator = new FiscalSyncCoordinator($omie, $canonical, $logger, new ChunkedWindowRunner($logger));
    $endpoint = (string) $config->get('omie.endpoints.orders');

    // ── 1. Snapshot vivo: paginação COMPLETA antes de qualquer escrita ───────────
    $page = 1; $raw = []; $totalRegistros = null; $totalPaginas = null;
    do {
        $resp = $client->post($endpoint, 'ListarPedidos', [
            'pagina' => $page,
            'registros_por_pagina' => 100,
            'apenas_importado_api' => 'N',
            'etapa' => $etapa,
        ]);
        $totalRegistros ??= (int) ($resp['total_de_registros'] ?? 0);
        $totalPaginas   ??= (int) ($resp['total_de_paginas'] ?? 1);
        foreach ((array) ($resp['pedido_venda_produto'] ?? []) as $o) {
            if (is_array($o)) { $raw[] = $o; }
        }
        $page++;
    } while ($page <= (int) $totalPaginas);

    $etapasVistas = [];
    foreach ($raw as $o) {
        $e = (string) Support::getByPaths($o, ['cabecalho.etapa'], '');
        $etapasVistas[$e] = ($etapasVistas[$e] ?? 0) + 1;
    }
    echo "  total_de_registros : {$totalRegistros} · páginas: {$totalPaginas} · recebidos: " . count($raw) . PHP_EOL;
    echo '  etapas no retorno  : ' . json_encode($etapasVistas) . PHP_EOL;

    $fatal = [];
    if (count($raw) !== (int) $totalRegistros) { $fatal[] = 'paginação incompleta'; }
    // PHP converte chave numérica em int — comparar como string.
    $etapasUnicas = array_map('strval', array_keys($etapasVistas));
    if ($etapasUnicas !== [$etapa]) { $fatal[] = 'retorno contém etapa fora de ' . $etapa . ': ' . implode(',', $etapasUnicas); }
    if ($fatal !== []) {
        fwrite(STDERR, 'ABORTADO: ' . implode(' · ', $fatal) . PHP_EOL);
        exit(1);
    }

    // ── 2. Normalização com o MESMO clientMap/vendorMap do sync normal ───────────
    $coordinator->syncVendors();
    $refClientMap = new ReflectionMethod($coordinator, 'buildClientMap');
    $clientMap = $refClientMap->invoke($coordinator, $raw);
    $refMapper = new ReflectionMethod($coordinator, 'canonicalMapper');
    $mapper = $refMapper->invoke($coordinator);
    $refVendorMap = new ReflectionProperty($coordinator, 'vendorMap');
    $vendorMap = (array) $refVendorMap->getValue($coordinator);

    $normalized = [];
    $naoNormalizou = [];
    foreach ($raw as $o) {
        $row = $mapper->normalizePendingOrderToCanonical($o, $clientMap, 'quote_agenda_live', $vendorMap);
        if ($row === null) {
            $naoNormalizou[] = (string) Support::getByPaths($o, ['cabecalho.codigo_pedido'], '?');
            continue;
        }
        $normalized[(string) $row['pedido_id_omie']] = $row;
    }
    echo '  normalizados       : ' . count($normalized) . ' de ' . count($raw)
        . ($naoNormalizou ? ' · NÃO normalizados: ' . implode(',', $naoNormalizou) : '') . PHP_EOL;
    if ($naoNormalizou !== []) {
        fwrite(STDERR, "ABORTADO: pedidos vivos que o mapper recusou.\n");
        exit(1);
    }

    // ── 3. DIFF read-only contra o canônico atual ────────────────────────────────
    $ids = array_keys($normalized);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT * FROM biomie_pedidos_canonicos WHERE company_id = ? AND pedido_id_omie IN ($ph)");
    $st->execute([$cid, ...$ids]);
    $atuais = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $atuais[(string) $r['pedido_id_omie']] = $r; }

    $novos = array_values(array_diff($ids, array_keys($atuais)));
    $drifts = [];
    foreach ($atuais as $id => $cur) {
        $new = $normalized[$id];
        $changed = [];
        foreach (DIFF_FIELDS as $f) {
            $a = $cur[$f] ?? null;
            $b = $new[$f] ?? null;
            if (in_array($f, ['valor_total', 'valor_mercadorias', 'valor_descontos'], true)) {
                $a = number_format((float) $a, 2, '.', ''); $b = number_format((float) $b, 2, '.', '');
            }
            if ((string) $a !== (string) $b) {
                $changed[$f] = ['canonico' => $a, 'payload_vivo' => $b];
            }
        }
        if ($changed !== []) { $drifts[$id] = $changed; }
    }

    echo PHP_EOL . '  ── GATE DE DIFF ──' . PHP_EOL;
    echo '  live_total         : ' . count($normalized) . PHP_EOL;
    echo '  canonical_existing : ' . count($atuais) . PHP_EOL;
    echo '  canonical_missing  : ' . count($novos) . ($novos ? ' → ' . implode(',', $novos) : '') . PHP_EOL;
    echo '  com diferença      : ' . count($drifts) . ' de ' . count($atuais) . PHP_EOL . PHP_EOL;

    // data_emissao deve seguir a REGRA LEGADA (previsão primeiro). Se mudou, tem de
    // ser porque a previsão mudou no Omie — qualquer outra causa é parada.
    $emissaoSuspeita = [];
    foreach ($drifts as $id => $ch) {
        if (!isset($ch['data_emissao'])) { continue; }
        $prevPayload = (string) ($normalized[$id]['data_previsao_faturamento'] ?? '');
        if ((string) $ch['data_emissao']['payload_vivo'] !== $prevPayload) {
            $emissaoSuspeita[$id] = $ch['data_emissao'];
        }
    }

    foreach ($drifts as $id => $ch) {
        echo "    · pedido {$id}:" . PHP_EOL;
        foreach ($ch as $f => $v) {
            $marca = ($f === 'data_inclusao_omie' || $f === 'data_alteracao_omie' || $f === 'data_previsao_faturamento')
                ? '  [data nova — esperado]' : '';
            printf("        %-26s %-22s → %s%s%s", $f, var_export($v['canonico'], true), var_export($v['payload_vivo'], true), $marca, PHP_EOL);
        }
    }

    if ($emissaoSuspeita !== []) {
        fwrite(STDERR, PHP_EOL . "PARADA: data_emissao mudaria fora da regra legada (≠ data_previsao do payload) em: "
            . implode(',', array_keys($emissaoSuspeita)) . PHP_EOL);
        exit(1);
    }

    if (isset($opts['out']) && (string) $opts['out'] !== '') {
        file_put_contents((string) $opts['out'], json_encode([
            'company_id' => $cid, 'slug' => $tenant['slug'], 'taken_at' => date(DATE_ATOM),
            'live_total' => count($normalized), 'canonical_existing' => count($atuais),
            'canonical_missing' => $novos, 'drifts' => $drifts, 'ids' => $ids,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        echo PHP_EOL . '  run salvo em: ' . $opts['out'] . PHP_EOL;
    }

    if (!$apply) {
        echo PHP_EOL . '  (DIFF read-only — nada escrito. Revise acima e rode --apply para gravar.)' . PHP_EOL;
        exit(0);
    }

    // ── 4. APPLY: uma transação, upsert do writer normal, SEM DELETE ─────────────
    // 'skipped' = HardDataExclusionPolicy (ex.: vendedor Licitação, Fase L). É
    // exclusão deliberada de negócio — NÃO é perda e não entra na Agenda.
    $stats = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
    $skippedIds = [];
    $pdo->beginTransaction();
    try {
        foreach ($normalized as $id => $row) {
            $res = $canonical->upsertOrder($row);
            if (isset($stats[$res])) { $stats[$res]++; }
            if ($res === 'skipped') { $skippedIds[] = (string) $id; }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }

    echo PHP_EOL . '  ✅ APPLY: inserted=' . $stats['inserted'] . ' updated=' . $stats['updated']
        . ' unchanged=' . $stats['unchanged'] . ' skipped=' . $stats['skipped'] . ' (sem DELETE)' . PHP_EOL;
    if ($skippedIds !== []) {
        echo '     skipped (exclusão dura, ex. Licitação): ' . implode(',', $skippedIds) . PHP_EOL;
    }

    // Elegíveis ao canônico = vivos menos os de exclusão dura.
    $elegiveis = array_values(array_diff($ids, $skippedIds));

    // ── Pós-validação ────────────────────────────────────────────────────────────
    $ph2 = implode(',', array_fill(0, count($elegiveis), '?'));
    $st2 = $pdo->prepare(
        "SELECT COUNT(*) total,
                SUM(data_inclusao_omie IS NOT NULL) di,
                SUM(data_alteracao_omie IS NOT NULL) da,
                SUM(data_previsao_faturamento IS NOT NULL) dp,
                SUM(etapa_omie = ?) e10
           FROM biomie_pedidos_canonicos
          WHERE company_id = ? AND pedido_id_omie IN ($ph2)"
    );
    $st2->execute([$etapa, $cid, ...$elegiveis]);
    $v = $st2->fetch(PDO::FETCH_ASSOC) ?: [];
    // Esperado = vivos ELEGÍVEIS (exclui os de exclusão dura, que nunca entram).
    $esperado = count($elegiveis);
    echo PHP_EOL . '  ── PÓS-VALIDAÇÃO ──' . PHP_EOL;
    printf("  vivos no canônico        : %s de %d%s", $v['total'] ?? 0, $esperado, PHP_EOL);
    printf("  data_inclusao_omie       : %s de %d%s", $v['di'] ?? 0, $esperado, PHP_EOL);
    printf("  data_alteracao_omie      : %s de %d%s", $v['da'] ?? 0, $esperado, PHP_EOL);
    printf("  data_previsao_faturamento: %s de %d%s", $v['dp'] ?? 0, $esperado, PHP_EOL);
    printf("  etapa %s                 : %s de %d%s", $etapa, $v['e10'] ?? 0, $esperado, PHP_EOL);
    $ok = ((int) ($v['total'] ?? 0) === $esperado) && ((int) ($v['e10'] ?? 0) === $esperado);
    echo '  ' . ($ok ? '✅ pós-validação OK' : '⚠ pós-validação INCONSISTENTE') . PHP_EOL;
    exit($ok ? 0 : 1);
} catch (Throwable $e) {
    $logger->error('quote_agenda_live_upsert: falha.', ['message' => $e->getMessage(), 'company_id' => $cid]);
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    $lock?->release();
}
