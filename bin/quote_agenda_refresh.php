<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — refresh direcionado + convergência Omie × ReadModel (Fase 2).
 *
 * SEGURO POR PADRÃO: sem --apply nada muta a Agenda (decide e loga com applied=0).
 *
 * Modos:
 *   --converge            relatório de convergência (SOMENTE LEITURA, 1 chamada
 *                         paginada por empresa — a mesma do scanner)
 *   --order=ID            refresh direcionado de um pedido (ConsultarPedido)
 *   --from-log            reprocessa pedidos que ficaram em `retry` no log
 *
 * Uma empresa por execução, SEM paralelismo. O throttle global do projeto
 * (3 req/min) e o debounce por pedido (60s) continuam valendo.
 *
 *   php bin/quote_agenda_refresh.php --company=UNI_DESIGN --converge
 *   php bin/quote_agenda_refresh.php --company=UNI_DESIGN --order=123456 --apply
 */

require_once __DIR__ . '/../app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require __DIR__ . '/../app/Bootstrap.php';
\BIomie\Bootstrap::init();
set_time_limit(0);

use BIomie\BronzeRepository;
use BIomie\Cache;
use BIomie\CanonicalRepository;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Omie\OmieClientFactory;
use BIomie\OmieRepository;
use BIomie\QuoteAgenda\QuoteLiveSnapshotService;
use BIomie\QuoteAgenda\QuoteOrderRefresher;
use BIomie\Support\TenantCliContext;

$opts    = getopt('', ['company:', 'order:', 'converge', 'from-log', 'apply', 'json', 'limit:']);
$apply   = isset($opts['apply']);
$slug    = strtoupper(trim((string) ($opts['company'] ?? '')));
$jsonOut = isset($opts['json']);
$limit   = max(1, min(50, (int) ($opts['limit'] ?? 10)));

if ($slug === '') {
    fwrite(STDERR, "Uso: --company=SLUG [--converge | --order=ID | --from-log] [--apply]\n");
    exit(2);
}

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/quote_agenda_refresh.log');
$conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo    = Database::connect($config, $conn);

$tenant = TenantCliContext::boot($pdo, ['refresh', '--company=' . $slug]);
$cid    = (int) $tenant['company_id'];

$reconnect = static fn () => $pdo;
$canonical = new CanonicalRepository($pdo, $logger, $reconnect, $cid, $tenant['canal']);
$canonical->ensureFiscalSchema();
$bronze = new BronzeRepository($pdo, $logger, $cid, $reconnect);
$bronze->ensureSchema();
$cache  = new Cache(dirname(__DIR__) . '/storage/cache');
$client = (new OmieClientFactory($pdo, $logger, $config, $cache, $reconnect))->forCompany($cid);
$ordersEndpoint = (string) $config->get('omie.endpoints.orders', 'https://app.omie.com.br/api/v1/produtos/pedido/');

/**
 * Consulta UM pedido. Somente leitura. Devolve o contrato que o refresher
 * espera — normalizando erro e status para a decisão saber classificar.
 *
 * @return array{ok:bool,etapa?:mixed,error?:string,http?:?int}
 */
$consultar = static function (string $orderId) use ($client, $ordersEndpoint): array {
    try {
        $resp = $client->post($ordersEndpoint, 'ConsultarPedido', ['codigo_pedido' => (int) $orderId]);
        $etapa = $resp['pedido_venda_produto']['cabecalho']['etapa']
              ?? $resp['cabecalho']['etapa']
              ?? null;

        return ['ok' => true, 'etapa' => $etapa];
    } catch (\Throwable $e) {
        $http = null;
        if (preg_match('/\b(4\d{2}|5\d{2})\b/', $e->getMessage(), $m)) { $http = (int) $m[1]; }

        return ['ok' => false, 'error' => $e->getMessage(), 'http' => $http];
    }
};

$refresher = new QuoteOrderRefresher($pdo, $cid, $consultar);
$out = ['company' => $tenant['slug'], 'company_id' => $cid, 'applied' => $apply];

