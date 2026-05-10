<?php

declare(strict_types=1);

namespace BIomie\Modules\Geo\Infrastructure;

use BIomie\TableHealthService;
use PDO;
use Throwable;

final class GeoCustomerSourceRepository
{
    public function __construct(
        private readonly PDO                $pdo,
        private readonly TableHealthService $tableHealth,
    ) {}

    public function countMissingSkeletons(): int
    {
        $row = $this->pdo->query(
            "SELECT COUNT(*) FROM biomie_clientes_dim AS d
             LEFT JOIN biomie_clientes_geo_dim AS g ON g.codigo_cliente_omie = d.codigo_cliente_omie
             WHERE g.id IS NULL"
        );
        return $row !== false ? (int) $row->fetchColumn() : 0;
    }

    public function countMatchable(): int
    {
        $row = $this->pdo->query(
            "SELECT COUNT(*) FROM biomie_clientes_geo_dim AS g
             JOIN biomie_bairros_fortaleza_dim AS b ON b.bairro_nome_normalizado = g.bairro_norm
             WHERE g.bairro_oficial_id_fortaleza IS NULL
               AND g.bairro_norm IS NOT NULL
               AND g.bairro_norm != ''"
        );
        return $row !== false ? (int) $row->fetchColumn() : 0;
    }

    public function countQueueable(): int
    {
        $row = $this->pdo->query(
            "SELECT COUNT(*) FROM biomie_clientes_geo_dim AS g
             WHERE g.bairro_oficial_id_fortaleza IS NULL"
        );
        return $row !== false ? (int) $row->fetchColumn() : 0;
    }

