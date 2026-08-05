<?php

declare(strict_types=1);

namespace BIomie\Commercial;

/**
 * Regra canônica da escada de metas.
 *
 * A meta-base é um compromisso imutável. A meta ativa é o primeiro marco ainda
 * não atingido. Manter os dois conceitos impede que uma conquista desapareça
 * quando o vendedor passa a perseguir o nível seguinte.
 */
final class TargetLadder
{
    /**
     * @param list<int|float|string> $levels
     * @return array<string,mixed>
     */
    public static function evaluate(array $levels, float $realized): array
    {
        $clean = [];
        foreach ($levels as $level) {
            if (!is_numeric($level) || (float) $level <= 0) {
                continue;
            }
            $clean[] = round((float) $level, 2);
        }
        sort($clean, SORT_NUMERIC);
        $clean = array_values(array_unique($clean, SORT_REGULAR));

        if ($clean === []) {
            return self::emptyResult($realized);
        }

        $base = $clean[0];
        $maximum = $clean[count($clean) - 1];
        $achieved = 0;
        $activeIndex = null;
        foreach ($clean as $index => $level) {
            if ($realized >= $level) {
                $achieved++;
                continue;
            }
            $activeIndex = $index;
            break;
        }

        $allAchieved = $activeIndex === null;
        $active = $allAchieved ? null : $clean[$activeIndex];
        $previous = $activeIndex === null
            ? $maximum
            : ($activeIndex > 0 ? $clean[$activeIndex - 1] : 0.0);
        $stepSpan = $active !== null ? max($active - $previous, 0.0) : 0.0;
        $stepProgress = $active !== null && $stepSpan > 0
            ? (($realized - $previous) / $stepSpan) * 100
            : 100.0;

        return [
            'tier_levels' => $clean,
            'tiers_total' => count($clean),
            'achieved_level' => $achieved,
            'current_tier' => $achieved,
            'active_level' => $activeIndex !== null ? $activeIndex + 1 : null,
            'base_target' => $base,
            'active_target' => $active,
            'maximum_target' => $maximum,
            'next_tier_value' => $active,
            'active_gap' => $active !== null ? round(max($active - $realized, 0.0), 2) : 0.0,
            'next_tier_gap' => $active !== null ? round(max($active - $realized, 0.0), 2) : 0.0,
            'base_attainment_pct' => round(($realized / $base) * 100, 2),
            'active_attainment_pct' => $active !== null ? round(($realized / $active) * 100, 2) : 100.0,
            'next_tier_pct' => $active !== null ? round(($realized / $active) * 100, 2) : 100.0,
            'step_progress_pct' => round(max(0.0, min(100.0, $stepProgress)), 2),
            'overall_progress_pct' => round(max(0.0, min(100.0, ($realized / $maximum) * 100)), 2),
            'all_levels_achieved' => $allAchieved,
            'over_maximum_amount' => $allAchieved ? round(max($realized - $maximum, 0.0), 2) : 0.0,
            'over_maximum_pct' => $allAchieved ? round(max(($realized / $maximum - 1) * 100, 0.0), 2) : 0.0,
        ];
    }

    /**
     * Escada inexistente.
     *
     * `base_target` e `maximum_target` sao NULL, nao 0.0 — e a mesma disciplina
     * de `meta_valor` no forecast: "nao ha meta" e "a meta e zero" levam a
     * conclusoes opostas. E aqui o zero nao era so impreciso, era ATIVO: os
     * consumidores fazem `(float) ($ladder['maximum_target'] ?? $targetPeriod)`
     * e `Number(row.base_target || target_)`. Com 0.0 o `??` nao dispara e o
     * fallback para a meta do periodo era silenciosamente perdido; com NULL ele
     * volta a funcionar.
     *
     * O contrato compartilhado com o Python cobre este caso desde que os dois
     * motores divergiram aqui — o PHP dizia 0.0 e o Python dizia None.
     *
     * @return array<string,mixed>
     */
    private static function emptyResult(float $realized): array
    {
        return [
            'tier_levels' => [], 'tiers_total' => 0, 'achieved_level' => 0,
            'current_tier' => 0, 'active_level' => null, 'base_target' => null,
            'active_target' => null, 'maximum_target' => null,
            'next_tier_value' => null, 'active_gap' => 0.0, 'next_tier_gap' => 0.0,
            'base_attainment_pct' => 0.0, 'active_attainment_pct' => 0.0,
            'next_tier_pct' => 0.0, 'step_progress_pct' => 0.0,
            'overall_progress_pct' => 0.0, 'all_levels_achieved' => false,
            'over_maximum_amount' => 0.0, 'over_maximum_pct' => 0.0,
        ];
    }
}
