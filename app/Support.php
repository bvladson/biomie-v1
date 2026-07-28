<?php

declare(strict_types=1);

namespace BIomie;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;

final class Support
{
    public static function money(float|int $value): float
    {
        return round((float) $value, 2);
    }

    public static function toFloat(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $normalized = str_replace(['.', ','], ['', '.'], preg_replace('/[^0-9,.-]/', '', $value) ?? '0');
            return is_numeric($normalized) ? (float) $normalized : 0.0;
        }

        return 0.0;
    }

    public static function getByPaths(array $array, array $paths, mixed $default = null): mixed
    {
        foreach ($paths as $path) {
            $value = self::get($array, $path);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $default;
    }

    public static function get(array $array, string $path, mixed $default = null): mixed
    {
        $segments = explode('.', $path);
        $value = $array;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public static function findFirstByKeys(array $array, array $keys, mixed $default = null): mixed
    {
        foreach ($keys as $key) {
            $value = self::findFirstByKey($array, $key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $default;
    }

    public static function findFirstByKey(array $array, string $key, mixed $default = null): mixed
    {
        foreach ($array as $currentKey => $value) {
            if ((string) $currentKey === $key && $value !== null && $value !== '') {
                return $value;
            }

            if (is_array($value)) {
                $nested = self::findFirstByKey($value, $key, null);
                if ($nested !== null && $nested !== '') {
                    return $nested;
                }
            }
        }

        return $default;
    }

    public static function formatDate(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $formats = ['d/m/Y', 'Y-m-d', DATE_ATOM, 'Y-m-d H:i:s'];
        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date instanceof DateTimeImmutable) {
                return $date->format('Y-m-d');
            }
        }

        $timestamp = strtotime($value);
        return $timestamp ? date('Y-m-d', $timestamp) : null;
    }

    public static function formatDateTime(?string $date, ?string $time = null): ?string
    {
        $formattedDate = self::formatDate($date);
        if ($formattedDate === null) {
            return null;
        }

        $normalizedTime = trim((string) ($time ?? ''));
        if ($normalizedTime === '') {
            $normalizedTime = '00:00:00';
        }

        if (preg_match('/^\d{2}:\d{2}$/', $normalizedTime) === 1) {
            $normalizedTime .= ':00';
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $normalizedTime) !== 1) {
            return $formattedDate . ' 00:00:00';
        }

        return $formattedDate . ' ' . $normalizedTime;
    }

    public static function digitsOnly(mixed $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    public static function eachDate(string $start, string $end): array
    {
        $dates = [];
        $startDate = new DateTimeImmutable($start);
        $endDate = new DateTimeImmutable($end);
        $period = new DatePeriod($startDate, new DateInterval('P1D'), $endDate->modify('+1 day'));
        foreach ($period as $date) {
            $dates[] = $date->format('Y-m-d');
        }
        return $dates;
    }
}
