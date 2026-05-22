<?php

declare(strict_types=1);

/**
 * Fase H — T11: Testes unitários de classificação OmieOperationScope.
 *
 * Verifica classifyName(), classifyInvoice() e classifyOrder() contra fixtures
 * conhecidos. Não requer banco de dados.
 *
 * Uso:
 *   php tools/dev/operation_scope_unit_check.php
 *   php tools/dev/operation_scope_unit_check.php --verbose
 *   php tools/dev/operation_scope_unit_check.php --json
 *
 * Exit code:
 *   0 — todos os testes passaram
 *   1 — ao menos um teste falhou
 */

use BIomie\Bootstrap;
use BIomie\Support\OmieOperationScope;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$opts    = getopt('', ['verbose', 'json']);
$verbose = isset($opts['verbose']);
$isJson  = isset($opts['json']);

// ── Fixtures ───────────────────────────────────────────────────────────────────

/**
 * classifyName fixtures: [label, input_name, expected_scope, min_confidence]
 * min_confidence: 'high' | 'medium' | 'any'
 *
 * @var list<array{string,string,string,string}>
 */
$nameFixtures = [
    // Commercial — exact canonical
    ['Orçamento - PD (exact accent)',     'Orçamento - PD',          OmieOperationScope::COMMERCIAL, 'high'],
    ['Orçamento - PD (lowercase)',        'orçamento - pd',          OmieOperationScope::COMMERCIAL, 'high'],
    ['Orcamento - PD (no accent)',        'Orcamento - PD',          OmieOperationScope::COMMERCIAL, 'high'],
    ['orcamento - pd (lower no accent)',  'orcamento - pd',          OmieOperationScope::COMMERCIAL, 'high'],
    ['Orcamento PD (no dash)',            'Orcamento PD',            OmieOperationScope::COMMERCIAL, 'high'],
    ['Orcamento-PD (hyphen no space)',    'Orcamento-PD',            OmieOperationScope::COMMERCIAL, 'high'],
    ['ORCAMENTO - PD (uppercase)',        'ORCAMENTO - PD',          OmieOperationScope::COMMERCIAL, 'high'],
    ['Venda de Mercadorias',              'Venda de Mercadorias',    OmieOperationScope::COMMERCIAL, 'high'],
    ['Venda de Produto',                  'Venda de Produto',        OmieOperationScope::COMMERCIAL, 'high'],
    ['Pedido de Venda',                   'Pedido de Venda',         OmieOperationScope::COMMERCIAL, 'high'],

    // Commercial — substring match
    ['Contains "orcamento - pd"',         'Tipo: Orcamento - PD v2', OmieOperationScope::COMMERCIAL, 'medium'],

    // Remittance — exact
    ['Remessa de Produto (exact)',        'Remessa de Produto',      OmieOperationScope::REMITTANCE, 'high'],
    ['REMESSA DE PRODUTO (uppercase)',    'REMESSA DE PRODUTO',      OmieOperationScope::REMITTANCE, 'high'],
    ['remessa de produto (lowercase)',    'remessa de produto',      OmieOperationScope::REMITTANCE, 'high'],
    ['Remessa de Produtos (plural)',      'Remessa de Produtos',     OmieOperationScope::REMITTANCE, 'high'],
    ['Simples Remessa',                   'Simples Remessa',         OmieOperationScope::REMITTANCE, 'high'],
    ['Remessa Produto (no de)',           'Remessa Produto',         OmieOperationScope::REMITTANCE, 'high'],
    ['Transferência',                     'Transferência',           OmieOperationScope::REMITTANCE, 'high'],
    ['Transferencia (no accent)',         'Transferencia',           OmieOperationScope::REMITTANCE, 'high'],
    ['Devolução',                         'Devolução',               OmieOperationScope::REMITTANCE, 'high'],
    ['Bonificacao',                       'Bonificacao',             OmieOperationScope::REMITTANCE, 'high'],
    ['Demonstracao',                      'Demonstracao',            OmieOperationScope::REMITTANCE, 'high'],

    // Remittance — substring
    ['Contains "remessa"',                'NF Remessa Especial',     OmieOperationScope::REMITTANCE, 'medium'],

    // Unknown
    ['Empty string',                      '',                        OmieOperationScope::UNKNOWN,    'any'],
    ['Spaces only',                       '   ',                     OmieOperationScope::UNKNOWN,    'any'],
    ['Completely unknown string',         'Operacao Qualquer XYZ',   OmieOperationScope::UNKNOWN,    'any'],
    ['Random numeric string',             '12345',                   OmieOperationScope::UNKNOWN,    'any'],
];

