<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Support;

final class CustomerIdentityResolver
{
    /** @return array{customer:string,document:string,name:string} */
    public static function normalize(?string $customer, ?string $document = null, ?string $name = null): array
    {
        $customerText = trim((string) $customer);
        $documentDigits = preg_replace('/\D+/', '', (string) $document) ?? '';
        $nameText = trim((string) $name);

        if ($documentDigits === '' && $customerText !== '' && !ctype_digit($customerText)) {
            $possibleDocument = preg_replace('/\D+/', '', $customerText) ?? '';
            if (in_array(strlen($possibleDocument), [11, 14], true)) {
                $documentDigits = $possibleDocument;
            }
        }

        return [
            'customer' => $customerText,
            'document' => $documentDigits,
            'name' => $nameText,
        ];
    }

    public static function customerKey(?string $codigoClienteOmie, ?string $document = null, ?string $name = null): string
    {
        $codigo = trim((string) $codigoClienteOmie);
        if ($codigo !== '' && $codigo !== '0') {
            return 'omie:' . $codigo;
        }

        $digits = preg_replace('/\D+/', '', (string) $document) ?? '';
        if ($digits !== '') {
            return 'doc:' . $digits;
        }

        $nameText = strtolower(trim((string) $name));
        return $nameText !== '' ? 'name:' . sha1($nameText) : 'unknown';
    }
}
