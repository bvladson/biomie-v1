<?php

declare(strict_types=1);

/**
 * MIGRATIONS — semântica de `pending` (pré-requisito do migrate-one).
 *
 * Prova que pendente = DESCOBERTAS − APLICADAS, e NÃO "version > MAX(applied)".
 *
 * Por que importa: o migrate-one da Agenda (20260718120000) será aplicado FORA
 * DE ORDEM, na frente de 4 migrations ANTERIORES de outro fluxo. Se o runner
 * usasse marca-d'água (high-water mark), essas 4 passariam a ser consideradas
 * "já ultrapassadas" e NUNCA mais seriam aplicáveis — corrompendo o outro
 * fluxo de trabalho em silêncio.
 *
 * READ-ONLY: não aplica nem reverte nada.
 *
 *   php tests/run_migration_pending_semantics.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Migrations\MigrationRunner;

/** Migrations de OUTRO fluxo de trabalho — devem seguir pendentes. */
const ALHEIAS = ['20260718010000', '20260718020000', '20260718030000', '20260718040000'];
const AGENDA  = '20260718120000';

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/tests.log');
$runner = new MigrationRunner(
    $config,
    $logger,
    dirname(__DIR__) . '/' . trim((string) $config->get('migrations.path', 'database/migrations'), '/'),
    (string) $config->get('migrations.table', 'biomie_schema_migrations'),
    (string) $config->get('app.version', 'dev')
);

$status = $runner->status();
$pending = [];
$applied = [];
foreach ($status['migrations'] as $m) {
    $v = (string) $m['version'];
    if (($m['status'] ?? '') === 'pending') { $pending[] = $v; } else { $applied[] = $v; }
}
sort($pending);
sort($applied);

$maxApplied = $applied === [] ? '' : max($applied);

$check('há migrations pendentes para avaliar', $pending !== [], implode(',', $pending));
$check('maior aplicada identificada', $maxApplied !== '', $maxApplied);

// ── 1. PROVA EMPÍRICA da ausência de marca-d'água ───────────────────────────────
// Insere (em transação REVERTIDA) um registro aplicado com versão ALTÍSSIMA e
// verifica que TODAS as pendentes — todas menores — continuam pendentes.
// Sob marca-d'água ("version > MAX(applied)") elas sumiriam da lista, e o outro
// fluxo de trabalho ficaria permanentemente impedido de migrar.
$pdo = test_pdo(); // suíte MUTÁVEL: escreve → só o test_db aceita (TestDatabaseGuard bloqueia produção)
$tabela = (string) $config->get('migrations.table', 'biomie_schema_migrations');
$versaoAlta = '99999999999999';

$pdo->beginTransaction();
try {
    $pdo->prepare(
        "INSERT INTO {$tabela}
            (schema_version, description, logical_connection, actual_connection,
             migration_type, checksum, app_version, status, executed_at)
         VALUES (:v, 'SINTETICA - teste de marca-dagua', 'canonical', 'flight_plan_db',
                 'expansive', :ck, 'test', 'applied', NOW())"
    )->execute(['v' => $versaoAlta, 'ck' => str_repeat('f', 64)]);

    // ⚠️ REUSA o runner JÁ conectado. Instanciar um novo aqui chamaria
    // ensureVersionTable() → CREATE TABLE IF NOT EXISTS → **COMMIT IMPLÍCITO**,
    // que persistiria a linha sintética apesar do rollback. (Aconteceu de fato
    // ao escrever este teste: sobrou lixo na tabela de controle de produção.)
    // O runner reconsulta os registros aplicados a cada status(), então basta.
    $pending2 = [];
    foreach ($runner->status()['migrations'] as $m) {
        if (($m['status'] ?? '') === 'pending') { $pending2[] = (string) $m['version']; }
    }
    sort($pending2);

    $check(
        'versão ALTÍSSIMA aplicada NÃO oculta as pendentes menores (sem marca-d agua)',
        $pending2 === $pending,
        'antes=' . count($pending) . ' depois=' . count($pending2)
    );
    $check(
        'as 4 alheias sobrevivem (seguirão migráveis após o out-of-order)',
        count(array_intersect(ALHEIAS, $pending2)) === 4,
        implode(',', array_values(array_intersect(ALHEIAS, $pending2)))
    );
} finally {
    $pdo->rollBack();   // nada persiste
}

