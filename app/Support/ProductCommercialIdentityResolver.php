<?php

declare(strict_types=1);

namespace BIomie\Support;

use PDO;
use Throwable;

/**
 * EST.R1.3 — Resolve a identidade comercial de um produto.
 *
 * Problema: topProductsCommercial() retorna TRIM(codigo_produto) como product_code,
 * mas Product360ReadRepository usava exact match sem TRIM — causando "Produto sem descricao"
 * e Resumo vazio enquanto outras abas tinham dados.
 *
 * Este resolver encontra o codigo_produto exato no DB dado o código trimado da URL,
 * e resolve o melhor display_name disponível.
 *
 * Nunca retorna 'Produto sem descricao' quando há evidência de descrição.
 * Nunca mistura produtos diferentes por descrição similar.
 */
final class ProductCommercialIdentityResolver
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Resolve identidade completa do produto.
     *
     * @return array{
     *   product_code: string,
     *   db_code: string,
     *   display_name: string,
     *   description_source: string,
     *   matched_by: string,
     *   confidence: string,
     *   warnings: list<string>
     * }
     */
    public function resolve(string $productCode, string $start, string $end): array
    {
        $code     = trim($productCode);
        $warnings = [];

        if ($code === '') {
            return $this->fallback($productCode, 'empty_code', 'Produto', []);
        }

        // ── Pass 1: exact DB match (produto sem espaços, caso mais comum) ──────
        $exact = $this->queryDbCode($code, exact: true);
        if ($exact !== null) {
            $name = $this->resolveDescription($exact, $start, $end, $warnings);
            return [
                'product_code'       => $code,
                'db_code'            => $exact,
                'display_name'       => $name,
                'description_source' => 'canonical_nf_item',
                'matched_by'         => 'exact',
                'confidence'         => 'high',
                'warnings'           => $warnings,
            ];
        }

        // ── Pass 2: TRIM match (produto com espaços no DB) ────────────────────
        $trimmed = $this->queryDbCode($code, exact: false);
        if ($trimmed !== null) {
            $warnings[] = 'product_code_has_whitespace';
            $name = $this->resolveDescription($trimmed, $start, $end, $warnings);
            return [
                'product_code'       => $code,
                'db_code'            => $trimmed,
                'display_name'       => $name,
                'description_source' => 'canonical_nf_item',
                'matched_by'         => 'trim',
                'confidence'         => 'high',
                'warnings'           => $warnings,
            ];
        }

        // ── Pass 3: mart_produto_periodo por SKU normalizado ──────────────────
        $martName = $this->queryMartDescription($code);
        if ($martName !== null) {
            $warnings[] = 'not_in_canonical_using_mart';
            return [
                'product_code'       => $code,
                'db_code'            => $code,
                'display_name'       => $martName,
                'description_source' => 'mart_produto_periodo',
                'matched_by'         => 'mart',
                'confidence'         => 'medium',
                'warnings'           => $warnings,
            ];
        }

        // ── Pass 4: código como fallback legível ──────────────────────────────
        $warnings[] = 'product_not_found_in_canonical';
        return $this->fallback($code, 'code_only', "Produto {$code}", $warnings);
    }

    /**
     * Resolve somente o display_name, mais leve para uso em listagens.
     */
    public function resolveDisplayName(string $productCode, string $start, string $end): string
    {
        return $this->resolve($productCode, $start, $end)['display_name'];
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Busca o codigo_produto exato ou trimado no DB.
     * Retorna o valor real armazenado no DB (pode ter espaços).
     */
    private function queryDbCode(string $code, bool $exact): ?string
    {
        try {
            if ($exact) {
                $stmt = $this->pdo->prepare(
                    "SELECT i.codigo_produto
                     FROM biomie_notas_fiscais_itens_canonicos i
                     WHERE i.codigo_produto = :code LIMIT 1"
                );
            } else {
                $stmt = $this->pdo->prepare(
                    "SELECT i.codigo_produto
                     FROM biomie_notas_fiscais_itens_canonicos i
                     WHERE TRIM(i.codigo_produto) = :code LIMIT 1"
                );
            }
            $stmt->execute([':code' => $code]);
            $val = $stmt->fetchColumn();
            return $val !== false ? (string) $val : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Resolve melhor descrição para um db_code:
     * 1. Maior receita no período → mais representativo comercialmente
     * 2. Descrição mais recente (última NF) como tiebreaker
     * 3. Fallback: primeira descrição não-vazia encontrada
     *
     * @param list<string> $warnings
     */
    private function resolveDescription(string $dbCode, string $start, string $end, array &$warnings): string
    {
        try {
            // Por maior receita no período
            $stmt = $this->pdo->prepare(
                "SELECT COALESCE(NULLIF(TRIM(i.descricao_produto), ''), '') AS descr,
                        SUM(i.valor_total) AS rev
                 FROM biomie_notas_fiscais_itens_canonicos i
                 INNER JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key
                 WHERE n.status_fiscal = 'autorizada'
                   AND COALESCE(n.data_competencia, n.data_emissao) BETWEEN :start AND :end
                   AND i.codigo_produto = :code
                   AND i.descricao_produto IS NOT NULL AND TRIM(i.descricao_produto) != ''
                 GROUP BY TRIM(i.descricao_produto)
                 ORDER BY rev DESC
                 LIMIT 3"
            );
            $stmt->execute([':start' => $start, ':end' => $end, ':code' => $dbCode]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            $rows = [];
        }

        if (empty($rows)) {
            // Fallback: qualquer descrição histórica do produto
            try {
                $stmt2 = $this->pdo->prepare(
                    "SELECT COALESCE(NULLIF(TRIM(i.descricao_produto), ''), '') AS descr
                     FROM biomie_notas_fiscais_itens_canonicos i
                     INNER JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key
                     WHERE i.codigo_produto = :code
                       AND i.descricao_produto IS NOT NULL AND TRIM(i.descricao_produto) != ''
                     ORDER BY n.data_competencia DESC
                     LIMIT 1"
                );
                $stmt2->execute([':code' => $dbCode]);
                $hist = $stmt2->fetchColumn();
                if ($hist !== false && $hist !== '') {
                    $warnings[] = 'description_from_history_not_period';
                    return (string) $hist;
                }
            } catch (Throwable) {}

            $warnings[] = 'no_description_found';
            return "Produto {$dbCode}";
        }

        if (count($rows) > 1) {
            $warnings[] = 'multiple_descriptions_same_sku';
        }

        return (string) ($rows[0]['descr'] ?? "Produto {$dbCode}");
    }

    private function queryMartDescription(string $code): ?string
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COALESCE(NULLIF(TRIM(descricao_produto), ''), NULL) AS descr
                 FROM mart_produto_periodo
                 WHERE codigo_produto = :code
                   AND descricao_produto IS NOT NULL
                 LIMIT 1"
            );
            $stmt->execute([':code' => $code]);
            $val = $stmt->fetchColumn();
            return ($val !== false && $val !== null && $val !== '') ? (string) $val : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param list<string> $warnings
     * @return array<string,mixed>
     */
    private function fallback(string $code, string $matchedBy, string $name, array $warnings): array
    {
        return [
            'product_code'       => $code,
            'db_code'            => $code,
            'display_name'       => $name,
            'description_source' => 'fallback',
            'matched_by'         => $matchedBy,
            'confidence'         => 'low',
            'warnings'           => $warnings,
        ];
    }
}
