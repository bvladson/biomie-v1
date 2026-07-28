<?php

declare(strict_types=1);

namespace BIomie\Sync;

use BIomie\CanonicalRepository;
use BIomie\Logger;
use BIomie\Support\TenantContext;

/**
 * Serviço responsável por identificar todos os clientes com movimentação (notas e pedidos)
 * e enfileirados para enriquecimento geográfico/perfilamento.
 */
final class GeoBackfillService
{
    public function __construct(
        private readonly CanonicalRepository $canonical,
        private readonly Logger $logger
    ) {
    }

    /**
     * @return int Quantidade de novos clientes enfileirados.
     */
    public function enqueueMissingClients(): int
    {
        $this->logger->info('GeoBackfillService: iniciando varredura de clientes carentes de enriquecimento.');

        $pdo = $this->canonical->getPdo();

        // Insere clientes vindos das NFs emitidas
        $sqlNf = "
            INSERT IGNORE INTO biomie_clientes_para_enriquecer (company_id, codigo_cliente_omie, source_origin)
            SELECT DISTINCT company_id, codigo_cliente_omie, 'canonica_nf'
            FROM biomie_notas_fiscais_canonicas nf
            WHERE " . TenantContext::sqlFilter('nf') . "
              AND codigo_cliente_omie > 0
              AND NOT EXISTS (
                  SELECT 1 FROM biomie_clientes_geo_dim g
                  WHERE g.company_id = nf.company_id
                    AND g.codigo_cliente_omie = nf.codigo_cliente_omie
              )
        ";

        // Insere clientes vindos dos Pedidos emitidos
        $sqlPedido = "
            INSERT IGNORE INTO biomie_clientes_para_enriquecer (company_id, codigo_cliente_omie, source_origin)
            SELECT DISTINCT company_id, codigo_cliente_omie, 'canonica_pedido'
            FROM biomie_pedidos_canonicos p
            WHERE " . TenantContext::sqlFilter('p') . "
              AND codigo_cliente_omie > 0
              AND NOT EXISTS (
                  SELECT 1 FROM biomie_clientes_geo_dim g
                  WHERE g.company_id = p.company_id
                    AND g.codigo_cliente_omie = p.codigo_cliente_omie
              )
        ";

        // Insere clientes do contas a receber (Titulos Financeiros)
        $sqlFinanceiro = "
            INSERT IGNORE INTO biomie_clientes_para_enriquecer (company_id, codigo_cliente_omie, source_origin)
            SELECT DISTINCT company_id, codigo_cliente_omie, 'canonica_titulo'
            FROM biomie_titulos_financeiros_canonicos t
            WHERE " . TenantContext::sqlFilter('t') . "
              AND codigo_cliente_omie > 0
              AND NOT EXISTS (
                  SELECT 1 FROM biomie_clientes_geo_dim g
                  WHERE g.company_id = t.company_id
                    AND g.codigo_cliente_omie = t.codigo_cliente_omie
              )
        ";

        $sqlIncompleteGeo = "
            INSERT INTO biomie_clientes_para_enriquecer
                (company_id, codigo_cliente_omie, source_origin, status, motivo)
            SELECT DISTINCT
                g.company_id,
                g.codigo_cliente_omie,
                'geo_dim_incomplete',
                'pendente',
                CASE
                    WHEN g.cidade_norm IS NULL OR TRIM(g.cidade_norm) = '' THEN 'cidade_ausente'
                    ELSE 'bairro_ausente'
                END
            FROM biomie_clientes_geo_dim g
            WHERE " . TenantContext::sqlFilter('g') . "
              AND g.codigo_cliente_omie > 0
              AND (
                    g.cidade_norm IS NULL OR TRIM(g.cidade_norm) = ''
                    OR g.bairro_norm IS NULL OR TRIM(g.bairro_norm) = ''
                    OR LOWER(TRIM(g.bairro_norm)) IN ('nao informado', 'nao_informado', 'sem bairro', 'sem_bairro')
              )
            ON DUPLICATE KEY UPDATE
                source_origin = VALUES(source_origin),
                status = 'pendente',
                motivo = VALUES(motivo)
        ";

        $totalInserted = 0;

        try {
            $stmt = $pdo->prepare($sqlNf);
            $stmt->execute();
            $totalInserted += $stmt->rowCount();

            $stmt = $pdo->prepare($sqlPedido);
            $stmt->execute();
            $totalInserted += $stmt->rowCount();

            $stmt = $pdo->prepare($sqlFinanceiro);
            $stmt->execute();
            $totalInserted += $stmt->rowCount();

            $stmt = $pdo->prepare($sqlIncompleteGeo);
            $stmt->execute();
            $totalInserted += $stmt->rowCount();

            $this->logger->info('GeoBackfillService: varredura finalizada.', ['novos_enfileirados' => $totalInserted]);
        } catch (\Throwable $e) {
            $this->logger->error('Falha ao enfileirar clientes.', ['erro' => $e->getMessage()]);
        }

        return $totalInserted;
    }
}
