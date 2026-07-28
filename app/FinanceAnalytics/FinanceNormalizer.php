<?php

declare(strict_types=1);

namespace BIomie\FinanceAnalytics;

use BIomie\Support;

final class FinanceNormalizer
{
    public function __construct(
        private readonly FinanceLabelResolver $labelResolver,
    ) {
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    public function normalizeAccounts(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            $normalized[] = [
                'account_id' => (string) Support::getByPaths($row, ['nCodCC', 'codigo', 'codigo_conta_corrente'], ''),
                'integration_code' => (string) Support::getByPaths($row, ['cCodCCInt', 'codigo_integra', 'codigo_integracao'], ''),
                'description' => (string) Support::getByPaths($row, ['descricao', 'nome', 'conta_corrente'], 'Conta sem descrição'),
            ];
        }

        return $normalized;
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    public function normalizeMovements(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            $amountPaid = Support::toFloat(Support::getByPaths($row, ['resumo.nValPago', 'nValPago', 'valor_pago'], 0));
            $amountNet = Support::toFloat(Support::getByPaths($row, ['resumo.nValLiquido', 'nValLiquido', 'valor_liquido'], $amountPaid));
            $normalized[] = [
                'title_id' => (string) Support::getByPaths($row, ['detalhes.nCodTitulo', 'nCodTitulo', 'codigo_lancamento_omie'], ''),
                'integration_code' => (string) Support::getByPaths($row, ['detalhes.cCodIntTitulo', 'cCodIntTitulo', 'codigo_integracao'], ''),
                'document' => (string) Support::getByPaths($row, ['detalhes.cNumTitulo', 'cNumTitulo', 'documento'], ''),
                'document_type_raw' => (string) Support::findFirstByKeys($row, ['cTipoTitulo', 'tipo_titulo', 'tipo_documento', 'cEspecie', 'especie_documento'], ''),
                'payment_method_raw' => (string) Support::findFirstByKeys($row, ['cFormaPag', 'forma_pagamento', 'meio_pagamento', 'cMeioPagamento', 'descricao_portador'], ''),
                'account_id' => (string) Support::getByPaths($row, ['detalhes.nCodCC', 'nCodCC', 'codigo_conta_corrente'], ''),
                'client_name' => (string) Support::findFirstByKeys($row, ['cNomeCliente', 'nome_cliente', 'razao_social', 'nome_fantasia'], ''),
                'paid_date' => Support::formatDate((string) Support::getByPaths($row, ['detalhes.dDtPagamento', 'dDtPagamento', 'data_pagamento'], '')) ?? '',
                'due_date' => Support::formatDate((string) Support::getByPaths($row, ['detalhes.dDtVenc', 'dDtVenc', 'data_vencimento'], '')) ?? '',
                'amount_paid' => $amountPaid,
                'amount_net' => $amountNet > 0 ? $amountNet : $amountPaid,
                'amount_open' => Support::toFloat(Support::getByPaths($row, ['resumo.nValAberto', 'nValAberto'], 0)),
                'status' => (string) Support::getByPaths($row, ['detalhes.cStatus', 'cStatus', 'status'], ''),
            ];
        }

        return $normalized;
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    public function normalizeTitles(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            $normalized[] = [
                'title_id' => (string) Support::getByPaths($row, ['cabecTitulo.nCodTitulo', 'nCodTitulo'], ''),
                'integration_code' => (string) Support::getByPaths($row, ['cabecTitulo.cCodIntTitulo', 'cCodIntTitulo'], ''),
                'document' => (string) Support::getByPaths($row, ['cabecTitulo.cNumTitulo', 'cNumTitulo', 'documento'], ''),
                'status' => (string) Support::getByPaths($row, ['cabecTitulo.cStatus', 'cStatus', 'status'], ''),
                'document_type_raw' => (string) Support::findFirstByKeys($row, ['cTipoTitulo', 'tipo_titulo', 'tipo_documento', 'cEspecie', 'especie_documento'], ''),
                'payment_method_raw' => (string) Support::findFirstByKeys($row, ['cFormaPag', 'forma_pagamento', 'meio_pagamento', 'cMeioPagamento', 'descricao_portador'], ''),
                'client_id' => (string) Support::getByPaths($row, ['cabecTitulo.nCodCliente', 'nCodCliente', 'codigo_cliente_omie'], ''),
                'client_name' => (string) Support::findFirstByKeys($row, ['cNomeCliente', 'nome_cliente', 'razao_social', 'nome_fantasia', 'Cliente (Razão Social)', 'Cliente (Nome Fantasia)'], ''),
                'client_document' => (string) Support::findFirstByKeys($row, ['cnpj_cpf', 'cpf_cnpj', 'CNPJ/CPF'], ''),
                'vendor_id' => (string) Support::getByPaths($row, ['cabecTitulo.nCodVendedor', 'nCodVendedor', 'codigo_vendedor_omie'], ''),
                'vendor_name' => (string) Support::findFirstByKeys($row, ['cNomeVendedor', 'nome_vendedor', 'Vendedor'], ''),
                'account_id' => (string) Support::getByPaths($row, ['cabecTitulo.nCodCC', 'nCodCC', 'codigo_conta_corrente'], ''),
                'issue_date' => Support::formatDate((string) Support::getByPaths($row, ['cabecTitulo.dDtEmissao', 'dDtEmissao'], '')) ?? '',
                'due_date' => Support::formatDate((string) Support::getByPaths($row, ['cabecTitulo.dDtVenc', 'dDtVenc'], '')) ?? '',
                'paid_date' => Support::formatDate((string) Support::getByPaths($row, ['cabecTitulo.dDtPagamento', 'dDtPagamento'], '')) ?? '',
                'amount_paid' => Support::toFloat(Support::getByPaths($row, ['resumo.nValPago', 'nValPago'], 0)),
                'amount_open' => Support::toFloat(Support::getByPaths($row, ['resumo.nValAberto', 'nValAberto'], 0)),
                'amount_net' => Support::toFloat(Support::getByPaths($row, ['resumo.nValLiquido', 'nValLiquido', 'resumo.nValPago'], 0)),
            ];
        }

        return $normalized;
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    public function normalizeBudget(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            $year = (int) ($row['__year'] ?? date('Y'));
            $month = (int) ($row['__month'] ?? date('n'));
            $normalized[] = [
                'year_month' => sprintf('%04d-%02d', $year, $month),
                'category_code' => (string) Support::getByPaths($row, ['cCodCateg', 'codigo_categoria'], ''),
                'category_name' => (string) Support::getByPaths($row, ['cDesCateg', 'descricao', 'categoria'], 'Categoria não informada'),
                'planned' => Support::toFloat(Support::getByPaths($row, ['nValorPrevisto', 'valor_previsto'], 0)),
                'realized' => Support::toFloat(Support::getByPaths($row, ['nValorRealilzado', 'nValorRealizado', 'valor_realizado'], 0)),
                'open' => 0.0,
                'overdue' => 0.0,
                'coverage_rate' => 0.0,
            ];
        }

        return $normalized;
    }

