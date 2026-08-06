<?php

declare(strict_types=1);

/**
 * CONTRATO — a tela só chama de "prioridade" o que o backtest validou.
 *
 * ─── O QUE ISTO IMPEDE ──────────────────────────────────────────────────────
 * Até 2026-08-06 a faixa do mapa dizia "Prioridade territorial: X" para as
 * cinco empresas, com a mesma palavra e o mesmo destaque. Aí o backtest mediu:
 *
 *   MAG_FILIAL  ordena       lift mediano 5,37   spearman  0,50
 *   EXPERT      nao ordena   lift mediano 0,27   spearman −0,18
 *
 * Na EXPERT o score é ANTI-preditivo: priorizar pelo topo é pior que sortear.
 * Apresentar os dois casos igual transforma medição em decoração — e é pior que
 * não ter medido, porque dá autoridade a um número que já se sabe que não vale.
 *
 * ─── A REGRA ────────────────────────────────────────────────────────────────
 * "Ação recomendada" e a palavra "prioridade" exigem `veredito = 'ordena'`.
 * Nos demais casos a tela mostra o MESMO dado — esconder seria o outro extremo —
 * mas como EXPLORAÇÃO, com a evidência ao lado.
 *
 *   php tests/run_geo_validation_ui_contract.php
 */

require_once __DIR__ . '/TestSupport.php';

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$root = dirname(__DIR__);
$api = (string) @file_get_contents($root . '/api/geo_intelligence.php');
$js  = (string) @file_get_contents($root . '/assets/js/biomie-map.js');
$html = (string) @file_get_contents($root . '/views/mapa_vendas.html');
$css = (string) @file_get_contents($root . '/assets/css/module-pages.css');

$check('os arquivos da tela geo foram lidos', $api !== '' && $js !== '' && $html !== '' && $css !== '');

// ───────────────────────────────────────── 1. a API entrega o veredito
$check(
    '🔴 a API lê o veredito do backtest',
    str_contains($api, 'intelligence_geo_backtests'),
);
$check(
    'a API entrega o bloco de validação para a tela',
    str_contains($api, "'validation' => \$validation"),
);
$check(
    '🔴 "acionável" exige veredito, não só confiança',
    // O filtro de confiança precisa estar DENTRO da condicional do veredito.
    preg_match('/\$actionableRows\s*=\s*\$ordersWell\s*\?/', $api) === 1,
    'actionable_territories precisa depender de $ordersWell',
);
$check(
    'a evidência acompanha o veredito (taxa base junto da precisão)',
    str_contains($api, "'top_hit_rate'") && str_contains($api, "'base_hit_rate'"),
);
$check(
    'o mais bem pontuado continua disponível para exploração',
    str_contains($api, "'top_unvalidated'"),
    'esconder o dado seria o extremo oposto do exagero',
);

// ───────────────────────────── 2. a tela muda a PALAVRA conforme o veredito
$check(
    '🔴 a tela não afirma "Prioridade" incondicionalmente',
    !preg_match('/[\'"`]<strong>Prioridade territorial:/', $js),
    'a frase precisa vir do mapa de veredito, não fixa no template',
);
$check(
    'existe mapa de apresentação por veredito',
    str_contains($js, 'GEO_VALIDATION_UI'),
);
foreach (['ordena', 'nao_ordena', 'amostra_insuficiente', 'nao_avaliado'] as $verdict) {
    $check(
        sprintf('o veredito "%s" tem apresentação declarada', $verdict),
        preg_match('/\b' . preg_quote($verdict, '/') . '\s*:\s*\{/', $js) === 1,
    );
}
$check(
    '🔴 só o veredito "ordena" produz "Ação recomendada"',
    preg_match_all('/rotuloAcao:\s*\'Ação recomendada\'/u', $js) === 1,
    'mais de um veredito prometendo ação recomendada',
);
$check(
    'os demais vereditos falam em hipótese, não em ação',
    substr_count($js, "rotuloAcao: 'Hipótese a investigar'") >= 3,
);

// ──────────────────────────────── 3. o markup e o estilo existem de fato
$check(
    'o card do território tem o selo de validação',
    str_contains($html, 'data-geo-intel-validation'),
);
$check(
    'o rótulo da ação é substituível pelo veredito',
    str_contains($html, 'data-geo-intel-action-label'),
);
$check(
    '🔴 o selo não depende só de cor (texto + ícone)',
    str_contains($css, '.geo-intel-validation::before')
        && str_contains($css, '.geo-intel-validation--nao_ordena::before'),
    'daltonismo e impressão precisam do mesmo sinal',
);
$check(
    'não há regra dark-mode nova (o app é light-only)',
    !preg_match('/prefers-color-scheme[^}]*geo-intel-validation/s', $css),
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
