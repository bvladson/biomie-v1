<?php

declare(strict_types=1);

namespace BIomie\Support\Filters;

final class FilterNormalizer
{
    public static function date(string $value): string
    {
        $v = trim($value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    }

    public static function money(string $value): ?float
    {
        $v = trim($value);
        return ($v !== '' && is_numeric($v)) ? (float) $v : null;
    }

    public static function text(string $value, int $maxLen = 200): string
    {
        return mb_substr(trim($value), 0, $maxLen);
    }

    public static function scope(string $value): string
    {
        $v = strtolower(trim($value));
        return in_array($v, ['commercial', 'remittance', 'all'], true) ? $v : 'commercial';
    }

    public static function direction(string $value): string
    {
        return strtolower(trim($value)) === 'asc' ? 'asc' : 'desc';
    }

    /** Returns true/false/null — null means "not provided". */
    public static function boolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') return null;
        $v = strtolower(trim((string) $value));
        if (in_array($v, ['1', 'true', 'yes', 'sim'], true)) return true;
        if (in_array($v, ['0', 'false', 'no', 'nao', 'não'], true)) return false;
        return null;
    }
}
