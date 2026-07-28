<?php

declare(strict_types=1);

namespace BIomie\QuoteAgenda;

use DateTimeImmutable;
use DateTimeZone;

/**
 * AGENDA DE ORÇAMENTOS — cadência de cobrança (Fase 3/4).
 *
 * Régua ABSOLUTA a partir da criação: marcos em created + [1,3,7,14] dias.
 * O atraso do gestor NÃO reinicia o relógio — se ele cobra a 1ª tarde, a 2ª já
 * nasce vencida. É o comportamento desejado num painel de fiscalização.
 *
 * Puro e determinístico: sem I/O, sem banco, sem Omie.
 *
 * FUSO: todo o cálculo roda em America/Fortaleza e o retorno é DIA CIVIL
 * ('Y-m-d'), porque a cobrança é diária — não por minuto. Isso elimina a classe
 * inteira de bugs de virada do dia.
 *
 * FIM DE SEMANA: sábado/domingo avançam para segunda. Feriados NÃO são tratados
 * (não há calendário) — daí o nome explícito rollForwardWeekend().
 */
final class FollowupDatePolicy
{
    public const TZ = 'America/Fortaleza';

    /** @var list<int> */
    private array $cadenceDays;

    /** @param list<int> $cadenceDays */
    public function __construct(
        array $cadenceDays = [1, 3, 7, 14],
        private readonly string $weekendPolicy = 'roll_forward',
        private readonly int $criticalAfterStep = 4,
    ) {
        $days = array_values(array_filter(array_map('intval', $cadenceDays), static fn (int $d): bool => $d >= 0));
        sort($days);
        $this->cadenceDays = $days !== [] ? $days : [1, 3, 7, 14];
    }

    /** @param array<string,mixed> $policyRow linha de biomie_quote_followup_policy */
    public static function fromPolicyRow(array $policyRow): self
    {
        $decoded = json_decode((string) ($policyRow['cadence_days_json'] ?? '[1,3,7,14]'), true);

        return new self(
            is_array($decoded) ? $decoded : [1, 3, 7, 14],
            (string) ($policyRow['weekend_policy'] ?? 'roll_forward'),
            (int) ($policyRow['critical_after_step'] ?? 4),
        );
    }

    public static function timezone(): DateTimeZone
    {
        return new DateTimeZone(self::TZ);
    }

    public function totalSteps(): int
    {
        return count($this->cadenceDays);
    }

    /** @return list<int> */
    public function cadenceDays(): array
    {
        return $this->cadenceDays;
    }

    /**
     * Marco absoluto do passo (1-based) → dia civil 'Y-m-d'.
     * Passo fora da régua é fixado no último marco.
     */
    public function dueOn(DateTimeImmutable $createdAt, int $step): string
    {
        $idx = max(1, min($step, $this->totalSteps())) - 1;
        $due = $createdAt->setTimezone(self::timezone())
            ->setTime(0, 0, 0)
            ->modify('+' . $this->cadenceDays[$idx] . ' days');

        return $this->rollForwardWeekend($due)->format('Y-m-d');
    }

    /**
     * Sábado/domingo → próxima segunda. NÃO trata feriados (por isso o nome).
     */
    public function rollForwardWeekend(DateTimeImmutable $date): DateTimeImmutable
    {
        if ($this->weekendPolicy !== 'roll_forward') {
            return $date;
        }
        while (in_array((int) $date->format('N'), [6, 7], true)) {
            $date = $date->modify('+1 day');
        }

        return $date;
    }

    /**
     * Passo inicial de um orçamento que JÁ existe há algum tempo.
     *
     * Sem isto, um orçamento de 6 meses entraria na agenda como "1ª cobrança
     * amanhã". Semeia pelo maior marco já vencido, comparando DATAS calculadas
     * (não idade numérica), porque o avanço de fim de semana muda o vencimento.
     */
    public function seedStep(DateTimeImmutable $createdAt, ?DateTimeImmutable $today = null): int
    {
        $hoje = ($today ?? new DateTimeImmutable('now', self::timezone()))
            ->setTimezone(self::timezone())->format('Y-m-d');

        $vencidos = 0;
        for ($step = 1; $step <= $this->totalSteps(); $step++) {
            if ($this->dueOn($createdAt, $step) <= $hoje) {
                $vencidos++;
            }
        }

        return max(1, $vencidos);
    }

    /** Um clique de cobrança avança exatamente um passo (limitado ao último). */
    public function nextStep(int $currentStep): int
    {
        return min($currentStep + 1, $this->totalSteps());
    }

    /** Após o último marco o orçamento permanece crítico até sair da etapa. */
    public function isCritical(int $step): bool
    {
        return $step >= $this->criticalAfterStep;
    }

    /**
     * Urgência para as cores do calendário (azul futuro / amarelo hoje /
     * vermelho atrasado / roxo crítico).
     */
    public function urgency(string $dueOn, int $step, ?DateTimeImmutable $today = null): string
    {
        $hoje = ($today ?? new DateTimeImmutable('now', self::timezone()))
            ->setTimezone(self::timezone())->format('Y-m-d');

        if ($this->isCritical($step) && $dueOn <= $hoje) {
            return 'critical';
        }
        if ($dueOn < $hoje) {
            return 'overdue';
        }
        if ($dueOn === $hoje) {
            return 'today';
        }

        return 'future';
    }
}
