<?php

declare(strict_types=1);

namespace BIomie\Modules\Fiscal\Domain;

/**
 * Projecao de tributos e creditos POR REGIME.
 *
 * A apuracao existente (mart_fiscal_monthly_settlement) soma apenas o que esta
 * DESTACADO nas notas de saida. Isso subestima ou zera a carga de 3 das 5
 * empresas do grupo, porque nem todo tributo passa pela nota:
 *
 *   Simples Nacional (EXPERT, UNI_DESIGN)
 *     A nota nao destaca nada — a apuracao devolve R$ 0. Mas o DAS existe e
 *     custa de 4% a 19% do faturamento. Aqui ele e estimado pelo Anexo I.
 *
 *   Lucro Presumido (ERGOOFFICE)
 *     PIS/COFINS/ICMS destacados estao certos, mas IRPJ (presuncao de 8%) e
 *     CSLL (presuncao de 12%) sao pagos por fora e nao aparecem em nota
 *     nenhuma — ~2,28% do faturamento invisivel na apuracao atual.
 *
 *   Lucro Real (MAG_FILIAL, MAG_MATRIZ)
 *     PIS/COFINS destacados estao certos e admitem credito das entradas. IRPJ
 *     e CSLL incidem sobre o LUCRO contabil, que nao se deriva de nota fiscal —
 *     esses ficam explicitamente fora, marcados como nao projetaveis, em vez de
 *     serem estimados com um numero inventado.
 *
 * TUDO AQUI E ESTIMATIVA GERENCIAL. Serve para antecipar caixa e achar desvio
 * de cadastro fiscal, nao para substituir a apuracao do contador.
 */
final class RegimeTaxProjector
{
    /** Presuncao de IRPJ no comercio (art. 15, Lei 9.249/95). */
    private const PRESUNCAO_IRPJ = 0.08;
    /** Presuncao de CSLL no comercio (art. 20, Lei 9.249/95). */
    private const PRESUNCAO_CSLL = 0.12;
    private const IRPJ_PCT = 0.15;
    private const CSLL_PCT = 0.09;
    /** Adicional de 10% sobre a base que exceder R$ 20 mil por mes. */
    private const IRPJ_ADICIONAL_PCT = 0.10;
    private const IRPJ_ADICIONAL_LIMITE_MENSAL = 20000.00;

    /**
     * @param array{
     *     receita_mes?:float,
     *     rbt12?:float,
     *     meses_com_receita?:int,
     *     destacado_pis?:float,
     *     destacado_cofins?:float,
     *     destacado_icms?:float,
     *     credito_pis?:float,
     *     credito_cofins?:float,
     *     credito_icms?:float,
     *     meses_no_periodo?:int
     * } $dados
     * @return array<string,mixed>
     */
    public static function project(string $regime, array $dados): array
    {
        $receita = max(0.0, (float) ($dados['receita_mes'] ?? 0.0));

        return match ($regime) {
            TaxRegime::SIMPLES_NACIONAL => self::simples($receita, $dados),
            TaxRegime::LUCRO_PRESUMIDO  => self::presumido($receita, $dados),
            default                     => self::real($receita, $dados),
        };
    }

    /**
     * @param  array<string,mixed> $dados
     * @return array<string,mixed>
     */
    private static function simples(float $receita, array $dados): array
    {
        $rbt12 = (float) ($dados['rbt12'] ?? 0.0);
        if ($rbt12 <= 0.0) {
            $rbt12 = SimplesNacionalAnexoI::rbt12FromMonths(
                $receita * max(1, (int) ($dados['meses_no_periodo'] ?? 1)),
                (int) ($dados['meses_com_receita'] ?? 1)
            );
        }

        $das = SimplesNacionalAnexoI::dasEstimado($receita, $rbt12);
        $destacado = self::somaDestacado($dados);

        $alertas = [];
        if ($destacado > 1.0) {
            $alertas[] = [
                'code' => 'simples_com_imposto_destacado',
                'severity' => 'high',
                'message' => 'Notas do Simples estao destacando PIS/COFINS/ICMS ('
                    . number_format($destacado, 2, ',', '.')
                    . '). No Simples esses tributos vao no DAS — destaque relevante indica erro de emissao.',
            ];
        }
        if ($das['acima_do_teto']) {
            $alertas[] = [
                'code' => 'simples_acima_do_teto',
                'severity' => 'high',
                'message' => 'Receita dos ultimos 12 meses acima de R$ 4,8 milhoes: risco de desenquadramento do Simples.',
            ];
        }

        return [
            'regime' => TaxRegime::SIMPLES_NACIONAL,
            'projetavel' => true,
            'receita_base' => round($receita, 2),
            'rbt12' => round($rbt12, 2),
            'tributos' => ['DAS' => $das['das']],
            'tributo_total' => $das['das'],
            'credito_total' => 0.0,
            'credito_projetavel' => false,
            'carga_pct' => $das['efetiva_pct'],
            'detalhe' => [
                'faixa' => $das['faixa'],
                'aliquota_efetiva_pct' => $das['efetiva_pct'],
                'anexo' => 'I — Comércio',
            ],
            'nao_projetavel' => [],
            'alertas' => $alertas,
            'base_legal' => 'LC 123/2006, Anexo I (redação da LC 155/2016)',
        ];
    }

