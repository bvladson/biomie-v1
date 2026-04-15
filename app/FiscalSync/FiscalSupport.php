<?php

declare(strict_types=1);

namespace BIomie\FiscalSync;

use BIomie\Support;

final class FiscalSupport
{
    /**
     * @param list<array<string,mixed>> $orders
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
    public function isAuthorizedInvoiceFromOmie(array $raw, array $orderFlags): bool
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
    public function resolveOrderFlags(array $orderFlags, mixed ...$values): ?array
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
    public function buildOrderLookupKeys(mixed ...$values): array
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

    public function isTruthyFlag(mixed $value): bool
    {
        return strtoupper(trim((string) $value)) === 'S';
    }

    public function classifyStatus(string $etapa, string $statusPedido): string
    {
        if ($etapa !== '' && ctype_digit($etapa)) {
            $code = (int) $etapa;
            if ($code >= 50 && $code <= 69) {
                return 'faturado';
            }
            if ($code >= 20 && $code <= 49) {
                return 'autorizado';
            }
            if ($code >= 70) {
                return 'cancelado';
            }
            if ($code <= 19) {
                return 'outro';
            }
        }

        $normalized = mb_strtolower(trim($etapa !== '' ? $etapa : $statusPedido));
        if ($normalized === '') {
            return 'outro';
        }

        if (str_contains($normalized, 'faturad') || str_contains($normalized, 'entregue') || str_contains($normalized, 'conclu')) {
            return 'faturado';
        }
        if (str_contains($normalized, 'autoriz') || str_contains($normalized, 'aprovad') || str_contains($normalized, 'separ') || str_contains($normalized, 'pedido')) {
            return 'autorizado';
        }
        if (str_contains($normalized, 'cancel') || str_contains($normalized, 'devolv') || str_contains($normalized, 'recusad') || str_contains($normalized, 'denegad') || str_contains($normalized, 'rejeit')) {
            return 'cancelado';
        }

        return 'outro';
    }

    /**
     * @return array{inserted:int,updated:int,unchanged:int,skipped:int}
     */
    public function emptyStats(): array
    {
        return [
            'inserted' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped' => 0,
        ];
    }

    /**
     * @param array{inserted:int,updated:int,unchanged:int,skipped:int} $current
     * @param array{inserted:int,updated:int,unchanged:int,skipped:int} $delta
     * @return array{inserted:int,updated:int,unchanged:int,skipped:int}
     */
    public function mergeStats(array $current, array $delta): array
    {
        return [
            'inserted' => (int) ($current['inserted'] ?? 0) + (int) ($delta['inserted'] ?? 0),
            'updated' => (int) ($current['updated'] ?? 0) + (int) ($delta['updated'] ?? 0),
            'unchanged' => (int) ($current['unchanged'] ?? 0) + (int) ($delta['unchanged'] ?? 0),
            'skipped' => (int) ($current['skipped'] ?? 0) + (int) ($delta['skipped'] ?? 0),
        ];
    }

    /**
     * @param array{inserted:int,updated:int,unchanged:int,skipped:int} $stats
     */
    public function sumStats(array $stats): int
    {
        return (int) ($stats['inserted'] ?? 0)
            + (int) ($stats['updated'] ?? 0)
            + (int) ($stats['unchanged'] ?? 0)
            + (int) ($stats['skipped'] ?? 0);
    }
}
