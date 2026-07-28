<?php

declare(strict_types=1);

namespace BIomie\Stock;

use BIomie\OmieClient;
use RuntimeException;

/**
 * EST.R1 — Cliente read-only para endpoints de estoque Omie.
 *
 * Nunca escreve no banco. Apenas recupera dados da API Omie.
 * Toda persistência é responsabilidade do StockSyncCoordinator.
 *
 * Rate limit e retry são gerenciados pelo OmieClient base.
 */
final class OmieStockClient
{
    private const EP_LOCAIS   = 'https://app.omie.com.br/api/v1/geral/locaisestoque/';
    private const EP_ESTOQUE  = 'https://app.omie.com.br/api/v1/geral/estoque/';

    public function __construct(private readonly OmieClient $client) {}

    /**
     * Lista todos os locais de estoque ativos.
     *
     * @return list<array<string,mixed>>
     */
    public function listarLocais(int $pagina = 1, int $porPagina = 50): array
    {
        $resp = $this->client->post(self::EP_LOCAIS, 'ListarLocaisEstoque', [
            'nPagina'       => $pagina,
            'nRegPorPagina' => $porPagina,
            'lSoAtivos'     => 'S',
        ]);
        return (array) ($resp['locais_cadastro'] ?? []);
    }

    /**
     * Retorna o resumo de estoque de todos os produtos (ou filtrado por produto).
     *
     * @return list<array<string,mixed>>
     */
    public function resumoEstoque(int $pagina = 1, int $porPagina = 50, string $codigoProduto = ''): array
    {
        $params = ['nPagina' => $pagina, 'nRegPorPagina' => $porPagina];
        if ($codigoProduto !== '') {
            $params['codigo_produto'] = $codigoProduto;
        }
        $resp = $this->client->post(self::EP_ESTOQUE, 'ResumoEstoque', $params);
        return (array) ($resp['estoque'] ?? $resp['registros'] ?? []);
    }

    /**
     * Consulta estoque de um produto específico em todos os locais.
     *
     * @return array<string,mixed>
     */
    public function consultarEstoque(string $codigoProduto): array
    {
        if ($codigoProduto === '') {
            throw new RuntimeException('codigo_produto obrigatório para ConsultarEstoque');
        }
        return $this->client->post(self::EP_ESTOQUE, 'ConsultarEstoque', [
            'codigo_produto'              => $codigoProduto,
            'codigo_produto_integracao'   => '',
        ]);
    }

    /**
     * Retorna movimentos de estoque em um período.
     *
     * @return list<array<string,mixed>>
     */
    public function movimentos(string $dataInicio, string $dataFim, int $pagina = 1, int $porPagina = 50, string $codigoProduto = ''): array
    {
        $params = [
            'nPagina'       => $pagina,
            'nRegPorPagina' => $porPagina,
            'dDtInicio'     => $dataInicio,
            'dDtFim'        => $dataFim,
        ];
        if ($codigoProduto !== '') {
            $params['codigo_produto'] = $codigoProduto;
        }
        $resp = $this->client->post(self::EP_ESTOQUE, 'ConsultarMovimentoEstoque', $params);
        return (array) ($resp['movimentos'] ?? $resp['registros'] ?? []);
    }

    /**
     * Retorna o total de registros disponíveis para resumoEstoque (paginação).
     */
    public function totalResumoEstoque(): int
    {
        $resp = $this->client->post(self::EP_ESTOQUE, 'ResumoEstoque', [
            'nPagina'       => 1,
            'nRegPorPagina' => 1,
        ]);
        return (int) ($resp['total_de_registros'] ?? 0);
    }
}
