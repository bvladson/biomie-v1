<?php

declare(strict_types=1);

namespace BIomie\Support;

use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Centralized filter parsing for map/geo APIs.
 *
 * Reads date_from/date_to (plus aliases), city, neighborhood, cnae, seller_id.
 * Validates dates, normalises city/bairro keys, detects the date column to use.
 */
final class MapFilterSupport
{
    /** Ordered by preference — first match wins. */
    private const DATE_COLUMN_CANDIDATES = [
        'competencia', 'data_referencia', 'data_competencia',
        'data_faturamento', 'data_emissao', 'data_movimento',
        'periodo', 'year_month', 'created_at',
    ];

    /**
     * Parse all filter parameters from $_GET (or any array).
     *
     * Falls back to year-to-date when dates are absent or invalid.
     *
     * @param array<string,mixed> $get
     * @return array{
     *   date_from:string, date_to:string,
     *   city:string|null, neighborhood:string|null,
     *   cnae:string|null, seller_id:string|null,
     *   start_comp:string, end_comp:string,
     *   filters_received:array, filters_normalized:array,
     * }
     */
    public static function fromRequest(array $get): array
    {
        $rawFrom    = self::first($get, ['date_from', 'start', 'data_inicio']);
        $rawTo      = self::first($get, ['date_to', 'end', 'data_fim']);
        $rawCity    = self::first($get, ['city', 'cidade']) ?? '';
        $rawNeighbor = self::first($get, ['neighborhood', 'bairro']) ?? '';
        $rawCnae    = trim((string) ($get['cnae'] ?? ''));
        $rawSeller  = self::first($get, ['seller_id', 'vendedor_id']) ?? '';

        $dateFrom = self::parseDate($rawFrom ?? '');
        $dateTo   = self::parseDate($rawTo ?? '');

        if ($dateFrom === null || $dateTo === null) {
            // Default: year-to-date (Jan 1 of current year → today)
            $today    = new DateTimeImmutable('today');
            $dateFrom = (new DateTimeImmutable('first day of January this year'))->format('Y-m-d');
            $dateTo   = $today->format('Y-m-d');
        } elseif ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $cityNorm    = $rawCity !== '' ? GeoMapSupport::normalizeKey(self::stripStateSuffix($rawCity)) : null;
        $bairroNorm  = $rawNeighbor !== '' ? GeoMapSupport::normalizeKey($rawNeighbor) : null;
        $cnae        = $rawCnae !== '' ? $rawCnae : null;
        $sellerId    = $rawSeller !== '' ? $rawSeller : null;

        $normalized = [
            'date_from'    => $dateFrom,
            'date_to'      => $dateTo,
            'city'         => $cityNorm,
            'neighborhood' => $bairroNorm,
            'cnae'         => $cnae,
            'seller_id'    => $sellerId,
        ];

        return [
            'date_from'          => $dateFrom,
            'date_to'            => $dateTo,
            'city'               => $cityNorm,
            'neighborhood'       => $bairroNorm,
            'cnae'               => $cnae,
            'seller_id'          => $sellerId,
            'start_comp'         => substr($dateFrom, 0, 7),
            'end_comp'           => substr($dateTo, 0, 7),
            'filters_received'   => [
                'date_from'    => $rawFrom,
                'date_to'      => $rawTo,
                'city'         => $rawCity !== '' ? $rawCity : null,
                'neighborhood' => $rawNeighbor !== '' ? $rawNeighbor : null,
                'cnae'         => $rawCnae !== '' ? $rawCnae : null,
                'seller_id'    => $rawSeller !== '' ? $rawSeller : null,
            ],
            'filters_normalized' => $normalized,
        ];
    }

    /**
     * Detect which date column exists in $table, ordered by priority.
     */
    public static function detectDateColumn(PDO $pdo, string $table): ?string
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
            );
            $stmt->execute([$table]);
            $existing = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

            foreach (self::DATE_COLUMN_CANDIDATES as $candidate) {
                if (in_array($candidate, $existing, true)) {
                    return $candidate;
                }
            }
        } catch (Throwable) {}
        return null;
    }

    /**
     * Build a WHERE-clause fragment for the detected date column.
     *
     * @return array{0:string|null, 1:array<string,string>, 2:string|null}
     *   [sql_fragment, bind_params, date_column_used]
     */
    public static function buildDateFilter(
        ?string $dateCol,
        string $startComp,
        string $endComp,
        string $dateFrom,
        string $dateTo,
        string $tableAlias = '',
    ): array {
        if ($dateCol === null) {
            return [null, [], null];
        }

        $colExpr = $tableAlias !== '' ? "{$tableAlias}.{$dateCol}" : $dateCol;

        if (in_array($dateCol, ['competencia', 'periodo', 'year_month'], true)) {
            return [
                "{$colExpr} BETWEEN :date_comp_from AND :date_comp_to",
                ['date_comp_from' => $startComp, 'date_comp_to' => $endComp],
                $dateCol,
            ];
        }

        return [
            "DATE({$colExpr}) BETWEEN :date_from AND :date_to",
            ['date_from' => $dateFrom, 'date_to' => $dateTo],
            $dateCol,
        ];
    }

    /**
     * Probe available period range in a mart table.
     *
     * @return array{min:string|null,max:string|null}
     */
    public static function probePeriodRange(PDO $pdo, string $table, string $dateCol): array
    {
        try {
            $row = $pdo->query(
                "SELECT MIN({$dateCol}) AS min_d, MAX({$dateCol}) AS max_d FROM `{$table}`"
            )?->fetch(PDO::FETCH_ASSOC);
            return [
                'min' => ($row['min_d'] ?? null) ?: null,
                'max' => ($row['max_d'] ?? null) ?: null,
            ];
        } catch (Throwable) {
            return ['min' => null, 'max' => null];
        }
    }

    /**
     * Count mart rows without the date filter to detect divergence.
     */
    public static function countMartRows(PDO $pdo, string $table): int
    {
        try {
            return (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")?->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private static function stripStateSuffix(string $value): string
    {
        return preg_replace('/\s*\([A-Za-z]{2}\)\s*$/', '', trim($value)) ?? $value;
    }

    private static function parseDate(string $value): ?string
    {
        if (trim($value) === '') {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($dt === false || $dt->format('Y-m-d') !== $value) {
            return null;
        }
        return $value;
    }

    /**
     * @param array<string,mixed> $source
     * @param list<string> $keys
     */
    private static function first(array $source, array $keys): ?string
    {
        foreach ($keys as $k) {
            $v = $source[$k] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }
        return null;
    }
}
