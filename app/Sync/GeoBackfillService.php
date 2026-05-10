<?php

declare(strict_types=1);

namespace BIomie\Sync;

use BIomie\CanonicalRepository;
use BIomie\Logger;

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
            INSERT IGNORE INTO biomie_clientes_para_enriquecer (codigo_cliente_omie, source_origin)
            SELECT DISTINCT codigo_cliente_omie, 'canonica_nf'
            FROM biomie_notas_fiscais_canonicas
            WHERE codigo_cliente_omie > 0
              AND codigo_cliente_omie NOT IN (SELECT codigo_cliente_omie FROM biomie_clientes_geo_dim)
        ";

        // Insere clientes vindos dos Pedidos emitidos
        $sqlPedido = "
            INSERT IGNORE INTO biomie_clientes_para_enriquecer (codigo_cliente_omie, source_origin)
            SELECT DISTINCT codigo_cliente_omie, 'canonica_pedido'
            FROM biomie_pedidos_canonicos
            WHERE codigo_cliente_omie > 0
              AND codigo_cliente_omie NOT IN (SELECT codigo_cliente_omie FROM biomie_clientes_geo_dim)
        ";

        // Insere clientes do contas a receber (Titulos Financeiros)
        $sqlFinanceiro = "
            INSERT IGNORE INTO biomie_clientes_para_enriquecer (codigo_cliente_omie, source_origin)
            SELECT DISTINCT codigo_cliente_omie, 'canonica_titulo'
            FROM biomie_titulos_financeiros_canonicas
            WHERE codigo_cliente_omie > 0
              AND codigo_cliente_omie NOT IN (SELECT codigo_cliente_omie FROM biomie_clientes_geo_dim)
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

            $this->logger->info('GeoBackfillService: varredura finalizada.', ['novos_enfileirados' => $totalInserted]);
        } catch (\Throwable $e) {
            $this->logger->error('Falha ao enfileirar clientes.', ['erro' => $e->getMessage()]);
        }

        return $totalInserted;
    }
}
