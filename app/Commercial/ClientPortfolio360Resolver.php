<?php

declare(strict_types=1);

namespace BIomie\Commercial;

use BIomie\Support\BiVisibilityScope;
use PDO;
use Throwable;

/**
 * COM.R3 — Resolvedor de carteira 360 de clientes por vendedor.
 *
 * Fontes de dono (prioridade decrescente):
 *   1. biomie_clientes_dim.vendedor_responsavel_nome (confidence 100)
 *   2. Vendedor da primeira NF autorizada do cliente (confidence 70)
 *   3. Sem dono (confidence 0)
 *
 * Definições semânticas aprovadas em COM.R3:
 *   - Cliente ATIVO     : teve NF autorizada Orç-PD no período selecionado
 *   - Cliente RECORRENTE: 2+ compras em meses distintos (evita falso-positivo de múltiplas NFs no mesmo dia)
 *   - Cliente NOVO      : primeira NF autorizada dentro do período selecionado
 *   - FORA DA CARTEIRA  : vendedor da NF ≠ dono da carteira (apenas sinalizador — não bloqueia)
 *
 * Read-only. Sem escrita. Sem override manual nesta versão.
 */
final class ClientPortfolio360Resolver
{
    private PDO    $pdo;
    private string $visCond;
    private bool   $hasDimTable;
    private bool   $hasNfTable;

    public function __construct(PDO $pdo)
    {
        $this->pdo         = $pdo;
        $this->visCond     = BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf');
        $this->hasDimTable = $this->tableExists('biomie_clientes_dim');
        $this->hasNfTable  = $this->tableExists('biomie_notas_fiscais_canonicas');
    }

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Summary KPIs for portfolio of one vendor (or all vendors).
     *
     * @return array{
     *   total_clientes_carteira:int,
     *   clientes_ativos_periodo:int,
     *   clientes_recorrentes:int,
     *   clientes_novos:int,
     *   clientes_sem_dono:int,
     *   conflitos_fora_carteira:int,
     *   valor_fora_carteira:float,
     * }
     */
    public function resolveSummary(string $start, string $end, ?string $vendorName = null): array
    {
        if (!$this->hasNfTable) {
            return $this->emptySummary();
        }

        $vendorFilter = '';
        $params = [':start' => $start, ':end' => $end];
        if ($vendorName !== null && $vendorName !== '') {
            $vendorFilter = ' AND nf.nome_vendedor = :vendor';
            $params[':vendor'] = $vendorName;
        }

        // Clientes ativos no período
        $clientesAtivos = $this->countQ(
            "SELECT COUNT(DISTINCT nf.codigo_cliente_omie)
             FROM biomie_notas_fiscais_canonicas nf
             WHERE nf.status_fiscal = 'autorizada'
               AND nf.operation_scope = 'commercial'
               AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
               AND {$this->visCond}{$vendorFilter}",
            $params
        );

        // Recorrentes (2+ meses distintos)
        $recorrentes = $this->countQ(
            "SELECT COUNT(*) FROM (
                SELECT nf.codigo_cliente_omie
                FROM biomie_notas_fiscais_canonicas nf
                WHERE nf.status_fiscal = 'autorizada'
                  AND nf.operation_scope = 'commercial'
                  AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
                  AND {$this->visCond}{$vendorFilter}
                  AND nf.codigo_cliente_omie IS NOT NULL
                GROUP BY nf.codigo_cliente_omie
                HAVING COUNT(DISTINCT DATE_FORMAT(COALESCE(nf.data_competencia, nf.data_emissao), '%Y-%m')) >= 2
             ) _rec",
            $params
        );