// ── MODO CONVERGÊNCIA: Omie × ReadModel, somente leitura ────────────────────
if (isset($opts['converge'])) {
    $svc = new QuoteLiveSnapshotService(
        $pdo, $client,
        new OmieRepository($client, $config, $bronze, 'quote-agenda-converge'),
        $config, $canonical, $cid
    );
    $snap = $svc->capture('10');

    // Snapshot INCOMPLETO ⇒ o relatório não conclui divergência: sem paginação
    // completa, "faltando no Omie" seria inferência por ausência.
    if (!$snap->isComplete()) {
        $out['converge'] = ['ok' => false, 'reason' => 'snapshot incompleto — sem conclusão', 'problems' => $snap->problems];
        echo $jsonOut ? json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL
                      : "  ⚠️  Snapshot incompleto: " . $snap->problemsAsText() . PHP_EOL;
        exit(1);
    }

    $omieIds = array_map('strval', $snap->eligibleIds());
    $st = $pdo->prepare('SELECT omie_order_id FROM biomie_sales_quote_followup_schedule
                          WHERE company_id = :c AND resolved_at IS NULL');
    $st->execute(['c' => $cid]);
    $modelIds = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));

    $soNoOmie   = array_values(array_diff($omieIds, $modelIds));   // faltam entrar
    $soNoModelo = array_values(array_diff($modelIds, $omieIds));   // saíram da etapa 10
    $emAmbos    = array_values(array_intersect($omieIds, $modelIds));

    $out['converge'] = [
        'ok' => true,
        'omie_etapa10' => count($omieIds),
        'readmodel_aberto' => count($modelIds),
        'em_ambos' => count($emAmbos),
        'so_no_omie' => $soNoOmie,       // a Agenda ainda não conhece
        'so_no_readmodel' => $soNoModelo, // candidatos a saíram/excluídos — EXIGEM confirmação
        'convergente' => $soNoOmie === [] && $soNoModelo === [],
    ];

    if (!$jsonOut) {
        printf("%s  CONVERGÊNCIA · %s%s", PHP_EOL, $tenant['slug'], PHP_EOL);
        printf("    Omie (etapa 10) ....... %d%s", count($omieIds), PHP_EOL);
        printf("    ReadModel (em aberto) . %d%s", count($modelIds), PHP_EOL);
        printf("    em ambos .............. %d%s", count($emAmbos), PHP_EOL);
        printf("    só no Omie ............ %d %s%s", count($soNoOmie), $soNoOmie ? implode(',', array_slice($soNoOmie, 0, 8)) : '', PHP_EOL);
        printf("    só no ReadModel ....... %d %s%s", count($soNoModelo), $soNoModelo ? implode(',', array_slice($soNoModelo, 0, 8)) : '', PHP_EOL);
        printf("    %s%s", $out['converge']['convergente'] ? '✅ CONVERGENTE' : '⚠️  divergências acima (nenhuma ação tomada — relatório é leitura)', PHP_EOL);
        echo '    Os "só no ReadModel" NÃO são desativados aqui: exigem confirmação' . PHP_EOL;
        echo '    individual (--order=ID), pois ausência não é prova de exclusão.' . PHP_EOL;
    } else {
        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    }
    exit(0);
}

// ── MODO PEDIDO ÚNICO ───────────────────────────────────────────────────────
if (($opts['order'] ?? '') !== '') {
    $out['result'] = $refresher->refresh((string) $opts['order'], 'cli', $apply);
    echo $jsonOut ? json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL
                  : sprintf("  %s → %s (%s)%s%s", $out['result']['order_id'] ?? '?',
                        $out['result']['action'] ?? ($out['result']['skipped'] ?? false ? 'skipped' : '?'),
                        $out['result']['reason'] ?? '-', $apply ? '' : ' [dry-run]', PHP_EOL);
    exit(0);
}

// ── MODO REPROCESSAR RETRIES ────────────────────────────────────────────────
if (isset($opts['from-log'])) {
    $st = $pdo->prepare(
        "SELECT omie_order_id, MAX(checked_at) last_at
           FROM biomie_quote_order_refresh_log
          WHERE company_id = :c AND decision = 'retry'
       GROUP BY omie_order_id
         HAVING TIMESTAMPDIFF(SECOND, last_at, NOW()) >= :deb
       ORDER BY last_at ASC LIMIT {$limit}"
    );
    $st->execute(['c' => $cid, 'deb' => QuoteOrderRefresher::DEBOUNCE_SECONDS]);
    $ids = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));

    $out['reprocessed'] = [];
    foreach ($ids as $id) {   // SEQUENCIAL — o throttle global cuida do ritmo
        $out['reprocessed'][] = $refresher->refresh($id, 'cli', $apply);
    }
    echo $jsonOut ? json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL
                  : sprintf("  %d pedido(s) reprocessado(s)%s%s", count($ids), $apply ? '' : ' [dry-run]', PHP_EOL);
    exit(0);
}

fwrite(STDERR, "Escolha um modo: --converge | --order=ID | --from-log\n");
exit(2);