    /** @param list<array<string,mixed>> $movements @return list<array<string,mixed>> */
    public function titlesFromMovements(array $movements): array
    {
        return array_map(fn (array $row): array => [
            'title_id' => (string) ($row['title_id'] ?? ''),
            'integration_code' => (string) ($row['integration_code'] ?? ''),
            'document' => (string) ($row['document'] ?? ''),
            'document_label' => $this->labelResolver->resolveDocumentLabel($row),
            'document_type' => $this->labelResolver->inferDocumentType($row),
            'payment_method' => $this->labelResolver->inferPaymentMethod($row, (string) ($row['account_name'] ?? '')),
            'client_name' => (string) ($row['client_name'] ?? ''),
            'client_document' => '',
            'vendor_id' => '',
            'vendor_name' => 'Sem vendedor',
            'account_id' => (string) ($row['account_id'] ?? ''),
            'account_name' => (string) ($row['account_name'] ?? 'Conta não identificada'),
            'due_date' => (string) ($row['due_date'] ?? ''),
            'paid_date' => (string) ($row['paid_date'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'amount_open' => Support::toFloat($row['amount_open'] ?? 0),
            'amount_net' => Support::toFloat($row['amount_net'] ?? 0),
            'amount_paid' => Support::toFloat($row['amount_paid'] ?? 0),
        ], $movements);
    }
}
