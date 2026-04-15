<?php

declare(strict_types=1);

namespace BIomie\FinanceAnalytics;

use BIomie\Support;
use DateTimeImmutable;

final class FinanceRowFormatter
{
    public function __construct(
        private readonly FinanceLabelResolver $labelResolver,
    ) {
    }

    public function formatReceivedRow(array $row): array
    {
        return [
            'title_id' => (string) ($row['title_id'] ?? ''),
            'document' => (string) ($row['document'] ?? ''),
            'document_label' => $this->labelResolver->resolveDocumentLabel($row),
            'document_type' => trim((string) ($row['document_type'] ?? '')) ?: 'Título financeiro',
            'client_name' => trim((string) ($row['client_name'] ?? '')) ?: 'Cliente não informado',
            'account_name' => trim((string) ($row['account_name'] ?? '')) ?: 'Conta não identificada',
            'payment_method' => trim((string) ($row['payment_method'] ?? '')) ?: 'Não identificado',
            'vendor_name' => trim((string) ($row['vendor_name'] ?? '')) ?: 'Sem vendedor',
            'paid_date' => (string) ($row['paid_date'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'amount_net' => Support::money($row['amount_net'] ?? $row['amount_paid'] ?? 0),
        ];
    }

    public function formatTitleRow(array $row): array
    {
        $dueDate = ($row['due_date'] ?? '') !== '' ? new DateTimeImmutable((string) $row['due_date']) : null;
        $daysLate = 0;
        if ($dueDate instanceof DateTimeImmutable) {
            $today = new DateTimeImmutable('today');
            if ($today > $dueDate) {
                $daysLate = (int) $today->diff($dueDate)->format('%a');
            }
        }

        return [
            'title_id' => (string) ($row['title_id'] ?? ''),
            'document' => (string) ($row['document'] ?? ''),
            'document_label' => $this->labelResolver->resolveDocumentLabel($row),
            'document_type' => trim((string) ($row['document_type'] ?? '')) ?: 'Título financeiro',
            'payment_method' => trim((string) ($row['payment_method'] ?? '')) ?: 'Não identificado',
            'client_name' => trim((string) ($row['client_name'] ?? '')) ?: 'Cliente não informado',
            'client_document' => (string) ($row['client_document'] ?? ''),
            'vendor_name' => trim((string) ($row['vendor_name'] ?? '')) ?: 'Sem vendedor',
            'account_name' => trim((string) ($row['account_name'] ?? '')) ?: 'Conta não identificada',
            'due_date' => (string) ($row['due_date'] ?? ''),
            'paid_date' => (string) ($row['paid_date'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'days_late' => $daysLate,
            'amount_open' => Support::money($row['amount_open'] ?? 0),
            'amount_net' => Support::money($row['amount_net'] ?? 0),
        ];
    }
}
