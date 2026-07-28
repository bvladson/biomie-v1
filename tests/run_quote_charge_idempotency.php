<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — Fase 5: idempotência da cobrança (INTEGRAÇÃO REAL).
 *
 * Usa DUAS CONEXÕES reais e transações explícitas — SELECT ... FOR UPDATE e a
 * contenção no índice UNIQUE só existem dentro de transação.
 *
 * O 409 in_progress NÃO é provado inserindo uma linha "processing" à mão: isso
 * não é corrida. Aqui a conexão A mantém uma reserva NÃO COMMITADA e a conexão B
 * bate no mesmo UNIQUE, espera de forma LIMITADA e converte o timeout em 409.
 *
 * CREATE TABLE já commitou (DDL implícito no MariaDB): a migration permanece
 * aplicada e apenas os DADOS de teste são eliminados no final.
 *
 *   php tests/run_quote_charge_idempotency.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Config;
use BIomie\Database;
use BIomie\QuoteAgenda\QuoteFollowupChargeService;

const CO   = 999902;          // empresa sintética
const OID  = 9999000777;      // pedido sintético

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$config = new Config();
// suíte MUTÁVEL (com DDL que faz COMMIT implícito): NUNCA produção. As duas
// conexões apontam para o test_db; a A passa pelo TestDatabaseGuard via test_pdo().
$conn = 'test_db';

// DUAS conexões reais e INDEPENDENTES.
// Database::connect() mantém um POOL por (driver,user,host,port,db) e devolveria
// a MESMA instância — o que anularia o teste de corrida. A conexão B é montada
// à mão, não-persistente, fora do pool.
$A = test_pdo();
$B = (static function () use ($config, $conn): PDO {
    $dsn = sprintf('%s:host=%s;port=%d;dbname=%s;charset=%s',
        (string) $config->get($conn . '.driver', 'mysql'),
        (string) $config->get($conn . '.host', 'localhost'),
        (int) $config->get($conn . '.port', 3306),
        (string) $config->get($conn . '.name'),
        (string) $config->get($conn . '.charset', 'utf8mb4'));

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_PERSISTENT         => false,
    ];
    if ((bool) $config->get($conn . '.ssl_enabled', false)) {
        $ca = trim((string) $config->get($conn . '.ssl_ca', ''));
        $caOpt = defined('Pdo\\Mysql::ATTR_SSL_CA') ? constant('Pdo\\Mysql::ATTR_SSL_CA') : PDO::MYSQL_ATTR_SSL_CA;
        $vOpt  = defined('Pdo\\Mysql::ATTR_SSL_VERIFY_SERVER_CERT')
            ? constant('Pdo\\Mysql::ATTR_SSL_VERIFY_SERVER_CERT') : PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT;
        $options[$caOpt] = $ca;
        $options[$vOpt] = (bool) $config->get($conn . '.ssl_verify_server_cert', true);
    }

    return new PDO($dsn, (string) $config->get($conn . '.user'), (string) $config->get($conn . '.pass', ''), $options);
})();
// B é montada à mão (fora do pool): reconfirma o portão antes de qualquer escrita.
\BIomie\Tests\TestDatabaseGuard::assertSafe($B);

$T_SCHED = 'biomie_sales_quote_followup_schedule';
$T_POL   = 'biomie_quote_followup_policy';
$T_AUD   = 'biomie_quote_followup_charge_audit';

$cleanup = static function (PDO $pdo) use ($T_SCHED, $T_POL, $T_AUD): void {
    foreach ([$T_AUD, $T_SCHED, $T_POL] as $t) {
        $pdo->prepare("DELETE FROM {$t} WHERE company_id = :c")->execute(['c' => CO]);
    }
};

$uuid = static fn (): string => sprintf('%s-%s-4%s-%s-%s',
    bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), substr(bin2hex(random_bytes(2)), 1),
    bin2hex(random_bytes(2)), bin2hex(random_bytes(6)));

