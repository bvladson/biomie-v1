<?php

declare(strict_types=1);

namespace BIomie\Stock;

/**
 * EST.R1 — Mapeia payloads brutos da Omie para o modelo canônico de estoque.
 *
 * Cada método recebe um item do array da Omie e retorna um array
 * pronto para INSERT/UPDATE nas tabelas biomie_*.
 */
final class StockPayloadMapper
{
    /**
     * Mapeia um item de ListarLocaisEstoque para biomie_estoque_locais_dim.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    public static function mapLocal(array $item): array
    {
        $codigo = trim((string) ($item['codigo_local_estoque'] ?? $item['nCodLocEstoque'] ?? ''));
        $nome   = trim((string) ($item['nome_local_estoque']   ?? $item['cNomLocEstoque'] ?? $item['cDescricao'] ?? $codigo));
        $ativo  = in_array(strtoupper((string) ($item['nAtivo'] ?? $item['ativo'] ?? 'S')), ['S', '1', 'true', 'yes'], true) ? 1 : 0;
        $venda  = in_array(strtoupper((string) ($item['nPermiteVenda'] ?? $item['permite_venda'] ?? 'S')), ['S', '1', 'true', 'yes'], true) ? 1 : 0;

        return [
            'codigo_local'  => $codigo,
            'nome_local'    => $nome,
            'ativo'         => $ativo,
            'permite_venda' => $venda,
            'raw_source'    => 'omie',
        ];
    }

    /**
     * Mapeia um item de ResumoEstoque para biomie_produtos_estoque_saldos.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    public static function mapSaldo(array $item): array
    {
        $codigo   = trim((string) ($item['codigo_produto']            ?? $item['cCodProd']    ?? ''));
        $local    = trim((string) ($item['codigo_local_estoque']      ?? $item['codigo_local'] ?? ''));
        $descProd = trim((string) ($item['descricao']                 ?? $item['cDescrProd']  ?? $codigo));
        $descLoc  = trim((string) ($item['nome_local_estoque']        ?? $item['nome_local']  ?? $local));
        $atual    = (float) ($item['saldo']          ?? $item['estoque_atual'] ?? $item['qtde_estoque'] ?? 0);
        $minimo   = (float) ($item['estoque_minimo'] ?? $item['qtde_minima']   ?? 0);
        $inicial  = (float) ($item['estoque_inicial'] ?? 0);
        $unidade  = trim((string) ($item['unidade'] ?? $item['cUnidade'] ?? ''));
        $custo    = (float) ($item['custo_unitario'] ?? $item['valor_unitario'] ?? $item['custo_medio'] ?? 0);

        return [
            'codigo_produto'    => $codigo,
            'codigo_local'      => $local,
            'descricao_produto' => $descProd,
            'nome_local'        => $descLoc,
            'estoque_atual'     => round($atual, 4),
            'estoque_minimo'    => round($minimo, 4),
            'estoque_inicial'   => round($inicial, 4),
            'unidade'           => $unidade,
            'custo_medio'       => round($custo, 2),
            'raw_source'        => 'omie',
            'atualizado_em'     => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Mapeia um item de ConsultarMovimentoEstoque para biomie_produtos_estoque_movimentos.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    public static function mapMovimento(array $item): array
    {
        $codigo   = trim((string) ($item['codigo_produto']       ?? $item['cCodProd']        ?? ''));
        $local    = trim((string) ($item['codigo_local_estoque'] ?? $item['codigo_local']     ?? ''));
        $dataRaw  = trim((string) ($item['dDtMovimento']        ?? $item['data_movimento']   ?? date('Y-m-d')));
        $data     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataRaw)
            ? $dataRaw
            : (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dataRaw, $m) ? "{$m[3]}-{$m[2]}-{$m[1]}" : date('Y-m-d'));

        $tipo     = strtoupper(trim((string) ($item['cTipoMov']    ?? $item['tipo_movimento'] ?? '')));
        $qtde     = (float) ($item['nQuantidade']   ?? $item['quantidade'] ?? 0);
        $valor    = (float) ($item['nValorUnitario'] ?? $item['valor']     ?? 0) * abs($qtde);
        $doc      = trim((string) ($item['cDocumento']  ?? $item['documento']      ?? ''));
        $notaKey  = trim((string) ($item['nota_key']    ?? '')) ?: null;
        $pedidoId = ((int) ($item['nCodPedido'] ?? $item['pedido_id_omie'] ?? 0)) ?: null;
        $clifor   = trim((string) ($item['cNomeCliforn'] ?? $item['cliente_fornecedor'] ?? ''));

        return [
            'codigo_produto'     => $codigo,
            'codigo_local'       => $local,
            'data_movimento'     => $data,
            'tipo_movimento'     => $tipo,
            'quantidade'         => round($qtde, 4),
            'valor'              => round($valor, 2),
            'documento'          => $doc,
            'nota_key'           => $notaKey,
            'pedido_id_omie'     => $pedidoId,
            'cliente_fornecedor' => $clifor,
            'raw_source'         => 'omie',
        ];
    }
}