    /**
     * @param  array<string,mixed> $dados
     * @return array<string,mixed>
     */
    private static function presumido(float $receita, array $dados): array
    {
        $baseIrpj = $receita * self::PRESUNCAO_IRPJ;
        $baseCsll = $receita * self::PRESUNCAO_CSLL;

        $meses = max(1, (int) ($dados['meses_no_periodo'] ?? 1));
        $limiteAdicional = self::IRPJ_ADICIONAL_LIMITE_MENSAL * $meses;
        $excedente = max(0.0, $baseIrpj - $limiteAdicional);

        $irpj = $baseIrpj * self::IRPJ_PCT + $excedente * self::IRPJ_ADICIONAL_PCT;
        $csll = $baseCsll * self::CSLL_PCT;

        $destacadoPis = (float) ($dados['destacado_pis'] ?? 0.0);
        $destacadoCofins = (float) ($dados['destacado_cofins'] ?? 0.0);
        $destacadoIcms = (float) ($dados['destacado_icms'] ?? 0.0);
        // ICMS-ST, DIFAL, FECOP, IPI e ISS. Sem eles a projecao ficaria ABAIXO
        // da apuracao ja existente e a diferenca deixaria de significar
        // "tributo invisivel" — passaria a misturar o que o projetor esqueceu.
        $destacadoOutros = (float) ($dados['destacado_outros'] ?? 0.0);

        $alertas = [];
        $pisCofins = $destacadoPis + $destacadoCofins;
        if ($receita > 0 && $pisCofins > 0) {
            $carga = $pisCofins / $receita * 100.0;
            // Cumulativo = 3,65%. Perto de 9,25% e aliquota de Lucro Real.
            if ($carga > 6.0) {
                $alertas[] = [
                    'code' => 'presumido_com_aliquota_de_real',
                    'severity' => 'high',
                    'message' => 'PIS/COFINS destacados somam ' . number_format($carga, 2, ',', '.')
                        . '% da receita. No Presumido (cumulativo) o esperado e 3,65% — '
                        . 'carga proxima de 9,25% indica cadastro fiscal de Lucro Real.',
                ];
            }
        }
        if (((float) ($dados['credito_pis'] ?? 0.0) + (float) ($dados['credito_cofins'] ?? 0.0)) > 1.0) {
            $alertas[] = [
                'code' => 'presumido_com_credito_pis_cofins',
                'severity' => 'medium',
                'message' => 'Ha credito de PIS/COFINS lancado, mas o regime cumulativo nao admite esse credito.',
            ];
        }

        return [
            'regime' => TaxRegime::LUCRO_PRESUMIDO,
            'projetavel' => true,
            'receita_base' => round($receita, 2),
            'tributos' => [
                'PIS' => round($destacadoPis, 2),
                'COFINS' => round($destacadoCofins, 2),
                'ICMS' => round($destacadoIcms, 2),
                'Outros destacados' => round($destacadoOutros, 2),
                'IRPJ' => round($irpj, 2),
                'CSLL' => round($csll, 2),
            ],
            'tributo_total' => round($destacadoPis + $destacadoCofins + $destacadoIcms + $destacadoOutros + $irpj + $csll, 2),
            // Cumulativo nao credita PIS/COFINS; ICMS de entrada ainda credita.
            'credito_total' => round((float) ($dados['credito_icms'] ?? 0.0), 2),
            'credito_projetavel' => true,
            'carga_pct' => $receita > 0
                ? round(($destacadoPis + $destacadoCofins + $destacadoIcms + $destacadoOutros + $irpj + $csll) / $receita * 100.0, 4)
                : 0.0,
            'detalhe' => [
                'base_irpj' => round($baseIrpj, 2),
                'base_csll' => round($baseCsll, 2),
                'irpj_adicional_sobre' => round($excedente, 2),
                'fora_da_nota' => ['IRPJ', 'CSLL'],
            ],
            'nao_projetavel' => [],
            'alertas' => $alertas,
            'base_legal' => 'Leis 9.249/95 (presunções 8%/12%) e 9.718/98 (cumulativo)',
        ];
    }

