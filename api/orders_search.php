<?php

/**
 * api/orders_search.php — Busca e filtros de pedidos canonicos.
 *
 * Consulta a tabela biomie_pedidos_canonicos com filtros combinados.
 * Nunca chama o Omie diretamente.
 *
 * Parâmetros (todos opcionais):
 *   q          — busca livre (numero_pedido, codigo_pedido, nome_cliente, documento_cliente)
 *   vendor     — codigo ou nome do vendedor
 *   status     — status_comercial
 *   start      — data início emissão (YYYY-MM-DD)
 *   end        — data fim emissão (YYYY-MM-DD)
 *   min_value  — valor mínimo (valor_total)
 *   max_value  — valor máximo (valor_total)
 *   sort       — campo de ordenação: emissao (padrão) | valor | cliente | pedido
 *   order      — asc | desc (padrão: desc)
 *   page       — página (1-based, padrão: 1)
 *   per_page   — itens por página (padrão: 50, máx: 200)
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
    $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $conn)) {
        Http::json(['ok' => false, 'message' => 'Banco de dados não configurado.', 'request_id' => RequestContext::id()], 503);
    }
    $pdo = Database::connect($config, $conn);

    // ── Params ────────────────────────────────────────────────────────────────
    $q        = trim((string) Http::query('q', ''));
    $vendor   = trim((string) Http::query('vendor', ''));
    $status   = trim((string) Http::query('status', ''));
    $start    = trim((string) Http::query('start', ''));
    $end      = trim((string) Http::query('end', ''));
    $minValue = trim((string) Http::query('min_value', ''));
    $maxValue = trim((string) Http::query('max_value', ''));
    $sortRaw  = trim((string) Http::query('sort', 'emissao'));
    $orderRaw = strtolower(trim((string) Http::query('order', 'desc')));
    $page     = max(1, (int) Http::query('page', 1));
    $perPage  = min(200, max(1, (int) Http::query('per_page', 50)));

    // Validate dates if provided
    if ($start !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
        Http::json(['ok' => false, 'message' => 'Parâmetro start inválido. Use YYYY-MM-DD.', 'request_id' => RequestContext::id()], 400);
    }
    if ($end !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        Http::json(['ok' => false, 'message' => 'Parâmetro end inválido. Use YYYY-MM-DD.', 'request_id' => RequestContext::id()], 400);
    }

    // ── Sort column map ───────────────────────────────────────────────────────
    $sortMap = [
        'emissao' => 'data_emissao',
        'valor'   => 'valor_total',
        'cliente' => 'nome_cliente',
        'pedido'  => 'codigo_pedido',
    ];
    $sortCol = $sortMap[$sortRaw] ?? 'data_emissao';
    $sortDir = $orderRaw === 'asc' ? 'ASC' : 'DESC';

    // ── Build WHERE ───────────────────────────────────────────────────────────
    $where  = [];
    $params = [];

    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[]            = '(codigo_pedido LIKE :q1 OR pedido_id_omie LIKE :q2 OR nome_cliente LIKE :q3 OR documento_cliente LIKE :q4 OR codigo_integracao LIKE :q5)';
        $params['q1']       = $like;
        $params['q2']       = $like;
        $params['q3']       = $like;
        $params['q4']       = $like;
        $params['q5']       = $like;
    }

    if ($vendor !== '') {
        // Match by numeric ID or by name (partial)
        if (ctype_digit($vendor)) {
            $where[]           = '(codigo_vendedor_omie = :vendor_id OR nome_vendedor LIKE :vendor_name)';
            $params['vendor_id']   = $vendor;
            $params['vendor_name'] = '%' . $vendor . '%';
        } else {
            $where[]           = 'nome_vendedor LIKE :vendor_name';
            $params['vendor_name'] = '%' . $vendor . '%';
        }
    }

    if ($status !== '') {
        $where[]          = 'status_comercial = :status';
        $params['status'] = $status;
    }

    if ($start !== '') {
        $where[]         = 'data_emissao >= :start';
        $params['start'] = $start;
    }

    if ($end !== '') {
        $where[]       = 'data_emissao <= :end';
        $params['end'] = $end;
    }

    if ($minValue !== '' && is_numeric($minValue)) {
        $where[]              = 'valor_total >= :min_value';
        $params['min_value']  = (float) $minValue;
    }

    if ($maxValue !== '' && is_numeric($maxValue)) {
        $where[]              = 'valor_total <= :max_value';
        $params['max_value']  = (float) $maxValue;
    }

    $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

    // ── Count total ───────────────────────────────────────────────────────────
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM biomie_pedidos_canonicos $whereSql");
    $stmtCount->execute($params);
    $total = (int) $stmtCount->fetchColumn();

    // ── Fetch page ────────────────────────────────────────────────────────────
    $offset    = ($page - 1) * $perPage;
    $totalPages = max(1, (int) ceil($total / $perPage));

    $stmtData = $pdo->prepare(
        "SELECT
            codigo_pedido,
            pedido_id_omie,
            codigo_integracao,
            status_comercial,
            etapa_omie,
            data_emissao,
            data_faturamento,
            nome_cliente,
            documento_cliente,
            codigo_cliente_omie,
            nome_vendedor,
            codigo_vendedor_omie,
            valor_mercadorias,
            valor_descontos,
            valor_total,
            numero_nf_faturamento,
            updated_at
         FROM biomie_pedidos_canonicos
         $whereSql
         ORDER BY $sortCol $sortDir
         LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $key => $val) {
        $stmtData->bindValue(':' . $key, $val);
    }
    $stmtData->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmtData->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtData->execute();
    $rows = $stmtData->fetchAll(PDO::FETCH_ASSOC);

    // ── Normalize rows for display ────────────────────────────────────────────
    $results = array_map(static function (array $row): array {
        return [
            'codigo_pedido'          => (string) ($row['codigo_pedido'] ?? ''),
            'pedido_id_omie'         => (string) ($row['pedido_id_omie'] ?? ''),
            'codigo_integracao'      => (string) ($row['codigo_integracao'] ?? ''),
            'status'                 => (string) ($row['status_comercial'] ?? ''),
            'etapa'                  => (string) ($row['etapa_omie'] ?? ''),
            'data_emissao'           => (string) ($row['data_emissao'] ?? ''),
            'data_faturamento'       => (string) ($row['data_faturamento'] ?? ''),
            'cliente'                => (string) ($row['nome_cliente'] ?? ''),
            'documento_cliente'      => (string) ($row['documento_cliente'] ?? ''),
            'codigo_cliente_omie'    => (string) ($row['codigo_cliente_omie'] ?? ''),
            'vendedor'               => (string) ($row['nome_vendedor'] ?? ''),
            'codigo_vendedor_omie'   => (string) ($row['codigo_vendedor_omie'] ?? ''),
            'valor_mercadorias'      => Support::money((float) ($row['valor_mercadorias'] ?? 0)),
            'valor_descontos'        => Support::money((float) ($row['valor_descontos'] ?? 0)),
            'valor_total'            => Support::money((float) ($row['valor_total'] ?? 0)),
            'numero_nf'              => (string) ($row['numero_nf_faturamento'] ?? ''),
            'updated_at'             => (string) ($row['updated_at'] ?? ''),
        ];
    }, $rows);

    // ── Active filters summary ────────────────────────────────────────────────
    $activeFilters = array_filter([
        'q'         => $q ?: null,
        'vendor'    => $vendor ?: null,
        'status'    => $status ?: null,
        'start'     => $start ?: null,
        'end'       => $end ?: null,
        'min_value' => $minValue !== '' ? (float) $minValue : null,
        'max_value' => $maxValue !== '' ? (float) $maxValue : null,
    ]);

    Http::json([
        'ok'      => true,
        'results' => $results,
        'pagination' => [
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $total,
            'total_pages' => $totalPages,
            'has_next'    => $page < $totalPages,
            'has_prev'    => $page > 1,
        ],
        'sort' => [
            'field' => $sortRaw,
            'order' => $orderRaw === 'asc' ? 'asc' : 'desc',
        ],
        'filters' => $activeFilters,
        'meta'    => [
            'source'     => 'canonical',
            'request_id' => RequestContext::id(),
        ],
    ]);
} catch (Throwable $e) {
    $logger->error('Falha no orders_search.', ['message' => $e->getMessage(), 'request_id' => RequestContext::id()]);
    Http::json(['ok' => false, 'message' => 'Não foi possível executar a busca.', 'request_id' => RequestContext::id()], 500);
}
