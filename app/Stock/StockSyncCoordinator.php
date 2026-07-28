<?php

declare(strict_types=1);

namespace BIomie\Stock;

use BIomie\Logger;
use BIomie\Support\TenantContext;
use PDO;
use Throwable;

/**
 * EST.R1 — Coordenador de sincronização de estoque Omie → DB.
 *
 * Semântica dry-run / apply:
 *   - dry-run (padrão): lê Omie, calcula diff, retorna relatório sem persistir.
 *   - apply: persiste locais, saldos e movimentos nas tabelas canônicas.
 *
 * Nunca altera tabelas comerciais, fiscais ou financeiras.
 * Usa INSERT ... ON DUPLICATE KEY UPDATE (idempotente).
 */
final class StockSyncCoordinator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly OmieStockClient $omieClient,
        private readonly Logger $logger,
        private readonly bool $dryRun = true,
    ) {}

    /**
     * Sincroniza locais de estoque.
     *
     * @return array{synced:int,errors:int,dry_run:bool,items:list<array<string,mixed>>}
     */
    public function syncLocais(): array
    {
        $synced = 0;
        $errors = 0;
        $items  = [];

        try {
            $pagina   = 1;
            $porPag   = 50;
            do {
                $lote = $this->omieClient->listarLocais($pagina, $porPag);
                foreach ($lote as $raw) {
                    $mapped = StockPayloadMapper::mapLocal($raw);
                    $items[] = $mapped;
                    if (!$this->dryRun) {
                        $this->upsertLocal($mapped);
                    }
                    $synced++;
                }
                $pagina++;
            } while (count($lote) >= $porPag);
        } catch (Throwable $e) {
            $errors++;
            $this->logger->error('StockSyncCoordinator::syncLocais falhou', ['error' => $e->getMessage()]);
        }

        return ['synced' => $synced, 'errors' => $errors, 'dry_run' => $this->dryRun, 'items' => $this->dryRun ? array_slice($items, 0, 5) : []];
    }

    /**
     * Sincroniza saldos de estoque (todos os produtos ou filtro).
     *
     * @return array{synced:int,errors:int,dry_run:bool,sample:list<array<string,mixed>>}
     */
    public function syncSaldos(string $codigoProduto = '', int $limit = 0): array
    {
        $synced = 0;
        $errors = 0;
        $sample = [];

        try {
            $pagina = 1;
            $porPag = 50;
            do {
                $lote = $this->omieClient->resumoEstoque($pagina, $porPag, $codigoProduto);
                foreach ($lote as $raw) {
                    $mapped = StockPayloadMapper::mapSaldo($raw);
                    if (count($sample) < 5) $sample[] = $mapped;
                    if (!$this->dryRun) {
                        $this->upsertSaldo($mapped);
                    }
                    $synced++;
                }
                $pagina++;
                if ($limit > 0 && $synced >= $limit) break;
            } while (count($lote) >= $porPag);
        } catch (Throwable $e) {
            $errors++;
            $this->logger->error('StockSyncCoordinator::syncSaldos falhou', ['error' => $e->getMessage()]);
        }

        return ['synced' => $synced, 'errors' => $errors, 'dry_run' => $this->dryRun, 'sample' => $sample];
    }

    /**
     * Sincroniza movimentos de estoque em um período.
     *
     * @return array{synced:int,errors:int,dry_run:bool,sample:list<array<string,mixed>>}
     */
    public function syncMovimentos(string $dataInicio, string $dataFim, string $codigoProduto = '', int $limit = 0): array
    {
        $synced = 0;
        $errors = 0;
        $sample = [];

        try {
            $pagina = 1;
            $porPag = 50;
            do {
                $lote = $this->omieClient->movimentos($dataInicio, $dataFim, $pagina, $porPag, $codigoProduto);
                foreach ($lote as $raw) {
                    $mapped = StockPayloadMapper::mapMovimento($raw);
                    if (count($sample) < 5) $sample[] = $mapped;
                    if (!$this->dryRun) {
                        $this->insertMovimento($mapped);
                    }
                    $synced++;
                }
                $pagina++;
                if ($limit > 0 && $synced >= $limit) break;
            } while (count($lote) >= $porPag);
        } catch (Throwable $e) {
            $errors++;
            $this->logger->error('StockSyncCoordinator::syncMovimentos falhou', ['error' => $e->getMessage()]);
        }

        return ['synced' => $synced, 'errors' => $errors, 'dry_run' => $this->dryRun, 'sample' => $sample];
    }

    // ── Write ops (only when !dryRun) ─────────────────────────────────────────

    private function upsertLocal(array $data): void
    {
        $this->pdo->prepare(
            "INSERT INTO biomie_estoque_locais_dim
                (company_id, codigo_local, nome_local, ativo, permite_venda, raw_source)
             VALUES
                (:company_id, :codigo_local, :nome_local, :ativo, :permite_venda, :raw_source)
             ON DUPLICATE KEY UPDATE
                nome_local    = VALUES(nome_local),
                ativo         = VALUES(ativo),
                permite_venda = VALUES(permite_venda),
                raw_source    = VALUES(raw_source)"
        )->execute(['company_id' => TenantContext::companyId()] + $data);
    }

    private function upsertSaldo(array $data): void
    {
        $this->pdo->prepare(
            "INSERT INTO biomie_produtos_estoque_saldos
                (company_id, codigo_produto, codigo_local, descricao_produto, nome_local,
                 estoque_atual, estoque_minimo, estoque_inicial, unidade, custo_medio, raw_source, atualizado_em)
             VALUES
                (:company_id, :codigo_produto, :codigo_local, :descricao_produto, :nome_local,
                 :estoque_atual, :estoque_minimo, :estoque_inicial, :unidade, :custo_medio, :raw_source, :atualizado_em)
             ON DUPLICATE KEY UPDATE
                descricao_produto = VALUES(descricao_produto),
                nome_local        = VALUES(nome_local),
                estoque_atual     = VALUES(estoque_atual),
                estoque_minimo    = VALUES(estoque_minimo),
                estoque_inicial   = VALUES(estoque_inicial),
                unidade           = VALUES(unidade),
                custo_medio       = VALUES(custo_medio),
                raw_source        = VALUES(raw_source),
                atualizado_em     = VALUES(atualizado_em)"
        )->execute(['company_id' => TenantContext::companyId()] + $data);
    }

    private function insertMovimento(array $data): void
    {
        $this->pdo->prepare(
            "INSERT IGNORE INTO biomie_produtos_estoque_movimentos
                (company_id, codigo_produto, codigo_local, data_movimento, tipo_movimento,
                 quantidade, valor, documento, nota_key, pedido_id_omie, cliente_fornecedor, raw_source)
             VALUES
                (:company_id, :codigo_produto, :codigo_local, :data_movimento, :tipo_movimento,
                 :quantidade, :valor, :documento, :nota_key, :pedido_id_omie, :cliente_fornecedor, :raw_source)"
        )->execute(['company_id' => TenantContext::companyId()] + $data);
    }
}
