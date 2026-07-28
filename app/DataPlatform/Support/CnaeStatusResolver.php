<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Support;

use BIomie\Support\CnaeCodeNormalizer;

final class CnaeStatusResolver
{
    public const OK = 'ok';
    public const MISSING_CNPJ = 'missing_cnpj';
    public const NOT_APPLICABLE_CPF = 'not_applicable_cpf';
    public const INVALID_DOCUMENT = 'invalid_document';
    public const UNKNOWN = 'unknown';

    /** @return list<string> */
    public static function statuses(): array
    {
        return [
            self::OK,
            self::MISSING_CNPJ,
            self::NOT_APPLICABLE_CPF,
            self::INVALID_DOCUMENT,
            self::UNKNOWN,
        ];
    }

    public static function documentType(?string $document): string
    {
        $digits = preg_replace('/\D+/', '', (string) $document) ?? '';
        return match (strlen($digits)) {
            14 => 'cnpj',
            11 => 'cpf',
            0 => 'unknown',
            default => 'invalid',
        };
    }

    public static function resolve(?string $document, ?string $cnae): string
    {
        return match (self::documentType($document)) {
            'cnpj' => CnaeCodeNormalizer::isValidSubclass($cnae) ? self::OK : self::MISSING_CNPJ,
            'cpf' => self::NOT_APPLICABLE_CPF,
            'invalid' => self::INVALID_DOCUMENT,
            default => self::UNKNOWN,
        };
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::OK => 'CNAE informado',
            self::MISSING_CNPJ => 'CNPJ sem CNAE',
            self::NOT_APPLICABLE_CPF => 'CPF - CNAE nao aplicavel',
            self::INVALID_DOCUMENT => 'Documento invalido',
            default => 'Status desconhecido',
        };
    }
}
