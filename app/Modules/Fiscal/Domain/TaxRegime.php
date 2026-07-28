<?php

declare(strict_types=1);

namespace BIomie\Modules\Fiscal\Domain;

/**
 * Regimes tributários do grupo — cada empresa apura impostos de forma diferente.
 *
 * Grupo MAG (2026):
 *   - Lucro Real ............ MAG_FILIAL, MAG_MATRIZ
 *   - Lucro Presumido ....... ERGOOFFICE
 *   - Simples Nacional (EPP)  EXPERT, UNI_DESIGN
 *
 * As alíquotas abaixo são as de COMÉRCIO DE MERCADORIAS (revenda), que é o
 * perfil de operação do grupo. Elas descrevem o que se ESPERA da apuração —
 * os valores exibidos no painel continuam vindo do XML das notas (fonte da
 * verdade); o regime serve para interpretar esses valores e apontar desvios
 * (ex.: carga de PIS/COFINS de 9,25% numa empresa do Presumido é um erro).
 */
final class TaxRegime
{
    public const LUCRO_REAL       = 'lucro_real';
    public const LUCRO_PRESUMIDO  = 'lucro_presumido';
    public const SIMPLES_NACIONAL = 'simples_nacional';

    /**
     * Regime de cada empresa do grupo, por slug.
     *
     * @return array<string,string>
     */
    public static function bySlug(): array
    {
        return [
            'MAG_FILIAL' => self::LUCRO_REAL,
            'MAG_MATRIZ' => self::LUCRO_REAL,
            'ERGOOFFICE' => self::LUCRO_PRESUMIDO,
            'EXPERT'     => self::SIMPLES_NACIONAL,
            'UNI_DESIGN' => self::SIMPLES_NACIONAL,
        ];
    }

    public static function forSlug(string $slug): string
    {
        return self::bySlug()[strtoupper(trim($slug))] ?? self::LUCRO_REAL;
    }

    /**
     * Perfil completo do regime: rótulo, alíquotas esperadas e como ler a apuração.
     *
     * @return array<string,mixed>
     */
    public static function profile(string $code): array
    {
        return self::catalog()[$code] ?? self::catalog()[self::LUCRO_REAL];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function catalog(): array
    {
        return [
            self::LUCRO_REAL => [
                'code'  => self::LUCRO_REAL,
                'label' => 'Lucro Real',
                'short' => 'Real',
                'badge' => 'danger', // maior complexidade/carga
                'pis_cofins_regime' => 'nao_cumulativo',
                'pis_pct'    => 1.65,
                'cofins_pct' => 7.60,
                'pis_cofins_total_pct' => 9.25,
                'credita_entradas' => true,
                'irpj' => ['aliquota_pct' => 15.0, 'adicional_pct' => 10.0, 'base' => 'lucro_real'],
                'csll' => ['aliquota_pct' => 9.0, 'base' => 'lucro_real'],
                'icms' => 'destacado_com_credito',
                'taxes' => ['PIS', 'COFINS', 'ICMS', 'IRPJ', 'CSLL'],
                'summary' => 'PIS 1,65% + COFINS 7,60% (não-cumulativo, com crédito nas compras). IRPJ 15% (+10% adicional) e CSLL 9% sobre o lucro real. ICMS destacado com crédito.',
                'reading' => 'No não-cumulativo, cada compra para revenda gera crédito de PIS/COFINS que abate os débitos das saídas. Carga líquida próxima de 9,25% indica pouco aproveitamento de crédito — oportunidade de melhora.',
            ],

            self::LUCRO_PRESUMIDO => [
                'code'  => self::LUCRO_PRESUMIDO,
                'label' => 'Lucro Presumido',
                'short' => 'Presumido',
                'badge' => 'warning',
                'pis_cofins_regime' => 'cumulativo',
                'pis_pct'    => 0.65,
                'cofins_pct' => 3.00,
                'pis_cofins_total_pct' => 3.65,
                'credita_entradas' => false,
                // Comércio: presunção de 8% (IRPJ) e 12% (CSLL) sobre a receita bruta.
                'irpj' => ['aliquota_pct' => 15.0, 'adicional_pct' => 10.0, 'base' => 'presuncao_8pct', 'efetivo_pct' => 1.20],
                'csll' => ['aliquota_pct' => 9.0, 'base' => 'presuncao_12pct', 'efetivo_pct' => 1.08],
                'icms' => 'destacado_com_credito',
                'taxes' => ['PIS', 'COFINS', 'ICMS', 'IRPJ', 'CSLL'],
                'summary' => 'PIS 0,65% + COFINS 3,00% (cumulativo, SEM crédito nas compras). IRPJ 15% sobre presunção de 8% (≈1,20% da receita) e CSLL 9% sobre presunção de 12% (≈1,08%). ICMS destacado.',
                'reading' => 'No cumulativo NÃO há crédito de PIS/COFINS nas compras — a carga é fixa sobre o faturamento (3,65%). Se aparecer carga de 9,25%, a nota está sendo emitida com alíquota de Lucro Real: erro de cadastro fiscal.',
            ],

            self::SIMPLES_NACIONAL => [
                'code'  => self::SIMPLES_NACIONAL,
                'label' => 'Simples Nacional (EPP)',
                'short' => 'Simples/EPP',
                'badge' => 'success',
                'pis_cofins_regime' => 'unificado_das',
                'pis_pct'    => null,
                'cofins_pct' => null,
                'pis_cofins_total_pct' => null,
                'credita_entradas' => false,
                'irpj' => ['base' => 'das_unificado'],
                'csll' => ['base' => 'das_unificado'],
                'icms' => 'embutido_no_das',
                'anexo' => 'I — Comércio',
                'das_faixa_pct' => ['min' => 4.00, 'max' => 19.00],
                'taxes' => ['DAS'],
                'summary' => 'DAS unificado (Anexo I — Comércio): alíquota efetiva de 4% a 19% conforme a receita bruta dos últimos 12 meses. PIS, COFINS, ICMS, IRPJ, CSLL e CPP já estão embutidos no DAS.',
                'reading' => 'No Simples, os tributos NÃO são apurados nota a nota — vão todos no DAS mensal. PIS/COFINS/ICMS destacados nas notas de saída devem ser zero (ou apenas informativos); valores relevantes aqui indicam erro de emissão.',
            ],
        ];
    }
}