/**
 * classifyInvoice fixtures: [label, raw_invoice, expected_scope]
 *
 * @var list<array{string,array<string,mixed>,string}>
 */
$invoiceFixtures = [
    ['ide.natOp = Orcamento - PD',         ['ide' => ['natOp' => 'Orcamento - PD']],         OmieOperationScope::COMMERCIAL],
    ['ide.natOp = Remessa de Produto',     ['ide' => ['natOp' => 'Remessa de Produto']],     OmieOperationScope::REMITTANCE],
    ['ide.natOp = ORÇAMENTO - PD',         ['ide' => ['natOp' => 'ORÇAMENTO - PD']],         OmieOperationScope::COMMERCIAL],
    ['ide.natOp = empty, finNFe = 4',              ['ide' => ['natOp' => '', 'finNFe' => '4']],                                              OmieOperationScope::REMITTANCE],
    ['ide.natOp = empty, finNFe = 1 (insufficient)', ['ide' => ['natOp' => '', 'finNFe' => '1']],                                            OmieOperationScope::UNKNOWN],
    ['CFOP 5.101 (venda producao)',                ['ide' => ['natOp' => ''], 'det' => [['prod' => ['CFOP' => '5.101']]]],                   OmieOperationScope::COMMERCIAL],
    ['CFOP 6.101 (venda interstate)',              ['ide' => ['natOp' => ''], 'det' => [['prod' => ['CFOP' => '6.101']]]],                   OmieOperationScope::COMMERCIAL],
    ['CFOP 5.922 (faturamento futuro)',            ['ide' => ['natOp' => ''], 'det' => [['prod' => ['CFOP' => '5.922']]]],                   OmieOperationScope::COMMERCIAL],
    ['CFOP 5.915 (simples remessa)',               ['ide' => ['natOp' => ''], 'det' => [['prod' => ['CFOP' => '5.915']]]],                   OmieOperationScope::REMITTANCE],
    ['CFOP 5.916 (remessa bonificacao)',           ['ide' => ['natOp' => ''], 'det' => [['prod' => ['CFOP' => '5.916']]]],                   OmieOperationScope::REMITTANCE],
    ['CFOP 5.911 (remessa amostra)',               ['ide' => ['natOp' => ''], 'det' => [['prod' => ['CFOP' => '5.911']]]],                   OmieOperationScope::REMITTANCE],
    ['CFOP 6.201 (devolucao) via finNFe=4',       ['ide' => ['natOp' => '', 'finNFe' => '4'], 'det' => [['prod' => ['CFOP' => '6.201']]]], OmieOperationScope::REMITTANCE],
    ['CFOP 6.116 (venda futura — commercial)',     ['ide' => ['natOp' => ''], 'det' => [['prod' => ['CFOP' => '6.116']]]],                   OmieOperationScope::COMMERCIAL],
    ['ide missing entirely',               [],                                                OmieOperationScope::UNKNOWN],
    ['ide.natOp = unknown text',           ['ide' => ['natOp' => 'Operacao Estranha']],      OmieOperationScope::UNKNOWN],
];

/**
 * classifyOrder fixtures: [label, raw_order, expected_scope]
 *
 * @var list<array{string,array<string,mixed>,string}>
 */