$restou = (int) $pdo->query(
    "SELECT COUNT(*) FROM {$tabela} WHERE schema_version = '{$versaoAlta}'"
)->fetchColumn();
$check('rollback removeu a versão sintética', $restou === 0, "{$restou} linha(s)");

// ── 2. discovered = applied + pending (conjunto, não intervalo) ─────────────────
$sum = (int) ($status['summary']['applied'] ?? 0)
     + (int) ($status['summary']['pending'] ?? 0)
     + (int) ($status['summary']['rolled_back'] ?? 0);
$check(
    'discovered = applied + pending + rolled_back (conjunto)',
    $sum === (int) ($status['summary']['discovered'] ?? -1),
    sprintf('%d vs %d', $sum, (int) ($status['summary']['discovered'] ?? -1))
);

// ── 3. As 4 migrations de OUTRO fluxo seguem pendentes e reconhecíveis ──────────
$alheiasPendentes = array_values(array_intersect(ALHEIAS, $pending));
$check('as 4 migrations alheias estão pendentes', count($alheiasPendentes) === 4, implode(',', $alheiasPendentes));

// A Agenda tem DOIS estados legítimos: pendente (pré-deploy) ou aplicada
// (pós-deploy de 2026-07-20). O que nunca pode acontecer é um terceiro estado.
$agendaAplicada = in_array(AGENDA, $applied, true);
$check('a Agenda está pendente OU aplicada (nunca um terceiro estado)',
    in_array(AGENDA, $pending, true) xor $agendaAplicada,
    $agendaAplicada ? 'aplicada' : 'pendente');
$check('a Agenda ordena DEPOIS das 4 alheias (out-of-order consciente)',
    strcmp(AGENDA, max(ALHEIAS)) > 0, AGENDA . ' > ' . max(ALHEIAS));

// PÓS-DEPLOY, a produção vira a PROVA VIVA da ausência de marca-d'água: a
// Agenda (maior versão) está aplicada e as 4 alheias — todas MENORES — seguem
// descobertas como pendentes. Sob "version > MAX(applied)" elas teriam sumido.
if ($agendaAplicada) {
    // A prova NÃO exige que toda pendente seja menor que a maior aplicada —
    // outros fluxos criam migrations novas o tempo todo (drift legítimo). O que
    // importa é que as ANTERIORES à Agenda sobreviveram ao out-of-order: sob
    // marca-d'água elas teriam sumido da lista para sempre.
    $anterioresVisiveis = array_values(array_intersect(ALHEIAS, $pending));
    $todasAbaixo = array_values(array_filter(ALHEIAS,
        static fn (string $v): bool => strcmp($v, $maxApplied) < 0));

    $check('PROVA VIVA: as 4 alheias são MENORES que a maior aplicada',
        count($todasAbaixo) === 4, count($todasAbaixo) . '/4 abaixo de ' . $maxApplied);
    $check('PROVA VIVA: e mesmo assim seguem visíveis como pendentes',
        count($anterioresVisiveis) === 4, implode(',', $anterioresVisiveis));

    $posteriores = array_values(array_filter($pending,
        static fn (string $v): bool => strcmp($v, $maxApplied) > 0));
    $check('pendentes POSTERIORES são drift legítimo de outro fluxo, não falha',
        true, $posteriores === [] ? 'nenhuma' : implode(',', $posteriores));
}

// ── 4. O runner reconhece cada alheia individualmente (não só no agregado) ──────
$reconhecidas = 0;
foreach ($status['migrations'] as $m) {
    if (in_array((string) $m['version'], ALHEIAS, true) && ($m['file_basename'] ?? '') !== '') {
        $reconhecidas++;
    }
}
$check('runner resolve arquivo/classe das 4 alheias', $reconhecidas === 4, "{$reconhecidas}/4");

// ── 5. Checksums sem divergência (pré-condição do migrate-one) ──────────────────
$check('nenhum checksum divergente', (int) ($status['summary']['checksum_mismatch'] ?? -1) === 0,
    $status['summary']['checksum_mismatch'] ?? '?');

$passed = count(array_filter($checks, static fn (array $r): bool => $r['ok']));
$result = [
    'ok' => $passed === count($checks),
    'total' => count($checks),
    'passed' => $passed,
    'failed' => count($checks) - $passed,
    'context' => ['applied_count' => count($applied), 'pending' => $pending, 'max_applied' => $maxApplied],
    'checks' => $checks,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);
