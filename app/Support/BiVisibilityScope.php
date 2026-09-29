<?php

declare(strict_types=1);

namespace BIomie\Support;

use BIomie\SchemaCapabilities;
use PDO;

/**
 * Fase L — Exclusão Semântica da Licitação.
 *
 * Regra central: is_biomie_commercial_visible = 1 para aparecer no BIomie comercial.
 * Ortogonal ao operation_scope: uma NF de Licitação pode ter operation_scope='commercial'
 * (é uma NF de venda legítima) mas is_biomie_commercial_visible=0 porque pertence a
 * uma unidade de negócio fora do escopo do cockpit comercial.
 */
final class BiVisibilityScope
{
    /**
     * Condição SQL para uso em arrays $where (sem AND).
     * Assume que a coluna já existe — usar getCondition() quando incerto.
     */
    public static function commercialVisibleSql(string $alias = 'nf'): string
    {
        $prefix = $alias !== '' ? "{$alias}." : '';
        if (TenantContext::isConsolidado()) {
            return self::groupCondition($alias, true, true);
        }
        if (TenantContext::isLicitacao()) {
            return "{$prefix}business_channel = 'licitacao'";
        }
        if (TenantContext::isMixed()) {
            // Todas as operações: os DOIS canais. Licitação preservada (Fase L) +
            // varejo visível — MESMO universo do CommercialNfUniverse. NUNCA o
            // is_biomie_commercial_visible=1 sozinho, que esconderia licitação.
            return "({$prefix}business_channel = 'licitacao'"
                . " OR ({$prefix}business_channel = 'varejo' AND COALESCE({$prefix}is_biomie_commercial_visible, 1) = 1))";
        }
        return "COALESCE({$prefix}is_biomie_commercial_visible, 1) = 1";
    }

    /**
     * Fallback quando a coluna ainda não existe: exclusão por nome de vendedor.
     * Retorna condição SQL sem AND.
     */
    public static function licitacaoFallbackSql(string $alias = 'nf'): string
    {
        $prefix = $alias !== '' ? "{$alias}." : '';
        if (TenantContext::isConsolidado()) {
            return self::groupFallbackCondition($alias, false);
        }
        if (TenantContext::isLicitacao()) {
            return "UPPER({$prefix}nome_vendedor) LIKE '%LICITAC%'";
        }
        return "NOT UPPER({$prefix}nome_vendedor) LIKE '%LICITAC%'";
    }

    /**
     * Probes the DB and returns the SQL condition (without AND) for the given table.
     * Cached per (table, alias) within the request.
     */
    public static function getCondition(PDO $pdo, string $table, string $alias = 'nf'): string
    {
        static $cache = [];
        $key = "{$table}:{$alias}:" . TenantContext::contextId();
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $hasChannel = SchemaCapabilities::columnExists($pdo, $table, 'business_channel');
        $hasCol = SchemaCapabilities::columnExists($pdo, $table, 'is_biomie_commercial_visible');
        if (TenantContext::isConsolidado()) {
            return $cache[$key] = $hasChannel
                ? self::groupCondition($alias, true, $hasCol)
                : self::groupFallbackCondition($alias, $hasCol);
        }
        if ($hasChannel) {
            $prefix = $alias !== '' ? "{$alias}." : '';
            // Todas as operações: os DOIS canais (licitação preservada + varejo
            // visível). Sem isto, mixed cairia no ramo varejo e esconderia licitação.
            if (TenantContext::isMixed()) {
                $varejoCond = "{$prefix}business_channel = 'varejo'";
                if ($hasCol) {
                    $varejoCond .= " AND COALESCE({$prefix}is_biomie_commercial_visible, 1) = 1";
                }
                return $cache[$key] = "({$prefix}business_channel = 'licitacao' OR ({$varejoCond}))";
            }
            $channel = TenantContext::isLicitacao() ? 'licitacao' : 'varejo';
            $channelCondition = "{$prefix}business_channel = '{$channel}'";
            if ($channel === 'varejo' && $hasCol) {
                $channelCondition .= " AND COALESCE({$prefix}is_biomie_commercial_visible, 1) = 1";
            }
            return $cache[$key] = $channelCondition;
        }

        $prefix = $alias !== '' ? "{$alias}." : '';
        if (TenantContext::isLicitacao()) {
            $cache[$key] = "UPPER({$prefix}nome_vendedor) LIKE '%LICITAC%'";
        } else {
            $cache[$key] = $hasCol
                ? "COALESCE({$prefix}is_biomie_commercial_visible, 1) = 1"
                : "NOT UPPER({$prefix}nome_vendedor) LIKE '%LICITAC%'";
        }

        return $cache[$key];
    }

    private static function groupCondition(
        string $alias,
        bool $hasChannel,
        bool $hasVisibleColumn,
    ): string {
        if (!$hasChannel) {
            return self::groupFallbackCondition($alias, $hasVisibleColumn);
        }

        $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
        $parts = [];
        foreach (TenantContext::allowedBusinessScopes() as $companyId => $scopes) {
            $companyId = (int) $companyId;
            if ($companyId <= 0 || $scopes === []) {
                continue;
            }

            $channelParts = [];
            if (in_array('licitacao', $scopes, true)) {
                $channelParts[] = "{$prefix}business_channel = 'licitacao'";
            }
            if (in_array('varejo', $scopes, true)) {
                $retail = "{$prefix}business_channel = 'varejo'";
                if ($hasVisibleColumn) {
                    $retail .= " AND COALESCE({$prefix}is_biomie_commercial_visible, 1) = 1";
                }
                $channelParts[] = '(' . $retail . ')';
            }
            if ($channelParts !== []) {
                $parts[] = "({$prefix}company_id = {$companyId} AND ("
                    . implode(' OR ', $channelParts)
                    . '))';
            }
        }

        return $parts === [] ? '1=0' : '(' . implode(' OR ', $parts) . ')';
    }

    private static function groupFallbackCondition(string $alias, bool $hasVisibleColumn): string
    {
        // Em grupo, inferir canal pelo nome do vendedor pode ampliar acesso.
        // Sem business_channel comprovável, a única resposta segura é zero linha.
        unset($alias, $hasVisibleColumn);

        return '1=0';
    }

    /**
     * Normaliza nome de vendedor: lowercase → strip acentos → uppercase.
     * Usado para comparar nomes vindos do Omie com as regras internas.
     */
    public static function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name), 'UTF-8');
        $map  = [
            'ã' => 'a', 'â' => 'a', 'á' => 'a', 'à' => 'a', 'ä' => 'a',
            'ê' => 'e', 'é' => 'e', 'è' => 'e', 'ë' => 'e',
            'î' => 'i', 'í' => 'i', 'ì' => 'i', 'ï' => 'i',
            'õ' => 'o', 'ô' => 'o', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ];
        return mb_strtoupper(strtr($name, $map), 'UTF-8');
    }

    /**
     * Retorna true se o vendedor é o vendedor operacional "Licitação".
     * Igualdade normalizada, não substring — ver {@see LicitacaoSeller}.
     */
    public static function isLicitationName(string $name): bool
    {
        return LicitacaoSeller::isLicitacaoName($name);
    }

    /** @return list<string> */
    public static function validScopes(): array
    {
        return ['comercial', 'licitacao', 'outros'];
    }
}
