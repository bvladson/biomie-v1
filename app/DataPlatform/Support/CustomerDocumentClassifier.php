<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Support;

use BIomie\Support\DocumentMask;

final class CustomerDocumentClassifier
{
    public const TYPE_CNPJ = 'cnpj';
    public const TYPE_CPF = 'cpf';
    public const TYPE_INVALID = 'invalid';
    public const TYPE_UNKNOWN = 'unknown';

    /** @return array{digits:string,type:string,status:string,label:string,masked:string} */
    public static function classify(?string $document, bool $canViewFullDocument = false): array
    {
        $raw = trim((string) $document);
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        $type = self::typeFromDigits($digits);
        $status = match ($type) {
            self::TYPE_CNPJ, self::TYPE_CPF => 'valid_format',
            self::TYPE_INVALID => 'invalid_format',
            default => 'missing',
        };

        return [
            'digits' => $digits,
            'type' => $type,
            'status' => $status,
            'label' => self::label($type),
            'masked' => $canViewFullDocument ? $raw : (DocumentMask::maskDocument($raw, 'Nao informado') ?? 'Nao informado'),
        ];
    }

    public static function typeFromDigits(string $digits): string
    {
        return match (strlen($digits)) {
            14 => self::TYPE_CNPJ,
            11 => self::TYPE_CPF,
            0 => self::TYPE_UNKNOWN,
            default => self::TYPE_INVALID,
        };
    }

    public static function label(string $type): string
    {
        return match ($type) {
            self::TYPE_CNPJ => 'CNPJ',
            self::TYPE_CPF => 'CPF',
            self::TYPE_INVALID => 'Documento invalido',
            default => 'Documento desconhecido',
        };
    }
}
