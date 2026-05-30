<?php

declare(strict_types=1);

namespace BIomie\Support;

use BIomie\Commercial\CommercialMetricDate;
use PDO;
use Throwable;

/**
 * COM.R3 — Builder read-only para Product 360.
 *
 * Consolida NFs, clientes, vendedores, pedidos vinculados, evolução temporal
 * e estoque por local para um produto específico.
 *
 * Estoque:
 *   - Lê biomie_produtos_estoque_saldos e biomie_produtos_estoque_movimentos
 *   - Se tabelas ausentes ou vazias: retorna empty_state 'Estoque ainda não sincronizado'
 *   - Alerta de estoque baixo: estoque_atual <= estoque_minimo quando minimo > 0
 *
 * Modes (para carregamento lazy por tab):
 *   summary | invoices | clients | vendors | orders | stock | stock_movements | low_stock | drawer
 */
final class Product360Builder
{
    private PDO    $pdo;
    private string $visCond;
    private bool   $hasSaldosTable;
    private bool   $hasMovimentosTable;
    private bool   $hasItensTable;

    public function __construct(PDO $pdo)
    {
        $this->pdo                = $pdo;
        $this->visCond            = BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf');
        $this->hasSaldosTable     = $this->tableExists('biomie_produtos_estoque_saldos');
        $this->hasMovimentosTable = $this->tableExists('biomie_produtos_estoque_movimentos');
        $this->hasItensTable      = $this->tableExists('biomie_notas_fiscais_itens_canonicos');
    }

    // ── Public modes ──────────────────────────────────────────────────────────

