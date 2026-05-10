<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Application\Vendors;

use BIomie\Http;
use DateTimeImmutable;
use InvalidArgumentException;

final class VendorRequest
{
    /**
     * @param array{start:string,end:string,label:string,competencia:?string} $range
     */
    public function __construct(
        public readonly array $range,
        public readonly bool $forceRefresh,
        public readonly int $vendorLimit,
    ) {
    }

    public static function fromGlobals(): self
    {
        $range = self::resolvePeriod();
        $forceRefresh = Http::boolQuery('force', false);
        $vendorLimit = min(100, max(10, (int) (Http::query('limit') ?? '50')));

        return new self($range, $forceRefresh, $vendorLimit);
    }

    public function start(): string
    {
        return $this->range['start'];
    }

    public function end(): string
    {
        return $this->range['end'];
    }

    public function competencia(): ?string
    {
        return $this->range['competencia'] ?? null;
    }

    /**
     * @return array{start:string,end:string,label:string,competencia:?string}
     */
    private static function resolvePeriod(): array
    {
        $competencia = Http::query('competencia');
        if ($competencia !== null && $competencia !== '') {
            if (!preg_match('/^\d{4}-\d{2}$/', $competencia)) {
                throw new InvalidArgumentException('competencia deve seguir o formato YYYY-MM.');
            }

            $month = DateTimeImmutable::createFromFormat('!Y-m', $competencia);
            if (!$month instanceof DateTimeImmutable || $month->format('Y-m') !== $competencia) {
                throw new InvalidArgumentException('competencia deve seguir o formato YYYY-MM.');
            }

            $start = $month->format('Y-m-01');
            $end = $month->modify('last day of this month')->format('Y-m-d');

            return [
                'start' => $start,
                'end' => $end,
                'label' => VendorSupport::formatPeriodLabel($start, $end),
                'competencia' => $competencia,
            ];
        }

        $range = Http::resolveDateRange();
        Http::validateDateRange($range['start'], $range['end']);
        $range['competencia'] = null;

        return $range;
    }
}
