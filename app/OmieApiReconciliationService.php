<?php

declare(strict_types=1);

namespace BIomie;

final class OmieApiReconciliationService
{
    /** @var array<string,string> */
    private array $vendorMap = [];
    private readonly OmieConsolidatedCsvService $comparator;

    public function __construct(
        private readonly OmieRepository $omie,
        private readonly Logger $logger,
        ?OmieConsolidatedCsvService $comparator = null,
    ) {
        $this->comparator = $comparator ?? new OmieConsolidatedCsvService();
    }

    /**
     * @param list<array<string,mixed>> $systemRows
     * @return array<string,mixed>
     */
    public function buildPeriodReport(string $start, string $end, array $systemRows): array
    {
        $start = Support::formatDate($start) ?? '';
        $end = Support::formatDate($end) ?? '';

        if ($start === '' || $end === '') {
            throw new \InvalidArgumentException('Periodo invalido para a conciliacao.');
        }

        if ($start > $end) {
            throw new \InvalidArgumentException('A data inicial nao pode ser maior que a final.');
        }

        $warnings = [];
        $notes = $this->fetchNotesByIssueDate($start, $end, $warnings);

        $parsed = [
            'source_file' => 'Omie API - ListarNF por emissao',
            'period' => [
                'start' => $start,
                'end' => $end,
                'label' => date('d/m/Y', strtotime($start)) . ' ate ' . date('d/m/Y', strtotime($end)),
            ],
            'notes' => $notes,
            'summary' => $this->buildSummary($notes),
            'warnings' => $warnings,
        ];

        $report = $this->comparator->compareWithSystem($parsed, $systemRows);
        $report['source']['source_type'] = 'omie_api';
        $report['source']['source_file'] = 'Omie API - ListarNF por emissao';
        $report['source']['query_mode'] = 'issue_date';
        $report['source']['generated_at'] = date(DATE_ATOM);
        $report['metrics']['notes_omie'] = (int) ($report['metrics']['notes_csv'] ?? count($notes));

        return $report;
    }

    /**
     * @param list<string> $warnings
     * @return list<array<string,mixed>>
     */
    private function fetchNotesByIssueDate(string $start, string $end, array &$warnings): array
    {
        $this->loadVendorMap($warnings);
        $orderFlags = $this->buildOrderFlagsMap($this->omie->listPendingOrders($start, $end));

        $grouped = [];
        foreach (['N' => 'autorizada', 'C' => 'cancelada'] as $statusCode => $statusLabel) {
            $rawInvoices = $this->omie->listInvoicesByIssueDate($start, $end, $statusCode);

            foreach ($rawInvoices as $rawInvoice) {
                if (!is_array($rawInvoice)) {
                    continue;
                }

                if ($statusLabel === 'autorizada' && !$this->isAuthorizedInvoiceFromOmie($rawInvoice, $orderFlags)) {
                    continue;
                }

                $note = $this->normalizeInvoice($rawInvoice, $statusLabel);
                if ($note === null) {
                    $warnings[] = 'Uma NF retornou sem numero/data de emissao e foi ignorada na leitura automatica.';
                    continue;
                }

                $noteKey = (string) ($note['note_key'] ?? '');
                if ($noteKey === '') {
                    continue;
                }

                if (isset($grouped[$noteKey])) {
                    $existingStatus = (string) ($grouped[$noteKey]['status'] ?? '');
                    if ($existingStatus !== $statusLabel) {
                        $warnings[] = 'NF ' . (string) ($note['note_ref'] ?? $noteKey) . ' retornou em mais de um status na API.';
                    }
                }

                $grouped[$noteKey] = $note;
            }
        }

        $notes = array_values($grouped);
        usort($notes, static fn (array $left, array $right): int => strcmp(
            (($left['issue_date'] ?? '') . '|' . ($left['note_ref'] ?? '')),
            (($right['issue_date'] ?? '') . '|' . ($right['note_ref'] ?? ''))
        ));

        return $notes;
    }

