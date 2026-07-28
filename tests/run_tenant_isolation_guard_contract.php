<?php

declare(strict_types=1);

/**
 * ISOLAMENTO MULTI-EMPRESA — contrato do TenantIsolationGuard.
 *
 * O guard nasceu do vazamento de 2026-06-27 (MAG vendo clientes da ERGO) e roda
 * no OpsSentinel a cada ciclo. Um guard de seguranca falha de DUAS maneiras, e
 * este contrato cobre as duas:
 *
 *   1. FALSO POSITIVO — acusa vazamento onde nao ha. Foi o que aconteceu ate
 *      2026-07-28: a fatia da Probe 3 somava `valor_faturado` filtrando
 *      `pedidos_faturados > 0` (grao de PEDIDO) enquanto o reader soma
 *      `valor_nfe` com `nfes_emitidas > 0 OR valor_nfe <> 0` (grao de NOTA).
 *      Como so 2 das 1.414 linhas do mart tinham pedidos_faturados > 0, a fatia
 *      dava ~R$0 e TODA empresa era acusada — prendendo o sentinel em
 *      severity=critical e afogando um vazamento real no ruido.
 *
 *   2. FALSO NEGATIVO (cegueira) — passa porque nao mede nada. Um guard verde
 *      por vacuidade e pior que nenhum guard, porque da confianca. Por isso o
 *      controle negativo abaixo simula um reader que perdeu o filtro de
 *      company_id e exige que o limiar dispare para CADA empresa ativa.
 *
 * Somente LEITURA — nao escreve nada, entao usa a conexao 'db' e nao o test_db.
 *
 *   php tests/run_tenant_isolation_guard_contract.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Database;
use BIomie\Logger;
use BIomie\Ops\TenantIsolationGuard;

$env = test_environment();
$pdo = Database::connect($env['config'], 'db');
$logger = new Logger(dirname(__DIR__) . '/storage/logs/tests.log');

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$start = date('Y-m-01', strtotime('-2 months'));
$end = date('Y-m-d');
const TOL = 1.0;

$competencias = [];
$cursor = new DateTimeImmutable(date('Y-m-01', strtotime($start)));
$limit = new DateTimeImmutable(date('Y-m-01', strtotime($end)));
while ($cursor <= $limit) {
    $competencias[] = $cursor->format('Y-m');
    $cursor = $cursor->modify('+1 month');
}
$ph = implode(',', array_map(static fn (int $i): string => ':m' . $i, array_keys($competencias)));
$compParams = [];
foreach ($competencias as $i => $m) {
    $compParams[':m' . $i] = $m;
}

$scalar = static function (string $sql, array $params) use ($pdo): float {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float) $stmt->fetchColumn();
};

$companies = $pdo->query('SELECT id, slug FROM biomie_companies WHERE ativo = 1 ORDER BY id')
    ->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ── 1. O guard nao acusa vazamento onde nao ha ──────────────────────────────
$result = (new TenantIsolationGuard($pdo, $logger))->run();
$check(
    '🔴 guard PASSA no estado atual (sem falso positivo)',
    $result['ok'],
    ['failures' => $result['failures']]
);
$check(
    'guard cobre todas as empresas ativas',
    $result['companies'] === count($companies),
    ['companies' => $result['companies'], 'esperado' => count($companies)]
);
// 5 probes por empresa: topClients, topProducts, summarizeInvoices, cnae, geo.
$check(
    'guard roda 5 probes por empresa (a 5a e o mart geo)',
    $result['checks'] === count($companies) * 5,
    ['checks' => $result['checks'], 'esperado' => count($companies) * 5]
);

// ── 2. Probe 3: a fatia espelha o reader (nao pode dar ~0 de novo) ──────────
$totalOwnBilled = 0.0;
foreach ($companies as $c) {
    $totalOwnBilled += $scalar(
        'SELECT COALESCE(SUM(valor_nfe),0) FROM mart_vendedor_dia
          WHERE company_id = :c AND dia BETWEEN :s AND :e
            AND (nfes_emitidas > 0 OR valor_nfe <> 0)',
        [':c' => (int) $c['id'], ':s' => $start, ':e' => $end]
    );
}
$check(
    '🔴 fatia da Probe 3 nao e vacua (regressao de 2026-07-28)',
    $totalOwnBilled > TOL,
    ['soma_das_fatias' => $totalOwnBilled]
);

// ── 3. Controle negativo: reader vazando TEM de disparar ───────────────────
$global3 = $scalar(
    'SELECT COALESCE(SUM(valor_nfe),0) FROM mart_vendedor_dia
      WHERE dia BETWEEN :s AND :e AND (nfes_emitidas > 0 OR valor_nfe <> 0)',
    [':s' => $start, ':e' => $end]
);
$global5 = $scalar(
    "SELECT COALESCE(SUM(faturamento),0) FROM mart_compradores_por_geo
      WHERE competencia IN ({$ph})",
    $compParams
);

$cegas = [];
foreach ($companies as $c) {
    $cid = (int) $c['id'];
    $slug = (string) $c['slug'];

    $own3 = $scalar(
        'SELECT COALESCE(SUM(valor_nfe),0) FROM mart_vendedor_dia
          WHERE company_id = :c AND dia BETWEEN :s AND :e
            AND (nfes_emitidas > 0 OR valor_nfe <> 0)',
        [':c' => $cid, ':s' => $start, ':e' => $end]
    );
    $own5 = $scalar(
        "SELECT COALESCE(SUM(faturamento),0) FROM mart_compradores_por_geo
          WHERE company_id = :c AND competencia IN ({$ph})",
        $compParams + [':c' => $cid]
    );

    // Exatamente a condicao usada dentro do guard.
    if (!($global3 > $own3 + max(TOL, $own3 * 0.005))) {
        $cegas[] = $slug . '/probe3';
    }
    if (!($global5 > $own5 + max(TOL, $own5 * 0.005))) {
        $cegas[] = $slug . '/probe5';
    }
}

$check(
    '🔴 reader vazando (valor global) dispara em TODAS as empresas',
    $cegas === [],
    ['combinacoes_cegas' => $cegas, 'global_probe3' => $global3, 'global_probe5' => $global5]
);

// ── 4. O mart geo esta de fato coberto ─────────────────────────────────────
$check(
    'mart_compradores_por_geo tem dados para a probe medir',
    $global5 > TOL,
    ['global_geo' => $global5]
);

$passed = count(array_filter($checks, static fn (array $r): bool => $r['ok']));
$out = [
    'ok' => $passed === count($checks),
    'total' => count($checks),
    'passed' => $passed,
    'failed' => count($checks) - $passed,
    'period' => ['start' => $start, 'end' => $end],
    'checks' => $checks,
];
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($out['ok'] ? 0 : 1);
