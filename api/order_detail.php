<?php

/**
 * api/order_detail.php — Detalhe completo de pedido.
 *
 * Retorna pedido + NFs vinculadas + itens de NF + títulos financeiros + timeline.
 *
 * Parâmetros:
 *   codigo_pedido — código do pedido canonical (obrigatório)
 *
 * Permissão: sales.view ou fiscal.view
 */

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support;

require __DIR__ . '/bootstrap.php';

Auth::requireAnyCapability(['sales.view', 'fiscal.view'], true);

try {
    $codigoPedido = trim((string) Http::query('codigo_pedido'));
    if ($codigoPedido === '') {
        Http::json(['ok' => false, 'message' => 'codigo_pedido é obrigatório.', 'request_id' => RequestContext::id()], 400);
    }

    $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $conn)) {
        Http::json(['ok' => false, 'message' => 'Banco de dados não configurado.', 'request_id' => RequestContext::id()], 503);
    }
    $pdo = Database::connect($config, $conn);

    // ── Order row ─────────────────────────────────────────────────────────────
    $tenantFilter = \BIomie\Support\TenantContext::sqlFilter();
    $stmtOrder = $pdo->prepare(
        "SELECT * FROM biomie_pedidos_canonicos WHERE codigo_pedido = :codigo AND {$tenantFilter} LIMIT 1"
    );
    $stmtOrder->execute(['codigo' => $codigoPedido]);
    $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

    if ($order === false) {
        Http::json(['ok' => false, 'message' => 'Pedido não encontrado.', 'request_id' => RequestContext::id()], 404);
    }

    $pedidoIdOmie = (int) ($order['pedido_id_omie'] ?? 0);

    // ── Linked NFs ────────────────────────────────────────────────────────────
    $invoices = [];
    $noteKeys = [];
    if ($pedidoIdOmie > 0 || $codigoPedido !== '') {
        $stmtNf = $pdo->prepare(
            "SELECT * FROM biomie_notas_fiscais_canonicas
             WHERE {$tenantFilter}
               AND (codigo_pedido_origem = :codigo OR pedido_id_omie = :pid)
               AND status_fiscal != 'cancelada'
             ORDER BY data_emissao DESC, numero_nf DESC
             LIMIT 20"
        );
        $stmtNf->execute(['codigo' => $codigoPedido, 'pid' => $pedidoIdOmie ?: null]);
        $invoices = $stmtNf->fetchAll(PDO::FETCH_ASSOC);
        foreach ($invoices as $nf) {
            $k = trim((string) ($nf['nota_key'] ?? ''));
            if ($k !== '') { $noteKeys[] = $k; }
        }
    }

    // ── NF items ──────────────────────────────────────────────────────────────
    $items = [];
    if ($noteKeys !== []) {
        $placeholders = implode(',', array_fill(0, count($noteKeys), '?'));
        $stmtItems = $pdo->prepare(
            "SELECT * FROM biomie_notas_fiscais_itens_canonicos
             WHERE nota_key IN ($placeholders) AND {$tenantFilter}
             ORDER BY nota_key, sequencia_item"
        );
        $stmtItems->execute($noteKeys);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Financial titles ──────────────────────────────────────────────────────
    $financeTitles = [];
    try {
        $stmtTitles = $pdo->prepare(
            "SELECT * FROM biomie_titulos_financeiros_canonicos
             WHERE ({$tenantFilter})
               AND (codigo_integracao = :codigo OR codigo_integracao = :pedido_id_str)
             ORDER BY data_vencimento ASC, titulo_key ASC
             LIMIT 20"
        );
        $stmtTitles->execute([
            'codigo'         => $codigoPedido,
            'pedido_id_str'  => (string) $pedidoIdOmie,
        ]);
        $financeTitles = $stmtTitles->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $financeTitles = [];
    }

    // ── Installments (parcelas) from order row or finance titles ───────────────
    $installments = orderDetailBuildInstallments($order, $financeTitles);

    // ── Timeline ─────────────────────────────────────────────────────────────
    $timeline = orderDetailBuildTimeline($order, $invoices);

    // ── Vendor / customer structs ─────────────────────────────────────────────
    $seller = [
        'id'   => (string) ($order['codigo_vendedor_omie'] ?? ''),
        'name' => (string) ($order['nome_vendedor'] ?? ''),
    ];
    $customer = [
        'id'       => (string) ($order['codigo_cliente_omie'] ?? ''),
        'name'     => (string) ($order['nome_cliente'] ?? ''),
        'document' => (string) ($order['documento_cliente'] ?? ''),
        'address'  => null,
        'phone'    => null,
        'email'    => null,
    ];

    // ── Totals ────────────────────────────────────────────────────────────────
    $totals = [
        'subtotal'    => Support::money((float) ($order['valor_mercadorias'] ?? 0)),
        'discount'    => Support::money((float) ($order['valor_descontos'] ?? 0)),
        'ipi'         => 0.0,
        'icms_st'     => 0.0,
        'total'       => Support::money((float) ($order['valor_total'] ?? 0)),
    ];

    Http::json([
        'ok'    => true,
        'order' => [
            'codigo_pedido'             => (string) ($order['codigo_pedido'] ?? ''),
            'numero_pedido'             => (string) ($order['pedido_id_omie'] ?? ''),
            'codigo_pedido_integracao'  => (string) ($order['codigo_integracao'] ?? ''),
            'status'                    => (string) ($order['status_comercial'] ?? ''),
            'etapa'                     => (string) ($order['etapa_omie'] ?? ''),
            'data_previsao'             => (string) ($order['data_emissao'] ?? ''),
            'data_inclusao'             => (string) ($order['data_emissao'] ?? ''),
            'data_faturamento'          => (string) ($order['data_faturamento'] ?? ''),
            'seller'                    => $seller,
            'customer'                  => $customer,
            'totals'                    => $totals,
            'notes'                     => '',
            'payment_terms'             => '',
        ],
        'items'          => $items,
        'installments'   => $installments,
        'invoices'       => $invoices,
        'finance_titles' => $financeTitles,
        'timeline'       => $timeline,
        'raw_available'  => Auth::hasCapability('logs.view') || Auth::hasCapability('admin.view'),
        'meta'           => [
            'source'     => 'canonical',
            'request_id' => RequestContext::id(),
        ],
    ]);
} catch (Throwable $e) {
    $logger->error('Falha no order_detail.', ['message' => $e->getMessage(), 'request_id' => RequestContext::id()]);
    Http::json(['ok' => false, 'message' => 'Não foi possível carregar o detalhe do pedido.', 'request_id' => RequestContext::id()], 500);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * @param array<string,mixed> $order
 * @param list<array<string,mixed>> $titles
 * @return list<array<string,mixed>>
 */
function orderDetailBuildInstallments(array $order, array $titles): array
{
    if ($titles !== []) {
        return array_map(static function (array $t, int $i): array {
            return [
                'numero'      => $i + 1,
                'vencimento'  => (string) ($t['data_vencimento'] ?? ''),
                'valor'       => Support::money((float) ($t['valor_liquido'] ?? $t['valor_aberto'] ?? 0)),
                'status'      => (string) ($t['status_titulo'] ?? ''),
                'especie'     => (string) ($t['especie_documento'] ?? ''),
                'forma'       => (string) ($t['forma_pagamento'] ?? ''),
                'liquidacao'  => (string) ($t['data_liquidacao'] ?? ''),
            ];
        }, $titles, array_keys($titles));
    }

    // No titles — build a single installment from the order total
    $total = (float) ($order['valor_total'] ?? 0);
    if ($total <= 0.0) {
        return [];
    }
    return [[
        'numero'     => 1,
        'vencimento' => (string) ($order['data_faturamento'] ?? ''),
        'valor'      => Support::money($total),
        'status'     => (string) ($order['status_comercial'] ?? ''),
        'especie'    => '',
        'forma'      => '',
        'liquidacao' => '',
    ]];
}

/**
 * @param array<string,mixed> $order
 * @param list<array<string,mixed>> $invoices
 * @return list<array<string,string>>
 */
function orderDetailBuildTimeline(array $order, array $invoices): array
{
    $events = [];
    if (!empty($order['data_emissao'])) {
        $events[] = ['date' => (string) $order['data_emissao'], 'event' => 'Pedido incluído', 'icon' => 'add_circle'];
    }
    if (!empty($order['data_faturamento'])) {
        $events[] = ['date' => (string) $order['data_faturamento'], 'event' => 'Faturado', 'icon' => 'receipt_long'];
    }
    foreach ($invoices as $nf) {
        if (!empty($nf['data_emissao'])) {
            $num = (string) ($nf['numero_nf'] ?? '');
            $events[] = ['date' => (string) $nf['data_emissao'], 'event' => 'NF ' . $num . ' emitida', 'icon' => 'description'];
        }
    }
    if (!empty($order['data_cancelamento'])) {
        $events[] = ['date' => (string) $order['data_cancelamento'], 'event' => 'Cancelado', 'icon' => 'cancel'];
    }

    usort($events, static fn ($a, $b) => strcmp((string) $a['date'], (string) $b['date']));
    return $events;
}
