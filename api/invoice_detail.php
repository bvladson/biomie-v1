<?php

/**
 * api/invoice_detail.php — Detalhe completo de Nota Fiscal.
 *
 * Retorna NF + itens + pedido origem + cliente + vendedor + títulos financeiros + timeline.
 *
 * Parâmetros (ao menos um obrigatório):
 *   numero_nf  — número da NF
 *   nota_key   — chave interna canonical (nota_key)
 *   chave_nfe  — chave de acesso SEFAZ (44 dígitos)
 *
 * Permissão: fiscal.view ou sales.view
 */

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support;
use BIomie\Support\FiscalValueBasis;

require __DIR__ . '/bootstrap.php';

Auth::requireAnyCapability(['fiscal.view', 'sales.view'], true);

try {
    $numeroNf = trim((string) Http::query('numero_nf'));
    $numeroNf = preg_replace('/^\s*(nota\s*fiscal|nf-e|nfe|nf)\s*[:#-]?\s*/i', '', $numeroNf) ?? $numeroNf;
    $numeroNf = preg_replace('/\s+/', '', $numeroNf) ?? $numeroNf;
    $notaKey  = trim((string) Http::query('nota_key'));
    $chaveNfe = trim((string) Http::query('chave_nfe'));

    if ($numeroNf === '' && $notaKey === '' && $chaveNfe === '') {
        Http::json(['ok' => false, 'message' => 'Informe numero_nf, nota_key ou chave_nfe.', 'request_id' => RequestContext::id()], 400);
    }

    $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $conn)) {
        Http::json(['ok' => false, 'message' => 'Banco de dados não configurado.', 'request_id' => RequestContext::id()], 503);
    }
    $pdo = Database::connect($config, $conn);

    // ── NF row ────────────────────────────────────────────────────────────────
    $invoice = null;
    $tenantFilter = \BIomie\Support\TenantContext::sqlFilter();

    if ($notaKey !== '') {
        $stmt = $pdo->prepare("SELECT * FROM biomie_notas_fiscais_canonicas WHERE nota_key = :k AND {$tenantFilter} LIMIT 1");
        $stmt->execute(['k' => $notaKey]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($invoice === null && $chaveNfe !== '') {
        $stmt = $pdo->prepare("SELECT * FROM biomie_notas_fiscais_canonicas WHERE chave_nfe = :k AND {$tenantFilter} LIMIT 1");
        $stmt->execute(['k' => $chaveNfe]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($invoice === null && $numeroNf !== '') {
        // Support "numero/serie" combined format (e.g. "00001178/002")
        $serie = null;
        $numPart = $numeroNf;
        if (str_contains($numeroNf, '/')) {
            [$numPart, $serie] = explode('/', $numeroNf, 2);
            $numPart = trim($numPart);
            $serie   = trim($serie);
        }

        if ($serie !== null && $serie !== '') {
            $stmt = $pdo->prepare(
                "SELECT * FROM biomie_notas_fiscais_canonicas
                 WHERE numero_nf = :n AND serie_nf = :s AND {$tenantFilter}
                 ORDER BY data_emissao DESC LIMIT 1"
            );
            $stmt->execute(['n' => $numPart, 's' => $serie]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($invoice === null) {
            $stmt = $pdo->prepare(
                "SELECT * FROM biomie_notas_fiscais_canonicas WHERE numero_nf = :n AND {$tenantFilter}
                 ORDER BY data_emissao DESC LIMIT 1"
            );
            $stmt->execute(['n' => $numPart]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }

    if ($invoice === null) {
        Http::json(['ok' => false, 'message' => 'Nota fiscal não encontrada.', 'request_id' => RequestContext::id()], 404);
    }

    $foundKey     = trim((string) ($invoice['nota_key'] ?? ''));
    $pedidoIdOmie = (int) ($invoice['pedido_id_omie'] ?? 0);
    $codigoPedido = trim((string) ($invoice['codigo_pedido_origem'] ?? ''));

    // ── NF items ──────────────────────────────────────────────────────────────
    $items = [];
    if ($foundKey !== '') {
        $stmtItems = $pdo->prepare(
            "SELECT * FROM biomie_notas_fiscais_itens_canonicos
             WHERE nota_key = :k AND {$tenantFilter} ORDER BY sequencia_item"
        );
        $stmtItems->execute(['k' => $foundKey]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Linked order ──────────────────────────────────────────────────────────
    $order = null;
    if ($pedidoIdOmie > 0) {
        $stmtOrder = $pdo->prepare("SELECT * FROM biomie_pedidos_canonicos WHERE pedido_id_omie = :pid AND {$tenantFilter} LIMIT 1");
        $stmtOrder->execute(['pid' => $pedidoIdOmie]);
        $order = $stmtOrder->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if ($order === null && $codigoPedido !== '') {
        $stmtOrder = $pdo->prepare("SELECT * FROM biomie_pedidos_canonicos WHERE codigo_pedido = :c AND {$tenantFilter} LIMIT 1");
        $stmtOrder->execute(['c' => $codigoPedido]);
        $order = $stmtOrder->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ── Financial titles ──────────────────────────────────────────────────────
    $financeTitles = [];
    try {
        if ($codigoPedido !== '' || $pedidoIdOmie > 0) {
            $stmtTitles = $pdo->prepare(
                "SELECT * FROM biomie_titulos_financeiros_canonicos
                 WHERE ({$tenantFilter})
                   AND (codigo_integracao = :codigo OR codigo_integracao = :pid_str)
                 ORDER BY data_vencimento ASC LIMIT 20"
            );
            $stmtTitles->execute([
                'codigo'   => $codigoPedido,
                'pid_str'  => (string) $pedidoIdOmie,
            ]);
            $financeTitles = $stmtTitles->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable) {
        $financeTitles = [];
    }

    // ── Totals — Fase O: full fiscal breakdown ───────────────────────────────
    $fiscalComp = FiscalValueBasis::fiscalComponentsFromRow($invoice);
    $totals = [
        'subtotal'         => Support::money($fiscalComp['valor_mercadorias']),
        'discount'         => Support::money($fiscalComp['valor_descontos']),
        'ipi'              => Support::money($fiscalComp['valor_ipi']),
        'icms_st'          => 0.0,
        'freight'          => Support::money($fiscalComp['valor_frete']),
        'other_expenses'   => Support::money($fiscalComp['valor_outras_despesas']),
        'insurance'        => Support::money($fiscalComp['valor_seguro']),
        'total'            => Support::money($fiscalComp['valor_total']),
        'base_comercial'   => Support::money($fiscalComp['valor_base_comercial']),
        'has_deductions'   => FiscalValueBasis::hasNonCommissionableAddons($invoice),
    ];

    // ── Seller / customer ─────────────────────────────────────────────────────
    $seller = [
        'id'   => (string) ($invoice['codigo_vendedor_omie'] ?? ''),
        'name' => (string) ($invoice['nome_vendedor'] ?? ''),
    ];
    $customer = [
        'id'       => (string) ($invoice['codigo_cliente_omie'] ?? ''),
        'name'     => (string) ($invoice['nome_cliente'] ?? ''),
        'document' => (string) ($invoice['documento_cliente'] ?? ''),
    ];

    // ── Timeline ─────────────────────────────────────────────────────────────
    $timeline = invoiceDetailBuildTimeline($invoice, $order);

    // ── DANFE/XML availability ────────────────────────────────────────────────
    $danfe  = null;
    $xmlUrl = null;
    try {
        $stmtXml = $pdo->prepare(
            'SELECT xml_url, danfe_url FROM biomie_xml_documentos_canonicos WHERE nota_key = :k LIMIT 1'
        );
        $stmtXml->execute(['k' => $foundKey]);
        $xmlRow = $stmtXml->fetch(PDO::FETCH_ASSOC) ?: [];
        $danfe  = ($xmlRow['danfe_url'] ?? null) ?: null;
        $xmlUrl = ($xmlRow['xml_url']   ?? null) ?: null;
    } catch (Throwable) {
        // Table may not exist yet — degrade silently
    }

    // Fase H — escopo de operação (colunas adicionadas pela migration H3; null quando ausentes)
    $opScope      = isset($invoice['operation_scope'])       ? (string) $invoice['operation_scope']       : null;
    $opName       = isset($invoice['omie_operation_name'])   ? (string) $invoice['omie_operation_name']   : null;
    $opConfidence = isset($invoice['operation_scope_confidence']) ? (string) $invoice['operation_scope_confidence'] : null;
    $opSource     = isset($invoice['operation_scope_source'])    ? (string) $invoice['operation_scope_source']    : null;

    Http::json([
        'ok'      => true,
        'invoice' => [
            'numero_nfe'               => (string) ($invoice['numero_nf'] ?? ''),
            'serie_nfe'                => (string) ($invoice['serie_nf'] ?? ''),
            'chave_nfe'                => (string) ($invoice['chave_nfe'] ?? ''),
            'status_nfe'               => (string) ($invoice['status_fiscal'] ?? ''),
            'data_emissao'             => (string) ($invoice['data_emissao'] ?? ''),
            'hora_emissao'             => null,
            'data_fatura'              => (string) ($invoice['data_competencia'] ?? ''),
            'danfe'                    => $danfe,
            'xml_distr'                => $xmlUrl,
            'operation_scope'          => $opScope,
            'omie_operation_name'      => $opName,
            'operation_scope_confidence' => $opConfidence,
            'operation_scope_source'   => $opSource,
        ],
        'order'          => $order ?? [],
        'customer'       => $customer,
        'seller'         => $seller,
        'items'          => $items,
        'totals'         => $totals,
        'finance_titles' => $financeTitles,
        'timeline'       => $timeline,
        'meta'           => [
            'source'     => 'canonical',
            'request_id' => RequestContext::id(),
        ],
    ]);
} catch (Throwable $e) {
    $logger->error('Falha no invoice_detail.', ['message' => $e->getMessage(), 'request_id' => RequestContext::id()]);
    Http::json(['ok' => false, 'message' => 'Não foi possível carregar o detalhe da nota fiscal.', 'request_id' => RequestContext::id()], 500);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * @param array<string,mixed> $invoice
 * @param array<string,mixed>|null $order
 * @return list<array<string,string>>
 */
function invoiceDetailBuildTimeline(array $invoice, ?array $order): array
{
    $events = [];
    if ($order !== null && !empty($order['data_emissao'])) {
        $events[] = ['date' => (string) $order['data_emissao'], 'event' => 'Pedido incluído', 'icon' => 'add_circle'];
    }
    if (!empty($invoice['data_emissao'])) {
        $num = (string) ($invoice['numero_nf'] ?? '');
        $events[] = ['date' => (string) $invoice['data_emissao'], 'event' => 'NF ' . $num . ' emitida', 'icon' => 'description'];
    }
    if (!empty($invoice['data_cancelamento'])) {
        $events[] = ['date' => (string) $invoice['data_cancelamento'], 'event' => 'Cancelada', 'icon' => 'cancel'];
    }
    usort($events, static fn ($a, $b) => strcmp((string) $a['date'], (string) $b['date']));
    return $events;
}