        // Novos (primeira NF no período)
        $novos = $this->countQ(
            "SELECT COUNT(*) FROM (
                SELECT nf.codigo_cliente_omie, MIN(COALESCE(nf.data_competencia, nf.data_emissao)) AS primeira
                FROM biomie_notas_fiscais_canonicas nf
                WHERE nf.status_fiscal = 'autorizada'
                  AND nf.operation_scope = 'commercial'
                  AND {$this->visCond}
                  AND nf.codigo_cliente_omie IS NOT NULL
                GROUP BY nf.codigo_cliente_omie
                HAVING primeira BETWEEN :start AND :end
             ) _new",
            [':start' => $start, ':end' => $end]
        );

        // Sem dono
        $semDono = 0;
        if ($this->hasDimTable) {
            $semDono = $this->countQ(
                "SELECT COUNT(DISTINCT nf.codigo_cliente_omie)
                 FROM biomie_notas_fiscais_canonicas nf
                 LEFT JOIN biomie_clientes_dim d ON d.codigo_cliente_omie = nf.codigo_cliente_omie
                 WHERE nf.status_fiscal = 'autorizada'
                   AND nf.operation_scope = 'commercial'
                   AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
                   AND {$this->visCond}
                   AND (d.vendedor_responsavel_nome IS NULL OR d.vendedor_responsavel_nome = '')",
                $params
            );
        }

        // Conflitos (dim only)
        $conflitos = 0;
        $conflitosValor = 0.0;
        if ($this->hasDimTable) {
            try {
                $stmt = $this->pdo->prepare(
                    "SELECT COUNT(*) AS c,
                            COALESCE(SUM(COALESCE(nf.valor_base_comercial_nf, nf.valor_mercadorias)), 0) AS v
                     FROM biomie_notas_fiscais_canonicas nf
                     JOIN biomie_clientes_dim d ON d.codigo_cliente_omie = nf.codigo_cliente_omie
                     WHERE nf.status_fiscal = 'autorizada'
                       AND nf.operation_scope = 'commercial'
                       AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
                       AND {$this->visCond}
                       AND d.vendedor_responsavel_nome IS NOT NULL AND d.vendedor_responsavel_nome <> ''
                       AND nf.nome_vendedor <> d.vendedor_responsavel_nome{$vendorFilter}"
                );
                $stmt->execute($params);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $conflitos = (int)($row['c'] ?? 0);
                $conflitosValor = (float)($row['v'] ?? 0);
            } catch (Throwable) {}
        }

        return [
            'total_clientes_carteira' => $clientesAtivos,
            'clientes_ativos_periodo' => $clientesAtivos,
            'clientes_recorrentes'    => $recorrentes,
            'clientes_novos'          => $novos,
            'clientes_sem_dono'       => $semDono,
            'conflitos_fora_carteira' => $conflitos,
            'valor_fora_carteira'     => $conflitosValor,
        ];
    }

    /**
     * Clients in portfolio for a specific vendor, with metrics.
     *
     * @return list<array{codigo_cliente_omie:int|null,nome_cliente:string,documento_cliente:string,ownership_source:string,ownership_confidence:int,is_active:bool,is_recurring:bool,is_new:bool,ultima_compra:string,nfs:int,receita:float}>
     */
    public function resolvePortfolioByVendor(
        string $vendorName,
        string $start,
        string $end,
        int    $limit  = 100,
        int    $offset = 0
    ): array {
        if (!$this->hasNfTable) return [];

        $source1Rows = [];
        $source2Map  = [];

        // Source 1: dim table clients
        if ($this->hasDimTable) {
            try {
                $stmt = $this->pdo->prepare(
                    "SELECT d.codigo_cliente_omie,
                            MAX(nf.nome_cliente) AS nome_cliente,
                            MAX(nf.documento_cliente) AS documento_cliente,
                            'dim_responsavel' AS ownership_source,
                            100 AS ownership_confidence,
                            MAX(COALESCE(nf.data_competencia, nf.data_emissao)) AS ultima_compra_raw,
                            COUNT(CASE WHEN COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end AND nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial' AND {$this->visCond} THEN 1 END) AS nfs_periodo,
                            COALESCE(SUM(CASE WHEN COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end AND nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial' AND {$this->visCond} THEN COALESCE(nf.valor_base_comercial_nf, nf.valor_mercadorias) ELSE 0 END), 0) AS receita_periodo
                     FROM biomie_clientes_dim d
                     LEFT JOIN biomie_notas_fiscais_canonicas nf ON nf.codigo_cliente_omie = d.codigo_cliente_omie
                     WHERE d.vendedor_responsavel_nome = :vendor
                     GROUP BY d.codigo_cliente_omie
                     ORDER BY receita_periodo DESC
                     LIMIT {$limit} OFFSET {$offset}"
                );
                $stmt->execute([':start' => $start, ':end' => $end, ':vendor' => $vendorName]);
                $source1Rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable) {}
        }

        // Source 2: fallback — clients whose FIRST NF is from this vendor
        if (empty($source1Rows)) {
            try {
                $stmt = $this->pdo->prepare(
                    "SELECT t.codigo_cliente_omie,
                            MAX(nf.nome_cliente) AS nome_cliente,
                            MAX(nf.documento_cliente) AS documento_cliente,
                            'primeira_nf' AS ownership_source,
                            70 AS ownership_confidence,
                            MAX(COALESCE(nf.data_competencia, nf.data_emissao)) AS ultima_compra_raw,
                            COUNT(CASE WHEN COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end AND nf.status_fiscal = 'autorizada' AND {$this->visCond} THEN 1 END) AS nfs_periodo,
                            COALESCE(SUM(CASE WHEN COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end AND nf.status_fiscal = 'autorizada' AND {$this->visCond} THEN COALESCE(nf.valor_base_comercial_nf, nf.valor_mercadorias) ELSE 0 END), 0) AS receita_periodo
                     FROM (
                         SELECT codigo_cliente_omie
                         FROM biomie_notas_fiscais_canonicas
                         WHERE status_fiscal = 'autorizada' AND operation_scope = 'commercial'
                           AND codigo_cliente_omie IS NOT NULL
                         GROUP BY codigo_cliente_omie
                         HAVING MIN(COALESCE(data_competencia, data_emissao)) IN (
                             SELECT MIN(COALESCE(data_competencia, data_emissao))
                             FROM biomie_notas_fiscais_canonicas n2
                             WHERE n2.status_fiscal = 'autorizada' AND n2.operation_scope = 'commercial'
                               AND n2.codigo_cliente_omie = biomie_notas_fiscais_canonicas.codigo_cliente_omie
                               AND n2.nome_vendedor = :vendor
                         )
                     ) t
                     LEFT JOIN biomie_notas_fiscais_canonicas nf ON nf.codigo_cliente_omie = t.codigo_cliente_omie
                     GROUP BY t.codigo_cliente_omie
                     ORDER BY receita_periodo DESC
                     LIMIT {$limit} OFFSET {$offset}"
                );
                $stmt->execute([':start' => $start, ':end' => $end, ':vendor' => $vendorName]);
                $source1Rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable) {}
        }

        return array_map(function (array $r) use ($start): array {
            $isActive    = (int)($r['nfs_periodo'] ?? 0) > 0;
            $codigoCli   = $r['codigo_cliente_omie'] !== null ? (int)$r['codigo_cliente_omie'] : null;

            $isRecurring = false;
            if ($isActive && $codigoCli !== null) {
                $isRecurring = $this->isRecurringClient($codigoCli, $start, $r['ultima_compra_raw'] ?? '');
            }

            $isNew = false;
            if ($codigoCli !== null) {
                $firstNf = $this->firstNfDate($codigoCli);
                $isNew   = $firstNf !== '' && $firstNf >= $start;
            }

            return [
                'codigo_cliente_omie'  => $codigoCli,
                'nome_cliente'         => (string)($r['nome_cliente'] ?? ''),
                'documento_cliente'    => (string)($r['documento_cliente'] ?? ''),
                'ownership_source'     => (string)($r['ownership_source'] ?? 'sem_dono'),
                'ownership_confidence' => (int)($r['ownership_confidence'] ?? 0),
                'is_active'            => $isActive,
                'is_recurring'         => $isRecurring,
                'is_new'               => $isNew,
                'ultima_compra'        => substr((string)($r['ultima_compra_raw'] ?? ''), 0, 10),
                'nfs'                  => (int)($r['nfs_periodo'] ?? 0),
                'receita'              => (float)($r['receita_periodo'] ?? 0),
            ];
        }, $source1Rows);
    }

    /**
     * Conflicts: NFs where sale vendor ≠ portfolio owner.
     *
     * @return list<array{nome_cliente:string,vendedor_venda:string,dono_carteira:string,nfs:int,valor:float,severity:string}>
     */
    public function resolveConflicts(string $start, string $end, ?string $vendorName = null, int $limit = 50): array
    {
        if (!$this->hasNfTable || !$this->hasDimTable) return [];

        $vendorFilter = '';
        $params = [':start' => $start, ':end' => $end];
        if ($vendorName !== null && $vendorName !== '') {
            $vendorFilter = ' AND nf.nome_vendedor = :vendor';
            $params[':vendor'] = $vendorName;
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT nf.nome_cliente, nf.nome_vendedor AS vendedor_venda,
                        d.vendedor_responsavel_nome AS dono_carteira,
                        COUNT(*) AS nfs,
                        COALESCE(SUM(COALESCE(nf.valor_base_comercial_nf, nf.valor_mercadorias)), 0) AS valor
                 FROM biomie_notas_fiscais_canonicas nf
                 JOIN biomie_clientes_dim d ON d.codigo_cliente_omie = nf.codigo_cliente_omie
                 WHERE nf.status_fiscal = 'autorizada'
                   AND nf.operation_scope = 'commercial'
                   AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
                   AND {$this->visCond}
                   AND d.vendedor_responsavel_nome IS NOT NULL AND d.vendedor_responsavel_nome <> ''
                   AND nf.nome_vendedor <> d.vendedor_responsavel_nome{$vendorFilter}
                 GROUP BY nf.nome_cliente, nf.nome_vendedor, d.vendedor_responsavel_nome
                 ORDER BY valor DESC
                 LIMIT {$limit}"
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }

        return array_map(fn(array $r) => [
            'nome_cliente'   => (string)($r['nome_cliente'] ?? ''),
            'vendedor_venda' => (string)($r['vendedor_venda'] ?? ''),
            'dono_carteira'  => (string)($r['dono_carteira'] ?? ''),
            'nfs'            => (int)($r['nfs'] ?? 0),
            'valor'          => (float)($r['valor'] ?? 0),
            'severity'       => 'info',
        ], $rows);
    }

    /**
     * Recurring clients for a vendor (2+ distinct months).
     *
     * @return list<array{codigo_cliente_omie:int|null,nome_cliente:string,meses_distintos:int,nfs:int,receita:float}>
     */
    public function resolveRecurring(string $start, string $end, ?string $vendorName = null, int $limit = 50): array
    {
        if (!$this->hasNfTable) return [];

        $vendorFilter = '';
        $params = [':start' => $start, ':end' => $end];
        if ($vendorName !== null && $vendorName !== '') {
            $vendorFilter = ' AND nf.nome_vendedor = :vendor';
            $params[':vendor'] = $vendorName;
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT nf.codigo_cliente_omie,
                        MAX(nf.nome_cliente) AS nome_cliente,
                        COUNT(DISTINCT DATE_FORMAT(COALESCE(nf.data_competencia, nf.data_emissao), '%Y-%m')) AS meses_distintos,
                        COUNT(*) AS nfs,
                        COALESCE(SUM(COALESCE(nf.valor_base_comercial_nf, nf.valor_mercadorias)), 0) AS receita
                 FROM biomie_notas_fiscais_canonicas nf
                 WHERE nf.status_fiscal = 'autorizada'
                   AND nf.operation_scope = 'commercial'
                   AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
                   AND {$this->visCond}
                   AND nf.codigo_cliente_omie IS NOT NULL{$vendorFilter}
                 GROUP BY nf.codigo_cliente_omie
                 HAVING meses_distintos >= 2
                 ORDER BY receita DESC
                 LIMIT {$limit}"
            );
            $stmt->execute($params);
            return array_map(fn(array $r) => [
                'codigo_cliente_omie' => $r['codigo_cliente_omie'] !== null ? (int)$r['codigo_cliente_omie'] : null,
                'nome_cliente'        => (string)($r['nome_cliente'] ?? ''),
                'meses_distintos'     => (int)($r['meses_distintos'] ?? 0),
                'nfs'                 => (int)($r['nfs'] ?? 0),
                'receita'             => (float)($r['receita'] ?? 0),
            ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * New clients (first NF within the period).
     *
     * @return list<array{codigo_cliente_omie:int|null,nome_cliente:string,vendedor:string,primeira_nf:string,nfs:int,receita:float}>
     */
    public function resolveNewCustomers(string $start, string $end, int $limit = 50): array
    {
        if (!$this->hasNfTable) return [];

        try {
            $stmt = $this->pdo->prepare(
                "SELECT nf.codigo_cliente_omie,
                        MAX(nf.nome_cliente) AS nome_cliente,
                        MAX(nf.nome_vendedor) AS vendedor,
                        MIN(COALESCE(nf.data_competencia, nf.data_emissao)) AS primeira_nf,
                        COUNT(*) AS nfs,
                        COALESCE(SUM(COALESCE(nf.valor_base_comercial_nf, nf.valor_mercadorias)), 0) AS receita
                 FROM biomie_notas_fiscais_canonicas nf
                 WHERE nf.status_fiscal = 'autorizada'
                   AND nf.operation_scope = 'commercial'
                   AND {$this->visCond}
                   AND nf.codigo_cliente_omie IS NOT NULL
                 GROUP BY nf.codigo_cliente_omie
                 HAVING MIN(COALESCE(nf.data_competencia, nf.data_emissao)) BETWEEN :start AND :end
                 ORDER BY receita DESC
                 LIMIT {$limit}"
            );
            $stmt->execute([':start' => $start, ':end' => $end]);
            return array_map(fn(array $r) => [
                'codigo_cliente_omie' => $r['codigo_cliente_omie'] !== null ? (int)$r['codigo_cliente_omie'] : null,
                'nome_cliente'        => (string)($r['nome_cliente'] ?? ''),
                'vendedor'            => (string)($r['vendedor'] ?? ''),
                'primeira_nf'         => substr((string)($r['primeira_nf'] ?? ''), 0, 10),
                'nfs'                 => (int)($r['nfs'] ?? 0),
                'receita'             => (float)($r['receita'] ?? 0),
            ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable) {
            return [];
        }
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function tableExists(string $table): bool
    {
        try {
            $s = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t LIMIT 1');
            $s->execute(['t' => $table]);
            return (int) $s->fetchColumn() > 0;
        } catch (Throwable) { return false; }
    }

    private function countQ(string $sql, array $params = []): int
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (Throwable) { return 0; }
    }

    private function isRecurringClient(int $codigoCli, string $start, string $end): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(DISTINCT DATE_FORMAT(COALESCE(data_competencia, data_emissao), '%Y-%m'))
                 FROM biomie_notas_fiscais_canonicas
                 WHERE status_fiscal = 'autorizada'
                   AND operation_scope = 'commercial'
                   AND codigo_cliente_omie = :cli
                   AND COALESCE(data_competencia, data_emissao) BETWEEN :s AND :e"
            );
            $stmt->execute([':cli' => $codigoCli, ':s' => $start, ':e' => $end]);
            return (int)$stmt->fetchColumn() >= 2;
        } catch (Throwable) { return false; }
    }

    private function firstNfDate(int $codigoCli): string
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT MIN(COALESCE(data_competencia, data_emissao))
                 FROM biomie_notas_fiscais_canonicas
                 WHERE status_fiscal = 'autorizada' AND operation_scope = 'commercial'
                   AND codigo_cliente_omie = :cli"
            );
            $stmt->execute([':cli' => $codigoCli]);
            $val = $stmt->fetchColumn();
            return $val !== false && $val !== null ? (string)$val : '';
        } catch (Throwable) { return ''; }
    }

    /** @return array<string,mixed> */
    private function emptySummary(): array
    {
        return [
            'total_clientes_carteira' => 0,
            'clientes_ativos_periodo' => 0,
            'clientes_recorrentes'    => 0,
            'clientes_novos'          => 0,
            'clientes_sem_dono'       => 0,
            'conflitos_fora_carteira' => 0,
            'valor_fora_carteira'     => 0.0,
        ];
    }
}
