<?php

declare(strict_types=1);

namespace BIomie\Support;

final class CustomerDisplayResolver
{
    private const DEFAULT_NAME = 'Cliente nao informado';

    /**
     * @param array<string,mixed> $row
     * @return array{display_name:string,document_masked:?string,source:string,quality:string,missing_reason:?string}
     */
    public static function resolve(array $row): array
    {
        $document = self::firstUsable($row, [
            'cliente_dim_cnpj_cpf',
            'cliente_dim_cpf_cnpj',
            'cliente_dim_documento',
            'nf_dest_cnpj_cpf',
            'nf_ide_cnpj_cpf',
            'pedido_documento_cliente',
            'documento_cliente',
            'cliente_documento',
            'customer_document',
            'client_document',
        ]);
        $documentMasked = DocumentMask::maskDocument($document);

        $candidates = [
            [['cliente_dim_razao_social', 'cliente_dim_nome_cliente', 'dim_nome_cliente'], 'cliente_dim.razao_social', 'strong'],
            [['nf_dest_cRazao', 'nf_dest_crazao', 'nf_dest_razao', 'nf_razao_social', 'nome_cliente', 'cliente', 'client_name'], 'nf_destinatario.cRazao', 'strong'],
            [['cliente_dim_nome_fantasia', 'nome_fantasia', 'cliente_nome_fantasia'], 'cliente_dim.nome_fantasia', 'medium'],
            [['pedido_cliente_nome', 'pedido_nome_cliente'], 'pedido.cliente_nome', 'medium'],
            [['cliente_dim_contato', 'cliente_contato', 'contato'], 'cliente_dim.contato', 'weak'],
        ];

        foreach ($candidates as [$keys, $source, $quality]) {
            $name = self::firstUsable($row, $keys);
            if ($name === null || !self::isUsefulName($name)) {
                continue;
            }

            return [
                'display_name' => trim($name),
                'document_masked' => $documentMasked,
                'source' => $source,
                'quality' => $quality,
                'missing_reason' => null,
            ];
        }

        if ($documentMasked !== null) {
            return [
                'display_name' => $documentMasked,
                'document_masked' => $documentMasked,
                'source' => 'document_fallback',
                'quality' => 'fallback',
                'missing_reason' => null,
            ];
        }

        return [
            'display_name' => self::DEFAULT_NAME,
            'document_masked' => null,
            'source' => 'missing',
            'quality' => 'missing',
            'missing_reason' => 'customer_identity_missing',
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
            if ($value === '' || !self::isUsefulScalar($value)) {
                continue;
            }

            return $value;
        }

        return null;
    }

    private static function isUsefulScalar(string $value): bool
    {
        $normalized = self::normalizeToken($value);
        if (in_array($normalized, ['NULL', 'NULO', '-', '0'], true)) {
            return false;
        }

        return true;
    }

    private static function isUsefulName(string $value): bool
    {
        $normalized = self::normalizeToken($value);
        if (in_array($normalized, [
            'CLIENTE',
            'SEM NOME',
            'CLIENTE SEM NOME',
            'CONSUMIDOR FINAL',
        ], true)) {
            return false;
        }

        if (preg_match('/^CLIENTE\s+N.?O\s+INFORMADO$/u', $normalized) === 1) {
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