    public function hasClientesWithCnae(): bool
    {
        try {
            return (int) $this->pdo->query(
                "SELECT COUNT(*) FROM biomie_clientes_dim
                 WHERE cnae_codigo IS NOT NULL AND cnae_codigo != ''"
            )->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string,int> */
    public function buildCoverage(): array
    {
        try {
            $row = $this->pdo->query(
                "SELECT
                    COUNT(*)                                                               AS total_geo,
                    SUM(bairro_oficial_id_fortaleza IS NOT NULL)                          AS com_bairro_oficial,
                    SUM(bairro_oficial_id_fortaleza IS NULL)                              AS sem_bairro_oficial,
                    SUM(bairro_norm IS NOT NULL AND bairro_norm != '')                    AS com_bairro_norm,
                    SUM(bairro_norm IS NULL OR bairro_norm = '')                          AS sem_bairro_norm,
                    SUM(uf = 'CE')                                                        AS clientes_ce,
                    (SELECT COUNT(*) FROM biomie_bairros_fortaleza_dim WHERE ativo = 1)   AS bairros_dim_ativos
                 FROM biomie_clientes_geo_dim"
            );
            $data = $row !== false ? $row->fetch(PDO::FETCH_ASSOC) : [];
            return [
                'total_geo'          => (int) ($data['total_geo']          ?? 0),
                'com_bairro_oficial' => (int) ($data['com_bairro_oficial']  ?? 0),
                'sem_bairro_oficial' => (int) ($data['sem_bairro_oficial']  ?? 0),
                'com_bairro_norm'    => (int) ($data['com_bairro_norm']     ?? 0),
                'sem_bairro_norm'    => (int) ($data['sem_bairro_norm']     ?? 0),
                'clientes_ce'        => (int) ($data['clientes_ce']         ?? 0),
                'bairros_dim_ativos' => (int) ($data['bairros_dim_ativos']  ?? 0),
                'bairros_expected'   => 121,
            ];
        } catch (Throwable) {
            return ['bairros_expected' => 121];
        }
    }

    /** @param list<string> $candidates */
    public function pickColumn(string $table, array $candidates): ?string
    {
        foreach ($candidates as $col) {
            if ($this->columnExists($table, $col)) {
                return $col;
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    public function buildDryRunSnapshot(): array
    {
        $snap = [];
        $tables = [
            'biomie_clientes_dim', 'biomie_clientes_geo_dim', 'biomie_bairros_fortaleza_dim',
            'biomie_clientes_para_enriquecer', 'mart_cliente_periodo', 'mart_fiscal_nfe',
            'biomie_vendas_geo_cliente_fact', 'biomie_geo_vendas_diaria',
            'mart_compradores_por_geo', 'mart_vendas_por_cnae_cnpj',
        ];
        foreach ($tables as $t) {
            $exists = $this->tableHealth->tableExists($t);
            $snap['tables'][$t] = ['exists' => $exists, 'count' => $exists ? (int) ($this->tableHealth->safeCount($t) ?? 0) : 0];
        }

        $snap['schema'] = [
            'geo_dim_has_bairro_norm'    => $this->columnExists('biomie_clientes_geo_dim', 'bairro_norm'),
            'geo_dim_has_bairro_raw'     => $this->columnExists('biomie_clientes_geo_dim', 'bairro_raw'),
            'clientes_dim_has_cnae'      => $this->columnExists('biomie_clientes_dim', 'cnae_codigo'),
            'clientes_dim_has_cidade'    => $this->columnExists('biomie_clientes_dim', 'cidade'),
            'enriquecer_has_motivo'      => $this->columnExists('biomie_clientes_para_enriquecer', 'motivo'),
            'enriquecer_has_cliente_dim' => $this->columnExists('biomie_clientes_para_enriquecer', 'cliente_dim_id'),
        ];

        if ($this->tableHealth->tableExists('biomie_clientes_geo_dim')) {
            try {
                $row = $this->pdo->query(
                    "SELECT COUNT(*) AS total,
                            SUM(bairro_norm IS NOT NULL AND bairro_norm != '')    AS com_bairro_norm,
                            SUM(bairro_raw IS NOT NULL AND bairro_raw != '')      AS com_bairro_raw,
                            SUM(bairro_oficial_id_fortaleza IS NOT NULL)          AS com_match_oficial,
                            SUM(uf = 'CE')                                        AS clientes_ce
                     FROM biomie_clientes_geo_dim"
                )->fetch(PDO::FETCH_ASSOC);
                $snap['geo_coverage'] = [
                    'total'             => (int) ($row['total']            ?? 0),
                    'com_bairro_norm'   => (int) ($row['com_bairro_norm']  ?? 0),
                    'com_bairro_raw'    => (int) ($row['com_bairro_raw']   ?? 0),
                    'com_match_oficial' => (int) ($row['com_match_oficial'] ?? 0),
                    'clientes_ce'       => (int) ($row['clientes_ce']      ?? 0),
                    'sem_bairro_norm'   => max(0, (int) ($row['total'] ?? 0) - (int) ($row['com_bairro_norm'] ?? 0)),
                ];
            } catch (Throwable) {
                $snap['geo_coverage'] = [];
            }
        }

        if ($this->tableHealth->tableExists('biomie_clientes_dim')) {
            try {
                $row = $this->pdo->query(
                    "SELECT COUNT(*) AS total,
                            SUM(documento_normalizado IS NOT NULL AND documento_normalizado != '') AS com_doc,
                            SUM(vendedor_responsavel_confidence > 0)                              AS com_vend,
                            SUM(cnae_codigo IS NOT NULL AND cnae_codigo != '')                    AS com_cnae,
                            SUM(cidade IS NOT NULL AND cidade != '')                              AS com_cidade
                     FROM biomie_clientes_dim"
                )->fetch(PDO::FETCH_ASSOC);
                $snap['clientes_dim_stats'] = [
                    'total'      => (int) ($row['total']      ?? 0),
                    'com_doc'    => (int) ($row['com_doc']    ?? 0),
                    'com_vend'   => (int) ($row['com_vend']   ?? 0),
                    'com_cnae'   => (int) ($row['com_cnae']   ?? 0),
                    'com_cidade' => (int) ($row['com_cidade'] ?? 0),
                    'sem_cnae'   => max(0, (int) ($row['total'] ?? 0) - (int) ($row['com_cnae'] ?? 0)),
                ];
            } catch (Throwable) {
                $snap['clientes_dim_stats'] = [];
            }
        }

        if ($this->tableHealth->tableExists('biomie_clientes_geo_dim')
            && $this->tableHealth->tableExists('biomie_bairros_fortaleza_dim')) {
            try {
                $stmt = $this->pdo->query(
                    "SELECT g.bairro_norm, COUNT(*) AS clientes
                     FROM biomie_clientes_geo_dim AS g
                     WHERE g.bairro_norm IS NOT NULL AND g.bairro_norm != ''
                       AND g.bairro_oficial_id_fortaleza IS NULL
                     GROUP BY g.bairro_norm
                     ORDER BY clientes DESC
                     LIMIT 10"
                );
                $snap['bairros_sem_match'] = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            } catch (Throwable) {
                $snap['bairros_sem_match'] = [];
            }
        }

        $martPeriodoCount = $this->tableHealth->tableExists('mart_cliente_periodo')
            ? (int) ($this->tableHealth->safeCount('mart_cliente_periodo') ?? 0) : 0;

        $snap['estimates'] = [
            'skeletons_a_criar'     => $this->countMissingSkeletons(),
            'bairros_a_fazer_match' => $this->countMatchable(),
            'clientes_a_enfileirar' => $this->countQueueable(),
            'compradores_geo_est'   => $martPeriodoCount,
        ];

        return $snap;
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
            );
            $stmt->execute([$table, $column]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
