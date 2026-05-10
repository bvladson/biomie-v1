<?php

declare(strict_types=1);

namespace BIomie\DataGovernance;

use DateTimeImmutable;

/**
 * Política oficial de classificação de recorrência de clientes.
 *
 * Definições canônicas:
 *
 *   novo        — primeira compra dentro do período analisado; menos de 2 meses distintos.
 *   recorrente  — comprou em 2+ meses distintos nos últimos 12 meses E comprourecentemente (≤ DORMANT_DAYS).
 *   dormente    — foi recorrente mas não compra há mais de DORMANT_DAYS.
 *   inativo     — última compra há mais de 365 dias ou nunca comprou.
 *
 * Limiares configuráveis via constantes; nunca dispersar esses valores em queries.
 */
final class RecurrencePolicy
{
    /** Mínimo de meses distintos nos últimos 12 para ser "recorrente". */
    public const MIN_MONTHS_RECURRING = 2;

    /** Dias sem compra para um recorrente virar "dormente". */
    public const DORMANT_DAYS = 90;

    /** Dias sem compra para qualquer cliente virar "inativo". */
    public const INACTIVE_DAYS = 365;

    // Valores canônicos de recorrencia_faixa.
    public const NOVO       = 'novo';
    public const RECORRENTE = 'recorrente';
    public const DORMENTE   = 'dormente';
    public const INATIVO    = 'inativo';

    /**
     * Classifica a recorrência do cliente a partir de estatísticas pré-computadas.
     *
     * @param string|null $ultimaCompraEm       Data ISO da última compra (YYYY-MM-DD)
     * @param int         $mesesDistintos12m    Qtd. de meses distintos com compra nos últimos 12 meses
     */
    public static function classify(
        ?string $ultimaCompraEm,
        int $mesesDistintos12m,
    ): string {
        if ($ultimaCompraEm === null) {
            return self::INATIVO;
        }

        $lastDate = DateTimeImmutable::createFromFormat('Y-m-d', $ultimaCompraEm)
            ?: new DateTimeImmutable($ultimaCompraEm);

        $daysSinceLast = (new DateTimeImmutable('today'))->diff($lastDate)->days;

        if ($daysSinceLast >= self::INACTIVE_DAYS) {
            return self::INATIVO;
        }

        if ($mesesDistintos12m >= self::MIN_MONTHS_RECURRING) {
            return $daysSinceLast <= self::DORMANT_DAYS
                ? self::RECORRENTE
                : self::DORMENTE;
        }

        return self::NOVO;
    }

    /**
     * Retorna true se o cliente é considerado ativo (comprou nos últimos DORMANT_DAYS).
     */
    public static function isActive(?string $ultimaCompraEm): bool
    {
        if ($ultimaCompraEm === null) {
            return false;
        }

        $lastDate = DateTimeImmutable::createFromFormat('Y-m-d', $ultimaCompraEm)
            ?: new DateTimeImmutable($ultimaCompraEm);

        return (new DateTimeImmutable('today'))->diff($lastDate)->days <= self::DORMANT_DAYS;
    }

    /**
     * Retorna true quando o cliente é recorrente ou dormente
     * (ou seja, já demonstrou padrão de recompra).
     */
    public static function hasRepeatPattern(string $faixa): bool
    {
        return in_array($faixa, [self::RECORRENTE, self::DORMENTE], true);
    }

    /**
     * Rótulo legível para exibição no dashboard.
     */
    public static function label(string $faixa): string
    {
        return match ($faixa) {
            self::RECORRENTE => 'Recorrente',
            self::DORMENTE   => 'Dormente (era recorrente)',
            self::NOVO       => 'Novo',
            self::INATIVO    => 'Inativo',
            default          => 'Desconhecido',
        };
    }

    /**
     * Definição completa dos limiares — exposta para endpoints de observabilidade.
     *
     * @return array<string,mixed>
     */
    public static function thresholds(): array
    {
        return [
            'min_months_recurring' => self::MIN_MONTHS_RECURRING,
            'dormant_days'         => self::DORMANT_DAYS,
            'inactive_days'        => self::INACTIVE_DAYS,
            'classes'              => [self::NOVO, self::RECORRENTE, self::DORMENTE, self::INATIVO],
        ];
    }
}
