<?php

declare(strict_types=1);

namespace BIomie\Commercial;

use InvalidArgumentException;

/**
 * COM.R2.1 — Contexto único de período e escopo comercial.
 *
 * Normaliza start/end, scope e channel para que todos os endpoints
 * usem a mesma lógica de interseção de dias por mês e filtros de escopo.
 * Read-only. Sem acesso ao banco.
 */
final class CommercialPeriodScopeContext
{
    // ── Escopos válidos ───────────────────────────────────────────────────────
    public const SCOPE_ORC_PD             = 'orc_pd';
    public const SCOPE_ORCAMENTO          = 'orcamento';
    public const SCOPE_CARTEIRA_ACEITA    = 'carteira_aceita';
    public const SCOPE_FATURADO           = 'faturado';
    public const SCOPE_ENTREGA_FINALIZADA = 'entrega_finalizada';
    public const SCOPE_REMESSA            = 'remessa';
    public const SCOPE_LICITACAO          = 'licitacao';
    public const SCOPE_CANCELADO          = 'cancelado';
    public const SCOPE_DEVOLVIDO          = 'devolvido';
    public const SCOPE_TODOS              = 'todos';

    // ── Canais válidos ────────────────────────────────────────────────────────
    public const CHANNEL_VAREJO        = 'varejo';
    public const CHANNEL_REVENDA       = 'revenda';
    public const CHANNEL_REPRESENTANTE = 'representante';
    public const CHANNEL_LICITACAO     = 'licitacao';
    public const CHANNEL_SEM_CANAL     = 'sem_canal';
    public const CHANNEL_TODOS         = 'todos';

    private const VALID_SCOPES = [
        self::SCOPE_ORC_PD, self::SCOPE_ORCAMENTO, self::SCOPE_CARTEIRA_ACEITA,
        self::SCOPE_FATURADO, self::SCOPE_ENTREGA_FINALIZADA, self::SCOPE_REMESSA,
        self::SCOPE_LICITACAO, self::SCOPE_CANCELADO, self::SCOPE_DEVOLVIDO,
        self::SCOPE_TODOS,
    ];

    private const VALID_CHANNELS = [
        self::CHANNEL_VAREJO, self::CHANNEL_REVENDA, self::CHANNEL_REPRESENTANTE,
        self::CHANNEL_LICITACAO, self::CHANNEL_SEM_CANAL, self::CHANNEL_TODOS,
    ];

    private string $dateStart;
    private string $dateEnd;
    private string $scope;
    private string $channel;
    /** @var array<string,mixed> */
    private array $filters;
    /** @var list<array{month:string,days_in_month:int,days_selected:int,fraction:float}> */
    private array $monthBreakdown;
    private int    $daysPeriod;

    /**
     * @param array<string,mixed> $filters Extra filters (vendedor, cliente, produto, etc.)
     */
    public function __construct(
        string $start,
        string $end,
        string $scope   = self::SCOPE_ORC_PD,
        string $channel = self::CHANNEL_TODOS,
        array  $filters = []
    ) {
        $this->dateStart = $this->normalizeDate($start, 'start');
        $this->dateEnd   = $this->normalizeDate($end,   'end');

        if ($this->dateStart > $this->dateEnd) {
            throw new InvalidArgumentException("start ({$this->dateStart}) deve ser ≤ end ({$this->dateEnd}).");
        }

        $this->scope   = in_array($scope,   self::VALID_SCOPES,   true) ? $scope   : self::SCOPE_ORC_PD;
        $this->channel = in_array($channel, self::VALID_CHANNELS, true) ? $channel : self::CHANNEL_TODOS;
        $this->filters = $filters;

        $this->monthBreakdown = $this->buildMonthBreakdown();
        $this->daysPeriod     = $this->calculateDaysPeriod();
    }

    // ── Factories ─────────────────────────────────────────────────────────────

