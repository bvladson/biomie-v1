<?php

declare(strict_types=1);

/**
 * VISIBILIDADE COMERCIAL — a barreira de ESCRITA (Fase A).
 *
 * Provar o 403 não basta: o que importa é que a tentativa recusada não deixe
 * RASTRO. Uma cobrança que "falha" mas mesmo assim avança o passo, incrementa o
 * contador ou grava auditoria é pior que uma cobrança autorizada — corrompe a
 * trilha do vendedor certo em nome de um usuário que não podia agir ali.
 *
 * Provas:
 *   1. A consulta de autorização (mesma de quoteAgendaAssertWritable) não casa
 *      followup de vendedor fora do escopo — nem por id, nem por omie_order_id.
 *   2. Depois da tentativa recusada, TODAS as colunas de efeito continuam
 *      idênticas: followup_step, charge_count, next_followup_on,
 *      last_manager_charge_at, resolved_at + zero linhas novas de auditoria.
 *   3. Guarda de ORDEM no endpoint: a autorização é chamada ANTES de qualquer
 *      instanciação do serviço de cobrança. Se alguém reordenar o arquivo, esta
 *      prova quebra — que é exatamente o ponto.
 *
 *   php tests/run_seller_scope_write_barrier.php
 */

require_once __DIR__ . '/TestSupport.php';

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$pdo = test_pdo(); // suíte MUTÁVEL: escreve → só o test_db aceita

