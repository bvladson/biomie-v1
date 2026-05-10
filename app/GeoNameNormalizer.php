<?php

declare(strict_types=1);

namespace BIomie;

/**
 * Normalizes city and neighborhood names for fuzzy matching against the
 * official Fortaleza bairros dimension.
 *
 * Uses the same accent-removal and whitespace-collapsing logic as
 * GeoMapSupport::normalizeKey() so that PHP-side normalization is
 * consistent with what the frontend sends and what the DB stores.
 */
final class GeoNameNormalizer
{
    /** Known aliases: raw (normalized) → canonical normalized form */
    private const ALIASES = [
        'joquei clube'              => 'joquei clube',
        'jokey clube'               => 'joquei clube',
        'jockey clube'              => 'joquei clube',
        'praia do futuro'           => 'praia do futuro i',
        'sapiranga coite'           => 'sapiranga coite',
        'sapiranga'                 => 'sapiranga coite',
        'coite'                     => 'sapiranga coite',
        'boa vista castelao'        => 'boa vista castelao',
        'castelao'                  => 'boa vista castelao',
        'conjunto ceara'            => 'conjunto ceara i',
        'cidade dos funcionarios'   => 'cidade dos funcionarios',
        'eng luciano cavalcante'    => 'engenheiro luciano cavalcante',
        'eng luciano'               => 'engenheiro luciano cavalcante',
        'luciano cavalcante'        => 'engenheiro luciano cavalcante',
        'planalto ayrton senna'     => 'planalto ayrton senna',
        'planalto senna'            => 'planalto ayrton senna',
        'pref jose walter'          => 'prefeito jose walter',
        'presidente jose walter'    => 'prefeito jose walter',
        'parque araçagi'            => 'parque aracagi',
        'parque aracagi'            => 'parque aracagi',
        'alvaro weyne'              => 'alvaro weyne',
        'antonio bezerra'           => 'antonio bezerra',
        'democrito rocha'           => 'democrito rocha',
        'dionisio torres'           => 'dionisio torres',
        'joao xxiii'                => 'joao xxiii',
        'rachel de queiroz'         => 'rachel de queiroz',
        'sao gerardo'               => 'sao gerardo',
        'sao bento'                 => 'sao bento',
        'parque sao jose'           => 'parque sao jose',
        'parque santa rosa'         => 'parque santa rosa',
        'parque santa maria'        => 'parque santa maria',
    ];

    /**
     * @param string|null $city  When provided, Fortaleza-specific aliases are only applied
     *                           if the city normalizes to 'fortaleza'. Pass null to always apply.
     */
    public static function normalizeNeighborhood(?string $value, ?string $city = null): string
    {
        $key = self::normalizeKey($value);
        if ($city !== null && self::normalizeCity($city) !== 'fortaleza') {
            return $key;
        }
        return self::ALIASES[$key] ?? $key;
    }

    public static function normalizeCity(?string $value): string
    {
        $v = trim((string) ($value ?? ''));
        // Strip trailing state abbreviation: "FORTALEZA (CE)" → "FORTALEZA"
        $v = preg_replace('/\s*\([A-Za-z]{2}\)\s*$/', '', $v) ?? $v;
        return self::normalizeKey($v);
    }

    public static function normalizeKey(?string $value): string
    {
        $v = trim((string) ($value ?? ''));
        $v = strtr($v, [
            'Á' => 'A', 'À' => 'A', "\u{00C2}" => 'A', "\u{00C3}" => 'A', 'Ä' => 'A',
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'Ç' => 'C', 'ç' => 'c',
        ]);
        $v = strtolower($v);
        $v = preg_replace('/[^a-z0-9 ]+/', '', $v) ?? $v;
        $v = preg_replace('/\s+/', ' ', $v) ?? $v;
        return trim($v);
    }
}
