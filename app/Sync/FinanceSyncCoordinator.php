<?php

declare(strict_types=1);

namespace BIomie\Sync;

use BIomie\CanonicalRepository;
use BIomie\FinanceRepository;
use BIomie\GoldMartBuilder;
use BIomie\Logger;
use BIomie\Support;
use DateTimeImmutable;
use Throwable;

/**
 * Coordenador de sincronização do domínio financeiro (títulos a receber/pagar).
 *
 * Substitui OmieFinanceCanonicalSyncService com chunking mensal.
 * Garante que períodos longos não falham silenciosamente por ausência de
 * paginação — cada mês é processado como janela independente.
 */
final class FinanceSyncCoordinator
{
    public function __construct(
        private readonly FinanceRepository $finance,
        private readonly CanonicalRepository $canonical,
        private readonly Logger $logger,
        private readonly ?GoldMartBuilder $goldMart = null,
    ) {
    }

    /**
     * @return array{
     *   titles:array{inserted:int,updated:int,unchanged:int,skipped:int},
     *   titles_checked:int,
     *   divergences:int,
     *   duration_seconds:float
     * }
     */
    public function syncPeriod(string $start, string $end, string $fonte = 'cron'): array
    {
        $t0 = microtime(true);
        $this->canonical->ensureFiscalSchema();

        $this->logger->info('FinanceSyncCoordinator: sincronização iniciada.', [
            'start' => $start,
            'end'   => $end,
            'fonte' => $fonte,
        ]);

        $totalStats       = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
        $totalTitles      = 0;
        $totalDivergences = 0;

        foreach ($this->buildMonthlyRanges($start, $end) as $range) {
            $monthResult      = $this->syncMonth($range['start'], $range['end'], $fonte);
            $totalStats       = $this->mergeStats($totalStats, $monthResult['stats']);
            $totalTitles      += $monthResult['titles_checked'];
            $totalDivergences += $monthResult['divergences'];
        }

        $duration = round(microtime(true) - $t0, 2);

        $this->logger->info('FinanceSyncCoordinator: sincronização finalizada.', [
            'titles'           => $totalStats,
            'titles_checked'   => $totalTitles,
            'divergences'      => $totalDivergences,
            'duration_seconds' => $duration,
        ]);

        if ($this->goldMart !== null) {
            try {
                $this->goldMart->refreshPeriod($start, $end);
            } catch (Throwable $e) {
                $this->logger->warning('FinanceSyncCoordinator: GoldMart refresh falhou; mart pode estar desatualizado.', [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return [
            'titles'           => $totalStats,
            'titles_checked'   => $totalTitles,
            'divergences'      => $totalDivergences,
            'duration_seconds' => $duration,
        ];
    }

    /**
     * @return array{stats:array{inserted:int,updated:int,unchanged:int,skipped:int},titles_checked:int,divergences:int}
     */
    private function syncMonth(string $start, string $end, string $fonte): array
    {
        $paidRows  = $this->finance->searchTitlesByPayment($start, $end);
        $dueRows   = $this->finance->searchTitlesByDueDate($start, $end);
        $titles    = $this->buildCanonicalTitles(array_merge($paidRows, $dueRows), $fonte);

        $needsEnrichment = array_filter(
            $titles,
            static fn (array $t): bool => ($t['forma_pagamento'] ?? null) === null || ($t['especie_documento'] ?? null) === null
        );

        if ($needsEnrichment !== []) {
            try {
                $receivables = $this->finance->listReceivablesByDueDate($start, $end);
                $titles      = $this->enrichPaymentMethods($titles, $receivables);
            } catch (Throwable $e) {
                $this->logger->warning('FinanceSyncCoordinator: enriquecimento via ListarContasReceber falhou; continuando sem método de pagamento.', [
                    'message' => $e->getMessage(),
                    'period'  => "$start → $end",
                ]);
            }
        }

        $stats       = $this->canonical->replaceFinancialTitlesWindow($start, $end, array_values($titles));
        $divergences = $this->detectDivergences(array_values($titles));

        return [
            'stats'          => $stats,
            'titles_checked' => count($titles),
            'divergences'    => $divergences,
        ];
    }

    /**
     * Enriquece forma_pagamento e especie_documento usando dados de ListarContasReceber.
     * Apenas preenche campos nulos — nunca sobrescreve dados existentes.
     *
     * @param array<string,array<string,mixed>> $titles indexed by titulo_key
     * @param list<array<string,mixed>>         $receivables raw rows from ListarContasReceber
     * @return array<string,array<string,mixed>>
     */
    private function enrichPaymentMethods(array $titles, array $receivables): array
    {
        $lookup = [];
        foreach ($receivables as $row) {
            $titleId = (int) Support::getByPaths($row, ['cabecTitulo.nCodTitulo', 'nCodTitulo', 'codigo_lancamento_omie'], 0);
            if ($titleId <= 0) {
                continue;
            }

            $lookup[$titleId] = [
                'forma_pagamento'            => $this->resolveReceivablePaymentMethod($row),
                'especie_documento'          => $this->nullableString(Support::findFirstByKeys($row, ['cTipoTitulo', 'cEspecie', 'tipo_titulo', 'especie_documento', 'codigo_tipo_documento'], '')),
                'codigo_conta_corrente_omie' => $this->nullableString(Support::findFirstByKeys($row, ['id_conta_corrente', 'codigo_conta_corrente', 'nCodCC'], '')),
                'chave_nfe'                  => $this->nullableString(Support::findFirstByKeys($row, ['chave_nfe', 'cChaveNFe'], '')),
                'pedido_id_omie'             => $this->nullableInt(Support::findFirstByKeys($row, ['nCodPedido', 'pedido_id_omie'], 0)),
                'codigo_pedido_origem'       => $this->nullableString(Support::findFirstByKeys($row, ['numero_pedido', 'cNumPedido', 'codigo_pedido'], '')),
                'codigo_cliente_omie'        => $this->nullableInt(Support::findFirstByKeys($row, ['codigo_cliente_fornecedor', 'nCodCliente'], 0)),
                'codigo_vendedor_omie'       => $this->nullableString(Support::findFirstByKeys($row, ['codigo_vendedor', 'nCodVendedor'], '')),
            ];
        }

        if ($lookup === []) {
            return $titles;
        }

        foreach ($titles as $key => $title) {
            $titleId = (int) ($title['titulo_id_omie'] ?? 0);
            if ($titleId <= 0 || !isset($lookup[$titleId])) {
                continue;
            }

            if (($title['forma_pagamento'] ?? null) === null && $lookup[$titleId]['forma_pagamento'] !== null) {
                $titles[$key]['forma_pagamento'] = $lookup[$titleId]['forma_pagamento'];
            }
            if (($title['especie_documento'] ?? null) === null && $lookup[$titleId]['especie_documento'] !== null) {
                $titles[$key]['especie_documento'] = $lookup[$titleId]['especie_documento'];
            }
            foreach ([
                'codigo_conta_corrente_omie',
                'codigo_pedido_origem',
                'codigo_vendedor_omie',
            ] as $field) {
                if (trim((string) ($title[$field] ?? '')) === '' && $lookup[$titleId][$field] !== null) {
                    $titles[$key][$field] = $lookup[$titleId][$field];
                }
            }
            foreach (['pedido_id_omie', 'codigo_cliente_omie'] as $field) {
                if (empty($title[$field]) && $lookup[$titleId][$field] !== null) {
                    $titles[$key][$field] = $lookup[$titleId][$field];
                }
            }
            if (trim((string) ($title['chave_nfe'] ?? '')) === '' && $lookup[$titleId]['chave_nfe'] !== null) {
                $titles[$key]['chave_nfe'] = $lookup[$titleId]['chave_nfe'];
                $titles[$key]['nota_key'] = $lookup[$titleId]['chave_nfe'];
                $titles[$key]['documento_origem_tipo'] = $titles[$key]['documento_origem_tipo'] ?? 'nfe';
                $titles[$key]['documento_origem_ref'] = $titles[$key]['documento_origem_ref'] ?? $lookup[$titleId]['chave_nfe'];
            }
        }

        return $titles;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,array<string,mixed>>
     */
    private function buildCanonicalTitles(array $rows, string $fonte): array
    {
        $titles = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $canonical = $this->normalizeTitle($row, $fonte);
            if ($canonical === null) {
                continue;
            }

            $titleKey = (string) ($canonical['titulo_key'] ?? '');
            if ($titleKey === '') {
                continue;
            }

            if (!isset($titles[$titleKey])) {
                $titles[$titleKey] = $canonical;
                continue;
            }

            $titles[$titleKey] = $this->mergeTitles($titles[$titleKey], $canonical);
        }

        return $titles;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private function normalizeTitle(array $row, string $fonte): ?array
    {
        $titleId         = (int) Support::getByPaths($row, ['cabecTitulo.nCodTitulo', 'nCodTitulo', 'detalhes.nCodTitulo', 'codigo_lancamento_omie'], 0);
        $integrationCode = trim((string) Support::getByPaths($row, ['cabecTitulo.cCodIntTitulo', 'cCodIntTitulo', 'detalhes.cCodIntTitulo'], ''));
        $documentNumber  = trim((string) Support::getByPaths($row, ['cabecTitulo.cNumTitulo', 'cNumTitulo', 'detalhes.cNumTitulo', 'documento'], ''));
        $issueDate       = Support::formatDate((string) Support::getByPaths($row, ['cabecTitulo.dDtEmissao', 'dDtEmissao'], ''));
        $dueDate         = Support::formatDate((string) Support::getByPaths($row, ['cabecTitulo.dDtVenc', 'dDtVenc', 'detalhes.dDtVenc'], ''));
        $paidDate        = Support::formatDate((string) Support::getByPaths($row, ['cabecTitulo.dDtPagamento', 'dDtPagamento', 'detalhes.dDtPagamento'], ''));

        $titleKey = $this->buildTitleKey($titleId, $integrationCode, $documentNumber, $dueDate, $issueDate, $row);
        if ($titleKey === '') {
            return null;
        }

        $origin     = $this->extractOrigin($row, $documentNumber);
        $amountPaid = Support::toFloat(Support::getByPaths($row, ['resumo.nValPago', 'nValPago'], 0));
        $amountNet  = Support::toFloat(Support::getByPaths($row, ['resumo.nValLiquido', 'nValLiquido', 'resumo.nValPago'], $amountPaid));
        $amountOpen = Support::toFloat(Support::getByPaths($row, ['resumo.nValAberto', 'nValAberto'], 0));

        return [
            'titulo_key'                 => $titleKey,
            'titulo_id_omie'             => $titleId > 0 ? $titleId : null,
            'codigo_integracao'          => $integrationCode !== '' ? $integrationCode : null,
            'numero_documento'           => $documentNumber,
            'status_titulo'              => $this->resolveFinancialStatus($row, $paidDate, $amountOpen, $amountPaid),
            'especie_documento'          => $this->nullableString(Support::findFirstByKeys($row, ['cTipoTitulo', 'tipo_titulo', 'tipo_documento', 'cEspecie', 'especie_documento'], '')),
            'forma_pagamento'            => $this->nullableString(Support::findFirstByKeys($row, ['cFormaPag', 'forma_pagamento', 'meio_pagamento', 'cMeioPagamento', 'descricao_portador'], '')),
            'documento_origem_tipo'      => $origin['type'],
            'documento_origem_ref'       => $origin['ref'],
            'nota_key'                   => $origin['nota_key'],
            'chave_nfe'                  => $origin['chave_nfe'],
            'pedido_id_omie'             => $origin['pedido_id_omie'],
            'codigo_pedido_origem'       => $origin['codigo_pedido_origem'],
            'codigo_cliente_omie'        => $this->nullableInt(Support::getByPaths($row, ['cabecTitulo.nCodCliente', 'nCodCliente', 'codigo_cliente_omie'], 0)),
            'nome_cliente'               => (string) Support::findFirstByKeys($row, ['cNomeCliente', 'nome_cliente', 'razao_social', 'nome_fantasia', 'Cliente (Razão Social)', 'Cliente (Nome Fantasia)'], 'Cliente nao informado'),
            'documento_cliente'          => $this->nullableString(Support::findFirstByKeys($row, ['cnpj_cpf', 'cpf_cnpj', 'CNPJ/CPF'], '')),
            'codigo_vendedor_omie'       => (string) Support::getByPaths($row, ['cabecTitulo.nCodVendedor', 'nCodVendedor', 'codigo_vendedor_omie'], ''),
            'nome_vendedor'              => (string) Support::findFirstByKeys($row, ['cNomeVendedor', 'nome_vendedor', 'Vendedor'], 'Sem vendedor'),
            'codigo_conta_corrente_omie' => $this->nullableString(Support::getByPaths($row, ['cabecTitulo.nCodCC', 'nCodCC', 'codigo_conta_corrente', 'detalhes.nCodCC'], '')),
            'data_emissao'               => $issueDate,
            'data_vencimento'            => $dueDate,
            'data_liquidacao'            => $paidDate,
            'data_competencia'           => $paidDate ?? $dueDate ?? $issueDate,
            'valor_pago'                 => $amountPaid,
            'valor_liquido'              => $amountNet > 0 ? $amountNet : $amountPaid,
            'valor_aberto'               => $amountOpen,
            'fonte_sync'                 => $fonte,
        ];
    }

    /**
     * @param array<string,mixed> $current
     * @param array<string,mixed> $incoming
     * @return array<string,mixed>
     */
    private function mergeTitles(array $current, array $incoming): array
    {
        foreach ([
            'codigo_integracao', 'numero_documento', 'status_titulo', 'especie_documento',
            'forma_pagamento', 'documento_origem_tipo', 'documento_origem_ref', 'nota_key',
            'chave_nfe', 'codigo_pedido_origem', 'nome_cliente', 'documento_cliente',
            'codigo_vendedor_omie', 'nome_vendedor', 'codigo_conta_corrente_omie',
        ] as $key) {
            if (($current[$key] ?? null) === null || (string) ($current[$key] ?? '') === '') {
                $current[$key] = $incoming[$key] ?? null;
            }
        }

        foreach (['titulo_id_omie', 'pedido_id_omie', 'codigo_cliente_omie'] as $key) {
            if (empty($current[$key]) && !empty($incoming[$key])) {
                $current[$key] = $incoming[$key];
            }
        }

        foreach (['data_emissao', 'data_vencimento', 'data_liquidacao', 'data_competencia'] as $key) {
            if (empty($current[$key]) && !empty($incoming[$key])) {
                $current[$key] = $incoming[$key];
            }
        }

        $current['valor_pago']    = max((float) ($current['valor_pago']    ?? 0), (float) ($incoming['valor_pago']    ?? 0));
        $current['valor_liquido'] = max((float) ($current['valor_liquido'] ?? 0), (float) ($incoming['valor_liquido'] ?? 0));
        $current['valor_aberto']  = max((float) ($current['valor_aberto']  ?? 0), (float) ($incoming['valor_aberto']  ?? 0));

        if (!empty($incoming['data_liquidacao'])) {
            $current['status_titulo']    = (string) ($incoming['status_titulo'] ?? 'liquidado');
            $current['data_liquidacao']  = $incoming['data_liquidacao'];
            $current['data_competencia'] = $incoming['data_competencia'] ?? $current['data_competencia'];
        }

        return $current;
    }

    /**
     * @param list<array<string,mixed>> $titles
     */
    private function detectDivergences(array $titles): int
    {
        $count = 0;

        foreach ($titles as $title) {
            $titleKey = (string) ($title['titulo_key'] ?? '?');

            if (empty($title['documento_origem_ref']) && empty($title['nota_key']) && empty($title['pedido_id_omie'])) {
                $this->canonical->logDivergence(
                    'titulo_financeiro_sem_origem',
                    'titulo:' . $titleKey,
                    sprintf('Titulo financeiro %s nao possui trilha explicita de origem documental.', $titleKey),
                    ['titulo_key' => $titleKey, 'numero_documento' => $title['numero_documento'] ?? null]
                );
                $count++;
            }

            if (empty($title['codigo_cliente_omie']) && trim((string) ($title['nome_cliente'] ?? '')) === '') {
                $this->canonical->logDivergence(
                    'titulo_financeiro_sem_cliente',
                    'titulo:' . $titleKey,
                    sprintf('Titulo financeiro %s nao possui identificacao suficiente de cliente.', $titleKey),
                    ['titulo_key' => $titleKey]
                );
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{type:?string,ref:?string,nota_key:?string,chave_nfe:?string,pedido_id_omie:?int,codigo_pedido_origem:?string}
     */
    private function extractOrigin(array $row, string $documentNumber): array
    {
        $accessKey    = $this->nullableString(Support::findFirstByKeys($row, ['cChaveNFe', 'chave_nfe', 'chave_nfe_origem', 'compl.cChaveNFe'], ''));
        $pedidoId     = $this->nullableInt(Support::findFirstByKeys($row, ['nIdPedido', 'pedido_id_omie'], 0));
        $pedidoCodigo = $this->nullableString(Support::findFirstByKeys($row, ['cNumPedido', 'codigo_pedido', 'pedido_origem'], ''));
        $originType   = $this->nullableString(Support::findFirstByKeys($row, ['tipo_documento_origem', 'documento_origem_tipo'], ''));
        $originRef    = $this->nullableString(Support::findFirstByKeys($row, ['documento_origem_ref', 'documento_origem'], ''));

        if ($originType === null) {
            if ($accessKey !== null) {
                $originType = 'nfe';
            } elseif ($pedidoId !== null || $pedidoCodigo !== null) {
                $originType = 'pedido';
            } elseif ($documentNumber !== '') {
                $originType = 'titulo';
            }
        }

        if ($originRef === null) {
            $originRef = $accessKey ?? $pedidoCodigo ?? ($documentNumber !== '' ? $documentNumber : null);
        }

        return [
            'type'                 => $originType,
            'ref'                  => $originRef,
            'nota_key'             => $accessKey,
            'chave_nfe'            => $accessKey,
            'pedido_id_omie'       => $pedidoId,
            'codigo_pedido_origem' => $pedidoCodigo,
        ];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function buildTitleKey(
        int $titleId,
        string $integrationCode,
        string $documentNumber,
        ?string $dueDate,
        ?string $issueDate,
        array $row,
    ): string {
        if ($titleId > 0) {
            return 'omie:' . $titleId;
        }
        if ($integrationCode !== '') {
            return 'int:' . $integrationCode;
        }
        if ($documentNumber === '') {
            return '';
        }

        $client = trim((string) Support::findFirstByKeys($row, ['cNomeCliente', 'nome_cliente', 'razao_social'], ''));

        return 'doc:' . sha1(implode('|', [
            $documentNumber,
            $dueDate ?? '',
            $issueDate ?? '',
            $client,
        ]));
    }

    /**
     * @param array<string,mixed> $row
     */
    private function resolveFinancialStatus(array $row, ?string $paidDate, float $amountOpen, float $amountPaid): string
    {
        $raw = strtolower(trim((string) Support::getByPaths($row, ['cabecTitulo.cStatus', 'cStatus', 'status'], '')));
        if ($paidDate !== null || $amountPaid > 0.0001) {
            return 'liquidado';
        }
        if ($amountOpen > 0.0001) {
            return 'aberto';
        }
        if ($raw !== '') {
            return $raw;
        }

        return 'outro';
    }

    /**
     * Quebra [start, end] em chunks por mês-calendário, **clampados à janela
     * realmente pedida**.
     *
     * Antes a janela era expandida para o mês inteiro (Y-m-01 → Y-m-t), o que
     * fazia cada delta financeiro (janela quente ≈ 24h) re-varrer o mês inteiro
     * na Omie a cada execução — desperdício de quota e latência. Como a escrita
     * canônica é upsert por (company_id, titulo_key) — `replaceFinancialTitlesWindow`
     * NÃO apaga a janela, só faz upsert — estreitar o intervalo é seguro: nada é
     * removido; títulos fora da janela permanecem intactos.
     *
     * O chunking mensal é preservado para backfills longos (evita varredura sem
     * paginação), mas o primeiro e o último chunk respeitam os limites pedidos.
     *
     * @return list<array{start:string,end:string}>
     */
    private function buildMonthlyRanges(string $start, string $end): array
    {
        $startTs = strtotime($start);
        $endTs   = strtotime($end);
        if ($startTs === false || $endTs === false) {
            return [];
        }
        if ($endTs < $startTs) {
            [$startTs, $endTs] = [$endTs, $startTs];
        }

        $reqStart = date('Y-m-d', $startTs);
        $reqEnd   = date('Y-m-d', $endTs);

        $cursor = new DateTimeImmutable(date('Y-m-01', $startTs));
        $limit  = new DateTimeImmutable(date('Y-m-01', $endTs));
        $ranges = [];

        while ($cursor <= $limit) {
            $monthStart = $cursor->format('Y-m-01');
            $monthEnd   = $cursor->format('Y-m-t');

            // Clampa o chunk do mês à janela pedida.
            $rangeStart = $monthStart < $reqStart ? $reqStart : $monthStart;
            $rangeEnd   = $monthEnd   > $reqEnd   ? $reqEnd   : $monthEnd;

            $ranges[] = [
                'start' => $rangeStart,
                'end'   => $rangeEnd,
            ];
            $cursor = $cursor->modify('+1 month');
        }

        return $ranges;
    }

    /**
     * @param array{inserted?:int,updated?:int,unchanged?:int,skipped?:int} $current
     * @param array{inserted?:int,updated?:int,unchanged?:int,skipped?:int} $delta
     * @return array{inserted:int,updated:int,unchanged:int,skipped:int}
     */
    private function mergeStats(array $current, array $delta): array
    {
        return [
            'inserted'  => (int) ($current['inserted']  ?? 0) + (int) ($delta['inserted']  ?? 0),
            'updated'   => (int) ($current['updated']   ?? 0) + (int) ($delta['updated']   ?? 0),
            'unchanged' => (int) ($current['unchanged'] ?? 0) + (int) ($delta['unchanged'] ?? 0),
            'skipped'   => (int) ($current['skipped']   ?? 0) + (int) ($delta['skipped']   ?? 0),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    /** @param array<string,mixed> $row */
    private function resolveReceivablePaymentMethod(array $row): ?string
    {
        $explicit = $this->nullableString(Support::findFirstByKeys($row, [
            'cFormaPag',
            'forma_pagamento',
            'cMeioPagamento',
            'meio_pagamento',
        ], ''));
        if ($explicit !== null) {
            return $explicit;
        }

        $boletoGenerated = strtolower(trim((string) Support::getByPaths($row, ['boleto.cGerado'], '')));
        $barcode = $this->nullableString(Support::findFirstByKeys($row, [
            'codigo_barras_ficha_compensacao',
            'cNumBoleto',
            'cCodBoleto',
        ], ''));

        return $boletoGenerated === 's' || $barcode !== null ? 'Boleto' : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
