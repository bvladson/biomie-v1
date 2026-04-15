<?php

declare(strict_types=1);

namespace BIomie;

final class OmieConsolidatedCsvService
{
    /**
     * @return array{
     *   source_file:string,
     *   period:array{start:string,end:string,label:string},
     *   notes:list<array<string,mixed>>,
     *   summary:array<string,mixed>,
     *   warnings:list<string>
     * }
     */
    public function parseFile(string $path, ?string $sourceFile = null): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Arquivo CSV não encontrado para a reconciliação.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Não foi possível abrir o CSV consolidado.');
        }

        $header = fgetcsv($handle, 0, ';', '"', '\\');
        if (!is_array($header) || $header === []) {
            fclose($handle);
            throw new \RuntimeException('O CSV consolidado não possui cabeçalho válido.');
        }

        $indexes = [];
        foreach ($header as $index => $column) {
            $indexes[$this->normalizeText((string) $column)] = $index;
        }

        $mapped = [
            'issue_date' => $this->resolveColumn($indexes, ['data de emissao']),
            'status' => $this->resolveColumn($indexes, ['situacao']),
            'note_number' => $this->resolveColumn($indexes, ['nota fiscal']),
            'series' => $this->resolveColumn($indexes, ['serie']),
            'vendor' => $this->resolveColumn($indexes, ['vendedor']),
            'invoice_total' => $this->resolveColumn($indexes, ['total da nota fiscal']),
            'merchandise_total' => $this->resolveColumn($indexes, ['total de mercadoria']),
            'discount_total' => $this->resolveColumn($indexes, ['desconto']),
            'customer_name' => $this->resolveColumn($indexes, ['cliente razao social', 'cliente nome fantasia']),
            'document_key' => $this->resolveColumn($indexes, ['chave de acesso da nf e']),
        ];

        foreach (['issue_date', 'status', 'note_number', 'series', 'vendor', 'invoice_total', 'merchandise_total', 'discount_total'] as $required) {
            if ($mapped[$required] === null) {
                fclose($handle);
                throw new \RuntimeException('O CSV não tem a coluna esperada para "' . $required . '".');
            }
        }

        $notes = [];
        $warnings = [];
        $minDate = null;
        $maxDate = null;

        while (($row = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
            if (!$this->rowHasContent($row)) {
                continue;
            }

            $issueDate = Support::formatDate((string) ($row[$mapped['issue_date']] ?? ''));
            $status = $this->mapStatus((string) ($row[$mapped['status']] ?? ''));
            $noteNumberDisplay = trim((string) ($row[$mapped['note_number']] ?? ''));
            $seriesDisplay = trim((string) ($row[$mapped['series']] ?? ''));
            $vendorName = trim((string) ($row[$mapped['vendor']] ?? ''));

            if ($issueDate === null || $noteNumberDisplay === '' || $status === null) {
                continue;
            }

            $noteKey = $this->buildNoteKey($noteNumberDisplay, $seriesDisplay, (string) ($row[$mapped['document_key']] ?? ''));
            $invoiceTotal = Support::money(Support::toFloat($row[$mapped['invoice_total']] ?? 0));
            $merchandiseTotal = Support::money(Support::toFloat($row[$mapped['merchandise_total']] ?? 0));
            $discountTotal = Support::money(Support::toFloat($row[$mapped['discount_total']] ?? 0));

            if (!isset($notes[$noteKey])) {
                $notes[$noteKey] = [
                    'note_key' => $noteKey,
                    'note_number' => $noteNumberDisplay,
                    'series' => $seriesDisplay,
                    'note_ref' => $this->formatReference($noteNumberDisplay, $seriesDisplay),
                    'status' => $status,
                    'issue_date' => $issueDate,
                    'vendor_name' => $vendorName !== '' ? $vendorName : 'Sem vendedor',
                    'customer_name' => trim((string) ($row[$mapped['customer_name']] ?? '')),
                    'document_key' => trim((string) ($row[$mapped['document_key']] ?? '')),
                    // No consolidado do Omie, o "Total da Nota Fiscal" vem por linha.
                    // Para fechar o valor da NF precisamos somar linha a linha.
                    'invoice_total' => 0.0,
                    'merchandise_total' => 0.0,
                    'discount_total' => 0.0,
                    'items_count' => 0,
                ];
            } else {
                if ($notes[$noteKey]['status'] !== $status) {
                    $warnings[] = 'NF ' . $notes[$noteKey]['note_ref'] . ' apareceu com status diferentes no CSV.';
                }
            }

            $notes[$noteKey]['invoice_total'] = Support::money((float) $notes[$noteKey]['invoice_total'] + $invoiceTotal);
            $notes[$noteKey]['merchandise_total'] = Support::money((float) $notes[$noteKey]['merchandise_total'] + $merchandiseTotal);
            $notes[$noteKey]['discount_total'] = Support::money((float) $notes[$noteKey]['discount_total'] + $discountTotal);
            $notes[$noteKey]['items_count'] = (int) $notes[$noteKey]['items_count'] + 1;

            $minDate = $minDate === null || $issueDate < $minDate ? $issueDate : $minDate;
            $maxDate = $maxDate === null || $issueDate > $maxDate ? $issueDate : $maxDate;
        }

        fclose($handle);

        if ($notes === [] || $minDate === null || $maxDate === null) {
            throw new \RuntimeException('Nenhuma NF válida foi encontrada no CSV consolidado.');
        }

        $notesList = array_values($notes);
        usort($notesList, fn (array $left, array $right): int => strcmp(
            ($left['issue_date'] ?? '') . '|' . ($left['note_ref'] ?? ''),
            ($right['issue_date'] ?? '') . '|' . ($right['note_ref'] ?? '')
        ));

        return [
            'source_file' => $sourceFile ?? basename($path),
            'period' => [
                'start' => $minDate,
                'end' => $maxDate,
                'label' => date('d/m/Y', strtotime($minDate)) . ' até ' . date('d/m/Y', strtotime($maxDate)),
            ],
            'notes' => $notesList,
            'summary' => $this->buildSummary($notesList),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @param list<array<string,mixed>> $systemRows
     * @return array<string,mixed>
     */
    public function compareWithSystem(array $parsed, array $systemRows): array
    {
        $csvNotes = [];
        foreach (($parsed['notes'] ?? []) as $row) {
            $csvNotes[(string) ($row['note_key'] ?? '')] = $row;
        }

        $systemNotes = [];
        foreach ($systemRows as $row) {
            $noteNumber = trim((string) ($row['numero_nf'] ?? ''));
            $series = trim((string) ($row['serie_nf'] ?? ''));
            $noteKey = $this->buildNoteKey($noteNumber, $series, (string) ($row['chave_nfe'] ?? ''));
            if ($noteKey === '') {
                continue;
            }

            $systemNotes[$noteKey] = [
                'note_key' => $noteKey,
                'note_number' => $noteNumber,
                'series' => $series,
                'note_ref' => $this->formatReference($noteNumber, $series),
                'status' => $this->mapStatus((string) ($row['status_fiscal'] ?? '')) ?? 'outra',
                'issue_date' => Support::formatDate((string) ($row['data_emissao'] ?? '')),
                'vendor_name' => trim((string) ($row['nome_vendedor'] ?? '')) ?: 'Sem vendedor',
                'customer_name' => trim((string) ($row['nome_cliente'] ?? '')),
                'document_key' => trim((string) ($row['chave_nfe'] ?? '')),
                'invoice_total' => Support::money((float) ($row['valor_total'] ?? 0)),
                'merchandise_total' => Support::money((float) ($row['valor_mercadorias'] ?? 0)),
                'discount_total' => Support::money((float) ($row['valor_descontos'] ?? 0)),
            ];
        }

        $allKeys = array_values(array_unique(array_merge(array_keys($csvNotes), array_keys($systemNotes))));
        sort($allKeys);

        $differences = [];
        $matches = 0;
        $missingInSystem = 0;
        $extraInSystem = 0;
        $valueMismatches = 0;
        $statusMismatches = 0;
        $vendorMismatches = 0;

        foreach ($allKeys as $key) {
            $csv = $csvNotes[$key] ?? null;
            $system = $systemNotes[$key] ?? null;
            $issues = [];
            $type = 'match';

            if ($csv === null) {
                $type = 'extra_in_system';
                $issues[] = 'NF existe no BIomie, mas não está no consolidado enviado.';
                $extraInSystem++;
            } elseif ($system === null) {
                $type = 'missing_in_system';
                $issues[] = 'NF está no consolidado, mas não foi encontrada no BIomie.';
                $missingInSystem++;
            } else {
                if (($csv['status'] ?? '') !== ($system['status'] ?? '')) {
                    $issues[] = 'Status diferente.';
                    $statusMismatches++;
                }
                if (!$this->sameVendor((string) ($csv['vendor_name'] ?? ''), (string) ($system['vendor_name'] ?? ''))) {
                    $issues[] = 'Vendedor diferente.';
                    $vendorMismatches++;
                }

                foreach (['invoice_total' => 'Total NF', 'merchandise_total' => 'Mercadoria', 'discount_total' => 'Desconto'] as $field => $label) {
                    if (!$this->sameMoney((float) ($csv[$field] ?? 0), (float) ($system[$field] ?? 0))) {
                        $issues[] = $label . ' diferente.';
                    }
                }

                if ($issues === []) {
                    $matches++;
                } else {
                    $type = 'mismatch';
                    $valueMismatches++;
                }
            }

            if ($issues !== []) {
                $differences[] = [
                    'note_key' => (string) (($csv['note_key'] ?? $system['note_key']) ?? ''),
                    'type' => $type,
                    'note_ref' => (string) (($csv['note_ref'] ?? $system['note_ref']) ?? ''),
                    'csv_status' => $csv['status'] ?? null,
                    'system_status' => $system['status'] ?? null,
                    'csv_vendor_name' => $csv['vendor_name'] ?? null,
                    'system_vendor_name' => $system['vendor_name'] ?? null,
                    'csv_issue_date' => $csv['issue_date'] ?? null,
                    'system_issue_date' => $system['issue_date'] ?? null,
                    'csv_invoice_total' => $csv['invoice_total'] ?? 0,
                    'system_invoice_total' => $system['invoice_total'] ?? 0,
                    'csv_merchandise_total' => $csv['merchandise_total'] ?? 0,
                    'system_merchandise_total' => $system['merchandise_total'] ?? 0,
                    'csv_discount_total' => $csv['discount_total'] ?? 0,
                    'system_discount_total' => $system['discount_total'] ?? 0,
                    'issues' => $issues,
                ];
            }
        }

        usort($differences, static fn (array $left, array $right): int => strcmp(
            (string) ($left['note_ref'] ?? ''),
            (string) ($right['note_ref'] ?? '')
        ));

        $systemNotesList = array_values($systemNotes);

        return [
            'source' => [
                'period' => $parsed['period'] ?? ['start' => '', 'end' => '', 'label' => ''],
                'source_file' => $parsed['source_file'] ?? 'arquivo.csv',
                'warnings' => $parsed['warnings'] ?? [],
            ],
            'omie_summary' => $parsed['summary'] ?? $this->buildSummary($csvNotes),
            'system_summary' => $this->buildSummary($systemNotesList),
            'metrics' => [
                'notes_csv' => count($csvNotes),
                'notes_system' => count($systemNotes),
                'matches' => $matches,
                'differences' => count($differences),
                'missing_in_system' => $missingInSystem,
                'extra_in_system' => $extraInSystem,
                'value_mismatches' => $valueMismatches,
                'status_mismatches' => $statusMismatches,
                'vendor_mismatches' => $vendorMismatches,
            ],
            'vendor_comparison' => $this->buildVendorComparison(($parsed['notes'] ?? []), $systemNotesList),
            'differences' => $differences,
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
            } elseif ($status === 'cancelada') {
                $summary['cancelled_notes']++;
                $summary['cancelled_invoice_total'] = Support::money($summary['cancelled_invoice_total'] + (float) ($note['invoice_total'] ?? 0));
                $summary['cancelled_merchandise_total'] = Support::money($summary['cancelled_merchandise_total'] + (float) ($note['merchandise_total'] ?? 0));
                $summary['cancelled_discount_total'] = Support::money($summary['cancelled_discount_total'] + (float) ($note['discount_total'] ?? 0));
            }
        }

        return $summary;
    }

    /**
     * @param list<array<string,mixed>> $omieNotes
     * @param list<array<string,mixed>> $systemNotes
     * @return list<array<string,mixed>>
     */
    private function buildVendorComparison(array $omieNotes, array $systemNotes): array
    {
        $rows = [];

        foreach (['omie' => $omieNotes, 'system' => $systemNotes] as $source => $notes) {
            foreach ($notes as $note) {
                $vendorName = trim((string) ($note['vendor_name'] ?? '')) ?: 'Sem vendedor';
                $vendorKey = $this->normalizeText($vendorName);
                if (!isset($rows[$vendorKey])) {
                    $rows[$vendorKey] = [
                        'vendor_key' => $vendorKey,
                        'vendor_name' => $vendorName,
                        'omie_authorized_notes' => 0,
                        'omie_authorized_total' => 0.0,
                        'omie_cancelled_notes' => 0,
                        'omie_cancelled_total' => 0.0,
                        'system_authorized_notes' => 0,
                        'system_authorized_total' => 0.0,
                        'system_cancelled_notes' => 0,
                        'system_cancelled_total' => 0.0,
                    ];
                }

                $prefix = $source . '_';
                $status = (string) ($note['status'] ?? 'outra');
                if ($status === 'autorizada') {
                    $rows[$vendorKey][$prefix . 'authorized_notes']++;
                    $rows[$vendorKey][$prefix . 'authorized_total'] = Support::money($rows[$vendorKey][$prefix . 'authorized_total'] + (float) ($note['invoice_total'] ?? 0));
                } elseif ($status === 'cancelada') {
                    $rows[$vendorKey][$prefix . 'cancelled_notes']++;
                    $rows[$vendorKey][$prefix . 'cancelled_total'] = Support::money($rows[$vendorKey][$prefix . 'cancelled_total'] + (float) ($note['invoice_total'] ?? 0));
                }
            }
        }

        foreach ($rows as &$row) {
            $row['delta_authorized_notes'] = (int) $row['system_authorized_notes'] - (int) $row['omie_authorized_notes'];
            $row['delta_authorized_total'] = Support::money((float) $row['system_authorized_total'] - (float) $row['omie_authorized_total']);
            $row['delta_cancelled_notes'] = (int) $row['system_cancelled_notes'] - (int) $row['omie_cancelled_notes'];
            $row['delta_cancelled_total'] = Support::money((float) $row['system_cancelled_total'] - (float) $row['omie_cancelled_total']);
            $row['has_difference'] = $row['delta_authorized_notes'] !== 0
                || abs((float) $row['delta_authorized_total']) > 0.01
                || $row['delta_cancelled_notes'] !== 0
                || abs((float) $row['delta_cancelled_total']) > 0.01;
        }
        unset($row);

        usort($rows, static function (array $left, array $right): int {
            if (($left['has_difference'] ?? false) !== ($right['has_difference'] ?? false)) {
                return ($left['has_difference'] ?? false) ? -1 : 1;
            }

            return strcmp((string) ($left['vendor_name'] ?? ''), (string) ($right['vendor_name'] ?? ''));
        });

        return array_values($rows);
    }

    private function resolveColumn(array $indexes, array $alternatives): ?int
    {
        foreach ($alternatives as $candidate) {
            $normalized = $this->normalizeText($candidate);
            if (array_key_exists($normalized, $indexes)) {
                return (int) $indexes[$normalized];
            }
        }

        return null;
    }

    private function rowHasContent(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function normalizeText(string $value): string
    {
        $value = trim(mb_strtolower(str_replace("\xEF\xBB\xBF", '', $value), 'UTF-8'));
        $map = [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ];
        $value = strtr($value, $map);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? '';
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    private function normalizeDigits(string $value): string
    {
        $digits = Support::digitsOnly($value);
        $digits = ltrim($digits, '0');
        return $digits !== '' ? $digits : '0';
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

    private function formatReference(string $noteNumber, string $series): string
    {
        $noteNumber = trim($noteNumber);
        $series = trim($series);
        if ($noteNumber === '') {
            return 'NF sem número';
        }

        return $noteNumber . ($series !== '' ? '/' . $series : '');
    }

    private function mapStatus(string $value): ?string
    {
        $status = $this->normalizeText($value);
        if ($status === 'autorizado' || $status === 'autorizada') {
            return 'autorizada';
        }
        if ($status === 'cancelado' || $status === 'cancelada') {
            return 'cancelada';
        }
        return null;
    }

    private function sameMoney(float $left, float $right): bool
    {
        return abs(Support::money($left) - Support::money($right)) <= 0.05;
    }

    private function sameVendor(string $left, string $right): bool
    {
        $normalizedLeft = $this->normalizeText($left);
        $normalizedRight = $this->normalizeText($right);
        if ($normalizedLeft === $normalizedRight) {
            return true;
        }

        return $normalizedLeft !== ''
            && $normalizedRight !== ''
            && (str_contains($normalizedLeft, $normalizedRight) || str_contains($normalizedRight, $normalizedLeft));
    }
}
