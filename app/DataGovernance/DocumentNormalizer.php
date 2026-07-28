<?php

declare(strict_types=1);

namespace BIomie\DataGovernance;

/**
 * Normalização e classificação de CPF / CNPJ.
 *
 * Regras canônicas:
 *  - CPF  = 11 dígitos numéricos
 *  - CNPJ = 14 dígitos numéricos
 *
 * Todos os métodos são estáticos — esta classe é consulta pura, sem estado.
 */
final class DocumentNormalizer
{
    /**
     * Remove qualquer caractere não numérico e retorna apenas os dígitos.
     */
    public static function normalize(string $document): string
    {
        return preg_replace('/\D/', '', $document) ?? '';
    }

    /**
     * Retorna 'cpf', 'cnpj' ou 'desconhecido'.
     */
    public static function type(string $document): string
    {
        $digits = self::normalize($document);

        return match (strlen($digits)) {
            11 => 'cpf',
            14 => 'cnpj',
            default => 'desconhecido',
        };
    }

    public static function isCpf(string $document): bool
    {
        return self::type($document) === 'cpf';
    }

    public static function isCnpj(string $document): bool
    {
        return self::type($document) === 'cnpj';
    }

    /**
     * Retorna o documento formatado com pontuação canônica.
     *  CPF  → 000.000.000-00
     *  CNPJ → 00.000.000/0000-00
     * Retorna a string original se o formato não for reconhecido.
     */
    public static function format(string $document): string
    {
        $digits = self::normalize($document);

        if (strlen($digits) === 11) {
            return vsprintf('%s%s%s.%s%s%s.%s%s%s-%s%s', str_split($digits));
        }

        if (strlen($digits) === 14) {
            return vsprintf(
                '%s%s.%s%s%s.%s%s%s/%s%s%s%s-%s%s',
                str_split($digits)
            );
        }

        return $document;
    }

    /**
     * Valida CPF pelo algoritmo de dígito verificador.
     */
    public static function isValidCpf(string $document): bool
    {
        $digits = self::normalize($document);
        if (strlen($digits) !== 11 || preg_match('/^(\d)\1{10}$/', $digits)) {
            return false;
        }

        for ($pass = 0; $pass < 2; $pass++) {
            $sum    = 0;
            $weight = 10 + $pass;
            for ($i = 0; $i < (9 + $pass); $i++) {
                $sum += (int) $digits[$i] * ($weight - $i);
            }
            $rem = $sum % 11;
            if ((int) $digits[9 + $pass] !== ($rem < 2 ? 0 : 11 - $rem)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Valida CNPJ pelo algoritmo de dígito verificador.
     */
    public static function isValidCnpj(string $document): bool
    {
        $digits = self::normalize($document);
        if (strlen($digits) !== 14 || preg_match('/^(\d)\1{13}$/', $digits)) {
            return false;
        }

        $weights = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        for ($pass = 0; $pass < 2; $pass++) {
            $sum = 0;
            for ($i = 0; $i < 12 + $pass; $i++) {
                $sum += (int) $digits[$i] * $weights[$i + (1 - $pass)];
            }
            $rem = $sum % 11;
            if ((int) $digits[12 + $pass] !== ($rem < 2 ? 0 : 11 - $rem)) {
                return false;
            }
            array_unshift($weights, 6);
        }

        return true;
    }

    /**
     * Expressão SQL para extrair dígitos de uma coluna (MySQL).
     * Útil quando o documento está armazenado com formatação variável.
     *
     * Ex.: DocumentNormalizer::sqlNormalize('documento_cliente')
     *   → "REGEXP_REPLACE(documento_cliente, '[^0-9]', '')"
     */
    public static function sqlNormalize(string $columnExpr): string
    {
        return "REGEXP_REPLACE({$columnExpr}, '[^0-9]', '')";
    }

    /**
     * Expressão SQL que retorna 'cpf', 'cnpj' ou 'desconhecido' a partir de
     * uma coluna de documento.
     */
    public static function sqlType(string $columnExpr): string
    {
        $norm = self::sqlNormalize($columnExpr);
        return <<<SQL
            CASE
                WHEN LENGTH({$norm}) = 11 THEN 'cpf'
                WHEN LENGTH({$norm}) = 14 THEN 'cnpj'
                ELSE 'desconhecido'
            END
        SQL;
    }
}