    /**
     * @param list<string> $warnings
     */
    private function loadVendorMap(array &$warnings): void
    {
        if ($this->vendorMap !== []) {
            return;
        }

        try {
            foreach ($this->omie->listVendors() as $vendor) {
                if (!is_array($vendor)) {
                    continue;
                }

                $code = trim((string) ($vendor['codigo'] ?? ''));
                $name = trim((string) ($vendor['nome'] ?? ''));
                if ($code === '' || $name === '') {
                    continue;
                }

                $this->vendorMap[$code] = $name;
            }
        } catch (\Throwable $exception) {
            $warnings[] = 'Nao foi possivel carregar a lista de vendedores do Omie. A conciliacao segue com os ids disponiveis na NF.';
            $this->logger->warning('Falha ao carregar vendedores para conciliacao automatica.', [
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>|null
     */
    private function normalizeInvoice(array $raw, string $status): ?array
    {
        $issueDate = Support::formatDate((string) Support::getByPaths($raw, ['ide.dEmi', 'dEmi'], ''));
        $noteNumber = trim((string) Support::getByPaths($raw, ['ide.nNF', 'nNF'], ''));
        $series = trim((string) Support::getByPaths($raw, ['ide.serie', 'serie'], ''));
        $documentKey = trim((string) Support::getByPaths($raw, ['compl.cChaveNFe', 'cChaveNFe'], ''));
        $noteKey = $this->buildNoteKey($noteNumber, $series, $documentKey);

        if ($issueDate === null || $noteKey === '') {
            return null;
        }

        $merchandiseTotal = Support::money(Support::toFloat(Support::getByPaths($raw, ['total.ICMSTot.vProd', 'total.vProd'], 0)));
        $discountTotal = Support::money(Support::toFloat(Support::getByPaths($raw, ['total.ICMSTot.vDesc', 'total.vDesc'], 0)));
        $invoiceTotal = Support::money(Support::toFloat(Support::getByPaths($raw, ['total.ICMSTot.vNF', 'total.vNF'], 0)));
        if ($invoiceTotal <= 0.0) {
            $invoiceTotal = Support::money(max($merchandiseTotal - $discountTotal, 0.0));
        }

        $vendorId = trim((string) Support::getByPaths($raw, ['pedido.nIdVendedor'], ''));
        $vendorName = $this->resolveVendorName($vendorId, $raw);
        $items = (array) ($raw['det'] ?? []);

        return [
            'note_key' => $noteKey,
            'note_number' => $noteNumber,
            'series' => $series,
            'note_ref' => $this->formatReference($noteNumber, $series),
            'status' => $status,
            'issue_date' => $issueDate,
            'vendor_name' => $vendorName,
            'customer_name' => trim((string) Support::getByPaths($raw, ['nfDestInt.cRazao', 'nfDestInt.cNomeFant', 'cnpj_cpf'], '')),
            'document_key' => $documentKey,
            'invoice_total' => $invoiceTotal,
            'merchandise_total' => $merchandiseTotal,
            'discount_total' => $discountTotal,
            'items_count' => count($items),
        ];
    }

    /**
     * @param list<array<string,mixed>> $notes
     * @return array<string,mixed>
     */
    private function buildSummary(array $notes): array
    {
        $summary = [
            'authorized_notes' => 0,
            'authorized_invoice_total' => 0.0,
            'authorized_merchandise_total' => 0.0,
            'authorized_discount_total' => 0.0,
            'cancelled_notes' => 0,
            'cancelled_invoice_total' => 0.0,
            'cancelled_merchandise_total' => 0.0,
            'cancelled_discount_total' => 0.0,
        ];

        foreach ($notes as $note) {
            $status = (string) ($note['status'] ?? 'outra');
            if ($status === 'autorizada') {
                $summary['authorized_notes']++;
                $summary['authorized_invoice_total'] = Support::money($summary['authorized_invoice_total'] + (float) ($note['invoice_total'] ?? 0));
                $summary['authorized_merchandise_total'] = Support::money($summary['authorized_merchandise_total'] + (float) ($note['merchandise_total'] ?? 0));
                $summary['authorized_discount_total'] = Support::money($summary['authorized_discount_total'] + (float) ($note['discount_total'] ?? 0));
                continue;
            }

            if ($status === 'cancelada') {
                $summary['cancelled_notes']++;
                $summary['cancelled_invoice_total'] = Support::money($summary['cancelled_invoice_total'] + (float) ($note['invoice_total'] ?? 0));
                $summary['cancelled_merchandise_total'] = Support::money($summary['cancelled_merchandise_total'] + (float) ($note['merchandise_total'] ?? 0));
                $summary['cancelled_discount_total'] = Support::money($summary['cancelled_discount_total'] + (float) ($note['discount_total'] ?? 0));
            }
        }

        return $summary;
    }

    /**
     * @param list<array<string,mixed>> $orders
     * @return array<string,array{authorized:bool,cancelled:bool,invoiced:bool,denied:bool,devolved:bool}>
     */
    private function buildOrderFlagsMap(array $orders): array
    {
        $map = [];

        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }

            $flags = [
                'authorized' => $this->isTruthyFlag(Support::getByPaths($order, ['infoCadastro.autorizado'], '')),
                'cancelled' => $this->isTruthyFlag(Support::getByPaths($order, ['infoCadastro.cancelado'], '')),
                'invoiced' => $this->isTruthyFlag(Support::getByPaths($order, ['infoCadastro.faturado'], '')),
                'denied' => $this->isTruthyFlag(Support::getByPaths($order, ['infoCadastro.denegado'], '')),
                'devolved' => $this->isTruthyFlag(Support::getByPaths($order, ['infoCadastro.devolvido'], '')),
            ];

            foreach ($this->buildOrderLookupKeys(
                Support::getByPaths($order, ['cabecalho.numero_pedido'], ''),
                Support::getByPaths($order, ['cabecalho.codigo_pedido', 'codigo_pedido'], '')
            ) as $lookupKey) {
                $map[$lookupKey] = $flags;
            }
        }

        return $map;
    }

    /**
     * @param array<string,mixed> $raw
     * @param array<string,array{authorized:bool,cancelled:bool,invoiced:bool,denied:bool,devolved:bool}> $orderFlags
     */
    private function isAuthorizedInvoiceFromOmie(array $raw, array $orderFlags): bool
    {
        $flags = $this->resolveOrderFlags(
            $orderFlags,
            Support::getByPaths($raw, ['pedido.cNumPedido', 'compl.nIdPedido'], ''),
            Support::getByPaths($raw, ['compl.nIdPedido'], '')
        );

        if ($flags === null) {
            return true;
        }

        if (($flags['cancelled'] ?? false) || ($flags['denied'] ?? false) || ($flags['devolved'] ?? false)) {
            return false;
        }

        return (bool) ($flags['authorized'] ?? false);
    }

    /**
     * @param array<string,array{authorized:bool,cancelled:bool,invoiced:bool,denied:bool,devolved:bool}> $orderFlags
     * @return array{authorized:bool,cancelled:bool,invoiced:bool,denied:bool,devolved:bool}|null
     */
    private function resolveOrderFlags(array $orderFlags, mixed ...$values): ?array
    {
        foreach ($this->buildOrderLookupKeys(...$values) as $lookupKey) {
            if (isset($orderFlags[$lookupKey])) {
                return $orderFlags[$lookupKey];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function buildOrderLookupKeys(mixed ...$values): array
    {
        $keys = [];

        foreach ($values as $value) {
            $raw = trim((string) $value);
            if ($raw === '') {
                continue;
            }

            $keys[] = $raw;
            $digits = Support::digitsOnly($raw);
            if ($digits !== '') {
                $keys[] = $digits;
                $keys[] = ltrim($digits, '0') ?: '0';
            }
        }

        return array_values(array_unique(array_filter($keys, static fn (string $value): bool => $value !== '')));
    }

    private function isTruthyFlag(mixed $value): bool
    {
        return strtoupper(trim((string) $value)) === 'S';
    }

    /**
     * @param array<string,mixed> $raw
     */
    private function resolveVendorName(string $vendorId, array $raw): string
    {
        if ($vendorId !== '' && isset($this->vendorMap[$vendorId])) {
            return $this->vendorMap[$vendorId];
        }

        $fallback = trim((string) Support::getByPaths($raw, [
            'pedido.cNomeVendedor',
            'pedido.nome_vendedor',
        ], ''));

        if ($fallback !== '') {
            return $fallback;
        }

        return $vendorId !== '' ? 'Vendedor ' . $vendorId : 'Sem vendedor';
    }

    private function buildNoteKey(string $noteNumber, string $series, string $documentKey = ''): string
    {
        $normalizedNumber = $this->normalizeDigits($noteNumber);
        $normalizedSeries = $this->normalizeDigits($series);

        if ($normalizedNumber !== '0' || $normalizedSeries !== '0') {
            return $normalizedNumber . '|' . $normalizedSeries;
        }

        $documentKey = trim($documentKey);
        return $documentKey !== '' ? $documentKey : '';
    }

    private function normalizeDigits(string $value): string
    {
        $digits = Support::digitsOnly($value);
        $digits = ltrim($digits, '0');
        return $digits !== '' ? $digits : '0';
    }

    private function formatReference(string $noteNumber, string $series): string
    {
        $noteNumber = trim($noteNumber);
        $series = trim($series);
        if ($noteNumber === '') {
            return 'NF sem numero';
        }

        return $noteNumber . ($series !== '' ? '/' . $series : '');
    }
}
