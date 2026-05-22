<?php

declare(strict_types=1);

namespace BIomie\Support;

final class NeighborhoodNormalizer
{
    /** @return array<string,string> */
    private static function accentMap(): array
    {
        return [
            "\u{00E1}" => 'a', "\u{00E0}" => 'a', "\u{00E2}" => 'a', "\u{00E3}" => 'a', "\u{00E4}" => 'a', "\u{00E5}" => 'a',
            "\u{00E9}" => 'e', "\u{00E8}" => 'e', "\u{00EA}" => 'e', "\u{00EB}" => 'e',
            "\u{00ED}" => 'i', "\u{00EC}" => 'i', "\u{00EE}" => 'i', "\u{00EF}" => 'i',
            "\u{00F3}" => 'o', "\u{00F2}" => 'o', "\u{00F4}" => 'o', "\u{00F5}" => 'o', "\u{00F6}" => 'o',
            "\u{00FA}" => 'u', "\u{00F9}" => 'u', "\u{00FB}" => 'u', "\u{00FC}" => 'u',
            "\u{00E7}" => 'c', "\u{00F1}" => 'n',
            "\u{00C1}" => 'A', "\u{00C0}" => 'A', "\u{00C2}" => 'A', "\u{00C3}" => 'A', "\u{00C4}" => 'A', "\u{00C5}" => 'A',
            "\u{00C9}" => 'E', "\u{00C8}" => 'E', "\u{00CA}" => 'E', "\u{00CB}" => 'E',
            "\u{00CD}" => 'I', "\u{00CC}" => 'I', "\u{00CE}" => 'I', "\u{00CF}" => 'I',
            "\u{00D3}" => 'O', "\u{00D2}" => 'O', "\u{00D4}" => 'O', "\u{00D5}" => 'O', "\u{00D6}" => 'O',
            "\u{00DA}" => 'U', "\u{00D9}" => 'U', "\u{00DB}" => 'U', "\u{00DC}" => 'U',
            "\u{00C7}" => 'C', "\u{00D1}" => 'N',
        ];
    }

    public static function normalizeKey(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return '';
        }

        $withoutAccents = self::removeAccents($trimmed);
        $lower = strtolower($withoutAccents);
        $punctuationAsSpace = preg_replace('/[^a-z0-9\s]+/', ' ', $lower) ?? $lower;
        $collapsed = self::collapseSpaces($punctuationAsSpace);

        return self::applyAlias($collapsed);
    }

    public static function displayName(?string $value): string
    {
        $display = trim((string) ($value ?? ''));
        $display = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $display) ?? $display;
        $display = preg_replace('/\s+/u', ' ', $display) ?? $display;
        return trim($display);
    }

    public static function applyAlias(string $normalizedKey): string
    {
        $record = NeighborhoodAliases::find($normalizedKey);
        return $record['canonical_key'] ?? $normalizedKey;
    }

    public static function normalizeCity(?string $value): string
    {
        $city = self::displayName($value);
        $city = preg_replace('/\s*\([A-Za-z]{2}\)\s*$/', '', $city) ?? $city;
        $city = preg_replace('/\s*[-\/]\s*[A-Za-z]{2}\s*$/', '', $city) ?? $city;
        return self::normalizeKey($city);
    }

    public static function isFortaleza(?string $city): bool
    {
        return self::normalizeCity($city) === 'fortaleza';
    }

    /**
     * @return array{
     *   original:string|null,
     *   trimmed:string,
     *   without_accents:string,
     *   lower:string,
     *   punctuation_as_space:string,
     *   collapsed_spaces:string,
     *   alias_applied:array{alias_key:string,canonical_key:string,motivo:string,origem:string,ativo:bool}|null,
     *   final:string,
     *   normalizer_engine:string
     * }
     */
    public static function explain(?string $value): array
    {
        $trimmed = $value === null ? '' : trim((string) $value);
        $withoutAccents = $trimmed === '' ? '' : self::removeAccents($trimmed);
        $lower = strtolower($withoutAccents);
        $punctuationAsSpace = preg_replace('/[^a-z0-9\s]+/', ' ', $lower) ?? $lower;
        $collapsed = self::collapseSpaces($punctuationAsSpace);
        $alias = $collapsed !== '' ? NeighborhoodAliases::find($collapsed) : null;

        return [
            'original' => $value,
            'trimmed' => $trimmed,
            'without_accents' => $withoutAccents,
            'lower' => $lower,
            'punctuation_as_space' => $punctuationAsSpace,
            'collapsed_spaces' => $collapsed,
            'alias_applied' => $alias,
            'final' => $alias['canonical_key'] ?? $collapsed,
            'normalizer_engine' => self::normalizerEngine(),
        ];
    }

    /** @return list<array{alias_key:string,canonical_key:string,motivo:string,origem:string,ativo:bool}> */
    public static function aliases(): array
    {
        return array_values(NeighborhoodAliases::records());
    }

    public static function matchType(?string $original, string $canonicalKey, ?string $officialDisplayName = null): array
    {
        $explain = self::explain($original);
        $trimmed = $explain['trimmed'];
        if ($trimmed === '') {
            return ['match_type' => 'empty', 'confidence' => 0.0, 'normalized' => '', 'canonical' => ''];
        }

        $normalized = $explain['collapsed_spaces'];
        $final = $explain['final'];
        if ($canonicalKey === '') {
            return ['match_type' => 'missing', 'confidence' => 0.0, 'normalized' => $normalized, 'canonical' => $final];
        }

        if ($officialDisplayName !== null && self::displayName($original) === self::displayName($officialDisplayName)) {
            return ['match_type' => 'exact', 'confidence' => 1.0, 'normalized' => $normalized, 'canonical' => $final];
        }

        if ($explain['alias_applied'] !== null && $final === $canonicalKey) {
            return ['match_type' => 'alias', 'confidence' => 0.85, 'normalized' => $normalized, 'canonical' => $final];
        }

        if ($final === $canonicalKey) {
            return ['match_type' => 'normalized', 'confidence' => 0.95, 'normalized' => $normalized, 'canonical' => $final];
        }

        return ['match_type' => 'missing', 'confidence' => 0.0, 'normalized' => $normalized, 'canonical' => $final];
    }

    private static function removeAccents(string $value): string
    {
        if (class_exists('\Normalizer')) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_D);
            if (is_string($normalized)) {
                return preg_replace('/\p{Mn}+/u', '', $normalized) ?? $normalized;
            }
        }

        if (function_exists('transliterator_transliterate')) {
            $transliterated = transliterator_transliterate('Any-Latin; Latin-ASCII; [:Nonspacing Mark:] Remove; NFC;', $value);
            if (is_string($transliterated) && $transliterated !== '') {
                return $transliterated;
            }
        }

        return strtr($value, self::accentMap());
    }

    private static function collapseSpaces(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        return trim($value);
    }

    private static function normalizerEngine(): string
    {
        if (class_exists('\Normalizer')) {
            return 'intl_normalizer';
        }
        if (function_exists('transliterator_transliterate')) {
            return 'intl_transliterator';
        }
        return 'strtr_pt_br';
    }
}
