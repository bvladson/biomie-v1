<?php

declare(strict_types=1);

namespace BIomie\FiscalSync;

use BIomie\CanonicalRepository;

final class FiscalDivergenceDetector
{
    public function __construct(
        private readonly CanonicalRepository $canonical,
    ) {
    }

    /**
     * @param array<string,mixed> $canonicalRow
     * @param array<string,string> $vendorMap
     */
    public function detectInvoiceDivergences(array $canonicalRow, array $vendorMap): int
    {
        $count = 0;
        $vendorId = (string) ($canonicalRow['codigo_vendedor_omie'] ?? '');

        if ($vendorId !== '' && !isset($vendorMap[$vendorId])) {
            $this->canonical->logDivergence(
                'vendedor_nao_cadastrado_nf',
                'nota:' . (string) ($canonicalRow['nota_key'] ?? '?'),
                sprintf('NF %s referencia vendedor %s inexistente no cadastro Omie.', (string) ($canonicalRow['numero_nf'] ?? '?'), $vendorId),
                ['nota_key' => $canonicalRow['nota_key'] ?? null, 'nome_no_documento' => $canonicalRow['nome_vendedor'] ?? null]
            );
            $count++;
        }

        if ($vendorId === '') {
            $this->canonical->logDivergence(
                'nota_sem_vendedor',
                'nota:' . (string) ($canonicalRow['nota_key'] ?? '?'),
                sprintf('NF %s nao possui codigo de vendedor.', (string) ($canonicalRow['numero_nf'] ?? '?')),
                ['nota_key' => $canonicalRow['nota_key'] ?? null]
            );
            $count++;
        }

        if (($canonicalRow['status_fiscal'] ?? '') === 'cancelada' && empty($canonicalRow['data_cancelamento'])) {
            $this->canonical->logDivergence(
                'nota_cancelada_sem_data',
                'nota:' . (string) ($canonicalRow['nota_key'] ?? '?'),
                sprintf('NF %s foi marcada como cancelada sem data de cancelamento.', (string) ($canonicalRow['numero_nf'] ?? '?')),
                ['nota_key' => $canonicalRow['nota_key'] ?? null]
            );
            $count++;
        }

        return $count;
    }

    /**
     * @param array<string,mixed> $canonicalRow
     * @param array<string,string> $vendorMap
     */
    public function detectOrderDivergences(array $canonicalRow, array $vendorMap): int
    {
        $count = 0;
        $vendorId = (string) ($canonicalRow['codigo_vendedor_omie'] ?? '');

        if ($vendorId !== '' && !isset($vendorMap[$vendorId])) {
            $this->canonical->logDivergence(
                'vendedor_nao_cadastrado_pedido',
                'pedido:' . (string) ($canonicalRow['pedido_id_omie'] ?? '?'),
                sprintf('Pedido %s referencia vendedor %s inexistente no cadastro Omie.', (string) ($canonicalRow['codigo_pedido'] ?? '?'), $vendorId),
                ['pedido_id' => $canonicalRow['pedido_id_omie'] ?? null, 'nome_no_pedido' => $canonicalRow['nome_vendedor'] ?? null]
            );
            $count++;
        }

        if ($vendorId === '') {
            $this->canonical->logDivergence(
                'pedido_sem_vendedor',
                'pedido:' . (string) ($canonicalRow['pedido_id_omie'] ?? '?'),
                sprintf('Pedido %s nao possui codigo de vendedor.', (string) ($canonicalRow['codigo_pedido'] ?? '?')),
                ['pedido_id' => $canonicalRow['pedido_id_omie'] ?? null]
            );
            $count++;
        }

        return $count;
    }
}
