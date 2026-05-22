<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Gold;

use DateTimeImmutable;
use InvalidArgumentException;

final class MartRefreshWindow
{
    /** @var list<string> */
    private array $competencias;

    private function __construct(
        public readonly string $requestedStart,
        public readonly string $requestedEnd,
        public readonly string $effectiveStart,
        public readonly string $effectiveEnd,
        public readonly string $startCompetencia,
        public readonly string $endCompetencia,
        public readonly bool $isCurrentMonth,
        public readonly bool $isPartialMonth,
        public readonly string $mode,
    ) {
        $this->competencias = self::competenciasBetween($this->startCompetencia, $this->endCompetencia);
    }

    /**
     * @param array{allow_open_month?:bool,force_full_month?:bool} $options
     */
    public static function fromRange(string $start, string $end, array $options = []): self
    {
        $start = trim($start);
        $end = trim($end);
        $startDate = self::parseDate($start, 'start');
        $endDate = self::parseDate($end, 'end');

        if ($startDate > $endDate) {
            throw new InvalidArgumentException('start deve ser anterior ou igual a end.');
        }

        $today = new DateTimeImmutable('today');
        $currentComp = $today->format('Y-m');
        $startComp = $startDate->format('Y-m');
        $endComp = $endDate->format('Y-m');

        $monthStart = $startDate->modify('first day of this month');
        $monthEnd = $endDate->modify('last day of this month');
        $isPartial = $startDate->format('Y-m-d') !== $monthStart->format('Y-m-d')
            || $endDate->format('Y-m-d') !== $monthEnd->format('Y-m-d');
        $isCurrent = $startComp <= $currentComp && $endComp >= $currentComp;
        $allowOpen = !empty($options['allow_open_month']);
        $forceFull = !empty($options['force_full_month']);

        $effectiveStart = $startDate;
        $effectiveEnd = $endDate;
        $mode = 'competencia';

        if ($isPartial) {
            if ($isCurrent && $allowOpen) {
                $mode = 'open_month';
            } elseif ($forceFull) {
                $effectiveStart = $monthStart;
                $effectiveEnd = $monthEnd;
                $startComp = $effectiveStart->format('Y-m');
                $endComp = $effectiveEnd->format('Y-m');
                $mode = 'competencia';
            } else {
                throw new InvalidArgumentException(
                    'Periodo parcial historico recusado. Use --force-full-month para expandir para competencias completas ou --allow-open-month para mes corrente.'
                );
            }
        }

        return new self(
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d'),
            $effectiveStart->format('Y-m-d'),
            $effectiveEnd->format('Y-m-d'),
            $startComp,
            $endComp,
            $isCurrent,
            $isPartial,
            $mode,
        );
    }

    /** @return list<string> */
    public function competencias(): array
    {
        return $this->competencias;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'requested_start' => $this->requestedStart,
            'requested_end' => $this->requestedEnd,
            'effective_start' => $this->effectiveStart,
            'effective_end' => $this->effectiveEnd,
            'start_competencia' => $this->startCompetencia,
            'end_competencia' => $this->endCompetencia,
            'competencias' => $this->competencias,
            'is_current_month' => $this->isCurrentMonth,
            'is_partial_month' => $this->isPartialMonth,
            'mode' => $this->mode,
        ];
    }

    /** @return array{start:string,end:string} */
    public function rangeForCompetencia(string $competencia): array
    {
        $start = $competencia . '-01';
        $end = (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');

        if ($competencia === $this->startCompetencia && $start < $this->effectiveStart) {
            $start = $this->effectiveStart;
        }
        if ($competencia === $this->endCompetencia && $end > $this->effectiveEnd) {
            $end = $this->effectiveEnd;
        }

        return ['start' => $start, 'end' => $end];
    }

    private static function parseDate(string $value, string $name): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("--{$name} deve ter formato YYYY-MM-DD.");
        }
        return $date;
    }

    /** @return list<string> */
    private static function competenciasBetween(string $startComp, string $endComp): array
    {
        $cursor = DateTimeImmutable::createFromFormat('!Y-m-d', $startComp . '-01');
        $limit = DateTimeImmutable::createFromFormat('!Y-m-d', $endComp . '-01');
        if (!$cursor || !$limit) {
            return [];
        }

        $items = [];
        while ($cursor <= $limit) {
            $items[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('+1 month');
        }

        return $items;
    }
}
