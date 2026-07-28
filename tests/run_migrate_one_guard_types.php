<?php

declare(strict_types=1);

/**
 * MIGRATE-ONE — tipagem das versões no guard de delta.
 *
 * REGRESSÃO REAL (deploy de 2026-07-20): o guard comparava
 *
 *     array_values(array_diff(array_keys($depois), array_keys($antes))) !== [$version]
 *
 * e falhava SEMPRE, inclusive no caminho de sucesso. Motivo: PHP converte
 * chaves de array que são strings numéricas em INT, então `array_keys()`
 * devolvia int(20260718120000) — nunca idêntico a "20260718120000".
 *
 * O efeito prático foi o pior possível: a migration APLICOU corretamente e o
 * CLI relatou falha, levando o deploy a parar em RECOVERY_REQUIRED com uma
 * mensagem autocontraditória ("novos = [X] (esperado apenas X)").
 *
 * READ-ONLY: não toca no banco.
 *
 *   php tests/run_migrate_one_guard_types.php
 */

require_once __DIR__ . '/TestSupport.php';

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

const VERSAO = '20260718120000';

// ── 1. A causa-raiz, isolada ─────────────────────────────────────────────────
$mapa = [VERSAO => true];
$chaves = array_keys($mapa);
$check(
    'array_keys() converte chave numérica-string em int (causa-raiz)',
    is_int($chaves[0]),
    get_debug_type($chaves[0])
);
$check(
    'comparação estrita SEM strval falha mesmo quando os valores são iguais',
    array_values($chaves) !== [VERSAO]
);

// ── 2. A correção ────────────────────────────────────────────────────────────
$corrigido = array_map('strval', array_values($chaves));
$check('com strval a comparação estrita passa', $corrigido === [VERSAO], json_encode($corrigido));
$check('e o tipo volta a ser string', is_string($corrigido[0]), get_debug_type($corrigido[0]));

/** Réplica exata do cálculo do guard, já corrigido. */
$delta = static function (array $antes, array $depois): array {
    return array_map('strval', array_values(array_diff(array_keys($depois), array_keys($antes))));
};

// ── 3. Caminho de sucesso: exatamente uma versão nova ────────────────────────
$antes  = ['20260718000000' => true, '20260716000000' => true];
$depois = $antes + [VERSAO => true];
$check('sucesso: delta == [alvo]', $delta($antes, $depois) === [VERSAO], json_encode($delta($antes, $depois)));

// ── 4. O guard ainda PRECISA reprovar os casos que existe para pegar ─────────
// Corrigir a tipagem não pode transformar o guard num carimbo.
$arrastou = $antes + [VERSAO => true, '20260718010000' => true];
$r = $delta($antes, $arrastou);
sort($r);
$check('migration alheia arrastada continua sendo detectada',
    $r !== [VERSAO] && count($r) === 2, json_encode($r));

$check('nenhuma versão nova continua sendo detectado', $delta($antes, $antes) === [], json_encode($delta($antes, $antes)));

$outra = $antes + ['20260718010000' => true];
$check('versão ERRADA aplicada continua sendo detectada',
    $delta($antes, $outra) !== [VERSAO], json_encode($delta($antes, $outra)));

// ── 5. O arquivo em produção carrega a correção ──────────────────────────────
$src = (string) file_get_contents(__DIR__ . '/../bin/schema_migrate.php');
$check(
    "bin/schema_migrate.php aplica strval no delta",
    str_contains($src, "array_map('strval', array_values(array_diff(array_keys(\$depois), array_keys(\$antes))))")
);

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
