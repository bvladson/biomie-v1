<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Infrastructure;

use PDO;
use Throwable;

final class Geo360ReadRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function summary(string $neighborhoodKey, string $start, string $end): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    COALESCE(SUM(m.faturamento), 0)                                                AS revenue,
                    COALESCE(SUM(m.qtd_notas), 0)                                                  AS invoices_count,
                    COUNT(DISTINCT m.codigo_cliente_omie)                                           AS customers_count,
                    IF(SUM(m.qtd_notas) > 0, SUM(m.faturamento) / SUM(m.qtd_notas), 0)           AS avg_ticket,
                    MIN(m.competencia)                                                              AS first_sale_at,
                    MAX(m.competencia)                                                              AS last_sale_at,
                    COUNT(DISTINCT m.cnae_codigo)                                                   AS cnaes_count
                FROM mart_compradores_por_geo m
                WHERE LOWER(TRIM(m.bairro_norm)) = :neighborhood_key
                  AND m.competencia BETWEEN :start_comp AND :end_comp
            ");
            $stmt->execute([
                'neighborhood_key' => $neighborhoodKey,
                'start_comp'       => substr($start, 0, 7),
                'end_comp'         => substr($end, 0, 7),
            ]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    public function buyers(string $neighborhoodKey, string $start, string $end, int $limit): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    m.codigo_cliente_omie,
                    MAX(m.cliente_nome)                     AS customer_name,
                    MAX(m.documento)                        AS document_masked,
                    SUM(m.faturamento)                      AS revenue,
                    SUM(m.qtd_notas)                        AS invoices_count,
                    MAX(m.bairro_norm)                      AS bairro_norm,
                    MAX(m.cidade_norm)                      AS cidade_norm
                FROM mart_compradores_por_geo m
                WHERE LOWER(TRIM(m.bairro_norm)) = :neighborhood_key
                  AND m.competencia BETWEEN :start_comp AND :end_comp
                GROUP BY m.codigo_cliente_omie
                ORDER BY SUM(m.faturamento) DESC
                LIMIT :limit
            ");
            $stmt->bindValue(':neighborhood_key', $neighborhoodKey);
            $stmt->bindValue(':start_comp', substr($start, 0, 7));
            $stmt->bindValue(':end_comp', substr($end, 0, 7));
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    public function cnaes(string $neighborhoodKey, string $start, string $end, int $limit): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    COALESCE(NULLIF(m.cnae_codigo, ''), 'SEM_CNAE')    AS cnae_codigo,
                    COALESCE(NULLIF(m.cnae_descricao, ''), 'Sem CNAE') AS cnae_descricao,
                    COUNT(DISTINCT m.codigo_cliente_omie)               AS customers_count,
                    SUM(m.faturamento)                                  AS revenue,
                    SUM(m.qtd_notas)                                    AS invoices_count
                FROM mart_compradores_por_geo m
                WHERE LOWER(TRIM(m.bairro_norm)) = :neighborhood_key
                  AND m.competencia BETWEEN :start_comp AND :end_comp
                GROUP BY COALESCE(NULLIF(m.cnae_codigo, ''), 'SEM_CNAE'),
                         COALESCE(NULLIF(m.cnae_descricao, ''), 'Sem CNAE')
                ORDER BY SUM(m.faturamento) DESC
                LIMIT :limit
            ");
            $stmt->bindValue(':neighborhood_key', $neighborhoodKey);
            $stmt->bindValue(':start_comp', substr($start, 0, 7));
            $stmt->bindValue(':end_comp', substr($end, 0, 7));
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    public function invoices(string $neighborhoodKey, string $start, string $end, int $limit): array
    {
        try {
            if (!$this->tableExists('biomie_clientes_geo_dim')) {
                return [];
            }
            $stmt = $this->pdo->prepare("
                SELECT
                    n.nota_key,
                    n.numero_nf,
                    n.serie_nf,
                    n.data_competencia,
                    n.codigo_cliente_omie,
                    n.codigo_vendedor_omie,
                    n.valor_total           AS invoice_total,
                    n.status_fiscal
                FROM biomie_notas_fiscais_canonicas n
                JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie = n.codigo_cliente_omie
                WHERE n.status_fiscal = 'autorizada'
                  AND LOWER(TRIM(g.bairro_norm)) = :neighborhood_key
                  AND n.data_competencia BETWEEN :start AND :end
                ORDER BY n.data_competencia DESC, n.valor_total DESC
                LIMIT :limit
            ");
            $stmt->bindValue(':neighborhood_key', $neighborhoodKey);
            $stmt->bindValue(':start', $start);
            $stmt->bindValue(':end', $end);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    public function vendors(string $neighborhoodKey, string $start, string $end, int $limit): array
    {
        try {
            if (!$this->tableExists('biomie_clientes_geo_dim')) {
                return [];
            }
            $stmt = $this->pdo->prepare("
                SELECT
                    n.codigo_vendedor_omie,
                    COALESCE(NULLIF(n.nome_vendedor_omie, ''), CONCAT('Vendedor ', n.codigo_vendedor_omie)) AS vendor_name,
                    COUNT(DISTINCT n.nota_key)  AS invoices_count,
                    SUM(n.valor_total)          AS revenue,
                    COUNT(DISTINCT n.codigo_cliente_omie) AS customers_count
                FROM biomie_notas_fiscais_canonicas n
                JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie = n.codigo_cliente_omie
                WHERE n.status_fiscal = 'autorizada'
                  AND LOWER(TRIM(g.bairro_norm)) = :neighborhood_key
                  AND n.data_competencia BETWEEN :start AND :end
                GROUP BY n.codigo_vendedor_omie, vendor_name
                ORDER BY SUM(n.valor_total) DESC
                LIMIT :limit
            ");
            $stmt->bindValue(':neighborhood_key', $neighborhoodKey);
            $stmt->bindValue(':start', $start);
            $stmt->bindValue(':end', $end);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    public function timeline(string $neighborhoodKey, string $start, string $end): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    m.competencia                           AS bucket,
                    SUM(m.faturamento)                      AS revenue,
                    SUM(m.qtd_notas)                        AS invoices_count,
                    COUNT(DISTINCT m.codigo_cliente_omie)   AS customers_count
                FROM mart_compradores_por_geo m
                WHERE LOWER(TRIM(m.bairro_norm)) = :neighborhood_key
                  AND m.competencia BETWEEN :start_comp AND :end_comp
                GROUP BY m.competencia
                ORDER BY m.competencia ASC
            ");
            $stmt->execute([
                'neighborhood_key' => $neighborhoodKey,
                'start_comp'       => substr($start, 0, 7),
                'end_comp'         => substr($end, 0, 7),
            ]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    public function martRowCount(string $neighborhoodKey, string $start, string $end): int
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM mart_compradores_por_geo
                WHERE LOWER(TRIM(bairro_norm)) = :neighborhood_key
                  AND competencia BETWEEN :start_comp AND :end_comp
            ");
            $stmt->execute([
                'neighborhood_key' => $neighborhoodKey,
                'start_comp'       => substr($start, 0, 7),
                'end_comp'         => substr($end, 0, 7),
            ]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    public function lastGoldMaterializedAt(string $neighborhoodKey): ?string
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT MAX(refreshed_at) FROM mart_compradores_por_geo
                WHERE LOWER(TRIM(bairro_norm)) = :neighborhood_key
            ");
            $stmt->execute(['neighborhood_key' => $neighborhoodKey]);
            $val = $stmt->fetchColumn();
            return $val ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    public function lastCanonicalNfAt(): ?string
    {
        try {
            $stmt = $this->pdo->query(
                "SELECT MAX(updated_at) FROM biomie_notas_fiscais_canonicas WHERE status_fiscal = 'autorizada'"
            );
            $val = $stmt->fetchColumn();
            return $val ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    public function officialNeighborhoodName(string $neighborhoodKey): ?string
    {
        try {
            if (!$this->tableExists('biomie_bairros_fortaleza_dim')) {
                return null;
            }
            $stmt = $this->pdo->prepare("
                SELECT bairro_nome FROM biomie_bairros_fortaleza_dim
                WHERE LOWER(TRIM(bairro_nome_normalizado)) = :key AND ativo = 1
                LIMIT 1
            ");
            $stmt->execute(['key' => $neighborhoodKey]);
            $val = $stmt->fetchColumn();
            return $val ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
            );
            $stmt->execute([$table]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
