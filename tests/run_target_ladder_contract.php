<?php

declare(strict_types=1);

require __DIR__ . '/TestSupport.php';

use BIomie\Commercial\TargetLadder;
use BIomie\CommercialPlanning;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$before = TargetLadder::evaluate([40000, 60000, 80000], 25000);
$assert($before['active_level'] === 1, 'Antes da base, Meta 1 deve estar ativa.');
$assert($before['active_target'] === 40000.0, 'Meta ativa incorreta antes da base.');

$promoted = TargetLadder::evaluate([40000, 60000, 80000], 47000);
$assert($promoted['achieved_level'] === 1, 'Meta 1 deveria estar conquistada.');
$assert($promoted['active_level'] === 2, 'Deveria promover para Meta 2.');
$assert($promoted['active_gap'] === 13000.0, 'Gap da Meta 2 incorreto.');
$assert($promoted['step_progress_pct'] === 35.0, 'Progresso entre Meta 1 e Meta 2 incorreto.');
$assert($promoted['base_attainment_pct'] === 117.5, 'Atingimento-base deve preservar a conquista.');

// Integracao: a tela comercial precisa consumir o degrau promovido, nao apenas
// calcular a escada corretamente em isolamento.
$planning = CommercialPlanning::build(
    [[
        'codigo_vendedor_omie' => 'V1', 'nome_vendedor' => 'Vendedor Teste',
        'status_comercial' => 'faturado', 'total' => 47000, 'pedidos' => 1,
    ]],
    ['V1|2026-08' => [
        'vendor_id' => 'V1', 'vendor_name' => 'Vendedor Teste',
        'year_month' => '2026-08', 'target_amount' => 40000,
        'tiers' => [60000, 80000], 'currency' => 'BRL', 'updated_at' => null,
    ]],
    '2026-08-01', '2026-08-31'
);
$seller = $planning['vendors'][0];
$assert($seller['active_target'] === 60000.0, 'Planejamento comercial nao promoveu para Meta 2.');
$assert($seller['attainment_pct'] === 78.33, 'Atingimento operacional ainda usa a primeira meta.');
$assert($seller['base_attainment_pct'] === 117.5, 'Planejamento perdeu a conquista da meta-base.');

$complete = TargetLadder::evaluate([40000, 60000, 80000], 86000);
$assert($complete['all_levels_achieved'] === true, 'Todos os níveis deveriam estar conquistados.');
$assert($complete['active_target'] === null, 'Não deve inventar meta depois do máximo.');
$assert($complete['over_maximum_amount'] === 6000.0, 'Superação máxima incorreta.');

$contract = json_decode((string) file_get_contents(dirname(__DIR__) . '/config/target-ladder-contract.json'), true);
$casos = $contract['cases'] ?? [];
$assert($casos !== [], 'contrato compartilhado vazio — o arquivo existe mas nao valida nada.');

$campos = 0;
foreach ($casos as $case) {
    $actual = TargetLadder::evaluate($case['levels'], (float) $case['realized']);
    foreach ($case as $field => $expected) {
        if (in_array($field, ['name', 'levels', 'realized'], true)) continue;
        $value = $actual[$field] ?? null;
        // `is_numeric(null)` e false, entao null so casa com null — que e o que
        // queremos: "sem meta" nao pode passar por "meta zero".
        $matches = is_numeric($value) && is_numeric($expected)
            ? abs((float) $value - (float) $expected) < 0.001
            : $value === $expected;
        $assert($matches, sprintf('%s: campo %s divergiu (%s != %s)', $case['name'], $field, json_encode($value), json_encode($expected)));
        $campos++;
    }
}

// Os casos de borda sao os que ja divergiram entre PHP e Python. Se alguem
// reduzir o contrato aos "casos felizes", o guard perde justamente a parte que
// pegou a divergencia — entao a presenca deles e verificada.
$nomes = array_column($casos, 'name');
foreach (['sem_meta_nenhuma', 'valores_invalidos', 'valores_duplicados', 'fora_de_ordem'] as $obrigatorio) {
    $assert(
        in_array($obrigatorio, $nomes, true),
        "o contrato perdeu o caso de borda '{$obrigatorio}' — foi nele que os dois motores divergiram."
    );
}

printf("target ladder contract: OK — %d casos, %d campos conferidos\n", count($casos), $campos);
