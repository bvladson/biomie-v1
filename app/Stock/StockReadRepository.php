<?php

declare(strict_types=1);

namespace BIomie\Stock;

use BIomie\Support\TenantContext;
use PDO;
use Throwable;

/**
 * EST.R1 — Repositório de leitura de estoque (local, saldos, movimentos).
 *
 * Toda query usa colunas explícitas — sem wildcard de seleção.
 * Retorna arrays associativos tipados.
 */
final class StockReadRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Verifica se as tabelas de estoque existem.
     *
     * @return array{locais:bool,saldos:bool,movimentos:bool}
     */
    public function tablesExist(): array
    {
        return [
            'locais'     => $this->tableExists('biomie_estoque_locais_dim'),
            'saldos'     => $this->tableExists('biomie_produtos_estoque_saldos'),
            'movimentos' => $this->tableExists('biomie_produtos_estoque_movimentos'),
        ];
    }

    /**
     * Lista locais de estoque ativos.
     *
     * @return list<array{codigo_local:string,nome_local:string,ativo:int,permite_venda:int}>
     */
    public function locaisAtivos(): array
    {
        if (!$this->tableExists('biomie_estoque_locais_dim')) return [];
        try {
            $tenantFilter = TenantContext::sqlFilter();
            $st = $this->pdo->query(
                "SELECT codigo_local, nome_local, ativo, permite_venda
                 FROM biomie_estoque_locais_dim
                 WHERE {$tenantFilter}
                   AND ativo = 1
                 ORDER BY nome_local ASC"
            );
            return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Saldo de estoque por produto e local.
     *
     * @return list<array<string,mixed>>
     */
    public function saldosPorProduto(string $codigoProduto): array
    {
        if (!$this->tableExists('biomie_produtos_estoque_saldos')) return [];
        try {
            $tenantFilter = TenantContext::sqlFilter();
            $st = $this->pdo->prepare(
                "SELECT codigo_produto, codigo_local, descricao_produto, nome_local,
                        estoque_atual, estoque_minimo, estoque_inicial, unidade, custo_medio,
                        atualizado_em
                 FROM biomie_produtos_estoque_saldos
                 WHERE {$tenantFilter}
                   AND codigo_produto = :code
                 ORDER BY nome_local ASC"
            );
            $st->execute([':code' => $codigoProduto]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Saldos de todos os produtos com abaixo_minimo.
     *
     * @return list<array<string,mixed>>
     */
    public function saldosAbaixoMinimo(int $limit = 100): array
    {
        if (!$this->tableExists('biomie_produtos_estoque_saldos')) return [];
        try {
            $tenantFilter = TenantContext::sqlFilter();
            $st = $this->pdo->prepare(
                "SELECT codigo_produto, codigo_local, descricao_produto, nome_local,
                        estoque_atual, estoque_minimo, unidade, atualizado_em
                 FROM biomie_produtos_estoque_saldos
                 WHERE {$tenantFilter}
                   AND estoque_minimo > 0
                   AND estoque_atual <= estoque_minimo
                 ORDER BY (estoque_minimo - estoque_atual) DESC
                 LIMIT :lim"
            );
            $st->bindValue(':lim', $limit, PDO::PARAM_INT);
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Movimentos de estoque de um produto em um período.
     *
     * @return list<array<string,mixed>>
     */
    public function movimentos(string $codigoProduto, string $start, string $end, int $limit = 50): array
    {
        if (!$this->tableExists('biomie_produtos_estoque_movimentos')) return [];
        try {
            $tenantFilter = TenantContext::sqlFilter();
            $st = $this->pdo->prepare(
                "SELECT data_movimento, codigo_local, tipo_movimento, quantidade,
                        valor, documento, nota_key, pedido_id_omie, cliente_fornecedor
                 FROM biomie_produtos_estoque_movimentos
                 WHERE {$tenantFilter}
                   AND codigo_produto = :code
                   AND data_movimento BETWEEN :start AND :end
                 ORDER BY data_movimento DESC, id DESC
                 LIMIT :lim"
            );
            $st->bindValue(':code',  $codigoProduto);
            $st->bindValue(':start', $start);
            $st->bindValue(':end',   $end);
            $st->bindValue(':lim',   $limit, PDO::PARAM_INT);
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Totais de estoque por produto (todos os locais agregados).
     *
     * @return list<array<string,mixed>>
     */
    public function totaisPorProduto(int $limit = 200): array
    {
        if (!$this->tableExists('biomie_produtos_estoque_saldos')) return [];
        try {
            $tenantFilter = TenantContext::sqlFilter();
            $st = $this->pdo->prepare(
                "SELECT codigo_produto, MAX(descricao_produto) AS descricao_produto,
                        SUM(estoque_atual) AS estoque_total,
                        SUM(estoque_minimo) AS minimo_total,
                        COUNT(DISTINCT codigo_local) AS n_locais,
                        MAX(atualizado_em) AS ultima_atualizacao,
                        MAX(unidade) AS unidade
                 FROM biomie_produtos_estoque_saldos
                 WHERE {$tenantFilter}
                 GROUP BY codigo_produto
                 ORDER BY estoque_total DESC
                 LIMIT :lim"
            );
            $st->bindValue(':lim', $limit, PDO::PARAM_INT);
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Contagem de produtos com/sem saldo sincronizado.
     *
     * @return array{total:int,com_saldo:int,sem_saldo:int,abaixo_minimo:int,ruptura:int}
     */
    public function statsGerais(): array
    {
        if (!$this->tableExists('biomie_produtos_estoque_saldos')) {
            return ['total' => 0, 'com_saldo' => 0, 'sem_saldo' => 0, 'abaixo_minimo' => 0, 'ruptura' => 0];
        }
        try {
            $tenantFilter = TenantContext::sqlFilter();
            $st = $this->pdo->query(
                "SELECT
                    COUNT(DISTINCT codigo_produto) AS total,
                    SUM(CASE WHEN estoque_atual > 0 THEN 1 ELSE 0 END) AS com_saldo,
                    SUM(CASE WHEN estoque_atual <= 0 THEN 1 ELSE 0 END) AS ruptura,
                    SUM(CASE WHEN estoque_minimo > 0 AND estoque_atual <= estoque_minimo AND estoque_atual > 0 THEN 1 ELSE 0 END) AS abaixo_minimo
                 FROM biomie_produtos_estoque_saldos
                 WHERE {$tenantFilter}"
            );
            $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : [];
            $total = (int)($row['total'] ?? 0);
            $comSaldo = (int)($row['com_saldo'] ?? 0);
            return [
                'total'         => $total,
                'com_saldo'     => $comSaldo,
                'sem_saldo'     => $total - $comSaldo,
                'abaixo_minimo' => (int)($row['abaixo_minimo'] ?? 0),
                'ruptura'       => (int)($row['ruptura'] ?? 0),
            ];
        } catch (Throwable) {
            return ['total' => 0, 'com_saldo' => 0, 'sem_saldo' => 0, 'abaixo_minimo' => 0, 'ruptura' => 0];
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            $st = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t LIMIT 1');
            $st->execute([':t' => $table]);
            return ((int) $st->fetchColumn()) > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
