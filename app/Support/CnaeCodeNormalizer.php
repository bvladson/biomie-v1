<?php

declare(strict_types=1);

namespace BIomie\Support;

final class CnaeCodeNormalizer
{
    /** @var list<string> */
    private const INVALID_DIGITS = [
        '',
        '0',
        '00',
        '000',
        '0000',
        '0000000',
        '00000000',
    ];

    public static function digits(?string $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }

        $lower = mb_strtolower($raw);
        if (in_array($lower, ['sem cnae', 'sem_cnae', 'nao informado', 'não informado', 'pj sem cnae'], true)) {
            return '';
        }

        return preg_replace('/\D+/', '', $raw) ?? '';
    }

    public static function normalizeSubclass(?string $value): string
    {
        $digits = self::digits($value);
        if (!self::isValidSubclassDigits($digits)) {
            return '';
        }

        return $digits;
    }

    public static function isValidSubclass(?string $value): bool
    {
        return self::isValidSubclassDigits(self::digits($value));
    }

    public static function formatSubclass(?string $value): string
    {
        $digits = self::normalizeSubclass($value);
        if ($digits === '') {
            return '';
        }

        return substr($digits, 0, 4) . '-' . substr($digits, 4, 1) . '/' . substr($digits, 5, 2);
    }

    public static function formatClasse(?string $value): string
    {
        $digits = self::digits($value);
        if (strlen($digits) < 5) {
            return trim((string) $value);
        }

        return substr($digits, 0, 2) . '.' . substr($digits, 2, 2) . '-' . substr($digits, 4, 1);
    }

    public static function classFromSubclass(?string $value): string
    {
        $digits = self::normalizeSubclass($value);
        if ($digits === '') {
            return '';
        }

        return substr($digits, 0, 5);
    }

    public static function groupFromSubclass(?string $value): string
    {
        $digits = self::normalizeSubclass($value);
        if ($digits === '') {
            return '';
        }

        return substr($digits, 0, 3);
    }

    public static function divisionFromSubclass(?string $value): string
    {
        $digits = self::normalizeSubclass($value);
        if ($digits === '') {
            return '';
        }

        return substr($digits, 0, 2);
    }

    private static function isValidSubclassDigits(string $digits): bool
    {
        if (in_array($digits, self::INVALID_DIGITS, true)) {
            return false;
        }

        if (strlen($digits) !== 7) {
            return false;
        }

        return ltrim($digits, '0') !== '';
    }
}