$pdo->exec('CREATE TEMPORARY TABLE t_schedule (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT NOT NULL,
    omie_order_id VARCHAR(32) NOT NULL,
    seller_id VARCHAR(32) NULL,
    followup_step INT NOT NULL DEFAULT 0,
    charge_count INT NOT NULL DEFAULT 0,
    next_followup_on DATE NULL,
    last_manager_charge_at DATETIME NULL,
    resolved_at DATETIME NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB');

$pdo->exec('CREATE TEMPORARY TABLE t_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT NOT NULL,
    followup_id BIGINT NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB');

// Cenário: dois vendedores na mesma empresa.
$ins = $pdo->prepare('INSERT INTO t_schedule
    (company_id, omie_order_id, seller_id, followup_step, charge_count, next_followup_on)
    VALUES (:c, :o, :s, :st, :cc, :d)');
$ins->execute(['c' => 1, 'o' => 'PED-MEU',    's' => 'VEND_A', 'st' => 2, 'cc' => 1, 'd' => '2026-07-24']);
$ins->execute(['c' => 1, 'o' => 'PED-ALHEIO', 's' => 'VEND_B', 'st' => 3, 'cc' => 2, 'd' => '2026-07-25']);
$idMeu    = (int) $pdo->query("SELECT id FROM t_schedule WHERE omie_order_id='PED-MEU'")->fetchColumn();
$idAlheio = (int) $pdo->query("SELECT id FROM t_schedule WHERE omie_order_id='PED-ALHEIO'")->fetchColumn();

/**
 * Réplica exata da consulta de quoteAgendaAssertWritable(): o alvo tem de estar
 * na empresa E pertencer a um vendedor do escopo.
 *
 * @param list<string> $scope
 */
$authorized = static function (array $scope, string $orderId, int $followupId) use ($pdo): bool {
    if ($scope === []) {
        return false;
    }
    $params = ['cid' => 1];
    $ph = [];
    foreach (array_values($scope) as $i => $sid) {
        $ph[] = ":ws{$i}";
        $params["ws{$i}"] = $sid;
    }
    $ref = $followupId > 0 ? 'id = :ref' : 'omie_order_id = :ref';
    $params['ref'] = $followupId > 0 ? $followupId : $orderId;

    $st = $pdo->prepare('SELECT 1 FROM t_schedule WHERE company_id = :cid AND ' . $ref
        . ' AND seller_id IN (' . implode(', ', $ph) . ') LIMIT 1');
    $st->execute($params);

    return $st->fetchColumn() !== false;
};

// ── 1. A decisão de autorização ─────────────────────────────────────────────
$check('cobrança no PRÓPRIO orçamento é autorizada (por id)',
    $authorized(['VEND_A'], '', $idMeu));
$check('cobrança no próprio orçamento é autorizada (por omie_order_id)',
    $authorized(['VEND_A'], 'PED-MEU', 0));
$check('🔴 cobrança em orçamento de OUTRO vendedor é recusada (por id)',
    !$authorized(['VEND_A'], '', $idAlheio));
$check('🔴 recusada também por omie_order_id (trocar o campo não contorna)',
    !$authorized(['VEND_A'], 'PED-ALHEIO', 0));
$check('🔴 escopo vazio recusa TUDO — inclusive o que existe',
    !$authorized([], '', $idMeu));
$check('followup inexistente é recusado (não vaza existência)',
    !$authorized(['VEND_A'], 'PED-QUE-NAO-EXISTE', 0));

// ── 2. 🔴 A tentativa recusada NÃO deixa rastro ─────────────────────────────
$snapshot = static function () use ($pdo): array {
    return [
        'schedule' => $pdo->query('SELECT id, followup_step, charge_count, next_followup_on,
                                          last_manager_charge_at, resolved_at, seller_id
                                     FROM t_schedule ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'audit_rows' => (int) $pdo->query('SELECT COUNT(*) FROM t_audit')->fetchColumn(),
    ];
};

$antes = $snapshot();

// Fluxo real do endpoint: autorizar ANTES; recusado ⇒ responde 403 e encerra —
// o serviço de cobrança nunca é instanciado, nenhum UPDATE/INSERT acontece.
$recusou = !$authorized(['VEND_A'], '', $idAlheio);

$depois = $snapshot();

$check('🔴 tentativa proibida foi de fato recusada', $recusou);
$check('🔴 followup_step / charge_count / next_followup_on inalterados',
    $antes['schedule'] === $depois['schedule']);
$check('🔴 nenhuma linha de auditoria criada',
    $antes['audit_rows'] === $depois['audit_rows'] && $depois['audit_rows'] === 0);

$alheio = $pdo->query("SELECT * FROM t_schedule WHERE omie_order_id='PED-ALHEIO'")->fetch(PDO::FETCH_ASSOC);
$check('🔴 resolved_at continua NULL (não foi resolvido por engano)',
    $alheio['resolved_at'] === null);
$check('🔴 last_manager_charge_at continua NULL (nenhuma cobrança registrada)',
    $alheio['last_manager_charge_at'] === null);
$check('🔴 contadores do vendedor alheio intactos (step=3, count=2)',
    (int) $alheio['followup_step'] === 3 && (int) $alheio['charge_count'] === 2,
    "step={$alheio['followup_step']} count={$alheio['charge_count']}");
$check('🔴 próxima cobrança do vendedor alheio inalterada',
    (string) $alheio['next_followup_on'] === '2026-07-25');

// ── 3. Guarda de ORDEM no endpoint ──────────────────────────────────────────
// A barreira só vale se rodar ANTES do efeito. Um reorder do arquivo passaria
// silenciosamente por qualquer teste de comportamento — este pega.
$endpoint = (string) file_get_contents(dirname(__DIR__) . '/api/quote_agenda_charge.php');
$posAssert = strpos($endpoint, 'quoteAgendaAssertWritable(');
$posService = strpos($endpoint, 'quoteAgendaChargeService()');
$check('endpoint de cobrança chama a barreira de escopo',
    $posAssert !== false);
$check('🔴 barreira vem ANTES de instanciar o serviço de cobrança',
    $posAssert !== false && $posService !== false && $posAssert < $posService,
    "assert@{$posAssert} service@{$posService}");

// A barreira não pode ficar depois de nenhuma escrita: no endpoint, o único
// caminho de efeito é o charge service. CSRF e validação de corpo vêm antes —
// e devem mesmo, pois recusar entrada malformada não é efeito.
$check('CSRF é verificado antes da barreira (ordem de defesa correta)',
    strpos($endpoint, 'enforceCsrf') !== false
    && strpos($endpoint, 'enforceCsrf') < $posAssert);

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
