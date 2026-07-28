<?php

declare(strict_types=1);

namespace BIomie\SalesAnalytics;

use BIomie\FiscalSync\FiscalSupport as FiscalStatusSupport;
use BIomie\Support;

final class SalesSupport
{
    private ?FiscalStatusSupport $fiscalStatusSupport = null;

    /**
     * @param list<array<string,mixed>> $vendors
     * @return list<array{codigo:int,nome:string,email:string,comissao:float}>
     */
    public function normalizeVendors(array $vendors): array
    {
        return array_map(static fn (array $vendor): array => [
            'codigo' => (int) ($vendor['codigo'] ?? 0),
            'nome' => (string) ($vendor['nome'] ?? 'Sem nome'),
            'email' => (string) ($vendor['email'] ?? ''),
            'comissao' => Support::toFloat($vendor['comissao'] ?? 0),
        ], $vendors);
    }

    /**
     * @return list<string>
     */
    public function buildVendorLookupKeys(mixed $vendorId): array
    {
        $raw = trim((string) $vendorId);
        if ($raw === '') {
            return [];
        }

        $keys = [$raw];
        $digits = Support::digitsOnly($raw);
        if ($digits !== '') {
            $keys[] = $digits;
            $keys[] = ltrim($digits, '0') ?: '0';
        }

        return array_values(array_unique(array_filter($keys, static fn (string $value): bool => $value !== '')));
    }

    /**
     * @return array<string,array{authorized:bool,cancelled:bool,invoiced:bool,denied:bool,devolved:bool}>
     */
    public function buildOrderFlagsMap(array $orders): array
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

            foreach ($this->buildOrderLookupKeysFromValues(
                Support::getByPaths($order, ['cabecalho.numero_pedido'], ''),
                Support::getByPaths($order, ['cabecalho.codigo_pedido', 'codigo_pedido'], '')
            ) as $lookupKey) {
                $map[$lookupKey] = $flags;
            }
        }

        return $map;
    }

    /**
     * @param array<string,mixed> $invoice
     * @param array<string,array{authorized:bool,cancelled:bool,invoiced:bool,denied:bool,devolved:bool}> $orderFlags
     */
    public function isAuthorizedInvoiceFromOmie(array $invoice, array $orderFlags): bool
    {
        return ($this->fiscalStatusSupport ??= new FiscalStatusSupport())
            ->isAuthorizedInvoiceFromOmie($invoice, $orderFlags);
    }

    /**
     * @param array<string,array{authorized:bool,cancelled:bool,invoiced:bool,denied:bool,devolved:bool}> $orderFlags
     * @return array{authorized:bool,cancelled:bool,invoiced:bool,denied:bool,devolved:bool}|null
     */
    public function resolveOrderFlags(array $orderFlags, mixed ...$values): ?array
    {
        foreach ($this->buildOrderLookupKeysFromValues(...$values) as $lookupKey) {
            if (isset($orderFlags[$lookupKey])) {
                return $orderFlags[$lookupKey];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function buildOrderLookupKeysFromValues(mixed ...$values): array
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

    public function classifyOrderStatusGroup(array $order, string $stage, string $statusPedido): string
    {
        $cancelled = $this->isTruthyFlag(Support::getByPaths($order, ['infoCadastro.cancelado'], ''));
        $denied = $this->isTruthyFlag(Support::getByPaths($order, ['infoCadastro.denegado'], ''));
        $devolved = $this->isTruthyFlag(Support::getByPaths($order, ['infoCadastro.devolvido'], ''));
        $authorized = $this->isTruthyFlag(Support::getByPaths($order, ['infoCadastro.autorizado'], ''));
        $invoiced = $this->isTruthyFlag(Support::getByPaths($order, ['infoCadastro.faturado'], ''));

        if ($cancelled || $denied || $devolved) {
            return 'cancelado';
        }

        if ($authorized && $invoiced) {
            return 'faturado';
        }

        if ($authorized) {
            return 'autorizado';
        }

        $statusGroup = $this->classifyStatus($stage);
        if ($statusGroup === 'outro' && $statusPedido !== '') {
            $statusGroup = $this->classifyStatus($statusPedido);
        }

        if ($invoiced && $statusGroup === 'faturado') {
            return 'outro';
        }

        return $statusGroup;
    }

    public function isTruthyFlag(mixed $value): bool
    {
        return strtoupper(trim((string) $value)) === 'S';
    }

    public function extractClientCode(array $order): int
    {
        $code = Support::getByPaths($order, [
            'cabecalho.codigo_cliente',
            'cliente.codigo_cliente_omie',
            'cliente.codigo_cliente',
            'codigo_cliente',
            'codigo_cliente_omie',
        ], null);

        if ($code === null || $code === '') {
            $code = Support::findFirstByKeys($order, ['codigo_cliente', 'codigo_cliente_omie'], 0);
        }

        return (int) $code;
    }

    /**
     * Classifica o status bruto do Omie em um grupo canônico ENUM.
     *
     * Retorna apenas valores compatíveis com o ENUM do banco:
     *   'faturado' | 'autorizado' | 'cancelado' | 'outro'
     *
     * Para classificação semântica estendida use CommercialPipelineClassifier::classifyEtapa().
     *
     * COM.R1.2: etapa 60 (Entrega Finalizada) retorna 'outro', NÃO 'faturado'.
     * A etapa 60 é pós-venda; o faturamento já ocorreu em etapa 50.
     */
    public function classifyStatus(string $raw): string
    {
        $n = mb_strtolower(trim($raw));
        if ($n === '') {
            return 'faturado';
        }

        if (ctype_digit($n)) {
            $code = (int) $n;
            // etapa 60 = Entrega Finalizada — pós-venda, NÃO novo faturamento
            if ($code === 60) {
                return 'outro';
            }
            if ($code >= 50 && $code <= 69) {
                return 'faturado';
            }
            if ($code >= 20 && $code <= 49) {
                return 'autorizado';
            }
            if ($code >= 70) {
                return 'cancelado';
            }
            // etapa 10 = Orçamento
            if ($code <= 19) {
                return 'outro';
            }

            return 'outro';
        }

        // entregue/concluido sem código numérico → 'outro' (pós-venda)
        if (str_contains($n, 'entregue') || str_contains($n, 'conclu') || str_contains($n, 'entrega finalizada')) {
            return 'outro';
        }
        if (str_contains($n, 'faturad')) {
            return 'faturado';
        }
        if (str_contains($n, 'autoriz') || str_contains($n, 'aprovad') || str_contains($n, 'separ') || str_contains($n, 'aberto') || str_contains($n, 'pedido')) {
            return 'autorizado';
        }
        if (str_contains($n, 'cancel') || str_contains($n, 'devolv') || str_contains($n, 'recusad') || str_contains($n, 'denegad') || str_contains($n, 'rejeit')) {
            return 'cancelado';
        }

        return 'outro';
    }

    /**
     * Classificação semântica estendida — retorna o scope completo do CommercialPipelineClassifier.
     * NÃO usa valores ENUM; use para análise, diagnóstico e dashboards.
     */
    public function classifyStatusExtended(string $etapaOmie): string
    {
        $entry = (new \BIomie\Support\CommercialPipelineClassifier())->classifyEtapa($etapaOmie);
        return $entry['scope'];
    }
}
