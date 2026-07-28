<?php

declare(strict_types=1);

namespace BIomie\Support;

final class SellerDisplayResolver
{
    private const DEFAULT_NAME = 'Sem vendedor';

    /**
     * @param array<string,mixed> $row
     * @return array{display_name:string,source:string,quality:string,missing_reason:?string}
     */
    public static function resolve(array $row): array
    {
        $sellerCode = self::firstUsable($row, [
            'vendedor_codigo',
            'codigo_vendedor_omie',
            'pedido_codVend',
            'codVend',
            'vendor_id',
            'vendor_ref',
        ]);

        $candidates = [
            [['vendedor_dim_nome', 'vendedor_nome'], 'vendedores.nome', 'strong'],
            [['pedido_vendedor_nome', 'nome_vendedor', 'vendor_name'], 'pedido.vendedor_nome', 'medium'],
        ];

        foreach ($candidates as [$keys, $source, $quality]) {
            $name = self::firstUsable($row, $keys);
            if ($name === null || !self::isUsefulName($name)) {
                continue;
            }

            return [
                'display_name' => trim($name),
                'source' => $source,
                'quality' => $quality,
                'missing_reason' => null,
            ];
        }

        return [
            'display_name' => self::DEFAULT_NAME,
            'source' => 'missing',
            'quality' => 'missing',
            'missing_reason' => $sellerCode !== null ? 'seller_code_not_found' : 'seller_code_missing',
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param list<string> $keys
     */
    private static function firstUsable(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }

            $value = trim((string) ($row[$key] ?? ''));
            if ($value === '' || in_array(self::normalizeToken($value), ['NULL', 'NULO', '-', '0'], true)) {
                continue;
            }

            return $value;
        }

        return null;
    }

    private static function isUsefulName(string $value): bool
    {
        $normalized = self::normalizeToken($value);
        if (in_array($normalized, ['SEM VENDEDOR', 'VENDEDOR', 'SEM NOME'], true)) {
            return false;
        }

        return true;
    }

    private static function normalizeToken(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtoupper($value, 'UTF-8');
    }
}
