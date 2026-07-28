<?php

declare(strict_types=1);

require_once __DIR__ . '/TestSupport.php';
require_once __DIR__ . '/TestRunner.php';

use BIomie\FiscalSync\FiscalCanonicalMapper;
use BIomie\FiscalSync\FiscalPartyResolver;
use BIomie\FiscalSync\FiscalSupport;
use BIomie\OmieApiReconciliationService;
use BIomie\SalesAnalytics\SalesSupport as LegacySalesSupport;

$runner = new TestRunner();
$support = new FiscalSupport();
$accessKey = '1' . str_repeat('0', 43);

$base = static fn (): array => [
    'compl' => [
        'nIdNF' => 123,
        'nIdPedido' => 456,
        'cChaveNFe' => $accessKey,
    ],
    'ide' => [
        'nNF' => '100',
        'serie' => '1',
        'dEmi' => '18/07/2026',
        'dCan' => '',
        'dInut' => '',
        'cDeneg' => 'N',
    ],
    'pedido' => [
        'cNumPedido' => 'PV-456',
        'cCancelado' => 'N',
        'cDevolvido' => 'N',
        'cDevParcial' => 'N',
        'nIdVendedor' => 'v10',
    ],
    'nfDestInt' => [
        'nCodCli' => 789,
        'cRazao' => 'Cliente Teste',
        'cnpj_cpf' => '00000000000000',
    ],
    'total' => ['ICMSTot' => ['vProd' => 100, 'vDesc' => 0, 'vNF' => 100]],
    'det' => [],
];

$assertStatus = static function (
    string $id,
    string $expected,
    array $payload,
    bool $listedAsCancelled = false,
    array $orderFlags = [],
) use ($runner, $support): void {
    $runner->run($id, $id, static function () use (
        $support,
        $expected,
        $payload,
        $listedAsCancelled,
        $orderFlags,
    ): array {
        $actual = $support->classifyInvoiceFiscalStatus($payload, $listedAsCancelled, $orderFlags);
        return [
            'ok' => $actual === $expected,
            'expected' => $expected,
            'actual' => $actual,
        ];
    });
};

$assertStatus('bronze_observed_authorized', 'autorizada', $base());
$assertStatus('listed_cancelled_wins', 'cancelada', $base(), true);

$payload = $base();
$payload['ide']['dCan'] = '19/07/2026';
$assertStatus('cancel_date_wins', 'cancelada', $payload);

$payload = $base();
$payload['ide']['dInut'] = '18/07/2026';
$payload['pedido']['cCancelado'] = 'S';
$assertStatus('bronze_observed_inutilized', 'nao_autorizada', $payload);

$payload = $base();
$payload['ide']['cDeneg'] = 'S';
$assertStatus('denied_flag', 'nao_autorizada', $payload);

$payload = $base();
$payload['protNFe']['infProt']['cStat'] = '100';
$assertStatus('sefaz_cstat_authorized', 'autorizada', $payload);

$payload = $base();
$payload['protNFe']['infProt']['cStat'] = '204';
$assertStatus('sefaz_cstat_rejected', 'nao_autorizada', $payload);

$payload = $base();
$payload['protNFe']['infProt']['cStat'] = '101';
$assertStatus('sefaz_cstat_cancelled', 'cancelada', $payload);

$payload = $base();
unset($payload['ide']['cDeneg']);
$payload['protNFe']['infProt']['nProt'] = '123456789012345';
$assertStatus('protocol_and_key_authorized', 'autorizada', $payload);

$payload = $base();
unset($payload['ide']['cDeneg']);
$assertStatus('key_alone_is_not_proof', 'nao_autorizada', $payload);

$payload = $base();
$payload['status'] = 'Nota nao autorizada';
$assertStatus('negative_text_wins', 'nao_autorizada', $payload);

$payload = $base();
$flags = [
    'PV-456' => [
        'authorized' => true,
        'cancelled' => true,
        'invoiced' => true,
        'denied' => false,
        'devolved' => false,
    ],
];
$assertStatus('negative_order_flag_is_conservative', 'nao_autorizada', $payload, false, $flags);

$runner->run('legacy_sales_analytics_is_fail_closed', 'legacy_sales_analytics_is_fail_closed', static function () use ($base): array {
    $legacySupport = new LegacySalesSupport();
    $authorizedPayload = $base();
    $unknownPayload = $base();
    unset($unknownPayload['ide']['cDeneg']);

    return [
        'ok' => $legacySupport->isAuthorizedInvoiceFromOmie($authorizedPayload, [])
            && !$legacySupport->isAuthorizedInvoiceFromOmie($unknownPayload, []),
    ];
});

$runner->run('api_reconciliation_is_fail_closed', 'api_reconciliation_is_fail_closed', static function () use ($base): array {
    $reflection = new ReflectionClass(OmieApiReconciliationService::class);
    $service = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('isAuthorizedInvoiceFromOmie');
    $authorizedPayload = $base();
    $unknownPayload = $base();
    unset($unknownPayload['ide']['cDeneg']);

    $authorized = $method->invoke($service, $authorizedPayload, []);
    $unknown = $method->invoke($service, $unknownPayload, []);

    return ['ok' => $authorized === true && $unknown === false];
});

