<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — Fase 5: frescor + contrato de leitura.
 *
 * Cobre: sem snapshot → stale · falha recente NÃO invalida último sucesso ·
 * limite fechado 44:59 fresco / 45:00 stale · erro sanitizado · LEFT JOIN
 * preserva followup sem canônico · valor ausente ≠ zero · isolamento por empresa.
 *
 * Transação revertida no fim: nada persiste.
 *
 *   php tests/run_quote_agenda_freshness.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Config;
use BIomie\Database;
use BIomie\QuoteAgenda\QuoteAgendaReadService;

const OID_COM_CANON = 9999001111;
const OID_SEM_CANON = 9999002222;

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$config = new Config();
$pdo = test_pdo(); // suíte MUTÁVEL: escreve → só o test_db aceita (TestDatabaseGuard bloqueia produção)

/**
 * company_id sintético escolhido DINAMICAMENTE entre 1 e 254.
 *
 * biomie_pedidos_canonicos.company_id é TINYINT(3) UNSIGNED (máx 255) e o
 * sql_mode NÃO é estrito: um id acima do teto é ajustado para 255 com apenas
 * warning — a linha "sumiria" do filtro por empresa em silêncio. Escolher um id
 * livre no range evita colidir com empresas reais e com o valor de saturação.
 */
$freeCompanyId = static function (PDO $pdo): int {
    $usados = $pdo->query(
        'SELECT id FROM biomie_companies
         UNION SELECT DISTINCT company_id FROM biomie_pedidos_canonicos
         UNION SELECT DISTINCT company_id FROM biomie_sales_quote_followup_schedule'
    )->fetchAll(PDO::FETCH_COLUMN);
    $usados = array_map('intval', $usados);
    for ($id = 254; $id >= 2; $id--) {          // 255 = valor de saturação: nunca usar
        if (!in_array($id, $usados, true)) { return $id; }
    }
    throw new RuntimeException('Nenhum company_id livre entre 2 e 254.');
};
$CO      = $freeCompanyId($pdo);
$CO_OUTRA = $CO - 1;

$T_SCHED = 'biomie_sales_quote_followup_schedule';
$T_POL   = 'biomie_quote_followup_policy';
$T_SNAP  = 'biomie_quote_live_snapshot_sync';
$T_ORD   = 'biomie_pedidos_canonicos';

