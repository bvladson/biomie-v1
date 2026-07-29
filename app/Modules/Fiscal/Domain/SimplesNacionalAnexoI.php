<?php

declare(strict_types=1);

namespace BIomie\Modules\Fiscal\Domain;

/**
 * Simples Nacional — Anexo I (Comercio), LC 123/2006 com a redacao da LC 155/2016.
 *
 * No Simples os tributos NAO aparecem nota a nota: PIS, COFINS, ICMS, IRPJ, CSLL
 * e CPP vao todos no DAS mensal. Por isso a apuracao que soma imposto destacado
 * nas saidas devolve ZERO para EXPERT e UNI_DESIGN — correto na origem, inutil
 * como informacao. Esta tabela permite ESTIMAR o DAS a partir do faturamento.
 *
 * A aliquota nao e a nominal da faixa: e efetiva, calculada sobre a receita
 * bruta dos ultimos 12 meses (RBT12):
 *
 *     efetiva = (RBT12 x nominal - parcela_a_deduzir) / RBT12
 *
 * Empresa nova, sem 12 meses de historico, usa a receita proporcionalizada —
 * ver {@see rbt12FromMonths()}.
 *
 * ATENCAO: isto e ESTIMATIVA GERENCIAL, nao apuracao oficial. Nao considera
 * segregacao de receitas, ST/monofasia (que reduzem a parcela de ICMS/PIS/COFINS
 * do DAS), nem Fator R (que nao se aplica ao Anexo I).
 */
final class SimplesNacionalAnexoI
{
    /**
     * Faixas: [teto RBT12, aliquota nominal %, parcela a deduzir R$].
     *
     * @return list<array{limite:float,nominal_pct:float,deduzir:float,faixa:int}>
     */
    public static function faixas(): array
    {
        return [
            ['faixa' => 1, 'limite' => 180000.00,  'nominal_pct' => 4.00,  'deduzir' => 0.00],
            ['faixa' => 2, 'limite' => 360000.00,  'nominal_pct' => 7.30,  'deduzir' => 5940.00],
            ['faixa' => 3, 'limite' => 720000.00,  'nominal_pct' => 9.50,  'deduzir' => 13860.00],
            ['faixa' => 4, 'limite' => 1800000.00, 'nominal_pct' => 10.70, 'deduzir' => 22500.00],
            ['faixa' => 5, 'limite' => 3600000.00, 'nominal_pct' => 14.30, 'deduzir' => 87300.00],
            ['faixa' => 6, 'limite' => 4800000.00, 'nominal_pct' => 19.00, 'deduzir' => 378000.00],
        ];
    }

    /** Teto de enquadramento no Simples (receita bruta anual). */
    public const TETO_ANUAL = 4800000.00;

    /**
     * Aliquota EFETIVA e faixa para um dado RBT12.
     *
     * @return array{faixa:int,nominal_pct:float,deduzir:float,efetiva_pct:float,
     *               acima_do_teto:bool,limite_faixa:float}
     */
    public static function aliquotaEfetiva(float $rbt12): array
    {
        $faixas = self::faixas();
        $ultima = $faixas[count($faixas) - 1];

        if ($rbt12 <= 0.0) {
            // Sem histórico não há como estimar: devolve a faixa 1 com 4% e
            // deixa explícito que não é uma medição.
            return [
                'faixa' => 1,
                'nominal_pct' => 4.00,
                'deduzir' => 0.00,
                'efetiva_pct' => 4.00,
                'acima_do_teto' => false,
                'limite_faixa' => 180000.00,
            ];
        }

        $escolhida = $ultima;
        foreach ($faixas as $f) {
            if ($rbt12 <= $f['limite']) {
                $escolhida = $f;
                break;
            }
        }

        $efetiva = (($rbt12 * $escolhida['nominal_pct'] / 100.0) - $escolhida['deduzir']) / $rbt12 * 100.0;

        return [
            'faixa' => $escolhida['faixa'],
            'nominal_pct' => $escolhida['nominal_pct'],
            'deduzir' => $escolhida['deduzir'],
            'efetiva_pct' => round(max($efetiva, 0.0), 4),
            'acima_do_teto' => $rbt12 > self::TETO_ANUAL,
            'limite_faixa' => $escolhida['limite'],
        ];
    }

    /**
     * DAS estimado do mes: receita do mes x aliquota efetiva do RBT12.
     *
     * @return array{das:float,efetiva_pct:float,faixa:int,acima_do_teto:bool}
     */
    public static function dasEstimado(float $receitaMes, float $rbt12): array
    {
        $a = self::aliquotaEfetiva($rbt12);

        return [
            'das' => round(max($receitaMes, 0.0) * $a['efetiva_pct'] / 100.0, 2),
            'efetiva_pct' => $a['efetiva_pct'],
            'faixa' => $a['faixa'],
            'acima_do_teto' => $a['acima_do_teto'],
        ];
    }

    /**
     * RBT12 de empresa com menos de 12 meses: media dos meses existentes x 12,
     * como manda o art. 18 da LC 123 para inicio de atividade.
     */
    public static function rbt12FromMonths(float $receitaAcumulada, int $mesesComReceita): float
    {
        if ($mesesComReceita <= 0) {
            return 0.0;
        }
        if ($mesesComReceita >= 12) {
            return round($receitaAcumulada, 2);
        }

        return round($receitaAcumulada / $mesesComReceita * 12.0, 2);
    }
}