try {
    $cleanup($A);

    // ── Cenário ──────────────────────────────────────────────────────────────────
    $A->prepare("INSERT INTO {$T_POL} (company_id, cadence_days_json, weekend_policy, enabled)
                 VALUES (:c, '[1,3,7,14]', 'roll_forward', 0)")->execute(['c' => CO]);
    $A->prepare("INSERT INTO {$T_SCHED}
        (company_id, omie_order_id, seller_id, quote_created_at, quote_created_source,
         next_followup_on, followup_step, charge_count)
        VALUES (:c, :o, '55', '2026-07-01 10:00:00', 'omie_dinc', '2026-07-02', 1, 0)")
        ->execute(['c' => CO, 'o' => OID]);

    $svcA = new QuoteFollowupChargeService($A, CO);
    $svcB = new QuoteFollowupChargeService($B, CO);
    $base = ['omie_order_id' => (string) OID, 'manager_user_id' => 7, 'expected_charge_count' => 0];

    // ── 1. Chave ausente → 400 ───────────────────────────────────────────────────
    $r = $svcA->charge('', $base);
    $check('1. chave ausente → 400', $r['status'] === 400, $r['body']['error'] ?? null);

    // ── 2. expected_charge_count ausente → 400 ───────────────────────────────────
    $r = $svcA->charge($uuid(), ['omie_order_id' => (string) OID, 'manager_user_id' => 7]);
    $check('2. expected_charge_count ausente → 400', $r['status'] === 400, $r['body']['error'] ?? null);

    // ── 3. Primeira cobrança → 200 ───────────────────────────────────────────────
    $k1 = $uuid();
    $r1 = $svcA->charge($k1, $base);
    $check('3. primeira cobrança → 200', $r1['status'] === 200, $r1['body']['error'] ?? $r1['status']);
    $check('3. charge_count = 1', ($r1['body']['charge_count'] ?? null) === 1, $r1['body']['charge_count'] ?? null);
    $check('3. passo avançou 1→2', ($r1['body']['followup_step'] ?? null) === 2, $r1['body']['followup_step'] ?? null);
    $check('3. marco absoluto created+3 = 2026-07-06 (04/07 é sábado)',
        ($r1['body']['next_followup_on'] ?? null) === '2026-07-06', $r1['body']['next_followup_on'] ?? null);
    $check('3. não é replay', $r1['replayed'] === false);

    // ── 4. Retry mesma chave + mesmo fingerprint → 200 replay, SEM nova cobrança ─
    $r2 = $svcA->charge($k1, $base);
    $check('4. retry → 200', $r2['status'] === 200, $r2['status']);
    $check('4. marcado como replay', $r2['replayed'] === true);
    $check('4. corpo IDÊNTICO ao original', $r2['body'] == $r1['body'], json_encode($r2['body']));
    $cc = (int) $A->query("SELECT charge_count FROM {$T_SCHED} WHERE company_id=" . CO)->fetchColumn();
    $check('4. charge_count continua 1 (não cobrou de novo)', $cc === 1, $cc);

    // ── 5. Mesma chave, requisição diferente → 422 ───────────────────────────────
    $r3 = $svcA->charge($k1, ['omie_order_id' => (string) OID, 'manager_user_id' => 999, 'expected_charge_count' => 1]);
    $check('5. mesma chave + fingerprint diferente → 422', $r3['status'] === 422, $r3['body']['error'] ?? $r3['status']);
    $check('5. erro = idempotency_key_reused', ($r3['body']['error'] ?? '') === 'idempotency_key_reused');

    // ── 6. CASO DECISIVO: duas chaves diferentes, mesmo expected_charge_count ────
    // (duplo clique gera DUAS chaves — só uma pode cobrar)
    $ccBefore = (int) $A->query("SELECT charge_count FROM {$T_SCHED} WHERE company_id=" . CO)->fetchColumn();
    $kA = $uuid(); $kB = $uuid();
    $stale = ['omie_order_id' => (string) OID, 'manager_user_id' => 7, 'expected_charge_count' => $ccBefore];

    $ra = $svcA->charge($kA, $stale);                 // conexão A
    $rb = $svcB->charge($kB, $stale);                 // conexão B, MESMO expected
    $ccAfter = (int) $A->query("SELECT charge_count FROM {$T_SCHED} WHERE company_id=" . CO)->fetchColumn();

    $check('6. apenas UMA das duas chaves retorna 200',
        (($ra['status'] === 200) xor ($rb['status'] === 200)), "A={$ra['status']} B={$rb['status']}");
    $outra = $ra['status'] === 200 ? $rb : $ra;
    $check('6. a outra recebe 409 stale_followup_state',
        $outra['status'] === 409 && ($outra['body']['error'] ?? '') === 'stale_followup_state',
        ($outra['body']['error'] ?? '') . '/' . $outra['status']);
    $check('6. charge_count aumentou EXATAMENTE 1', $ccAfter === $ccBefore + 1, "{$ccBefore}→{$ccAfter}");

    // ── 7. CORRIDA REAL: A segura reserva não commitada, B espera e vira 409 ─────
    $kRace = $uuid();
    $A->beginTransaction();
    $A->prepare("INSERT INTO {$T_AUD}
        (company_id, followup_id, omie_order_id, idempotency_key, request_fingerprint, created_at)
        VALUES (:c, NULL, :o, :k, REPEAT('a',64), NOW())")
        ->execute(['c' => CO, 'o' => OID, 'k' => $kRace]);
    // A NÃO commitou: a linha existe só na transação de A e trava o UNIQUE.

    $t0 = microtime(true);
    $rRace = $svcB->charge($kRace, ['omie_order_id' => (string) OID, 'manager_user_id' => 7,
                                     'expected_charge_count' => $ccAfter]);
    $waited = microtime(true) - $t0;

    $A->rollBack(); // rollback EXPLÍCITO da reserva concorrente

    $check('7. corrida real → 409 idempotency_in_progress',
        $rRace['status'] === 409 && ($rRace['body']['error'] ?? '') === 'idempotency_in_progress',
        ($rRace['body']['error'] ?? '') . '/' . $rRace['status']);
    $check('7. espera foi LIMITADA (timeout, não 50s default)', $waited < 20.0, round($waited, 2) . 's');
    $check('7. resposta traz retry_after', isset($rRace['body']['retry_after']), $rRace['body']['retry_after'] ?? null);
    $ccRace = (int) $A->query("SELECT charge_count FROM {$T_SCHED} WHERE company_id=" . CO)->fetchColumn();
    $check('7. corrida NÃO cobrou', $ccRace === $ccAfter, "{$ccAfter}→{$ccRace}");
    $orfa = (int) $A->query("SELECT COUNT(*) FROM {$T_AUD} WHERE company_id=" . CO . " AND idempotency_key=" . $A->quote($kRace))->fetchColumn();
    $check('7. rollback não deixou reserva órfã', $orfa === 0, "$orfa linhas");

    // ── 8. Followup de outra empresa → 404 (e não persiste chave) ────────────────
    $svcOutra = new QuoteFollowupChargeService($A, 999903);
    $kOther = $uuid();
    $r4 = $svcOutra->charge($kOther, $base);
    $check('8. followup de outra empresa → 404', $r4['status'] === 404, $r4['body']['error'] ?? $r4['status']);
    $persist = (int) $A->query("SELECT COUNT(*) FROM {$T_AUD} WHERE company_id=999903")->fetchColumn();
    $check('8. 404 NÃO persiste a chave', $persist === 0, "$persist linhas");

    // ── 9. Auditoria completa da 1ª cobrança ────────────────────────────────────
    $aud = $A->query("SELECT * FROM {$T_AUD} WHERE company_id=" . CO . " AND idempotency_key=" . $A->quote($k1))->fetch(PDO::FETCH_ASSOC);
    $check('9. auditoria: previous/new charge_count', (int) $aud['previous_charge_count'] === 0 && (int) $aud['new_charge_count'] === 1);
    $check('9. auditoria: previous/new step', (int) $aud['previous_followup_step'] === 1 && (int) $aud['new_followup_step'] === 2);
    $check('9. auditoria: completed_at preenchido', $aud['completed_at'] !== null);
    $check('9. auditoria: response_status 200', (int) $aud['response_status'] === 200);
    $check('9. auditoria: gestor registrado', (int) $aud['manager_user_id'] === 7);
} finally {
    if ($A->inTransaction()) { $A->rollBack(); }
    if ($B->inTransaction()) { $B->rollBack(); }
    $cleanup($A);
    $cleanup($A); // idempotente
}

$leak = (int) $A->query("SELECT COUNT(*) FROM {$T_AUD} WHERE company_id IN (" . CO . ",999903)")->fetchColumn();
$check('limpeza: nenhum dado de teste remanescente', $leak === 0, "$leak linhas");

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
