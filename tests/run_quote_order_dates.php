<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — Fase 2 (metade 1): testes do parser estrito, dos
 * normalizadores e do contrato do writer. Puro: sem DB, sem escrita.
 *
 *   composer test  → não inclui este; rode direto:
 *   php tests/run_quote_order_dates.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\FiscalSync\FiscalCanonicalMapper;
use BIomie\FiscalSync\FiscalPartyResolver;
use BIomie\FiscalSync\FiscalSupport;
use BIomie\Support\OmieDateParser;

/** @var list<array{name:string,ok:bool,detail:mixed}> $checks */
$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

// ── 1. Parser estrito ────────────────────────────────────────────────────────────
$check('parse_full_datetime',
    OmieDateParser::parseDateTime('10/07/2026', '08:15:30') === '2026-07-10 08:15:30',
    OmieDateParser::parseDateTime('10/07/2026', '08:15:30'));

$check('parse_hour_missing_midnight',
    OmieDateParser::parseDateTime('10/07/2026', '') === '2026-07-10 00:00:00',
    OmieDateParser::parseDateTime('10/07/2026', ''));

$check('parse_hour_null_midnight',
    OmieDateParser::parseDateTime('10/07/2026', null) === '2026-07-10 00:00:00',
    OmieDateParser::parseDateTime('10/07/2026', null));

$check('parse_previsao_date',
    OmieDateParser::parseDate('25/07/2026') === '2026-07-25',
    OmieDateParser::parseDate('25/07/2026'));

$check('reject_empty_date',
    OmieDateParser::parseDate('') === null && OmieDateParser::parseDateTime('', '08:00:00') === null,
    null);

$check('reject_zero_date',
    OmieDateParser::parseDate('00/00/0000') === null,
    OmieDateParser::parseDate('00/00/0000'));

$check('reject_zero_datetime',
    OmieDateParser::parseDateTime('00/00/0000', '00:00:00') === null,
    OmieDateParser::parseDateTime('00/00/0000', '00:00:00'));

$check('reject_impossible_date',
    OmieDateParser::parseDate('32/13/2026') === null && OmieDateParser::parseDate('31/02/2026') === null,
    [OmieDateParser::parseDate('32/13/2026'), OmieDateParser::parseDate('31/02/2026')]);

$check('reject_invalid_time_falls_to_midnight',
    OmieDateParser::parseDateTime('10/07/2026', '99:99:99') === '2026-07-10 00:00:00',
    OmieDateParser::parseDateTime('10/07/2026', '99:99:99'));

$check('parser_idempotent',
    OmieDateParser::parseDateTime('10/07/2026', '08:15:30')
        === OmieDateParser::parseDateTime('10/07/2026', '08:15:30'),
    null);

// ── 2. Mapper — payload com divergência real (dInc ≠ previsão) ────────────────────
$mapper = new FiscalCanonicalMapper(new FiscalSupport(), new FiscalPartyResolver());

$rawDivergente = [
    'cabecalho' => [
        'codigo_pedido'   => 8896550988,
        'numero_pedido'   => '1508',
        'etapa'           => '10',
        'data_previsao'   => '25/07/2026',
        'quantidade_itens'=> 2,
    ],
    'infoCadastro' => [
        'dInc' => '10/07/2026', 'hInc' => '08:15:30',
        'dAlt' => '12/07/2026', 'hAlt' => '14:05:00',
    ],
    'informacoes_adicionais' => ['codVend' => '25'],
    'total_pedido' => [
        'valor_total_pedido' => 12450.00,
        'valor_mercadorias'  => 12450.00,
        'valor_descontos'    => 0.0,
    ],
];

$mapped = $mapper->normalizePendingOrderToCanonical($rawDivergente, [], 'test', ['25' => 'CARLOS']);

$check('mapper_returns_row', is_array($mapped), $mapped === null ? 'null' : 'array');

if (is_array($mapped)) {
    $check('map_data_inclusao_omie',
        ($mapped['data_inclusao_omie'] ?? null) === '2026-07-10 08:15:30',
        $mapped['data_inclusao_omie'] ?? null);

    $check('map_data_alteracao_omie',
        ($mapped['data_alteracao_omie'] ?? null) === '2026-07-12 14:05:00',
        $mapped['data_alteracao_omie'] ?? null);

    $check('map_data_previsao_faturamento',
        ($mapped['data_previsao_faturamento'] ?? null) === '2026-07-25',
        $mapped['data_previsao_faturamento'] ?? null);

    // Regra de ouro: data_emissao legado SEGUE a previsão (comportamento antigo),
    // e portanto ≠ da inclusão real. É exatamente o que a nova coluna corrige.
    $check('legacy_data_emissao_unchanged_follows_previsao',
        ($mapped['data_emissao'] ?? null) === '2026-07-25',
        $mapped['data_emissao'] ?? null);

    $check('divergence_inclusao_differs_from_emissao',
        ($mapped['data_inclusao_omie'] ?? null) !== null
        && substr((string) $mapped['data_inclusao_omie'], 0, 10) !== ($mapped['data_emissao'] ?? null),
        [$mapped['data_inclusao_omie'] ?? null, $mapped['data_emissao'] ?? null]);

    // Nada mais muda: etapa / status / valor / vendedor / id.
    $check('map_etapa_unchanged', ($mapped['etapa_omie'] ?? null) === '10', $mapped['etapa_omie'] ?? null);
    $check('map_status_outro', ($mapped['status_comercial'] ?? null) === 'outro', $mapped['status_comercial'] ?? null);
    $check('map_valor_unchanged', (float) ($mapped['valor_total'] ?? 0) === 12450.00, $mapped['valor_total'] ?? null);
    $check('map_vendor_unchanged', (string) ($mapped['codigo_vendedor_omie'] ?? '') === '25', $mapped['codigo_vendedor_omie'] ?? null);
    $check('map_pedido_id_unchanged', (int) ($mapped['pedido_id_omie'] ?? 0) === 8896550988, $mapped['pedido_id_omie'] ?? null);
}

