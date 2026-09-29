<?php

declare(strict_types=1);

namespace BIomie\Support;

/**
 * REGRA DE NEGÓCIO DO CANAL (definida pelo dono do BIomie em 29/09/2026).
 *
 * Licitação é identificada oficialmente pelo vendedor operacional chamado
 * "Licitação" no Omie — padrão usado em TODAS as empresas do grupo. Qualquer
 * venda vinculada a outro vendedor pertence ao varejo, independentemente de o
 * cliente ser público ou privado. Não se infere canal por cliente, CNAE, razão
 * social, órgão público, observação ou projeto.
 *
 * Ordem de decisão (ChannelClassifierService + escrita canônica):
 *   1. override manual            → canal do override   (manual_override)
 *   2. cliente é empresa do grupo → 'outros'            (intra_grupo — não é venda)
 *   3. código do vendedor = o do vendedor "Licitação" daquela empresa
 *      (regra vendedor_id_list)   → licitação           (seller_licitacao)
 *   4. nome do vendedor normalizado = LICITACAO
 *      (regra vendedor_nome_igual; fallback se o código mudar) → licitação
 *   5. qualquer outro vendedor    → varejo              (seller_regular)
 *
 * O nome é comparado por IGUALDADE após normalizar (acento, caixa, espaços):
 * "LICITAÇÃO", "Licitação", "licitacao", " Licitação " casam; "Licitante",
 * "Licitações 2" ou "João Licitação" NÃO — substring já foi fonte de erro.
 */
final class LicitacaoSeller
{
    public const NORMALIZED_NAME = 'LICITACAO';

    /** Origens gravadas em visibility_rule_source. */
    public const SOURCE_LICITACAO = 'seller_licitacao';
    public const SOURCE_REGULAR = 'seller_regular';
    public const SOURCE_INTRA_GRUPO = 'intra_grupo';
    public const SOURCE_MANUAL = 'manual_override';

    /** Maiúsculas, sem acento, espaços colapsados e aparados. */
    public static function normalize(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        $name = mb_strtoupper($name, 'UTF-8');

        return strtr($name, [
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Ç' => 'C', 'Ñ' => 'N',
        ]);
    }

    public static function isLicitacaoName(string $name): bool
    {
        return self::normalize($name) === self::NORMALIZED_NAME;
    }
}
