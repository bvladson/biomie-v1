<?php

declare(strict_types=1);

namespace BIomie\FiscalSync;

use BIomie\Support;

final class FiscalSupport
{
    public const INVOICE_AUTHORIZED = 'autorizada';
    public const INVOICE_CANCELLED = 'cancelada';
    public const INVOICE_NOT_AUTHORIZED = 'nao_autorizada';

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
        return $this->classifyInvoiceFiscalStatus($raw, false, $orderFlags) === self::INVOICE_AUTHORIZED;
    }

    /**
     * Classifica o documento fiscal com evidencia do proprio ListarNF.
     *
     * O contrato e fail-closed: pedido faturado/autorizado nunca prova que a
     * SEFAZ autorizou a NF. Sinais negativos vencem qualquer chave/protocolo;
     * na ausencia de evidencia positiva o documento e preservado como
     * nao_autorizada e, portanto, nao entra no faturamento.
     *
     * @param array<string,mixed> $raw
     * @param array<string,array{authorized:bool,cancelled:bool,invoiced:bool,denied:bool,devolved:bool}> $orderFlags
     */
    public function classifyInvoiceFiscalStatus(
        array $raw,
        bool $listedAsCancelled,
        array $orderFlags = []
    ): string {
        $cancelDate = trim((string) Support::getByPaths($raw, ['ide.dCan', 'dCan'], ''));
        $inutilizationDate = trim((string) Support::getByPaths($raw, ['ide.dInut', 'dInut'], ''));
        $deniedFlag = strtoupper(trim((string) Support::getByPaths($raw, ['ide.cDeneg', 'cDeneg'], '')));
        $orderCancelled = $this->isTruthyFlag(Support::getByPaths($raw, ['pedido.cCancelado'], ''));
        $orderDevolved = $this->isTruthyFlag(Support::getByPaths($raw, ['pedido.cDevolvido'], ''));
        $orderPartiallyDevolved = $this->isTruthyFlag(Support::getByPaths($raw, ['pedido.cDevParcial'], ''));

        $statusCode = strtoupper($this->firstNonBlank($raw, [
            'protNFe.infProt.cStat',
            'infProt.cStat',
            'compl.cStat',
            'compl.cStatus',
            'ide.cStatus',
            'cStat',
            'cStatus',
        ]));
        $statusText = $this->normalizeSignalText($this->firstNonBlank($raw, [
            'protNFe.infProt.xMotivo',
            'infProt.xMotivo',
            'compl.xMotivo',
            'compl.cMsg',
            'xMotivo',
            'status',
        ]));

        if ($listedAsCancelled
            || $cancelDate !== ''
            || in_array($statusCode, ['101', '151', '155'], true)
            || in_array($statusCode, ['C', 'CANCELADA', 'CANCELADO'], true)
            || $this->containsAny($statusText, ['cancel'])
        ) {
            return self::INVOICE_CANCELLED;
        }

        $flags = $this->resolveOrderFlags(
            $orderFlags,
            Support::getByPaths($raw, ['pedido.cNumPedido', 'compl.nIdPedido'], ''),
            Support::getByPaths($raw, ['compl.nIdPedido'], '')
        );

        if ($inutilizationDate !== ''
            || $deniedFlag === 'S'
            || $orderCancelled
            || $orderDevolved
            || $orderPartiallyDevolved
            || in_array($statusCode, ['D', 'DENEGADA', 'DENEGADO', 'I', 'INUTILIZADA', 'INUTILIZADO', 'R', 'REJEITADA', 'REJEITADO'], true)
            || (bool) ($flags['cancelled'] ?? false)
            || (bool) ($flags['denied'] ?? false)
            || (bool) ($flags['devolved'] ?? false)
            || $this->containsAny($statusText, [
                'deneg', 'rejeit', 'inutil', 'nao autoriz', 'recus',
            ])
        ) {
            return self::INVOICE_NOT_AUTHORIZED;
        }

        if (ctype_digit($statusCode)) {
            return in_array($statusCode, ['100', '150'], true)
                ? self::INVOICE_AUTHORIZED
                : self::INVOICE_NOT_AUTHORIZED;
        }

        if (in_array($statusCode, ['AUTORIZADA', 'AUTORIZADO'], true)) {
            return self::INVOICE_AUTHORIZED;
        }

        if ($this->containsAny($statusText, ['autoriz'])) {
            return self::INVOICE_AUTHORIZED;
        }

        $accessKey = Support::digitsOnly((string) Support::getByPaths($raw, [
            'compl.cChaveNFe', 'cChaveNFe', 'chave_nfe',
        ], ''));
        $issueDate = trim((string) Support::getByPaths($raw, ['ide.dEmi', 'dEmi'], ''));
        $protocol = Support::digitsOnly($this->firstNonBlank($raw, [
            'protNFe.infProt.nProt', 'infProt.nProt', 'compl.nProt', 'nProt',
        ]));
        $validAccessKey = strlen($accessKey) === 44;

        if ($validAccessKey && $issueDate !== '' && ($deniedFlag === 'N' || $protocol !== '')) {
            return self::INVOICE_AUTHORIZED;
        }

        return self::INVOICE_NOT_AUTHORIZED;
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

    /**
     * @param array<string,mixed> $payload
     * @param list<string> $paths
     */
    private function firstNonBlank(array $payload, array $paths): string
    {
        foreach ($paths as $path) {
            $value = Support::getByPaths($payload, [$path], null);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    /** @param list<string> $needles */
    private function containsAny(string $haystack, array $needles): bool
    {
        if ($haystack === '') {
            return false;
        }

        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeSignalText(string $value): string
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        if (function_exists('iconv')) {
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
            if (is_string($ascii)) {
                $normalized = mb_strtolower($ascii, 'UTF-8');
            }
        }

        return $normalized;
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