$svc = new QuoteAgendaReadService($pdo, $CO);
$setSnap = static function (array $f) use ($pdo, $T_SNAP, $CO): void {
    $pdo->prepare("DELETE FROM {$T_SNAP} WHERE company_id = " . $CO)->execute();
    if ($f === []) { return; }
    $pdo->prepare("INSERT INTO {$T_SNAP}
        (company_id, started_at, completed_at, last_successful_at, status, live_total, eligible_total, snapshot_hash, last_error)
        VALUES (:c, NOW(), NOW(), :ls, :st, :lt, :et, :h, :err)")
        ->execute(['c' => $CO, 'ls' => $f['last_successful_at'] ?? null, 'st' => $f['status'] ?? 'success',
                   'lt' => $f['live_total'] ?? null, 'et' => $f['eligible_total'] ?? null,
                   'h' => $f['snapshot_hash'] ?? null, 'err' => $f['last_error'] ?? null]);
};

$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO {$T_POL} (company_id, cadence_days_json, weekend_policy, stale_after_minutes, enabled)
                   VALUES (:c, '[1,3,7,14]', 'roll_forward', 45, 0)")->execute(['c' => $CO]);

    // ── 1. Sem linha de snapshot → is_stale=true ────────────────────────────────
    $setSnap([]);
    $f = $svc->freshness();
    $check('1. sem snapshot → is_stale=true', $f['is_stale'] === true, $f['is_stale']);
    $check('1. sem snapshot → never_synced=true', $f['never_synced'] === true);
    $check('1. sem snapshot → last_successful_at null', $f['last_successful_at'] === null);

    // ── 2. Limite fechado: 44:59 fresco · 45:00 stale ───────────────────────────
    $setSnap(['last_successful_at' => $pdo->query('SELECT NOW() - INTERVAL 2697 SECOND')->fetchColumn(),
              'status' => 'success', 'live_total' => 42, 'eligible_total' => 41,
              'snapshot_hash' => str_repeat('b', 64)]);
    $f = $svc->freshness();
    $check('2. abaixo do limite → FRESCO', $f['is_stale'] === false, $f['age_seconds'] . 's');
    $check('2. invariante: age<2700 ⇒ !stale', ($f['age_seconds'] < 2700) === ($f['is_stale'] === false), $f['age_seconds']);

    $setSnap(['last_successful_at' => $pdo->query('SELECT NOW() - INTERVAL 2700 SECOND')->fetchColumn(),
              'status' => 'success', 'live_total' => 42, 'eligible_total' => 41,
              'snapshot_hash' => str_repeat('b', 64)]);
    $f = $svc->freshness();
    $check('2. 45:00 (2700s) → STALE', $f['is_stale'] === true, $f['age_seconds'] . 's');
    $check('2. invariante: age>=2700 ⇒ stale', ($f['age_seconds'] >= 2700) === ($f['is_stale'] === true), $f['age_seconds']);
    $check('2. banner presente quando stale', ($f['message'] ?? null) !== null);

    // ── 3. Falha recente NÃO invalida o último sucesso ──────────────────────────
    $setSnap(['last_successful_at' => $pdo->query('SELECT NOW() - INTERVAL 300 SECOND')->fetchColumn(),
              'status' => 'failed', 'live_total' => 42, 'eligible_total' => 41,
              'snapshot_hash' => str_repeat('c', 64),
              'last_error' => 'SQLSTATE[HY000] senha=SEGREDO no host interno 10.0.0.5']);
    $f = $svc->freshness();
    $check('3. falha recente + sucesso recente → is_stale=false', $f['is_stale'] === false, $f['is_stale']);
    $check('3. source_status = failed (erro sinalizado)', $f['source_status'] === 'failed', $f['source_status']);
    $check('3. has_error = true', $f['has_error'] === true);
    $check('3. error_code sanitizado', $f['error_code'] === 'snapshot_sync_failed', $f['error_code']);
    $check('3. NÃO expõe last_error bruto',
        !str_contains(json_encode($f), 'SEGREDO') && !str_contains(json_encode($f), '10.0.0.5'), 'sem vazamento');

    // ── 4. Campos do contrato presentes ─────────────────────────────────────────
    foreach (['source_status', 'is_stale', 'last_successful_at', 'age_minutes',
              'stale_after_minutes', 'snapshot_hash', 'live_total', 'eligible_total'] as $k) {
        $check("4. contrato traz '{$k}'", array_key_exists($k, $f), $k);
    }
    $check('4. last_successful_at em ISO-8601 Z',
        (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $f['last_successful_at']),
        $f['last_successful_at']);
    $check('4. snapshot_hash com 64 chars', strlen((string) $f['snapshot_hash']) === 64);

    // ── 5. LEFT JOIN: followup SEM canônico não desaparece ──────────────────────
    $pdo->prepare("INSERT INTO {$T_ORD}
        (company_id, pedido_id_omie, codigo_pedido, codigo_vendedor_omie, nome_vendedor,
         nome_cliente, status_comercial, etapa_omie, data_emissao, valor_total, valor_mercadorias, valor_descontos, quantidade_itens)
        VALUES (:c, :o, 'T-1', '55', 'VENDEDOR TESTE', 'CLIENTE TESTE', 'outro', '10', '2026-07-10', 2500.00, 2500.00, 0, 1)")
        ->execute(['c' => $CO, 'o' => OID_COM_CANON]);

    foreach ([OID_COM_CANON, OID_SEM_CANON] as $oid) {
        $pdo->prepare("INSERT INTO {$T_SCHED}
            (company_id, omie_order_id, seller_id, quote_created_at, quote_created_source, next_followup_on, followup_step, charge_count)
            VALUES (:c, :o, '55', '2026-07-10 09:00:00', 'omie_dinc', '2026-07-13', 2, 0)")
            ->execute(['c' => $CO, 'o' => $oid]);
    }

    $items = $svc->openItems();
    $check('5. LEFT JOIN preserva os DOIS followups', count($items) === 2, count($items));

    $byId = [];
    foreach ($items as $i) { $byId[(string) $i['omie_order_id']] = $i; }
    $semCanon = $byId[(string) OID_SEM_CANON] ?? null;
    $comCanon = $byId[(string) OID_COM_CANON] ?? null;

    $check('5. followup sem canônico NÃO desaparece', $semCanon !== null);
    $check('6. valor ausente NÃO vira zero (null)', $semCanon !== null && $semCanon['value'] === null,
        $semCanon['value'] ?? 'ausente');
    $check('6. canonical_available=false', $semCanon !== null && $semCanon['canonical_available'] === false);
    $check('6. data_quality=canonical_missing', $semCanon !== null && $semCanon['data_quality'] === 'canonical_missing');
    $check('6. com canônico traz valor real', $comCanon !== null && (float) $comCanon['value'] === 2500.00,
        $comCanon['value'] ?? null);

    // ── 7. Agregados: soma só os conhecidos ─────────────────────────────────────
    $cal = $svc->calendar('2026-07-01', '2026-07-31');
    $dia = null;
    foreach ($cal as $d) { if ($d['date'] === '2026-07-13') { $dia = $d; } }
    $check('7. dia agrupa os 2', $dia !== null && $dia['quote_count'] === 2, $dia['quote_count'] ?? null);
    $check('7. total_value soma SÓ o conhecido (2500)', $dia !== null && (float) $dia['total_value'] === 2500.00, $dia['total_value'] ?? null);
    $check('7. known_value_count=1', $dia !== null && $dia['known_value_count'] === 1, $dia['known_value_count'] ?? null);
    $check('7. unknown_value_count=1', $dia !== null && $dia['unknown_value_count'] === 1, $dia['unknown_value_count'] ?? null);
    $check('7. day_urgency é a MAIOR do dia', $dia !== null && $dia['urgency_rank'] >= 1, $dia['day_urgency'] ?? null);

    // ── 8. Valor alterado no canônico REFLETE (sem desnormalização) ─────────────
    $pdo->prepare("UPDATE {$T_ORD} SET valor_total = 7777.77 WHERE company_id = :c AND pedido_id_omie = :o")
        ->execute(['c' => $CO, 'o' => OID_COM_CANON]);
    $items2 = $svc->openItems();
    $novo = null;
    foreach ($items2 as $i) { if ((string) $i['omie_order_id'] === (string) OID_COM_CANON) { $novo = $i; } }
    $check('8. valor alterado no canônico reflete na API', $novo !== null && (float) $novo['value'] === 7777.77,
        $novo['value'] ?? null);

    // ── 9. Isolamento por empresa ───────────────────────────────────────────────
    $outra = new QuoteAgendaReadService($pdo, $CO_OUTRA);
    $check('9. outra empresa não enxerga os followups', count($outra->openItems()) === 0);

    // ── 10. readWithFreshness devolve envelope completo ─────────────────────────
    $env = $svc->readWithFreshness(static fn (QuoteAgendaReadService $s): array => $s->summary());
    $check('10. envelope traz data', isset($env['data']));
    $check('10. envelope traz freshness', isset($env['freshness']['is_stale']));
    $check('10. envelope traz generated_at ISO-Z',
        (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $env['generated_at']), $env['generated_at']);
    $check('10. endpoint dedicado = MESMA semântica',
        $svc->freshness()['is_stale'] === $env['freshness']['is_stale']);
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}

$leak = (int) $pdo->query("SELECT COUNT(*) FROM {$T_SCHED} WHERE company_id = " . $CO)->fetchColumn();
$check('limpeza: rollback removeu o cenário', $leak === 0, "$leak linhas");

$passed = count(array_filter($checks, static fn (array $r): bool => $r['ok']));
$result = [
    'ok' => $passed === count($checks),
    'total' => count($checks),
    'passed' => $passed,
    'failed' => count($checks) - $passed,
    'checks' => $checks,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);
