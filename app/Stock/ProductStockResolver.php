<?php

declare(strict_types=1);

namespace BIomie\Stock;

/**
 * EST.R1 — Resolve o status de estoque de um produto e formata para a API.
 *
 * Combina dados de saldo (por local) com métricas agregadas.
 * Não acessa Omie — usa apenas o StockReadRepository.
 */
final class ProductStockResolver
{
    public function __construct(private readonly StockReadRepository $repo) {}

    /**
     * Retorna payload completo de estoque para o Product 360.
     *
     * @return array<string,mixed>
     */
    public function resolve(string $codigoProduto, string $start, string $end, int $movLimit = 50): array
    {
        $tables   = $this->repo->tablesExist();
        $saldos   = $tables['saldos']     ? $this->repo->saldosPorProduto($codigoProduto) : [];
        $movs     = $tables['movimentos'] ? $this->repo->movimentos($codigoProduto, $start, $end, $movLimit) : [];

        if (!$tables['saldos']) {
            return $this->emptyPayload('Tabela de saldos não existe. Execute as migrations EST.R1.');
        }

        if (empty($saldos)) {
            return $this->emptyPayload('Produto sem saldo de estoque. Sincronize via EST.R1 sync.');
        }

        $totalAtual  = array_sum(array_column($saldos, 'estoque_atual'));
        $totalMinimo = array_sum(array_column($saldos, 'estoque_minimo'));

        $saldosFormatados = array_map(function (array $s): array {
            $atual  = (float)($s['estoque_atual']  ?? 0);
            $minimo = (float)($s['estoque_minimo'] ?? 0);
            return [
                'codigo_local'    => $s['codigo_local']    ?? '',
                'nome_local'      => $s['nome_local']       ?? '',
                'estoque_atual'   => round($atual, 4),
                'estoque_minimo'  => round($minimo, 4),
                'estoque_inicial' => round((float)($s['estoque_inicial'] ?? 0), 4),
                'unidade'         => $s['unidade']          ?? '',
                'custo_medio'     => round((float)($s['custo_medio'] ?? 0), 2),
                'atualizado_em'   => substr((string)($s['atualizado_em'] ?? ''), 0, 19),
                'status'          => $this->calcStatus($atual, $minimo),
            ];
        }, $saldos);

        $hasLowStock = !empty(array_filter($saldosFormatados, fn($s) => $s['status'] === 'baixo'));
        $hasRuptura  = !empty(array_filter($saldosFormatados, fn($s) => $s['status'] === 'sem_estoque'));

        return [
            'available'     => true,
            'empty_state'   => null,
            'summary'       => [
                'total_atual'    => round($totalAtual, 4),
                'total_minimo'   => round($totalMinimo, 4),
                'n_locais'       => count($saldos),
                'has_low_stock'  => $hasLowStock,
                'has_ruptura'    => $hasRuptura,
                'global_status'  => $hasRuptura ? 'ruptura' : ($hasLowStock ? 'baixo' : 'ok'),
                'last_updated'   => $this->latestUpdate($saldos),
            ],
            'saldos_por_local' => $saldosFormatados,
            'movimentos'       => $this->formatMovimentos($movs),
            'movimentos_empty' => empty($movs) ? 'Sem movimentos no período' : null,
        ];
    }

    /**
     * @param list<array<string,mixed>> $movs
     * @return list<array<string,mixed>>
     */
    private function formatMovimentos(array $movs): array
    {
        return array_map(static function (array $m): array {
            return [
                'data_movimento'     => substr((string)($m['data_movimento']     ?? ''), 0, 10),
                'codigo_local'       => (string)($m['codigo_local']              ?? ''),
                'tipo_movimento'     => (string)($m['tipo_movimento']            ?? ''),
                'quantidade'         => round((float)($m['quantidade']           ?? 0), 4),
                'valor'              => round((float)($m['valor']                ?? 0), 2),
                'documento'          => (string)($m['documento']                 ?? ''),
                'nota_key'           => (string)($m['nota_key']                  ?? ''),
                'pedido_id_omie'     => (int)($m['pedido_id_omie']               ?? 0),
                'cliente_fornecedor' => (string)($m['cliente_fornecedor']        ?? ''),
            ];
        }, $movs);
    }

    private function calcStatus(float $atual, float $minimo): string
    {
        if ($atual <= 0)                    return 'sem_estoque';
        if ($minimo > 0 && $atual <= $minimo) return 'baixo';
        return 'ok';
    }

    private function latestUpdate(array $saldos): string
    {
        $dates = array_filter(array_column($saldos, 'atualizado_em'));
        return $dates ? substr(max($dates), 0, 19) : '';
    }

    /** @return array<string,mixed> */
    private function emptyPayload(string $message): array
    {
        return [
            'available'        => false,
            'empty_state'      => $message,
            'summary'          => ['total_atual' => 0, 'total_minimo' => 0, 'n_locais' => 0, 'has_low_stock' => false, 'has_ruptura' => false, 'global_status' => 'unknown', 'last_updated' => ''],
            'saldos_por_local' => [],
            'movimentos'       => [],
            'movimentos_empty' => null,
        ];
    }
}