    /**
     * @param  array<string,mixed> $dados
     * @return array<string,mixed>
     */
    private static function real(float $receita, array $dados): array
    {
        $destacadoPis = (float) ($dados['destacado_pis'] ?? 0.0);
        $destacadoCofins = (float) ($dados['destacado_cofins'] ?? 0.0);
        $destacadoIcms = (float) ($dados['destacado_icms'] ?? 0.0);
        $destacadoOutros = (float) ($dados['destacado_outros'] ?? 0.0);
        $creditoPis = (float) ($dados['credito_pis'] ?? 0.0);
        $creditoCofins = (float) ($dados['credito_cofins'] ?? 0.0);
        $creditoIcms = (float) ($dados['credito_icms'] ?? 0.0);
        $credito = $creditoPis + $creditoCofins + $creditoIcms;

        $alertas = [];
        $pisCofins = $destacadoPis + $destacadoCofins;
        if ($pisCofins > 0 && ($creditoPis + $creditoCofins) / $pisCofins < 0.05) {
            $alertas[] = [
                'code' => 'real_sem_credito_de_entrada',
                'severity' => 'high',
                'message' => 'No nao-cumulativo cada compra para revenda gera credito de PIS/COFINS. '
                    . 'O credito lancado cobre menos de 5% do debito — ou as entradas nao foram '
                    . 'sincronizadas, ou ha credito sendo perdido.',
            ];
        }

        return [
            'regime' => TaxRegime::LUCRO_REAL,
            'projetavel' => true,
            'receita_base' => round($receita, 2),
            'tributos' => [
                'PIS' => round($destacadoPis, 2),
                'COFINS' => round($destacadoCofins, 2),
                'ICMS' => round($destacadoIcms, 2),
                'Outros destacados' => round($destacadoOutros, 2),
            ],
            'tributo_total' => round($destacadoPis + $destacadoCofins + $destacadoIcms + $destacadoOutros, 2),
            'credito_total' => round($credito, 2),
            'credito_projetavel' => true,
            'carga_pct' => $receita > 0
                ? round(($destacadoPis + $destacadoCofins + $destacadoIcms + $destacadoOutros - $credito) / $receita * 100.0, 4)
                : 0.0,
            'detalhe' => [
                'credito_pis' => round($creditoPis, 2),
                'credito_cofins' => round($creditoCofins, 2),
                'credito_icms' => round($creditoIcms, 2),
                'cobertura_credito_pct' => $pisCofins > 0
                    ? round(($creditoPis + $creditoCofins) / $pisCofins * 100.0, 2)
                    : 0.0,
            ],
            // Nao se estima IRPJ/CSLL do Real a partir de nota fiscal: a base e o
            // lucro contabil. Dizer "nao da para projetar" e mais util que um
            // numero inventado.
            'nao_projetavel' => [
                ['tributo' => 'IRPJ', 'motivo' => 'incide sobre o lucro real contábil, não sobre a receita'],
                ['tributo' => 'CSLL', 'motivo' => 'incide sobre o lucro real contábil, não sobre a receita'],
            ],
            'alertas' => $alertas,
            'base_legal' => 'Leis 10.637/02 e 10.833/03 (não-cumulativo)',
        ];
    }

    /** @param array<string,mixed> $dados */
    private static function somaDestacado(array $dados): float
    {
        return (float) ($dados['destacado_pis'] ?? 0.0)
            + (float) ($dados['destacado_cofins'] ?? 0.0)
            + (float) ($dados['destacado_icms'] ?? 0.0);
    }
}
