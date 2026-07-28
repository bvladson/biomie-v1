<?php

declare(strict_types=1);

namespace BIomie\Modules\Fiscal\Infrastructure;

use BIomie\OmieRepository;
use BIomie\Support;
use BIomie\Support\TenantContext;
use PDO;

final class FiscalPurchaseEntrySyncService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly OmieRepository $omie,
    ) {}

    public function sync(string $start, string $end): array
    {
        if (TenantContext::companyId() <= 0) {
            throw new \RuntimeException('Selecione uma empresa antes de sincronizar entradas fiscais.');
        }
        $entries = $this->omie->listPurchaseEntries($start, $end);
        $supplierCodes = [];
        foreach ($entries as $entry) {
            $code = (int) ($entry['cabec']['nCodCli'] ?? 0);
            if ($code > 0) {
                $supplierCodes[] = $code;
            }
        }
        $suppliers = $this->omie->listClientsSummaryByCodes($supplierCodes);

        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare(
                'DELETE FROM fiscal_purchase_entries
                  WHERE company_id = :company_id AND data_entrada BETWEEN :start AND :end'
            );
            $delete->execute([
                'company_id' => TenantContext::companyId(),
                'start' => $start,
                'end' => $end,
            ]);

            $documents = 0;
            $items = 0;
            foreach ($entries as $entry) {
                $normalized = $this->normalizeEntry($entry, $suppliers);
                $this->insertHeader($normalized['header']);
                foreach ($normalized['items'] as $item) {
                    $this->insertItem($item);
                    $items++;
                }
                $documents++;
            }

            $this->markSourceActive();
            $this->pdo->commit();
            (new FiscalSettlementRefresher($this->pdo))->refresh();

            return [
                'documents' => $documents,
                'items' => $items,
                'period' => ['start' => $start, 'end' => $end],
            ];
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function normalizeEntry(array $entry, array $suppliers): array
    {
        $header = (array) ($entry['cabec'] ?? []);
        $totals = (array) ($entry['totais'] ?? []);
        $freight = (array) ($entry['frete'] ?? []);
        $rawItems = is_array($entry['produtos'] ?? null) ? $entry['produtos'] : [];
        $omieId = (int) ($header['nCodNotaEnt'] ?? 0);
        $entryKey = 'omie-entry:' . $omieId;
        $date = $this->parseDate((string) ($header['dPrevisao'] ?? '')) ?? date('Y-m-d');
        $supplierId = (int) ($header['nCodCli'] ?? 0);
        $supplier = is_array($suppliers[(string) $supplierId] ?? null)
            ? $suppliers[(string) $supplierId]
            : [];
        $supplierName = trim((string) Support::getByPaths($supplier, [
            'razao_social',
            'nome_fantasia',
            'nome',
        ], ''));

        $items = [];
        $sums = ['icms' => 0.0, 'pis' => 0.0, 'cofins' => 0.0, 'ipi' => 0.0, 'cbs' => 0.0, 'ibs' => 0.0];
        foreach (array_values($rawItems) as $index => $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }
            $item = $this->normalizeItem($entryKey, $index + 1, $rawItem);
            foreach (array_keys($sums) as $tax) {
                $sums[$tax] += (float) $item['valor_' . $tax];
            }
            $items[] = $item;
        }

        $sourceHash = hash('sha256', json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $creditPotential = $sums['icms'] + $sums['pis'] + $sums['cofins'] + $sums['ipi'];

        return [
            'header' => [
                'entry_key' => $entryKey,
                'omie_entry_id' => $omieId,
                'numero_documento' => (string) ($header['cNumeroNotaEnt'] ?? $omieId),
                'data_entrada' => $date,
                'competencia' => substr($date, 0, 7),
                'fornecedor_id' => $supplierId ?: null,
                'fornecedor_nome' => $supplierName !== '' ? $supplierName : null,
                'comprador_id' => (int) ($header['nCodVend'] ?? 0) ?: null,
                'status_entrada' => 'registrada',
                'valor_mercadorias' => (float) ($totals['nMercadorias'] ?? 0),
                'valor_descontos' => (float) ($totals['nDescontos'] ?? 0),
                'valor_frete' => (float) ($freight['nValFrete'] ?? 0),
                'valor_seguro' => (float) ($freight['nValSeguro'] ?? 0),
                'valor_outros' => (float) ($freight['nValOutras'] ?? 0),
                'valor_total' => (float) ($totals['nTotalNotaEnt'] ?? 0),
                'valor_icms' => $sums['icms'],
                'valor_pis' => $sums['pis'],
                'valor_cofins' => $sums['cofins'],
                'valor_ipi' => $sums['ipi'],
                'valor_cbs' => $sums['cbs'],
                'valor_ibs' => $sums['ibs'],
                'credito_potencial' => $creditPotential,
                'itens_count' => count($items),
                'source_hash' => $sourceHash,
            ],
            'items' => $items,
        ];
    }

    private function normalizeItem(string $entryKey, int $sequence, array $item): array
    {
        $icms = (array) ($item['ICMS'] ?? []);
        $icmsSt = (array) ($item['ICMS_ST'] ?? []);
        $pis = (array) ($item['PIS'] ?? []);
        $cofins = (array) ($item['COFINS'] ?? []);
        $ipi = (array) ($item['IPI'] ?? []);
        $cbs = (array) ($item['CBS'] ?? []);
        $ibs = (array) ($item['IBS'] ?? []);
        $ibsCbs = (array) ($item['IBS_CBS'] ?? []);
        $quantity = (float) ($item['nQtde'] ?? 0);
        $unit = (float) ($item['nValUnit'] ?? 0);
        $discount = (float) ($item['nDesconto'] ?? 0);

        return [
            'entry_key' => $entryKey,
            'item_sequence' => $sequence,
            'omie_item_id' => (int) ($item['nCodIt'] ?? 0) ?: null,
            'omie_product_id' => (int) ($item['nCodProd'] ?? 0) ?: null,
            'ncm' => $this->digits((string) ($item['cNCM'] ?? '')),
            'cfop' => $this->digits((string) ($item['cCFOP'] ?? '')),
            'quantidade' => $quantity,
            'valor_unitario' => $unit,
            'valor_desconto' => $discount,
            'valor_total_item' => max(0.0, ($quantity * $unit) - $discount),
            'cst_icms' => (string) ($icms['cSitTrib'] ?? ''),
            'base_icms' => (float) ($icms['nBC'] ?? 0),
            'aliq_icms' => (float) ($icms['nAliq'] ?? 0),
            'valor_icms' => (float) ($icms['nValor'] ?? 0),
            'valor_icms_st' => (float) ($icmsSt['nValorST'] ?? 0),
            'cst_pis' => (string) ($pis['cSitTribPIS'] ?? ''),
            'base_pis' => (float) ($pis['nBCPIS'] ?? 0),
            'aliq_pis' => (float) ($pis['nAliqPIS'] ?? 0),
            'valor_pis' => (float) ($pis['nValPIS'] ?? 0),
            'cst_cofins' => (string) ($cofins['cSitTribCOFINS'] ?? ''),
            'base_cofins' => (float) ($cofins['nBCCOFINS'] ?? 0),
            'aliq_cofins' => (float) ($cofins['nAliqCOFINS'] ?? 0),
            'valor_cofins' => (float) ($cofins['nValCOFINS'] ?? 0),
            'cst_ipi' => (string) ($ipi['cSitTribIPI'] ?? ''),
            'base_ipi' => (float) ($ipi['nBCIPI'] ?? 0),
            'aliq_ipi' => (float) ($ipi['nAliqIPI'] ?? 0),
            'valor_ipi' => (float) ($ipi['nValIPI'] ?? 0),
            'base_cbs_ibs' => (float) ($ibsCbs['nBaseIbsCbs'] ?? 0),
            'valor_cbs' => (float) ($cbs['nValCbs'] ?? 0),
            'valor_ibs' => (float) ($ibs['nValIbs'] ?? 0),
        ];
    }

    private function insertHeader(array $row): void
    {
        $row = ['company_id' => TenantContext::companyId()] + $row;
        $columns = array_keys($row);
        $stmt = $this->pdo->prepare(
            'INSERT INTO fiscal_purchase_entries (`' . implode('`,`', $columns) . '`) VALUES ('
            . implode(',', array_fill(0, count($columns), '?')) . ')'
        );
        $stmt->execute(array_values($row));
    }

    private function insertItem(array $row): void
    {
        $row = ['company_id' => TenantContext::companyId()] + $row;
        $columns = array_keys($row);
        $stmt = $this->pdo->prepare(
            'INSERT INTO fiscal_purchase_entry_items (`' . implode('`,`', $columns) . '`) VALUES ('
            . implode(',', array_fill(0, count($columns), '?')) . ')'
        );
        $stmt->execute(array_values($row));
    }

    private function markSourceActive(): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE fiscal_source_registry
             SET status='active', connection_mode='api', last_sync_at=NOW(),
                 notes='Notas de entrada sincronizadas pela API Omie; créditos exibidos como potenciais até validação contábil.'
             WHERE company_id=:company_id AND source_code='omie_entrada'"
        );
        $stmt->execute(['company_id' => TenantContext::companyId()]);
    }

    private function parseDate(string $value): ?string
    {
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', trim($value), $matches) !== 1) {
            return null;
        }
        return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
    }

    private function digits(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        return $digits !== '' ? $digits : null;
    }
}