// ── 3. Mapper — hora ausente → meia-noite; e inclusão inválida → null ─────────────
$rawSemHora = $rawDivergente;
$rawSemHora['infoCadastro'] = ['dInc' => '10/07/2026', 'hInc' => ''];
$mappedSemHora = $mapper->normalizePendingOrderToCanonical($rawSemHora, [], 'test', []);
$check('map_hour_missing_midnight',
    is_array($mappedSemHora) && ($mappedSemHora['data_inclusao_omie'] ?? null) === '2026-07-10 00:00:00',
    $mappedSemHora['data_inclusao_omie'] ?? null);

$rawInclusaoInvalida = $rawDivergente;
$rawInclusaoInvalida['infoCadastro'] = ['dInc' => '00/00/0000', 'hInc' => '00:00:00'];
$mappedInvalida = $mapper->normalizePendingOrderToCanonical($rawInclusaoInvalida, [], 'test', []);
$check('map_invalid_inclusao_null',
    is_array($mappedInvalida)
    && array_key_exists('data_inclusao_omie', $mappedInvalida)
    && $mappedInvalida['data_inclusao_omie'] === null,
    is_array($mappedInvalida)
        ? var_export($mappedInvalida['data_inclusao_omie'] ?? '__missing__', true)
        : 'not-array');

// ── 4. Idempotência do mapper ─────────────────────────────────────────────────────
$mappedA = $mapper->normalizePendingOrderToCanonical($rawDivergente, [], 'test', ['25' => 'CARLOS']);
$mappedB = $mapper->normalizePendingOrderToCanonical($rawDivergente, [], 'test', ['25' => 'CARLOS']);
$check('mapper_idempotent', $mappedA === $mappedB, null);

// ── 5. Contrato do writer (upsertOrder) — colunas, placeholders e binds ───────────
$writerSrc = (string) file_get_contents(dirname(__DIR__) . '/app/Canonical/CanonicalWriteRepository.php');
$writerHas = static fn (string $needle): bool => str_contains($writerSrc, $needle);
$check('writer_has_columns',
    $writerHas('data_inclusao_omie, data_alteracao_omie, data_previsao_faturamento'),
    null);
$check('writer_has_placeholders',
    $writerHas(':dt_inclusao, :dt_alteracao, :dt_previsao_fat'),
    null);
$check('writer_has_on_duplicate',
    $writerHas('data_inclusao_omie = VALUES(data_inclusao_omie)')
    && $writerHas('data_previsao_faturamento = VALUES(data_previsao_faturamento)'),
    null);
$check('writer_has_binds',
    $writerHas("'dt_inclusao'") && $writerHas("'dt_alteracao'") && $writerHas("'dt_previsao_fat'"),
    null);
// Contagem: exatamente 1 ocorrência de cada placeholder no VALUES (sem deslocar/duplicar)
$check('writer_placeholder_count',
    substr_count($writerSrc, ':dt_inclusao,') === 1
    && substr_count($writerSrc, ':dt_previsao_fat,') === 1,
    null);

// ── Hash inclui as novas datas (repopula na ressync) ──────────────────────────────
$hashSrc = (string) file_get_contents(dirname(__DIR__) . '/app/Canonical/Support/CanonicalHashing.php');
$check('hash_includes_new_dates',
    str_contains($hashSrc, "'data_inclusao_omie' => \$order['data_inclusao_omie']")
    && str_contains($hashSrc, "'data_previsao_faturamento' => \$order['data_previsao_faturamento']"),
    null);

// ── Summary ───────────────────────────────────────────────────────────────────────
$passed = count(array_filter($checks, static fn (array $r): bool => $r['ok']));
$result = [
    'ok' => $passed === count($checks),
    'total' => count($checks),
    'passed' => $passed,
    'failed' => count($checks) - $passed,
    'checks' => $checks,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($result['ok'] ? 0 : 1);