    /**
     * KPI summary for a product in the period.
     *
     * @return array<string,mixed>
     */
    public function summary(string $productCode, string $start, string $end): array
    {
        if (!$this->hasItensTable) {
            return $this->emptyState('Itens de NF não disponíveis');
        }

        $dateExpr = CommercialMetricDate::metricDateSql('nf');

        try {
            $stmt = $this->pdo->prepare(
                "SELECT
                    COALESCE(NULLIF(TRIM(i.codigo_produto), ''), 'SEM_SKU') AS codigo_produto,
                    MAX(COALESCE(NULLIF(TRIM(i.descricao_produto), ''), 'Produto sem descrição')) AS descricao_produto,
                    COUNT(DISTINCT i.nota_key) AS nfs,
                    COALESCE(SUM(i.quantidade), 0) AS quantidade_total,
                    COALESCE(SUM(i.valor_total), 0) AS receita_total,
                    COALESCE(SUM(i.valor_desconto), 0) AS desconto_total,
                    COUNT(DISTINCT nf.codigo_cliente_omie) AS clientes,
                    COUNT(DISTINCT nf.nome_vendedor) AS vendedores,
                    MIN({$dateExpr}) AS primeira_venda,
                    MAX({$dateExpr}) AS ultima_venda
                 FROM biomie_notas_fiscais_itens_canonicos i
                 JOIN biomie_notas_fiscais_canonicas nf ON nf.nota_key = i.nota_key
                 WHERE nf.status_fiscal = 'autorizada'
                   AND nf.operation_scope = 'commercial'
                   AND {$dateExpr} BETWEEN :start AND :end
                   AND {$this->visCond}
                   AND COALESCE(NULLIF(TRIM(i.codigo_produto), ''), 'SEM_SKU') = :code"
            );
            $stmt->execute([':start' => $start, ':end' => $end, ':code' => $productCode]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return $this->emptyState('Erro ao carregar summary');
        }

        $qty      = (float)($row['quantidade_total'] ?? 0);
        $receita  = (float)($row['receita_total'] ?? 0);
        $nfs      = (int)($row['nfs'] ?? 0);

        return [
            'codigo_produto'    => $productCode,
            'descricao_produto' => (string)($row['descricao_produto'] ?? $productCode),
            'nfs'               => $nfs,
            'quantidade_total'  => $qty,
            'receita_total'     => $receita,
            'desconto_total'    => (float)($row['desconto_total'] ?? 0),
            'clientes'          => (int)($row['clientes'] ?? 0),
            'vendedores'        => (int)($row['vendedores'] ?? 0),
            'ticket_medio'      => $nfs > 0 ? round($receita / $nfs, 2) : 0.0,
            'preco_medio_unit'  => $qty > 0 ? round($receita / $qty, 2) : 0.0,
            'primeira_venda'    => substr((string)($row['primeira_venda'] ?? ''), 0, 10),
            'ultima_venda'      => substr((string)($row['ultima_venda'] ?? ''), 0, 10),
            'period'            => ['start' => $start, 'end' => $end],
        ];
    }

    /**
     * NFs listing for a product.
     *
     * @return list<array<string,mixed>>
     */
    public function invoices(string $productCode, string $start, string $end, int $limit = 50): array
    {
        if (!$this->hasItensTable) return [];

        $dateExpr = CommercialMetricDate::metricDateSql('nf');

        try {
            $stmt = $this->pdo->prepare(
                "SELECT nf.nota_key, nf.numero_nf,
                        {$dateExpr} AS data_ref,
                        nf.nome_cliente, nf.nome_vendedor,
                        i.quantidade, i.valor_total AS receita,
                        i.valor_desconto AS desconto,
                        nf.pedido_id_omie
                 FROM biomie_notas_fiscais_itens_canonicos i
                 JOIN biomie_notas_fiscais_canonicas nf ON nf.nota_key = i.nota_key
                 WHERE nf.status_fiscal = 'autorizada'
                   AND nf.operation_scope = 'commercial'
                   AND {$dateExpr} BETWEEN :start AND :end
                   AND {$this->visCond}
                   AND COALESCE(NULLIF(TRIM(i.codigo_produto), ''), 'SEM_SKU') = :code
                 ORDER BY {$dateExpr} DESC
                 LIMIT {$limit}"
            );
            $stmt->execute([':start' => $start, ':end' => $end, ':code' => $productCode]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Top clients for a product.
     *
     * @return list<array<string,mixed>>
     */
    public function clients(string $productCode, string $start, string $end, int $limit = 20): array
    {
        if (!$this->hasItensTable) return [];

        $dateExpr = CommercialMetricDate::metricDateSql('nf');

        try {
            $stmt = $this->pdo->prepare(
                "SELECT nf.codigo_cliente_omie, MAX(nf.nome_cliente) AS nome_cliente,
                        MAX(nf.documento_cliente) AS documento_cliente,
                        COUNT(DISTINCT i.nota_key) AS nfs,
                        COALESCE(SUM(i.quantidade), 0) AS quantidade,
                        COALESCE(SUM(i.valor_total), 0) AS receita
                 FROM biomie_notas_fiscais_itens_canonicos i
                 JOIN biomie_notas_fiscais_canonicas nf ON nf.nota_key = i.nota_key
                 WHERE nf.status_fiscal = 'autorizada'
                   AND nf.operation_scope = 'commercial'
                   AND {$dateExpr} BETWEEN :start AND :end
                   AND {$this->visCond}
                   AND COALESCE(NULLIF(TRIM(i.codigo_produto), ''), 'SEM_SKU') = :code
                 GROUP BY nf.codigo_cliente_omie
                 ORDER BY receita DESC
                 LIMIT {$limit}"
            );
            $stmt->execute([':start' => $start, ':end' => $end, ':code' => $productCode]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Top vendors for a product.
     *
     * @return list<array<string,mixed>>
     */
    public function vendors(string $productCode, string $start, string $end, int $limit = 10): array
    {
        if (!$this->hasItensTable) return [];

        $dateExpr = CommercialMetricDate::metricDateSql('nf');

        try {
            $stmt = $this->pdo->prepare(
                "SELECT nf.nome_vendedor,
                        COUNT(DISTINCT i.nota_key) AS nfs,
                        COUNT(DISTINCT nf.codigo_cliente_omie) AS clientes,
                        COALESCE(SUM(i.quantidade), 0) AS quantidade,
                        COALESCE(SUM(i.valor_total), 0) AS receita
                 FROM biomie_notas_fiscais_itens_canonicos i
                 JOIN biomie_notas_fiscais_canonicas nf ON nf.nota_key = i.nota_key
                 WHERE nf.status_fiscal = 'autorizada'
                   AND nf.operation_scope = 'commercial'
                   AND {$dateExpr} BETWEEN :start AND :end
                   AND {$this->visCond}
                   AND COALESCE(NULLIF(TRIM(i.codigo_produto), ''), 'SEM_SKU') = :code
                 GROUP BY nf.nome_vendedor
                 ORDER BY receita DESC
                 LIMIT {$limit}"
            );
            $stmt->execute([':start' => $start, ':end' => $end, ':code' => $productCode]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Stock by location for a product.
     *
     * @return array{available:bool,data:list<array<string,mixed>>,empty_state:string|null,has_low_stock:bool,total_atual:float,total_minimo:float}
     */
    public function stock(string $productCode): array
    {
        $emptyState = null;

        if (!$this->hasSaldosTable) {
            $emptyState = 'Estoque ainda não sincronizado — disponível em COM.R4+';
            return ['available' => false, 'data' => [], 'empty_state' => $emptyState, 'has_low_stock' => false, 'total_atual' => 0.0, 'total_minimo' => 0.0];
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT codigo_produto, codigo_local, descricao_produto, nome_local,
                        estoque_atual, estoque_minimo, estoque_inicial, unidade, custo_medio,
                        atualizado_em
                 FROM biomie_produtos_estoque_saldos
                 WHERE codigo_produto = :code
                 ORDER BY nome_local ASC"
            );
            $stmt->execute([':code' => $productCode]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return ['available' => false, 'data' => [], 'empty_state' => 'Erro ao carregar estoque', 'has_low_stock' => false, 'total_atual' => 0.0, 'total_minimo' => 0.0];
        }

        if (empty($rows)) {
            $emptyState = 'Produto sem saldo de estoque — sincronizar via Omie';
        }

        $totalAtual  = array_sum(array_column($rows, 'estoque_atual'));
        $totalMinimo = array_sum(array_column($rows, 'estoque_minimo'));
        $hasLowStock = !empty(array_filter($rows, fn($r) => (float)($r['estoque_minimo'] ?? 0) > 0 && (float)($r['estoque_atual'] ?? 0) <= (float)($r['estoque_minimo'] ?? 0)));

        $data = array_map(function (array $r) {
            $atual  = (float)($r['estoque_atual'] ?? 0);
            $minimo = (float)($r['estoque_minimo'] ?? 0);
            return [
                'codigo_local'     => (string)($r['codigo_local'] ?? ''),
                'nome_local'       => (string)($r['nome_local'] ?? ''),
                'estoque_atual'    => $atual,
                'estoque_minimo'   => $minimo,
                'estoque_inicial'  => (float)($r['estoque_inicial'] ?? 0),
                'unidade'          => (string)($r['unidade'] ?? ''),
                'custo_medio'      => (float)($r['custo_medio'] ?? 0),
                'atualizado_em'    => substr((string)($r['atualizado_em'] ?? ''), 0, 19),
                'stock_status'     => $this->stockStatus($atual, $minimo),
            ];
        }, $rows);

        return [
            'available'   => true,
            'data'        => $data,
            'empty_state' => $emptyState,
            'has_low_stock' => $hasLowStock,
            'total_atual'  => round((float)$totalAtual, 4),
            'total_minimo' => round((float)$totalMinimo, 4),
        ];
    }

    /**
     * Stock movements for a product in the period.
     *
     * @return array{available:bool,data:list<array<string,mixed>>,empty_state:string|null}
     */
    public function stockMovements(string $productCode, string $start, string $end, int $limit = 50): array
    {
        if (!$this->hasMovimentosTable) {
            return ['available' => false, 'data' => [], 'empty_state' => 'Histórico de movimentos não sincronizado — disponível em COM.R4+'];
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT data_movimento, codigo_local, tipo_movimento, quantidade,
                        valor, documento, nota_key, pedido_id_omie, cliente_fornecedor
                 FROM biomie_produtos_estoque_movimentos
                 WHERE codigo_produto = :code
                   AND data_movimento BETWEEN :start AND :end
                 ORDER BY data_movimento DESC, id DESC
                 LIMIT {$limit}"
            );
            $stmt->execute([':code' => $productCode, ':start' => $start, ':end' => $end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return ['available' => false, 'data' => [], 'empty_state' => 'Erro ao carregar movimentos'];
        }

        return [
            'available'   => true,
            'data'        => $rows,
            'empty_state' => empty($rows) ? 'Sem movimentos no período' : null,
        ];
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function stockStatus(float $atual, float $minimo): string
    {
        if ($atual <= 0) return 'sem_estoque';
        if ($minimo > 0 && $atual <= $minimo) return 'baixo';
        return 'ok';
    }

    /** @return array<string,mixed> */
    private function emptyState(string $message): array
    {
        return ['ok' => false, 'empty_state' => $message, 'nfs' => 0, 'receita_total' => 0.0];
    }

    private function tableExists(string $table): bool
    {
        try {
            $s = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t LIMIT 1');
            $s->execute(['t' => $table]);
            return (int) $s->fetchColumn() > 0;
        } catch (Throwable) { return false; }
    }
}