$runner->run('mapper_preserves_non_authorized_document', 'mapper_preserves_non_authorized_document', static function () use ($support, $base): array {
    $mapper = new FiscalCanonicalMapper($support, new FiscalPartyResolver());
    $payload = $base();
    $payload['ide']['dInut'] = '18/07/2026';
    $payload['pedido']['cCancelado'] = 'S';
    $row = $mapper->normalizeInvoiceToCanonical($payload, false, 'cron', ['v10' => 'Vendedor Teste']);

    return [
        'ok' => is_array($row)
            && ($row['status_fiscal'] ?? null) === 'nao_autorizada'
            && ($row['data_competencia'] ?? null) === '2026-07-18'
            && (float) ($row['valor_total'] ?? 0) === 100.0,
        'actual' => is_array($row) ? [
            'status_fiscal' => $row['status_fiscal'] ?? null,
            'data_competencia' => $row['data_competencia'] ?? null,
            'valor_total' => $row['valor_total'] ?? null,
        ] : null,
    ];
});

$runner->run('cancelled_competencia_remains_issue_date', 'cancelled_competencia_remains_issue_date', static function () use ($support, $base): array {
    $mapper = new FiscalCanonicalMapper($support, new FiscalPartyResolver());
    $payload = $base();
    $payload['ide']['dCan'] = '31/08/2026';
    $row = $mapper->normalizeInvoiceToCanonical($payload, true, 'cron', ['v10' => 'Vendedor Teste']);

    return [
        'ok' => is_array($row)
            && ($row['status_fiscal'] ?? null) === 'cancelada'
            && ($row['data_competencia'] ?? null) === '2026-07-18'
            && ($row['data_cancelamento'] ?? null) === '2026-08-31',
        'actual' => is_array($row) ? [
            'status_fiscal' => $row['status_fiscal'] ?? null,
            'data_competencia' => $row['data_competencia'] ?? null,
            'data_cancelamento' => $row['data_cancelamento'] ?? null,
        ] : null,
    ];
});

$runner->run('n_and_c_passes_keep_same_invoice_identity', 'n_and_c_passes_keep_same_invoice_identity', static function () use ($support, $base): array {
    $mapper = new FiscalCanonicalMapper($support, new FiscalPartyResolver());
    $payload = $base();
    $authorized = $mapper->normalizeInvoiceToCanonical($payload, false, 'cron', ['v10' => 'Vendedor Teste']);
    $cancelled = $mapper->normalizeInvoiceToCanonical($payload, true, 'cron', ['v10' => 'Vendedor Teste']);

    return [
        'ok' => is_array($authorized)
            && is_array($cancelled)
            && ($authorized['nota_key'] ?? null) === ($cancelled['nota_key'] ?? null)
            && ($authorized['status_fiscal'] ?? null) === 'autorizada'
            && ($cancelled['status_fiscal'] ?? null) === 'cancelada'
            && ($authorized['data_competencia'] ?? null) === ($cancelled['data_competencia'] ?? null),
        'actual' => [
            'authorized_key' => is_array($authorized) ? ($authorized['nota_key'] ?? null) : null,
            'cancelled_key' => is_array($cancelled) ? ($cancelled['nota_key'] ?? null) : null,
            'authorized_status' => is_array($authorized) ? ($authorized['status_fiscal'] ?? null) : null,
            'cancelled_status' => is_array($cancelled) ? ($cancelled['status_fiscal'] ?? null) : null,
        ],
    ];
});

$runner->run('coordinator_never_replaces_fiscal_window', 'coordinator_never_replaces_fiscal_window', static function (): array {
    $source = file_get_contents(dirname(__DIR__) . '/app/Sync/FiscalSyncCoordinator.php');
    $ok = is_string($source)
        && str_contains($source, '$this->canonical->upsertInvoicesBatch($normalized)')
        && !str_contains($source, '$this->canonical->replaceInvoicesWindow(');

    return ['ok' => $ok];
});

$runner->run('invoice_batch_upsert_is_non_destructive_and_idempotent', 'invoice_batch_upsert_is_non_destructive_and_idempotent', static function (): array {
    $source = file_get_contents(dirname(__DIR__) . '/app/Canonical/CanonicalWriteRepository.php');
    if (!is_string($source)) {
        return ['ok' => false, 'reason' => 'repository_source_unavailable'];
    }

    $batchStart = strpos($source, 'public function upsertInvoicesBatch(');
    $batchEnd = $batchStart === false ? false : strpos($source, 'private function tryUpdateFiscalBasis(', $batchStart);
    $upsertStart = strpos($source, 'public function upsertInvoice(');
    $upsertEnd = $upsertStart === false ? false : strpos($source, 'private function findExistingInvoiceIdentity(', $upsertStart);

    if ($batchStart === false || $batchEnd === false || $upsertStart === false || $upsertEnd === false) {
        return ['ok' => false, 'reason' => 'repository_method_boundary_not_found'];
    }

    $batchMethod = substr($source, $batchStart, $batchEnd - $batchStart);
    $upsertMethod = substr($source, $upsertStart, $upsertEnd - $upsertStart);
    $ok = str_contains($batchMethod, '$this->upsertInvoice($invoice)')
        && !str_contains($batchMethod, 'DELETE FROM')
        && !str_contains($batchMethod, 'replaceInvoicesWindow')
        && str_contains($upsertMethod, '$this->findExistingInvoiceIdentity(')
        && str_contains($upsertMethod, 'ON DUPLICATE KEY UPDATE')
        && str_contains($upsertMethod, "return 'unchanged';")
        && !str_contains($upsertMethod, "'DELETE FROM ' . self::TABLE_INVOICES .");

    return ['ok' => $ok];
});

$summary = $runner->summary();
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit(($summary['ok'] ?? false) ? 0 : 1);
