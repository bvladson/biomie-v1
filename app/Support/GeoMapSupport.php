<?php

declare(strict_types=1);

namespace BIomie\Support;

use BIomie\Http;
use DateTimeImmutable;

final class GeoMapSupport
{
    /**
     * @return array{date_from:string,date_to:string,start:string,end:string}
     */
    public static function resolveDateRange(): array
    {
        $from = Http::query('date_from') ?: Http::query('start');
        $to = Http::query('date_to') ?: Http::query('end');

        if ($from === null || $from === '' || $to === null || $to === '') {
            $today = new DateTimeImmutable('today');
            $from = $today->modify('first day of this month')->format('Y-m-d');
            $to = $today->format('Y-m-d');
        }

        Http::validateDateRange($from, $to);

        return [
            'date_from' => $from,
            'date_to' => $to,
            'start' => $from,
            'end' => $to,
        ];
    }

    public static function filterValue(string $canonical, string $legacy = ''): ?string
    {
        $value = Http::query($canonical);
        if (($value === null || $value === '') && $legacy !== '') {
            $value = Http::query($legacy);
        }

        $value = trim((string) ($value ?? ''));
        return $value !== '' && strtoupper($value) !== 'ALL' ? $value : null;
    }

    public static function normalizeKey(?string $value): string
    {
        return NeighborhoodNormalizer::normalizeKey($value);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{metric:string,min:float,max:float,buckets:list<array<string,float|string|null>>}
     */
    public static function revenueScale(array $rows): array
    {
        $values = array_map(static fn (array $row): float => (float) ($row['revenue'] ?? 0), $rows);
        $max = $values !== [] ? max($values) : 0.0;
        $low = $max > 0 ? round($max * 0.33, 2) : 0.0;
        $mid = $max > 0 ? round($max * 0.66, 2) : 0.0;

        return [
            'metric' => 'revenue',
            'min' => 0.0,
            'max' => (float) $max,
            'buckets' => [
                ['label' => 'Sem dados', 'min' => null, 'max' => null],
                ['label' => 'Baixo', 'min' => 0.0, 'max' => $low],
                ['label' => 'Medio', 'min' => $low, 'max' => $mid],
                ['label' => 'Alto', 'min' => $mid, 'max' => (float) $max],
            ],
        ];
    }

    /**
     * @return array{title:string,message:string,action:string}
     */
    public static function emptyState(): array
    {
        return [
            'title' => 'Nenhum dado encontrado',
            'message' => 'Nao encontramos vendas para os filtros selecionados.',
            'action' => 'Altere o periodo ou revise os filtros.',
        ];
    }
}
