<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\FinanceReceivableTraceBuilder;

require __DIR__ . '/bootstrap.php';

Auth::requireCapability('finance.view', true);

// ── Whitelist de parâmetros ────────────────────────────────────────────────────
$ALLOWED_MODES    = ['summary', 'nf_open', 'payment_methods', 'titles', 'drawer'];
$ALLOWED_STATUSES = ['open', 'overdue', 'due', 'received', 'all'];
$ALLOWED_METHODS  = ['boleto', 'pix', 'cartao_credito', 'cartao_debito', 'dinheiro',
                      'transferencia', 'cheque', 'deposito', 'nao_informado', 'outro', 'all'];
$ALLOWED_GROUPS   = ['nf', 'payment_method', 'vendor', 'customer', 'title'];
$ALLOWED_SORTS    = ['nota_key', 'numero_nf', 'cliente_nome', 'vendedor_nome',
                      'valor_aberto', 'valor_vencido', 'dias_atraso', 'data_vencimento',
                      'normalized_payment_method', 'method_key', 'titles_count'];

try {
    $range = Http::resolveDateRange();
    Http::validateDateRange($range['start'], $range['end']);

    $mode    = in_array(trim((string) ($_GET['mode'] ?? 'summary')), $ALLOWED_MODES, true)
               ? trim((string) $_GET['mode'])  : 'summary';
    $status  = in_array(trim((string) ($_GET['status'] ?? 'all')), $ALLOWED_STATUSES, true)
               ? trim((string) $_GET['status']) : 'all';
    $method  = in_array(trim((string) ($_GET['payment_method'] ?? 'all')), $ALLOWED_METHODS, true)
               ? trim((string) $_GET['payment_method']) : 'all';
    $groupBy = in_array(trim((string) ($_GET['group_by'] ?? 'nf')), $ALLOWED_GROUPS, true)
               ? trim((string) $_GET['group_by']) : 'nf';
    $sort    = in_array(trim((string) ($_GET['sort'] ?? '')), $ALLOWED_SORTS, true)
               ? trim((string) $_GET['sort']) : '';
    $direction = strtolower(trim((string) ($_GET['direction'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';

    $onlyWithoutNf     = (string) ($_GET['only_without_nf'] ?? '0') === '1';
    $onlyWithoutVendor = (string) ($_GET['only_without_vendor'] ?? '0') === '1';

    $limit = min(100, max(1, (int) ($_GET['limit'] ?? 50)));
    $page  = max(1, (int) ($_GET['page'] ?? 1));

    $nfFilter     = trim((string) ($_GET['nf'] ?? ''));
    $titleFilter  = trim((string) ($_GET['title'] ?? ''));
    $vendorFilter = trim((string) ($_GET['vendor'] ?? ''));
    $customerFilter = trim((string) ($_GET['customer'] ?? ''));

    $config = new \BIomie\Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $conn)) {
        Http::json(['ok' => false, 'message' => 'Banco de dados não configurado.'], 503);
    }

    $pdo = Database::connect($config, $conn);

    if (!Http::tableExists($pdo, 'biomie_titulos_financeiros_canonicos')) {
        Http::json([
            'ok'      => true,
            'period'  => ['start' => $range['start'], 'end' => $range['end']],
            'summary' => [],
            'open_invoice_summary'   => [],
            'payment_method_ranking' => [],
            'vendor_ranking'         => [],
            'boleto_open_titles'     => [],
            'nf_rows'   => [],
            'title_rows'=> [],
            'diagnostics' => ['source' => 'table_missing', 'request_id' => RequestContext::id()],
        ]);
    }

    $filters = [
        'status'           => $status,
        'payment_method'   => $method,
        'only_without_nf'  => $onlyWithoutNf ? '1' : '0',
        'only_without_vendor' => $onlyWithoutVendor ? '1' : '0',
    ];

    $builder = new FinanceReceivableTraceBuilder($pdo);
    $payload = $builder->build($range['start'], $range['end'], $filters);

    // ── Modo drawer: retornar contexto ampliado por NF ou método ──────────────
    if ($mode === 'drawer') {
        if ($groupBy === 'nf' && $nfFilter !== '') {
            $nfRows = array_values(array_filter(
                $payload['nf_rows'],
                static fn (array $r): bool => ($r['nota_key'] ?? '') === $nfFilter
            ));
            $matchingNf = null;
            foreach ($payload['open_invoice_summary'] as $inv) {
                if (($inv['nota_key'] ?? '') === $nfFilter) { $matchingNf = $inv; break; }
            }
            Http::json([
                'ok'          => true,
                'period'      => $payload['period'],
                'drawer_type' => 'nf',
                'nf_key'      => $nfFilter,
                'nf_summary'  => $matchingNf,
                'title_rows'  => array_slice($nfRows, ($page - 1) * $limit, $limit),
                'total'       => count($nfRows),
                'diagnostics' => array_merge($payload['diagnostics'], ['request_id' => RequestContext::id()]),
            ]);
        }

        if ($groupBy === 'payment_method') {
            $methodRows = $method !== 'all'
                ? array_values(array_filter($payload['nf_rows'], static fn (array $r): bool =>
                    ($r['normalized_payment_method'] ?? '') === $method && $r['is_open']))
                : array_values(array_filter($payload['nf_rows'], static fn (array $r): bool => (bool) $r['is_open']));

            $matchingMethod = null;
            foreach ($payload['payment_method_ranking'] as $pm) {
                if (($pm['method_key'] ?? '') === $method) { $matchingMethod = $pm; break; }
            }
            Http::json([
                'ok'             => true,
                'period'         => $payload['period'],
                'drawer_type'    => 'payment_method',
                'method_key'     => $method,
                'method_summary' => $matchingMethod,
                'title_rows'     => array_slice($methodRows, ($page - 1) * $limit, $limit),
                'total'          => count($methodRows),
                'diagnostics'    => array_merge($payload['diagnostics'], ['request_id' => RequestContext::id()]),
            ]);
        }

        if ($groupBy === 'vendor' && $vendorFilter !== '') {
            $vendorRows = array_values(array_filter($payload['nf_rows'], static fn (array $r): bool =>
                ($r['vendor_key'] ?? '') === $vendorFilter && $r['is_open']));
            Http::json([
                'ok'          => true,
                'period'      => $payload['period'],
                'drawer_type' => 'vendor',
                'vendor_key'  => $vendorFilter,
                'title_rows'  => array_slice($vendorRows, ($page - 1) * $limit, $limit),
                'total'       => count($vendorRows),
                'diagnostics' => array_merge($payload['diagnostics'], ['request_id' => RequestContext::id()]),
            ]);
        }
    }

    // ── Modo nf_open: resumo de NFs com boletos abertos ───────────────────────
    if ($mode === 'nf_open') {
        $invSummary = $payload['open_invoice_summary'];
        if ($nfFilter !== '') {
            $invSummary = array_values(array_filter($invSummary, static fn (array $r): bool =>
                str_contains((string) ($r['nota_key'] ?? ''), $nfFilter) ||
                str_contains((string) ($r['numero_nf'] ?? ''), $nfFilter)));
        }
        if ($vendorFilter !== '') {
            $invSummary = array_values(array_filter($invSummary, static fn (array $r): bool =>
                stripos((string) ($r['vendedor_nome'] ?? ''), $vendorFilter) !== false));
        }
        if ($customerFilter !== '') {
            $invSummary = array_values(array_filter($invSummary, static fn (array $r): bool =>
                stripos((string) ($r['cliente_nome'] ?? ''), $customerFilter) !== false));
        }
        Http::json([
            'ok'                   => true,
            'period'               => $payload['period'],
            'summary'              => $payload['summary'],
            'open_invoice_summary' => array_slice($invSummary, ($page - 1) * $limit, $limit),
            'total'                => count($invSummary),
            'diagnostics'          => array_merge($payload['diagnostics'], ['request_id' => RequestContext::id()]),
        ]);
    }

    // ── Modo payment_methods: ranking de métodos ───────────────────────────────
    if ($mode === 'payment_methods') {
        Http::json([
            'ok'                     => true,
            'period'                 => $payload['period'],
            'summary'                => $payload['summary'],
            'payment_method_ranking' => $payload['payment_method_ranking'],
            'diagnostics'            => array_merge($payload['diagnostics'], ['request_id' => RequestContext::id()]),
        ]);
    }

    // ── Modo titles: lista de boletos abertos ──────────────────────────────────
    if ($mode === 'titles') {
        $titles = $payload['boleto_open_titles'];
        if ($method !== 'all') {
            $titles = array_values(array_filter($titles, static fn (array $r): bool =>
                ($r['normalized_payment_method'] ?? '') === $method));
        }
        if ($nfFilter !== '') {
            $titles = array_values(array_filter($titles, static fn (array $r): bool =>
                str_contains((string) ($r['nota_key'] ?? ''), $nfFilter)));
        }
        Http::json([
            'ok'                 => true,
            'period'             => $payload['period'],
            'summary'            => $payload['summary'],
            'boleto_open_titles' => array_slice($titles, ($page - 1) * $limit, $limit),
            'total'              => count($titles),
            'diagnostics'        => array_merge($payload['diagnostics'], ['request_id' => RequestContext::id()]),
        ]);
    }

    // ── Modo summary (default) ─────────────────────────────────────────────────
    $payload['diagnostics']['request_id'] = RequestContext::id();
    Http::json($payload);
} catch (\InvalidArgumentException $exception) {
    Http::json(['ok' => false, 'message' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    Http::json(['ok' => false, 'message' => 'Erro interno na rastreabilidade financeira.'], 500);
}
