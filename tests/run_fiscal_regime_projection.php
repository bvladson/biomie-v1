<?php

declare(strict_types=1);

/**
 * PROJECAO FISCAL POR REGIME — contrato.
 *
 * A apuracao atual soma so o imposto DESTACADO nas notas de saida. Isso zera ou
 * subestima a carga de 3 das 5 empresas do grupo:
 *
 *   Simples (EXPERT, UNI_DESIGN): nota nao destaca nada, apuracao dava R$ 0,
 *     mas o DAS custa de 4% a 19% do faturamento.
 *   Presumido (ERGOOFFICE): IRPJ e CSLL sao pagos por fora da nota — ~2,28%
 *     do faturamento invisivel.
 *   Real (MAG_FILIAL): IRPJ/CSLL dependem do lucro contabil e NAO devem ser
 *     estimados a partir de nota; o contrato exige que fiquem marcados como
 *     nao projetaveis em vez de virar numero inventado.
 *
 * Os valores esperados abaixo foram calculados a mao a partir da legislacao.
 *
 *   php tests/run_fiscal_regime_projection.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Modules\Fiscal\Domain\RegimeTaxProjector;
use BIomie\Modules\Fiscal\Domain\SimplesNacionalAnexoI;
use BIomie\Modules\Fiscal\Domain\TaxRegime;

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};
$near = static fn (float $a, float $b, float $tol = 0.01): bool => abs($a - $b) <= $tol;

// ── 1. Anexo I: aliquota efetiva por faixa ──────────────────────────────────
// Faixa 1 nao tem deducao: efetiva == nominal == 4%.
$f1 = SimplesNacionalAnexoI::aliquotaEfetiva(150000.0);
$check('faixa 1 (RBT12 150k) = 4,00%', $f1['faixa'] === 1 && $near($f1['efetiva_pct'], 4.00), $f1);

// Faixa 3, RBT12 = 500.000: (500000*9,5% - 13.860)/500000 = (47.500-13.860)/500000 = 6,728%
$f3 = SimplesNacionalAnexoI::aliquotaEfetiva(500000.0);
$check('faixa 3 (RBT12 500k) = 6,728%', $f3['faixa'] === 3 && $near($f3['efetiva_pct'], 6.728, 0.001), $f3);

// Faixa 5, RBT12 = 2.000.000: (2000000*14,3% - 87.300)/2000000 = (286.000-87.300)/2e6 = 9,935%
$f5 = SimplesNacionalAnexoI::aliquotaEfetiva(2000000.0);
$check('faixa 5 (RBT12 2M) = 9,935%', $f5['faixa'] === 5 && $near($f5['efetiva_pct'], 9.935, 0.001), $f5);

// Faixa 6, RBT12 = 4.000.000: (4000000*19% - 378.000)/4e6 = (760.000-378.000)/4e6 = 9,55%
$f6 = SimplesNacionalAnexoI::aliquotaEfetiva(4000000.0);
$check('faixa 6 (RBT12 4M) = 9,55%', $f6['faixa'] === 6 && $near($f6['efetiva_pct'], 9.55, 0.001), $f6);

$check('🔴 acima do teto de 4,8M e sinalizado', SimplesNacionalAnexoI::aliquotaEfetiva(5000000.0)['acima_do_teto']);
$check('dentro do teto nao sinaliza', !SimplesNacionalAnexoI::aliquotaEfetiva(4000000.0)['acima_do_teto']);

// Efetiva sempre <= nominal (a deducao so reduz).
$monotonica = true;
foreach ([100000.0, 300000.0, 600000.0, 1000000.0, 2500000.0, 4500000.0] as $rbt) {
    $a = SimplesNacionalAnexoI::aliquotaEfetiva($rbt);
    if ($a['efetiva_pct'] > $a['nominal_pct'] + 0.0001) {
        $monotonica = false;
    }
}
$check('efetiva nunca supera a nominal', $monotonica);

// Inicio de atividade: 3 meses somando 90k ⇒ RBT12 proporcional = 360k.
$check('RBT12 proporcional p/ empresa nova', $near(SimplesNacionalAnexoI::rbt12FromMonths(90000.0, 3), 360000.0));
$check('12+ meses usa o acumulado', $near(SimplesNacionalAnexoI::rbt12FromMonths(500000.0, 12), 500000.0));

// ── 2. Simples: DAS estimado onde a apuracao dava zero ──────────────────────
// EXPERT: receita 1.109.533 no periodo, RBT12 ~1,9M ⇒ faixa 5.
$simples = RegimeTaxProjector::project(TaxRegime::SIMPLES_NACIONAL, [
    'receita_mes' => 1109533.0,
    'rbt12' => 1900000.0,
]);
$esperadoPct = SimplesNacionalAnexoI::aliquotaEfetiva(1900000.0)['efetiva_pct'];
$check(
    '🔴 Simples projeta DAS onde a apuracao dava R$ 0',
    $simples['tributo_total'] > 0
        && $near($simples['tributo_total'], 1109533.0 * $esperadoPct / 100.0, 1.0),
    ['das' => $simples['tributo_total'], 'efetiva_pct' => $esperadoPct]
);
$check('Simples nao projeta credito', $simples['credito_projetavel'] === false && $simples['credito_total'] === 0.0);
$check('Simples devolve so DAS', array_keys($simples['tributos']) === ['DAS']);

// Nota do Simples destacando imposto = erro de emissao.
$simplesErrado = RegimeTaxProjector::project(TaxRegime::SIMPLES_NACIONAL, [
    'receita_mes' => 100000.0, 'rbt12' => 500000.0, 'destacado_icms' => 12000.0,
]);
$codes = array_column($simplesErrado['alertas'], 'code');
$check('🔴 alerta quando nota do Simples destaca imposto', in_array('simples_com_imposto_destacado', $codes, true), $codes);
$check('sem destaque, sem alerta', RegimeTaxProjector::project(TaxRegime::SIMPLES_NACIONAL,
    ['receita_mes' => 100000.0, 'rbt12' => 500000.0])['alertas'] === []);

// ── 3. Presumido: IRPJ e CSLL que nao passam por nota ───────────────────────
// Receita 1.000.000 em 1 mes:
//   base IRPJ = 80.000 ⇒ 15% = 12.000; adicional 10% sobre (80.000-20.000)=60.000 ⇒ 6.000 ⇒ IRPJ 18.000
//   base CSLL = 120.000 ⇒ 9% = 10.800
$pres = RegimeTaxProjector::project(TaxRegime::LUCRO_PRESUMIDO, [
    'receita_mes' => 1000000.0,
    'meses_no_periodo' => 1,
    'destacado_pis' => 6500.0,
    'destacado_cofins' => 30000.0,
    'destacado_icms' => 100000.0,
]);
$check('Presumido: IRPJ 18.000 (15% + adicional)', $near($pres['tributos']['IRPJ'], 18000.0), $pres['tributos']);
$check('Presumido: CSLL 10.800', $near($pres['tributos']['CSLL'], 10800.0), $pres['tributos']);
$check(
    '🔴 IRPJ+CSLL entram no total (invisiveis na apuracao atual)',
    $near($pres['tributo_total'], 6500.0 + 30000.0 + 100000.0 + 18000.0 + 10800.0)
);
$check('Presumido marca IRPJ/CSLL como fora da nota',
    $pres['detalhe']['fora_da_nota'] === ['IRPJ', 'CSLL']);

// ── 3b. A projecao NAO pode ficar abaixo da apuracao ja existente ───────────
// A apuracao soma tambem ICMS-ST, DIFAL, FECOP, IPI e ISS. Se o projetor
// ignorar esses, a "diferenca" deixa de significar tributo invisivel e passa a
// misturar o que o projetor esqueceu — foi o bug pego na 1a rodada com dados
// reais (MAG_FILIAL projetava 22 mil A MENOS que a propria apuracao).
foreach ([TaxRegime::LUCRO_REAL, TaxRegime::LUCRO_PRESUMIDO] as $reg) {
    $semOutros = RegimeTaxProjector::project($reg, [
        'receita_mes' => 1000000.0, 'meses_no_periodo' => 1,
        'destacado_pis' => 16500.0, 'destacado_cofins' => 76000.0, 'destacado_icms' => 150000.0,
    ]);
    $comOutros = RegimeTaxProjector::project($reg, [
        'receita_mes' => 1000000.0, 'meses_no_periodo' => 1,
        'destacado_pis' => 16500.0, 'destacado_cofins' => 76000.0, 'destacado_icms' => 150000.0,
        'destacado_outros' => 40000.0,
    ]);
    $check(
        "🔴 destacado_outros (ST/DIFAL/FECOP/IPI/ISS) entra no total ({$reg})",
        $near((float) $comOutros['tributo_total'] - (float) $semOutros['tributo_total'], 40000.0),
        ['sem' => $semOutros['tributo_total'], 'com' => $comOutros['tributo_total']]
    );
    $check(
        "carga % tambem considera outros destacados ({$reg})",
        $comOutros['carga_pct'] > $semOutros['carga_pct']
    );
}

// Adicional so incide sobre o excedente: receita 200k ⇒ base 16k < 20k ⇒ sem adicional.
$presPequeno = RegimeTaxProjector::project(TaxRegime::LUCRO_PRESUMIDO,
    ['receita_mes' => 200000.0, 'meses_no_periodo' => 1]);
$check('sem adicional quando a base fica abaixo de 20k/mes',
    $near($presPequeno['tributos']['IRPJ'], 16000.0 * 0.15), $presPequeno['tributos']);

// Carga de Lucro Real numa empresa do Presumido = erro de cadastro.
$presErrado = RegimeTaxProjector::project(TaxRegime::LUCRO_PRESUMIDO, [
    'receita_mes' => 1000000.0, 'meses_no_periodo' => 1,
    'destacado_pis' => 16500.0, 'destacado_cofins' => 76000.0,
]);
$check('🔴 alerta: Presumido com aliquota de Lucro Real (9,25%)',
    in_array('presumido_com_aliquota_de_real', array_column($presErrado['alertas'], 'code'), true));
$check('carga cumulativa normal (3,65%) nao alerta',
    !in_array('presumido_com_aliquota_de_real', array_column($pres['alertas'], 'code'), true));

// ── 4. Real: IRPJ/CSLL NAO podem ser inventados ────────────────────────────
$real = RegimeTaxProjector::project(TaxRegime::LUCRO_REAL, [
    'receita_mes' => 1000000.0,
    'destacado_pis' => 16500.0, 'destacado_cofins' => 76000.0, 'destacado_icms' => 150000.0,
    'credito_pis' => 500.0, 'credito_cofins' => 2300.0,
]);
$naoProjetaveis = array_column($real['nao_projetavel'], 'tributo');
$check('🔴 Real NAO estima IRPJ/CSLL a partir de nota', $naoProjetaveis === ['IRPJ', 'CSLL'], $naoProjetaveis);
$check('Real nao coloca IRPJ/CSLL entre os tributos',
    !array_key_exists('IRPJ', $real['tributos']) && !array_key_exists('CSLL', $real['tributos']));
$check('🔴 alerta de credito de entrada quase ausente (caso real da MAG_FILIAL)',
    in_array('real_sem_credito_de_entrada', array_column($real['alertas'], 'code'), true));

$realComCredito = RegimeTaxProjector::project(TaxRegime::LUCRO_REAL, [
    'receita_mes' => 1000000.0, 'destacado_pis' => 16500.0, 'destacado_cofins' => 76000.0,
    'credito_pis' => 8000.0, 'credito_cofins' => 35000.0,
]);
$check('credito saudavel nao alerta',
    !in_array('real_sem_credito_de_entrada', array_column($realComCredito['alertas'], 'code'), true));

// ── 5. Regime de cada empresa do grupo ─────────────────────────────────────
$check('MAG_FILIAL e Lucro Real', TaxRegime::forSlug('MAG_FILIAL') === TaxRegime::LUCRO_REAL);
$check('ERGOOFFICE e Lucro Presumido', TaxRegime::forSlug('ERGOOFFICE') === TaxRegime::LUCRO_PRESUMIDO);
$check('EXPERT e Simples', TaxRegime::forSlug('EXPERT') === TaxRegime::SIMPLES_NACIONAL);
$check('UNI_DESIGN e Simples', TaxRegime::forSlug('UNI_DESIGN') === TaxRegime::SIMPLES_NACIONAL);
$check('slug desconhecido cai no Real (mais conservador)', TaxRegime::forSlug('XPTO') === TaxRegime::LUCRO_REAL);

// ── 6. Sem receita nao inventa imposto ─────────────────────────────────────
foreach ([TaxRegime::SIMPLES_NACIONAL, TaxRegime::LUCRO_PRESUMIDO, TaxRegime::LUCRO_REAL] as $r) {
    $zero = RegimeTaxProjector::project($r, ['receita_mes' => 0.0]);
    $check("receita zero ⇒ tributo zero ({$r})", $zero['tributo_total'] === 0.0 || $near($zero['tributo_total'], 0.0));
}

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
