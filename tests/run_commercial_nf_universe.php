<?php

declare(strict_types=1);

/**
 * UNIVERSO COMERCIAL — teste SEMÂNTICO independente (camada 2 do D11).
 *
 * A paridade canônico×mart (camada 1) é NECESSÁRIA mas não suficiente: se a
 * regra de universo estiver errada, os dois lados erram juntos e "batem". Este
 * teste é a defesa INDEPENDENTE — fixa, por regra de negócio, a qual contexto
 * cada operação pertence, e prova que CommercialNfUniverse classifica cada uma
 * corretamente. Se alguém mudar a regra de forma errada, a paridade continua
 * verde mas ISTO quebra.
 *
 * Também cruza as DUAS expressões da mesma regra — a SQL (usada pelo check) e o
 * classify() em PHP — provando que concordam. A duplicação é proposital: uma
 * confere a outra.
 *
 *   php tests/run_commercial_nf_universe.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Support\CommercialNfUniverse as U;

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

// ── Fixtures: cada operação com o contexto ESPERADO (verdade de negócio) ─────
$fixtures = [
    // nome                                    row                                                                          esperado
    ['NF comercial de varejo (visível)',       ['status_fiscal' => 'autorizada', 'operation_scope' => 'commercial', 'business_channel' => 'varejo',    'is_biomie_commercial_visible' => 1],    'varejo'],
    ['NF de licitação (visível=0, preservada)', ['status_fiscal' => 'autorizada', 'operation_scope' => 'commercial', 'business_channel' => 'licitacao', 'is_biomie_commercial_visible' => 0],    'licitacao'],
    ['NF de licitação com visible=1',           ['status_fiscal' => 'autorizada', 'operation_scope' => 'commercial', 'business_channel' => 'licitacao', 'is_biomie_commercial_visible' => 1],    'licitacao'],
    ['remessa (varejo invisível)',              ['status_fiscal' => 'autorizada', 'operation_scope' => 'commercial', 'business_channel' => 'varejo',    'is_biomie_commercial_visible' => 0],    'excluida'],
    ['NF cancelada',                            ['status_fiscal' => 'cancelada',  'operation_scope' => 'commercial', 'business_channel' => 'varejo',    'is_biomie_commercial_visible' => 1],    'excluida'],
    ['NF denegada',                             ['status_fiscal' => 'denegada',   'operation_scope' => 'commercial', 'business_channel' => 'varejo',    'is_biomie_commercial_visible' => 1],    'excluida'],
    ['operação não-comercial (transferência)',  ['status_fiscal' => 'autorizada', 'operation_scope' => 'transfer',   'business_channel' => 'varejo',    'is_biomie_commercial_visible' => 1],    'excluida'],
    ['varejo com visible NULL (⇒ visível)',     ['status_fiscal' => 'autorizada', 'operation_scope' => 'commercial', 'business_channel' => 'varejo',    'is_biomie_commercial_visible' => null], 'varejo'],
];

// ── 1. classify() bate com a verdade de negócio ─────────────────────────────
foreach ($fixtures as [$name, $row, $expected]) {
    $got = U::classify($row);
    $check("classify · {$name} ⇒ {$expected}", $got === $expected, "got={$got}");
}

// ── 2. A SQL e o classify() concordam (cruzam a mesma regra) ────────────────
$pdo = test_pdo(); // suíte MUTÁVEL

$pdo->exec('CREATE TEMPORARY TABLE fixture_nf (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    status_fiscal VARCHAR(20) NOT NULL,
    operation_scope VARCHAR(20) NOT NULL,
    business_channel VARCHAR(20) NULL,
    is_biomie_commercial_visible TINYINT NULL,
    valor_total DECIMAL(15,2) NOT NULL DEFAULT 0,
    valor_frete DECIMAL(15,2) NOT NULL DEFAULT 0,
    valor_outras_despesas DECIMAL(15,2) NOT NULL DEFAULT 0,
    valor_seguro DECIMAL(15,2) NOT NULL DEFAULT 0,
    valor_base_comercial_nf DECIMAL(15,2) NULL,
    data_emissao DATE NOT NULL
) ENGINE=InnoDB');

$ins = $pdo->prepare('INSERT INTO fixture_nf
    (company_id, status_fiscal, operation_scope, business_channel, is_biomie_commercial_visible, valor_total, valor_base_comercial_nf, data_emissao)
    VALUES (:cid, :sf, :os, :bc, :vis, :vt, :base, :d)');

$expectIncludedIds = [];
$id = 0;
foreach ($fixtures as [$name, $row, $expected]) {
    $id++;
    $ins->execute([
        ':cid' => 1, ':sf' => $row['status_fiscal'], ':os' => $row['operation_scope'],
        ':bc' => $row['business_channel'], ':vis' => $row['is_biomie_commercial_visible'],
        ':vt' => 100.00, ':base' => 100.00, ':d' => '2026-07-23',
    ]);
    if ($expected !== 'excluida') {
        $expectIncludedIds[] = (int) $pdo->lastInsertId();
    }
}

// Também: uma NF de OUTRA empresa (isolamento é do chamador via company_id).
$ins->execute([':cid' => 2, ':sf' => 'autorizada', ':os' => 'commercial', ':bc' => 'varejo',
    ':vis' => 1, ':vt' => 999.00, ':base' => 999.00, ':d' => '2026-07-23']);

// SQL do universo (sem alias) — o MESMO predicado que o check usa.
$sqlIds = $pdo->query('SELECT id FROM fixture_nf WHERE ' . U::sql('') . ' AND company_id = 1 ORDER BY id')
    ->fetchAll(PDO::FETCH_COLUMN);
$sqlIds = array_map('intval', $sqlIds);
sort($expectIncludedIds);

$check('🔴 SQL do universo inclui exatamente o que classify() diz (empresa 1)',
    $sqlIds === $expectIncludedIds,
    'sql=' . json_encode($sqlIds) . ' esperado=' . json_encode($expectIncludedIds));

// ── 3. Isolamento por empresa é do chamador (company_id) ────────────────────
$countAll = (int) $pdo->query('SELECT COUNT(*) FROM fixture_nf WHERE ' . U::sql(''))->fetchColumn();
$count1   = (int) $pdo->query('SELECT COUNT(*) FROM fixture_nf WHERE ' . U::sql('') . ' AND company_id = 1')->fetchColumn();
$check('🔴 company_id filtra: empresa 2 não entra na leitura da empresa 1',
    $count1 < $countAll && $count1 === count($expectIncludedIds),
    "todas={$countAll} empresa1={$count1}");

// ── 4. Subtotais por canal separam varejo de licitação ──────────────────────
$byCh = [];
foreach ($pdo->query('SELECT business_channel ch, COUNT(*) n FROM fixture_nf
                       WHERE ' . U::sql('') . ' AND company_id = 1 GROUP BY business_channel') as $r) {
    $byCh[(string) $r['ch']] = (int) $r['n'];
}
$check('canal varejo isolado (2: visível + NULL)', ($byCh['varejo'] ?? 0) === 2, json_encode($byCh));
$check('canal licitação isolado (2 preservadas)', ($byCh['licitacao'] ?? 0) === 2, json_encode($byCh));

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
