<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [];

$assert = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$common = (string) file_get_contents($root . '/api/quote_agenda_common.php');
$calendar = (string) file_get_contents($root . '/api/quote_agenda_calendar.php');
$day = (string) file_get_contents($root . '/api/quote_agenda_day.php');
$summary = (string) file_get_contents($root . '/api/quote_agenda_summary.php');
$charge = (string) file_get_contents($root . '/api/quote_agenda_charge.php');
$refresh = (string) file_get_contents($root . '/api/quote_agenda_refresh_request.php');
$page = (string) file_get_contents($root . '/quote_agenda.php');
$view = (string) file_get_contents($root . '/views/quote_agenda.php');
$client = (string) file_get_contents($root . '/assets/js/modules/quote-agenda/index.js');

$readCapabilityContract = static fn (string $source): bool =>
    str_contains($source, 'requireAnyCapability')
    && str_contains($source, 'sales.quotes.followup.view')
    && str_contains($source, 'sales.quotes.followup.self');

$assert(
    'escopo self usa vínculo obrigatório',
    str_contains($common, 'new SellerBindingResolver')
        && str_contains($common, "!Auth::hasCapability('sales.quotes.followup.view')")
);
$assert(
    'vendedor sem vínculo não degrada para todos',
    str_contains($common, '->sellerIdsFor($userId, $companyId)')
);
$assert(
    'agenda aceita seller_ids e vendor_ids múltiplos no choke-point',
    str_contains($common, "['seller_ids', 'vendor_ids', 'seller_id', 'vendor_id']")
        && str_contains($common, 'VendorScopeFilter::normalizeRequestedIds(')
);
$assert(
    'agenda valida integralmente a seleção contra o escopo',
    str_contains($common, 'array_diff($requested, $scope)')
        && str_contains($common, "'error' => 'seller_not_authorized'")
        && str_contains($common, '], 403)')
);
$assert(
    'agenda preserva seller_id escalar e aplica lista múltipla exata',
    str_contains($common, "\$requestSource === 'seller_id'")
        && str_contains($common, "\$f['seller_id'] = \$requested[0]")
        && str_contains($common, "\$f['seller_ids'] = \$requested")
);
$assert(
    'agenda rejeita filtro malformado ou acima do limite',
    str_contains($common, "'error' => 'invalid_seller_filter'")
        && str_contains($common, '], 400)')
);
$assert('calendário aceita view ou self', $readCapabilityContract($calendar));
$assert('dia aceita view ou self', $readCapabilityContract($day));
$assert('resumo aceita view ou self', $readCapabilityContract($summary));
$assert('página aceita view ou self', $readCapabilityContract($page));
$assert(
    'freshness global é removido em qualquer escopo restrito',
    str_contains($common, "\$freshness['snapshot_hash']")
        && str_contains($common, "\$freshness['live_total']")
        && str_contains($common, "\$freshness['eligible_total']")
        && str_contains($common, 'quoteAgendaSellerScope() !== null')
);
$assert(
    'cobrança continua exclusiva de manage',
    str_contains($charge, "requireCapability('sales.quotes.followup.manage'")
        && !str_contains($charge, 'requireAnyCapability')
);
$assert(
    'refresh remoto continua exclusivo de gestor',
    str_contains($refresh, "requireCapability('sales.quotes.followup.view'")
        && !str_contains($refresh, 'sales.quotes.followup.self')
);
$assert(
    'histórico gerencial é ocultado para self',
    str_contains($view, 'data-can-history=')
        && str_contains($client, 'state.canHistory')
        && str_contains($client, 'state.canHistory ?')
);

$failed = array_values(array_filter($checks, static fn (array $check): bool => !$check['ok']));
$result = [
    'ok' => $failed === [],
    'total' => count($checks),
    'passed' => count($checks) - count($failed),
    'failed' => count($failed),
    'checks' => $checks,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