$orderFixtures = [
    ['nome_tipo_atividade = Orcamento - PD',         ['cabecalho' => ['nome_tipo_atividade' => 'Orcamento - PD']],     OmieOperationScope::COMMERCIAL],
    ['nome_tipo_atividade = Remessa de Produto',     ['cabecalho' => ['nome_tipo_atividade' => 'Remessa de Produto']], OmieOperationScope::REMITTANCE],
    ['nome_tipo_atividade = ORÇAMENTO - PD',         ['cabecalho' => ['nome_tipo_atividade' => 'ORÇAMENTO - PD']],     OmieOperationScope::COMMERCIAL],
    ['nome_tipo_atividade = empty, no code',         ['cabecalho' => ['nome_tipo_atividade' => '']],                   OmieOperationScope::UNKNOWN],
    ['cabecalho missing',                            [],                                                               OmieOperationScope::UNKNOWN],
    ['cabecalho = null scalar',                      ['cabecalho' => 'not_an_array'],                                  OmieOperationScope::UNKNOWN],
    ['nome_tipo_atividade = unknown text',           ['cabecalho' => ['nome_tipo_atividade' => 'Tipo Desconhecido']],  OmieOperationScope::UNKNOWN],
];

// ── Runner ─────────────────────────────────────────────────────────────────────

/** @var list<array{label:string,passed:bool,expected:string,got:string,confidence:string,rule:string}> */
$results = [];
$passed  = 0;
$failed  = 0;

function assertScope(
    string $label,
    string $expected,
    string $minConfidence,
    array $lineage,
    array &$results,
    int &$passed,
    int &$failed,
): void {
    $got        = $lineage['scope'] ?? '?';
    $confidence = $lineage['confidence'] ?? '?';
    $rule       = $lineage['rule'] ?? '?';

    $scopeOk = ($got === $expected);
    $confOk  = $minConfidence === 'any'
        || ($minConfidence === 'medium' && in_array($confidence, ['high', 'medium'], true))
        || ($minConfidence === 'high'   && $confidence === 'high');

    $ok = $scopeOk && $confOk;

    $results[] = [
        'label'      => $label,
        'passed'     => $ok,
        'expected'   => $expected,
        'got'        => $got,
        'confidence' => $confidence,
        'rule'       => $rule,
    ];

    if ($ok) {
        $passed++;
    } else {
        $failed++;
    }
}

// classifyName
foreach ($nameFixtures as [$label, $name, $expectedScope, $minConf]) {
    $lineage = OmieOperationScope::classifyName($name, 'test');
    assertScope("classifyName: $label", $expectedScope, $minConf, $lineage, $results, $passed, $failed);
}

// classifyInvoice
foreach ($invoiceFixtures as [$label, $raw, $expectedScope]) {
    $lineage = OmieOperationScope::classifyInvoice($raw);
    assertScope("classifyInvoice: $label", $expectedScope, 'any', $lineage, $results, $passed, $failed);
}

// classifyOrder
foreach ($orderFixtures as [$label, $raw, $expectedScope]) {
    $lineage = OmieOperationScope::classifyOrder($raw);
    assertScope("classifyOrder: $label", $expectedScope, 'any', $lineage, $results, $passed, $failed);
}

$total = $passed + $failed;

// ── Output ─────────────────────────────────────────────────────────────────────

if ($isJson) {
    echo json_encode([
        'total'   => $total,
        'passed'  => $passed,
        'failed'  => $failed,
        'results' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($failed > 0 ? 1 : 0);
}

$checkMark = "\u{2713}";
$crossMark = "\u{2717}";

foreach ($results as $r) {
    $icon = $r['passed'] ? $checkMark : $crossMark;
    if (!$r['passed'] || $verbose) {
        $line = sprintf('%s %s', $icon, $r['label']);
        if (!$r['passed']) {
            $line .= sprintf(' — expected=%s got=%s rule=%s', $r['expected'], $r['got'], $r['rule']);
        } elseif ($verbose) {
            $line .= sprintf(' [%s / %s]', $r['confidence'], $r['rule']);
        }
        echo $line . "\n";
    }
}

echo "\n";
if ($failed === 0) {
    echo "OK: $passed/$total testes passaram.\n";
} else {
    echo "FALHA: $failed/$total testes falharam ($passed passaram).\n";
}

exit($failed > 0 ? 1 : 0);
