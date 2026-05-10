<?php

declare(strict_types=1);

namespace BIomie\Acl;

use BIomie\Support\DocumentMask;

/**
 * Mascara dados pessoais em respostas da API para usuários sem a capacidade
 * 'documents.full'. Segue o princípio de minimização de dados da LGPD (art. 6º, III).
 *
 * Formatos mascarados:
 *   CPF  11 dígitos → ***.***.***-**
 *   CNPJ 14 dígitos → 12.345.678/****-**
 *   Outros          → *** (substituição total)
 *   E-mail          → u****@domain.com
 *
 * Campos reconhecidos automaticamente em arrays:
 *   documento_cliente, client_document, cpf_cnpj, documento_normalizado,
 *   cnpj, cpf, document, email (quando fora de um contexto de login)
 */
final class DataMasker
{
    /** Nomes de campo que contêm documentos (CPF/CNPJ) e devem ser mascarados */
    private const DOCUMENT_FIELDS = [
        'documento_cliente',
        'cliente_documento',
        'client_document',
        'cpf_cnpj',
        'documento_normalizado',
        'document',
        'cnpj',
        'cpf',
    ];

    /** Nomes de campo que contêm e-mail pessoal */
    private const EMAIL_FIELDS = [
        'email_cliente',
        'client_email',
    ];

    /**
     * Mascara um documento (CPF ou CNPJ) preservando dígitos do meio.
     *
     * Aceita tanto formatado quanto somente dígitos.
     */
    public static function maskDocument(string $value): string
    {
        if ($value === '' || $value === '-') {
            return $value;
        }

        return DocumentMask::maskDocument($value, '***') ?? '***';
    }

    /**
     * Mascara um endereço de e-mail mantendo o domínio visível.
     *   usuario@empresa.com → u****@empresa.com
     */
    public static function maskEmail(string $email): string
    {
        if ($email === '' || !str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);
        $visible = mb_substr($local, 0, 1);
        return $visible . '****@' . $domain;
    }

    /**
     * Aplica mascaramento em um único array de dados (uma linha de resultado).
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function applyToRow(array $row, bool $showFull = false): array
    {
        if ($showFull) {
            return $row;
        }

        foreach (self::DOCUMENT_FIELDS as $field) {
            if (isset($row[$field]) && is_string($row[$field]) && $row[$field] !== '') {
                $row[$field] = self::maskDocument($row[$field]);
            }
        }

        foreach (self::EMAIL_FIELDS as $field) {
            if (isset($row[$field]) && is_string($row[$field]) && $row[$field] !== '') {
                $row[$field] = self::maskEmail($row[$field]);
            }
        }

        return $row;
    }

    /**
     * Aplica mascaramento em uma lista de arrays (resultado de query).
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function applyToRows(array $rows, bool $showFull = false): array
    {
        if ($showFull) {
            return $rows;
        }

        return array_map(
            static fn (array $row): array => self::applyToRow($row, false),
            $rows
        );
    }

    /**
     * Mascara campos de documento dentro de um payload JSON aninhado.
     * Aplica recursivamente a arrays e sub-arrays com chaves conhecidas.
     *
     * Útil para mascarar payloads completos de endpoints antes de enviar.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function applyToPayload(array $payload, bool $showFull = false): array
    {
        if ($showFull) {
            return $payload;
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                // Se é uma lista de arrays (ex.: top_orders, top_clients)
                if (array_is_list($value) && count($value) > 0 && is_array($value[0] ?? null)) {
                    $payload[$key] = self::applyToRows($value, false);
                } elseif (!array_is_list($value)) {
                    // Sub-objeto (ex.: summary com document embutido)
                    $payload[$key] = self::applyToRow($value, false);
                }
            } elseif (is_string($value) && in_array($key, self::DOCUMENT_FIELDS, true) && $value !== '') {
                $payload[$key] = self::maskDocument($value);
            } elseif (is_string($value) && in_array($key, self::EMAIL_FIELDS, true) && $value !== '') {
                $payload[$key] = self::maskEmail($value);
            }
        }

        return $payload;
    }
}
