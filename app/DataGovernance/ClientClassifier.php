<?php

declare(strict_types=1);

namespace BIomie\DataGovernance;

/**
 * Classificação canônica do tipo de pessoa jurídica de um cliente.
 *
 * Hierarquia de classificação (ordem importa):
 *   1. CPF             → 'pf'
 *   2. CNPJ + CNAE 84  → 'governo'
 *   3. CNPJ + CNAE 94  → 'associacao'
 *   4. CNPJ + CNAE     → 'pj'
 *   5. CNPJ sem CNAE   → 'pj_sem_cnae'
 *   6. Documento vazio/inválido → 'desconhecido'
 *
 * Esta classe é o ponto único de verdade para tipo_pessoa.
 * Nenhum outro lugar no código deve derivar tipo_pessoa diretamente.
 */
final class ClientClassifier
{
    // Valores canônicos do campo tipo_pessoa.
    public const PF            = 'pf';
    public const PJ            = 'pj';
    public const PJ_SEM_CNAE   = 'pj_sem_cnae';
    public const GOVERNO       = 'governo';
    public const ASSOCIACAO    = 'associacao';
    public const DESCONHECIDO  = 'desconhecido';

    /** Lista de todos os valores válidos do ENUM tipo_pessoa. */
    public const ALL_TYPES = [
        self::PF,
        self::PJ,
        self::PJ_SEM_CNAE,
        self::GOVERNO,
        self::ASSOCIACAO,
        self::DESCONHECIDO,
    ];

    /**
     * Classifica um cliente dado seu documento e CNAE principal.
     *
     * @param string  $document CPF ou CNPJ em qualquer formato (com ou sem pontuação)
     * @param ?string $cnae     Código CNAE em qualquer formato (ex.: "4712-1/00", "47121")
     */
    public static function classify(string $document, ?string $cnae): string
    {
        $type = DocumentNormalizer::type($document);

        if ($type === 'cpf') {
            return self::PF;
        }

        if ($type === 'cnpj') {
            if (SegmentDictionary::isGovernment($cnae)) {
                return self::GOVERNO;
            }

            if (SegmentDictionary::isAssociation($cnae)) {
                return self::ASSOCIACAO;
            }

            if ($cnae === null || trim($cnae) === '') {
                return self::PJ_SEM_CNAE;
            }

            return self::PJ;
        }

        return self::DESCONHECIDO;
    }

    /**
     * Expressão SQL que deriva tipo_pessoa diretamente de colunas de documento e CNAE.
     *
     * Útil em INSERT … SELECT quando se quer calcular tipo_pessoa sem chamada PHP.
     *
     * @param string $documentoCol nome ou expressão SQL da coluna de documento
     * @param string $cnaeCol      nome ou expressão SQL da coluna de CNAE
     */
    public static function sqlExpression(string $documentoCol, string $cnaeCol): string
    {
        $docNorm = DocumentNormalizer::sqlNormalize($documentoCol);
        return <<<SQL
            CASE
                WHEN LENGTH({$docNorm}) = 11 THEN 'pf'
                WHEN LENGTH({$docNorm}) = 14 AND {$cnaeCol} LIKE '84%' THEN 'governo'
                WHEN LENGTH({$docNorm}) = 14 AND {$cnaeCol} LIKE '94%' THEN 'associacao'
                WHEN LENGTH({$docNorm}) = 14 AND ({$cnaeCol} IS NULL OR {$cnaeCol} = '') THEN 'pj_sem_cnae'
                WHEN LENGTH({$docNorm}) = 14 THEN 'pj'
                ELSE 'desconhecido'
            END
        SQL;
    }

    /**
     * Rótulo legível para exibição no dashboard.
     */
    public static function label(string $tipoPessoa): string
    {
        return match ($tipoPessoa) {
            self::PF           => 'Pessoa Física',
            self::PJ           => 'Pessoa Jurídica',
            self::PJ_SEM_CNAE  => 'PJ sem Classificação',
            self::GOVERNO      => 'Governo / Órgão Público',
            self::ASSOCIACAO   => 'Associação / Sindicato',
            default            => 'Não identificado',
        };
    }
}