    /**
     * Build from raw HTTP query parameters (safe — falls back to defaults).
     *
     * @param array<string,mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        $start   = isset($query['start'])   ? (string) $query['start']   : date('Y-01-01');
        $end     = isset($query['end'])     ? (string) $query['end']     : date('Y-m-d');
        $scope   = isset($query['scope'])   ? (string) $query['scope']   : self::SCOPE_ORC_PD;
        $channel = isset($query['channel']) ? (string) $query['channel'] : self::CHANNEL_TODOS;

        $extra = [];
        foreach (['vendedor', 'cliente', 'produto', 'vendor_id'] as $key) {
            if (isset($query[$key]) && (string) $query[$key] !== '') {
                $extra[$key] = (string) $query[$key];
            }
        }

        try {
            return new self($start, $end, $scope, $channel, $extra);
        } catch (InvalidArgumentException) {
            return new self(date('Y-01-01'), date('Y-m-d'), self::SCOPE_ORC_PD, self::CHANNEL_TODOS, []);
        }
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function dateStart(): string { return $this->dateStart; }
    public function dateEnd(): string   { return $this->dateEnd; }
    public function scope(): string     { return $this->scope; }
    public function channel(): string   { return $this->channel; }
    public function daysPeriod(): int   { return $this->daysPeriod; }

    /** @return array<string,mixed> */
    public function filters(): array { return $this->filters; }

    /**
     * @return list<array{month:string,days_in_month:int,days_selected:int,fraction:float}>
     */
    public function monthBreakdown(): array { return $this->monthBreakdown; }

    /** @return list<string> YYYY-MM strings for months touched by the period */
    public function monthsCovered(): array
    {
        return array_column($this->monthBreakdown, 'month');
    }

    /**
     * Proportional target for the period given monthly targets.
     * Formula: Σ (monthly_target × days_selected / days_in_month)
     *
     * @param array<string,float> $monthlyTargets YYYY-MM => float
     */
    public function proportionalTarget(array $monthlyTargets): float
    {
        $total = 0.0;
        foreach ($this->monthBreakdown as $month) {
            $target = (float) ($monthlyTargets[$month['month']] ?? 0.0);
            $total += $target * $month['fraction'];
        }
        return round($total, 2);
    }

    /**
     * Cache/freshness signature for this context.
     */
    public function cacheKey(): string
    {
        return md5(implode('|', [
            $this->dateStart,
            $this->dateEnd,
            $this->scope,
            $this->channel,
            json_encode($this->filters, JSON_THROW_ON_ERROR),
        ]));
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'date_start'      => $this->dateStart,
            'date_end'        => $this->dateEnd,
            'scope'           => $this->scope,
            'channel'         => $this->channel,
            'days_period'     => $this->daysPeriod,
            'months_covered'  => $this->monthsCovered(),
            'month_breakdown' => $this->monthBreakdown,
            'filters'         => $this->filters,
            'cache_key'       => $this->cacheKey(),
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function normalizeDate(string $raw, string $label): string
    {
        $clean = trim($raw);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $clean)) {
            throw new InvalidArgumentException("Data {$label} inválida: '{$clean}'. Use YYYY-MM-DD.");
        }
        if (!checkdate(
            (int) substr($clean, 5, 2),
            (int) substr($clean, 8, 2),
            (int) substr($clean, 0, 4)
        )) {
            throw new InvalidArgumentException("Data {$label} inexistente: '{$clean}'.");
        }
        return $clean;
    }

    /**
     * @return list<array{month:string,days_in_month:int,days_selected:int,fraction:float}>
     */
    private function buildMonthBreakdown(): array
    {
        $result = [];
        $cursor = $this->dateStart;

        while (substr($cursor, 0, 7) <= substr($this->dateEnd, 0, 7)) {
            $ym   = substr($cursor, 0, 7);
            $year = (int) substr($ym, 0, 4);
            $mon  = (int) substr($ym, 5, 2);

            $daysInMonth  = cal_days_in_month(CAL_GREGORIAN, $mon, $year);
            $monthStart   = $ym . '-01';
            $monthEnd     = $ym . '-' . str_pad((string) $daysInMonth, 2, '0', STR_PAD_LEFT);

            $segStart = max($this->dateStart, $monthStart);
            $segEnd   = min($this->dateEnd,   $monthEnd);

            $daysSelected = (int) (
                (strtotime($segEnd) - strtotime($segStart)) / 86400
            ) + 1;

            $result[] = [
                'month'        => $ym,
                'days_in_month' => $daysInMonth,
                'days_selected' => $daysSelected,
                'fraction'     => $daysInMonth > 0 ? round($daysSelected / $daysInMonth, 6) : 0.0,
            ];

            // advance to next month
            $mon++;
            if ($mon > 12) { $mon = 1; $year++; }
            $cursor = sprintf('%04d-%02d-01', $year, $mon);
        }

        return $result;
    }

    private function calculateDaysPeriod(): int
    {
        return (int) ((strtotime($this->dateEnd) - strtotime($this->dateStart)) / 86400) + 1;
    }
}
