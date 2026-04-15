<?php

declare(strict_types=1);

namespace BIomie;

use DateTimeImmutable;

final class FinanceShadowCandidateBuilder
{
    public function __construct(private readonly CanonicalRepository $canonical)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function buildOverview(string $start, string $end): array
    {
        $titles = $this->canonical->listFinancialTitlesByPeriod($start, $end);
        $dataset = $this->buildDataset($titles);

        return [
            'ok' => true,
            'mode' => 'canonical-shadow-finance',
            'source' => 'canonical_shadow',
            'period' => [
                'start' => $start,
                'end' => $end,
                'label' => date('d/m/Y', strtotime($start)) . ' ate ' . date('d/m/Y', strtotime($end)),
            ],
            'summary' => $dataset['summary'],
            'received_rows' => $dataset['received_rows'],
            'open_titles' => $dataset['open_titles'],
            'overdue_titles' => $dataset['overdue_titles'],
            'by_vendor' => $dataset['by_vendor'],
            'by_client' => $dataset['by_client'],
            'status_breakdown' => $dataset['status_breakdown'],
            'document_types' => $dataset['document_types'],
            'payment_methods' => $dataset['payment_methods'],
            'meta' => [
                'generated_at' => date(DATE_ATOM),
                'served_from' => 'canonical-shadow',
                'table_kind' => 'financial_titles',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function buildTitles(string $start, string $end, string $bucket): array
    {
        $titles = $this->canonical->listFinancialTitlesByPeriod($start, $end);
        $dataset = $this->buildDataset($titles);
        $rows = match ($bucket) {
            'received' => $dataset['received_rows'],
            'overdue' => $dataset['overdue_titles'],
            default => $dataset['open_titles'],
        };

        return [
            'ok' => true,
            'mode' => 'canonical-shadow-finance',
            'source' => 'canonical_shadow',
            'period' => [
                'start' => $start,
                'end' => $end,
                'label' => date('d/m/Y', strtotime($start)) . ' ate ' . date('d/m/Y', strtotime($end)),
            ],
            'bucket' => $bucket,
            'rows' => $rows,
            'meta' => [
                'generated_at' => date(DATE_ATOM),
                'served_from' => 'canonical-shadow',
                'table_kind' => 'financial_titles',
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $titles
     * @return array<string,mixed>
     */
    private function buildDataset(array $titles): array
    {
        $today = new DateTimeImmutable('today');
        $receivedRows = [];
        $openTitles = [];
        $overdueTitles = [];
        $vendorMap = [];
        $clientMap = [];
        $documentTypes = [];
        $paymentMethods = [];
        $receivedTotal = 0.0;
        $openTotal = 0.0;
        $overdueTotal = 0.0;

        foreach ($titles as $title) {
            if (!is_array($title)) {
                continue;
            }

            $normalized = $this->normalizeTitle($title);
            $status = strtolower(trim((string) ($normalized['status'] ?? '')));
            $amountNet = Support::toFloat($normalized['amount_net'] ?? 0);
            $amountOpen = Support::toFloat($normalized['amount_open'] ?? 0);
            $dueDate = trim((string) ($normalized['due_date'] ?? ''));
            $clientName = trim((string) ($normalized['client_name'] ?? '')) ?: 'Cliente nao informado';
            $vendorName = trim((string) ($normalized['vendor_name'] ?? '')) ?: 'Sem vendedor';
            $documentType = trim((string) ($normalized['document_type'] ?? '')) ?: 'Titulo financeiro';
            $paymentMethod = trim((string) ($normalized['payment_method'] ?? '')) ?: 'Nao identificado';

            if ($amountNet > 0.0001 && in_array($status, ['liquidado', 'recebido', 'pago'], true)) {
                $receivedRows[] = $normalized;
                $receivedTotal += $amountNet;

                if (!isset($vendorMap[$vendorName])) {
                    $vendorMap[$vendorName] = [
                        'vendor_id' => (string) ($normalized['vendor_id'] ?? ''),
                        'vendor_name' => $vendorName,
                        'received' => 0.0,
                        'titles' => 0,
                        'avg_ticket' => 0.0,
                    ];
                }
                $vendorMap[$vendorName]['received'] += $amountNet;
                $vendorMap[$vendorName]['titles']++;
            }

            if ($amountOpen > 0.0001) {
                $openTitles[] = $normalized;
                $openTotal += $amountOpen;

                $isOverdue = $dueDate !== '' && new DateTimeImmutable($dueDate) < $today;
                if ($isOverdue) {
                    $overdueTitles[] = $normalized;
                    $overdueTotal += $amountOpen;

                    if (!isset($clientMap[$clientName])) {
                        $clientMap[$clientName] = [
                            'client_id' => (string) ($normalized['client_id'] ?? ''),
                            'client_name' => $clientName,
                            'client_document' => (string) ($normalized['client_document'] ?? ''),
                            'overdue' => 0.0,
                            'titles' => 0,
                        ];
                    }
                    $clientMap[$clientName]['overdue'] += $amountOpen;
                    $clientMap[$clientName]['titles']++;
                }
            }

            if (!isset($documentTypes[$documentType])) {
                $documentTypes[$documentType] = [
                    'document_type' => $documentType,
                    'titles' => 0,
                    'amount' => 0.0,
                ];
            }
            $documentTypes[$documentType]['titles']++;
            $documentTypes[$documentType]['amount'] += $amountOpen > 0 ? $amountOpen : $amountNet;

            if (!isset($paymentMethods[$paymentMethod])) {
                $paymentMethods[$paymentMethod] = [
                    'payment_method' => $paymentMethod,
                    'received' => 0.0,
                    'titles' => 0,
                ];
            }
            $paymentMethods[$paymentMethod]['titles']++;
            $paymentMethods[$paymentMethod]['received'] += $amountNet;
        }

        foreach ($vendorMap as &$vendorRow) {
            $titlesCount = max(1, (int) ($vendorRow['titles'] ?? 0));
            $vendorRow['received'] = Support::money((float) ($vendorRow['received'] ?? 0));
            $vendorRow['avg_ticket'] = Support::money((float) ($vendorRow['received'] ?? 0) / $titlesCount);
        }
        unset($vendorRow);

        $byVendor = array_values($vendorMap);
        usort($byVendor, static fn (array $left, array $right): int => Support::toFloat($right['received'] ?? 0) <=> Support::toFloat($left['received'] ?? 0));

        foreach ($clientMap as &$clientRow) {
            $clientRow['overdue'] = Support::money((float) ($clientRow['overdue'] ?? 0));
        }
        unset($clientRow);

        $byClient = array_values($clientMap);
        usort($byClient, static fn (array $left, array $right): int => Support::toFloat($right['overdue'] ?? 0) <=> Support::toFloat($left['overdue'] ?? 0));

        $documentTypesRows = array_values(array_map(static function (array $row): array {
            $row['amount'] = Support::money((float) ($row['amount'] ?? 0));
            return $row;
        }, $documentTypes));
        usort($documentTypesRows, static fn (array $left, array $right): int => Support::toFloat($right['amount'] ?? 0) <=> Support::toFloat($left['amount'] ?? 0));

        $paymentMethodRows = array_values(array_map(static function (array $row): array {
            $row['received'] = Support::money((float) ($row['received'] ?? 0));
            return $row;
        }, $paymentMethods));
        usort($paymentMethodRows, static fn (array $left, array $right): int => Support::toFloat($right['received'] ?? 0) <=> Support::toFloat($left['received'] ?? 0));

        usort($receivedRows, static fn (array $left, array $right): int => strcmp((string) ($right['paid_date'] ?? ''), (string) ($left['paid_date'] ?? '')));
        usort($openTitles, static fn (array $left, array $right): int => strcmp((string) ($left['due_date'] ?? ''), (string) ($right['due_date'] ?? '')));
        usort($overdueTitles, static fn (array $left, array $right): int => ((int) ($right['days_late'] ?? 0)) <=> ((int) ($left['days_late'] ?? 0)));

        return [
            'summary' => [
                'received' => Support::money($receivedTotal),
                'open' => Support::money($openTotal),
                'overdue' => Support::money($overdueTotal),
                'vendors' => count($byVendor),
                'received_titles' => count($receivedRows),
                'open_titles' => count($openTitles),
                'overdue_titles' => count($overdueTitles),
            ],
            'received_rows' => array_slice($receivedRows, 0, 80),
            'open_titles' => array_slice($openTitles, 0, 80),
            'overdue_titles' => array_slice($overdueTitles, 0, 80),
            'by_vendor' => array_slice($byVendor, 0, 20),
            'by_client' => array_slice($byClient, 0, 20),
            'status_breakdown' => [
                ['label' => 'Recebido', 'amount' => Support::money($receivedTotal), 'titles' => count($receivedRows)],
                ['label' => 'Em aberto', 'amount' => Support::money($openTotal), 'titles' => count($openTitles)],
                ['label' => 'Vencido', 'amount' => Support::money($overdueTotal), 'titles' => count($overdueTitles)],
            ],
            'document_types' => array_slice($documentTypesRows, 0, 12),
            'payment_methods' => array_slice($paymentMethodRows, 0, 12),
        ];
    }

    /**
     * @param array<string,mixed> $title
     * @return array<string,mixed>
     */
    private function normalizeTitle(array $title): array
    {
        $dueDate = (string) ($title['data_vencimento'] ?? '');
        $daysLate = 0;
        if ($dueDate !== '') {
            $today = new DateTimeImmutable('today');
            $due = new DateTimeImmutable($dueDate);
            if ($today > $due) {
                $daysLate = (int) $today->diff($due)->format('%a');
            }
        }

        $titleId = trim((string) ($title['titulo_id_omie'] ?? ''));
        if ($titleId === '') {
            $titleId = trim((string) ($title['titulo_key'] ?? ''));
        }

        $documentNumber = trim((string) ($title['numero_documento'] ?? ''));
        $originRef = trim((string) ($title['documento_origem_ref'] ?? ''));
        $documentLabel = $originRef !== '' ? $originRef : $documentNumber;

        return [
            'title_id' => $titleId,
            'document' => $documentNumber,
            'document_label' => $documentLabel,
            'document_type' => trim((string) ($title['especie_documento'] ?? '')) ?: 'Titulo financeiro',
            'payment_method' => trim((string) ($title['forma_pagamento'] ?? '')) ?: 'Nao identificado',
            'client_id' => (string) ($title['codigo_cliente_omie'] ?? ''),
            'client_name' => trim((string) ($title['nome_cliente'] ?? '')) ?: 'Cliente nao informado',
            'client_document' => (string) ($title['documento_cliente'] ?? ''),
            'vendor_id' => (string) ($title['codigo_vendedor_omie'] ?? ''),
            'vendor_name' => trim((string) ($title['nome_vendedor'] ?? '')) ?: 'Sem vendedor',
            'account_name' => trim((string) ($title['codigo_conta_corrente_omie'] ?? '')) ?: 'Conta nao identificada',
            'due_date' => $dueDate,
            'paid_date' => (string) ($title['data_liquidacao'] ?? ''),
            'status' => (string) ($title['status_titulo'] ?? ''),
            'days_late' => $daysLate,
            'amount_open' => Support::money((float) ($title['valor_aberto'] ?? 0)),
            'amount_net' => Support::money((float) ($title['valor_liquido'] ?? $title['valor_pago'] ?? 0)),
        ];
    }
}
