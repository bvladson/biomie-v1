<?php

declare(strict_types=1);

namespace BIomie\Support;

use PDO;
use Throwable;

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
    private const LICITACAO_PATTERNS = ['LICITAC'];

    /**
     * Condição SQL para uso em arrays $where (sem AND).
     * Assume que a coluna já existe — usar getCondition() quando incerto.
     */
    public static function commercialVisibleSql(string $alias = 'nf'): string
    {
        $prefix = $alias !== '' ? "{$alias}." : '';
        return "COALESCE({$prefix}is_biomie_commercial_visible, 1) = 1";
    }

    /**
     * Fallback quando a coluna ainda não existe: exclusão por nome de vendedor.
     * Retorna condição SQL sem AND.
     */
    public static function licitacaoFallbackSql(string $alias = 'nf'): string
    {
        $prefix = $alias !== '' ? "{$alias}." : '';
        return "NOT UPPER({$prefix}nome_vendedor) LIKE '%LICITAC%'";
    }

    /**
     * Probes the DB and returns the SQL condition (without AND) for the given table.
     * Cached per (table, alias) within the request.
     */
    public static function getCondition(PDO $pdo, string $table, string $alias = 'nf'): string
    {
        static $cache = [];
        $key = "{$table}:{$alias}";
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $hasCol = false;
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $stmt->execute([$table, 'is_biomie_commercial_visible']);
            $hasCol = (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {}

        $prefix = $alias !== '' ? "{$alias}." : '';
        $cache[$key] = $hasCol
            ? "COALESCE({$prefix}is_biomie_commercial_visible, 1) = 1"
            : "NOT UPPER({$prefix}nome_vendedor) LIKE '%LICITAC%'";

        return $cache[$key];
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
     * Retorna true se o nome corresponde a um padrão de licitação conhecido.
     */
    public static function isLicitationName(string $name): bool
    {
        $normalized = self::normalizeName($name);
        foreach (self::LICITACAO_PATTERNS as $pat) {
            if (str_contains($normalized, $pat)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    public static function validScopes(): array
    {
        return ['comercial', 'licitacao', 'outros'];
    }
}
