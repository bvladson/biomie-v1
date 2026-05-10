<?php

declare(strict_types=1);

namespace BIomie\Modules\Customers\Infrastructure;

use BIomie\OmieClient;
use RuntimeException;

final class OmieCustomerFetcher
{
    private const ENDPOINT_CLIENTES = 'https://app.omie.com.br/api/v1/geral/clientes/';
    private const CALL_CONSULTAR    = 'ConsultarCliente';
    private const CALL_LISTAR       = 'ListarClientes';

    public function __construct(
        private readonly OmieClient $omieClient,
    ) {}

    /**
     * Consulta um cliente pelo código. Retorna null se não encontrado na Omie.
     * Lança RuntimeException para erros de API / rate-limit.
     *
     * @return array<string,mixed>|null
     */
    public function consult(int $codigoOmie): ?array
    {
        try {
            $response = $this->omieClient->post(self::ENDPOINT_CLIENTES, self::CALL_CONSULTAR, [
                'codigo_cliente_omie' => $codigoOmie,
            ]);
        } catch (RuntimeException $e) {
            $normalized = strtolower(@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $e->getMessage()) ?: $e->getMessage());
            if (str_contains($normalized, 'nao encontrado')
                || str_contains($normalized, 'nao localizado')
                || str_contains($normalized, 'nao existe')
                || str_contains($normalized, 'not found')) {
                return null;
            }
            throw $e;
        }

        if (empty($response) || isset($response['faultcode'])) {
            return null;
        }
        if (!isset($response['codigo_cliente_omie'])) {
            return null;
        }

        return $response;
    }

    /**
     * Lista uma página de clientes via ListarClientes.
     * Usado no modo omie_page para backfill/diagnóstico.
     *
     * @return array<string,mixed>
     */
    public function listPage(int $pagina, int $limit): array
    {
        return $this->omieClient->post(self::ENDPOINT_CLIENTES, self::CALL_LISTAR, [
            'pagina'               => $pagina,
            'registros_por_pagina' => $limit,
            'apenas_importado_api' => 'N',
        ]);
    }

    public function getCallConsultar(): string { return self::CALL_CONSULTAR; }
    public function getCallListar(): string    { return self::CALL_LISTAR; }
}
