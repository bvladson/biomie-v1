<?php

declare(strict_types=1);

namespace BIomie\FinanceAnalytics;

use BIomie\Support;
use DateTimeImmutable;

final class FinanceLabelResolver
{
    /**
     * @param array<string,string> $vendorMap
     */
    public function resolveVendorName(array $title, array $vendorMap): string
    {
        $name = trim((string) ($title['vendor_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        foreach ($this->buildLookupKeys($title['vendor_id'] ?? '') as $key) {
            if (!isset($vendorMap[$key])) {
                continue;
            }
            $resolved = trim((string) $vendorMap[$key]);
            if ($resolved !== '') {
                return $resolved;
            }
        }

        return 'Sem vendedor';
    }

    /**
     * @param array<string,array<string,mixed>> $accountMap
     */
    public function resolveAccountLabel(mixed $accountId, array $accountMap): string
    {
        foreach ($this->buildLookupKeys($accountId) as $key) {
            if (!isset($accountMap[$key])) {
                continue;
            }
            $label = trim((string) ($accountMap[$key]['description'] ?? ''));
            if ($label !== '') {
                return $label;
            }
        }

        return 'Conta não identificada';
    }

    /** @return list<string> */
    public function buildLookupKeys(mixed ...$values): array
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

    public function inferDocumentType(array $row): string
    {
        $raw = strtolower(trim((string) Support::getByPaths($row, ['document_type_raw', 'status'], '')));
        $document = strtolower(trim((string) ($row['document'] ?? '')));
        $integration = strtolower(trim((string) ($row['integration_code'] ?? '')));
        $joined = trim($raw . ' ' . $document . ' ' . $integration);

        if ($joined === '') {
            return 'Título financeiro';
        }
        if (str_contains($joined, 'boleto') || str_contains($joined, 'bol')) {
            return 'Boleto';
        }
        if (str_contains($joined, 'pix')) {
            return 'PIX';
        }
        if (str_contains($joined, 'cart') || str_contains($joined, 'credito') || str_contains($joined, 'débito') || str_contains($joined, 'debito')) {
            return 'Cartão';
        }
        if (str_contains($joined, 'nf') || str_contains($joined, 'nfe') || str_contains($joined, 'nota')) {
            return 'Nota fiscal';
        }
        if (str_contains($joined, 'ped') || str_contains($joined, 'pedido')) {
            return 'Pedido';
        }
        if (str_contains($joined, 'dup')) {
            return 'Duplicata';
        }
        if (str_contains($joined, 'rec')) {
            return 'Recibo';
        }
        if ($raw !== '') {
            return $this->titleize($raw);
        }

        return 'Título financeiro';
    }

    public function inferPaymentMethod(array $row, string $accountName = ''): string
    {
        $raw = strtolower(trim((string) ($row['payment_method_raw'] ?? '')));
        $account = strtolower(trim($accountName));
        $type = strtolower(trim((string) ($row['document_type'] ?? $row['document_type_raw'] ?? '')));
        $joined = trim($raw . ' ' . $account . ' ' . $type);

        if ($joined === '') {
            return 'Não identificado';
        }
        if (str_contains($joined, 'cielo') || str_contains($joined, 'stone') || str_contains($joined, 'rede') || str_contains($joined, 'master') || str_contains($joined, 'visa') || str_contains($joined, 'cart')) {
            return 'Cartão';
        }
        if (str_contains($joined, 'pix')) {
            return 'PIX';
        }
        if (str_contains($joined, 'boleto') || str_contains($joined, 'cobran') || str_contains($joined, 'bank slip')) {
            return 'Boleto';
        }
        if (str_contains($joined, 'cash') || str_contains($joined, 'dinheiro')) {
            return 'Dinheiro';
        }
        if (str_contains($joined, 'transfer') || str_contains($joined, 'ted') || str_contains($joined, 'doc')) {
            return 'Transferência';
        }
        if ($raw !== '') {
            return $this->titleize($raw);
        }
        if ($account !== '') {
            return $this->titleize($account);
        }

        return 'Não identificado';
    }

    public function resolveDocumentLabel(array $row): string
    {
        $document = trim((string) ($row['document'] ?? ''));
        if ($document !== '') {
            return $document;
        }
        $integration = trim((string) ($row['integration_code'] ?? ''));
        if ($integration !== '') {
            return $integration;
        }
        $titleId = trim((string) ($row['title_id'] ?? ''));
        if ($titleId !== '') {
            return 'Título #' . $titleId;
        }

        return 'Sem documento';
    }

    public function titleize(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);

        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    public function daysLate(string $dueDate): int
    {
        if ($dueDate === '') {
            return 0;
        }
        $due = new DateTimeImmutable($dueDate);
        $today = new DateTimeImmutable('today');
        if ($today <= $due) {
            return 0;
        }

        return (int) $today->diff($due)->format('%a');
    }
}
