<?php

/**
 * api/orders_search.php — Busca de NFs na camada canonical.
 *
 * Consulta biomie_notas_fiscais_canonicas com filtros combinados.
 * Nunca chama o Omie diretamente.
 *
 * Parâmetros (todos opcionais — entradas inválidas são normalizadas silenciosamente):
 *   q          — busca livre (número NF, pedido, cliente, CNPJ, vendedor)
 *   scope      — commercial | remittance | all  (padrão: commercial)
 *   vendor     — código numérico ou nome parcial do vendedor
 *   start      — data início emissão (YYYY-MM-DD)
 *   end        — data fim emissão (YYYY-MM-DD)
 *   min_value  — valor mínimo (valor_total)
 *   max_value  — valor máximo (valor_total)
 *   sort       — emissao (padrão) | valor | cliente | nf | mercadoria
 *   direction  — asc | desc  (padrão: desc)
 *   page       — página 1-based  (padrão: 1)
 *   per_page   — itens por página  (padrão: 50, máx: 200)
 */

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\FiscalValueBasis;

require __DIR__ . '/bootstrap.php';

Auth::requireAnyCapability(['sales.view', 'fiscal.view'], true);

try {
    $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $conn)) {
        Http::json(['ok' => false, 'message' => 'Banco de dados não configurado.', 'request_id' => RequestContext::id()], 503);
    }
    $pdo = Database::connect($config, $conn);

    // ── Params (normalize silently — never 400 for bad inputs) ───────────────
    $q        = trim((string) Http::query('q', ''));
    $scopeRaw = strtolower(trim((string) Http::query('scope', 'commercial')));
    $scope    = in_array($scopeRaw, ['commercial', 'remittance', 'all'], true) ? $scopeRaw : 'commercial';
    $vendor   = trim((string) Http::query('vendor', ''));
    $start    = trim((string) Http::query('start', ''));
    $end      = trim((string) Http::query('end', ''));
    $minValue = trim((string) Http::query('min_value', ''));
    $maxValue = trim((string) Http::query('max_value', ''));
    $sortRaw  = trim((string) Http::query('sort', 'emissao'));
    $dirRaw   = strtolower(trim((string) Http::query('direction', 'desc')));
    $page     = max(1, (int) Http::query('page', 1));
    $perPage  = min(200, max(1, (int) Http::query('per_page', 50)));

    if ($start !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) { $start = ''; }
    if ($end   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   { $end   = ''; }

    // ── Sort map ──────────────────────────────────────────────────────────────
    $sortMap = [
        'emissao'    => 'nf.data_emissao',
        'valor'      => 'nf.valor_total',
        'cliente'    => 'nf.nome_cliente',
        'nf'         => 'CAST(nf.numero_nf AS UNSIGNED)',
        'mercadoria' => 'nf.valor_mercadorias',
    ];
    $sortCol = $sortMap[$sortRaw] ?? 'nf.data_emissao';
    $sortDir = $dirRaw === 'asc' ? 'ASC' : 'DESC';

    // ── Build WHERE ───────────────────────────────────────────────────────────
    $where  = [];
    $params = [];

    // Scope filter — regra absoluta: commercial só commercial, remittance só remittance
    if ($scope === 'commercial') {
        $where[] = "nf.operation_scope = 'commercial'";
    } elseif ($scope === 'remittance') {
        $where[] = "nf.operation_scope = 'remittance'";
    }
    // scope='all': sem filtro de operation_scope

    if ($q !== '') {
        $qLike = '%' . $q . '%';
        $params['q_like'] = $qLike;

        $qConds = [
            'nf.nome_cliente LIKE :q_like',
            "COALESCE(v.nome_vendedor, nf.nome_vendedor, '') LIKE :q_like",
            'nf.codigo_pedido_origem LIKE :q_like',
            "CONCAT(nf.serie_nf, '/', nf.numero_nf) LIKE :q_like",
            'nf.numero_nf LIKE :q_like',
        ];

        // CNPJ/CPF sem pontuação
        $qDigits = preg_replace('/[^0-9]/', '', $q);
        if ($qDigits !== '') {
            $qConds[] = "REPLACE(REPLACE(REPLACE(nf.documento_cliente, '.', ''), '-', ''), '/', '') LIKE :q_cnpj";
            $params['q_cnpj'] = '%' . $qDigits . '%';
        }

        // Correspondência numérica exata (remove zeros à esquerda)
        $qNumStr = ltrim($q, '0') ?: '0';
        if (ctype_digit($qNumStr) && $qNumStr !== '0') {
            $qConds[] = 'CAST(nf.numero_nf AS UNSIGNED) = :q_num';
            $params['q_num'] = (int) $qNumStr;
        }

        $where[] = '(' . implode(' OR ', $qConds) . ')';
    }

    if ($vendor !== '') {
        if (ctype_digit($vendor)) {
            $where[]               = "(nf.codigo_vendedor_omie = :vendor_id OR COALESCE(v.nome_vendedor, nf.nome_vendedor, '') LIKE :vendor_name)";
            $params['vendor_id']   = $vendor;
            $params['vendor_name'] = '%' . $vendor . '%';
        } else {
            $where[]               = "COALESCE(v.nome_vendedor, nf.nome_vendedor, '') LIKE :vendor_name";
            $params['vendor_name'] = '%' . $vendor . '%';
        }
    }

    if ($start !== '') { $where[] = 'nf.data_emissao >= :start'; $params['start'] = $start; }
    if ($end   !== '') { $where[] = 'nf.data_emissao <= :end';   $params['end']   = $end;   }

    if ($minValue !== '' && is_numeric($minValue)) {
        $where[] = 'nf.valor_total >= :min_value'; $params['min_value'] = (float) $minValue;
    }
    if ($maxValue !== '' && is_numeric($maxValue)) {
        $where[] = 'nf.valor_total <= :max_value'; $params['max_value'] = (float) $maxValue;
    }

    $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : 'WHERE 1=1';
    $fromSql  = 'FROM biomie_notas_fiscais_canonicas nf LEFT JOIN biomie_vendedores_omie v ON v.codigo_vendedor_omie = nf.codigo_vendedor_omie';

    // ── Fiscal basis availability (Fase O) ───────────────────────────────────
    $hasFiscalBasis = (bool) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biomie_notas_fiscais_canonicas' AND COLUMN_NAME = 'valor_frete'"
    )->fetchColumn();
    $baseSumExpr = $hasFiscalBasis
        ? FiscalValueBasis::commercialBaseSql('nf')
        : 'nf.valor_total';

    // ── Search summary ────────────────────────────────────────────────────────
    $stmtSum = $pdo->prepare(
        "SELECT COUNT(*) AS nf_count,
                COALESCE(SUM({$baseSumExpr}), 0)        AS base_value,
                COALESCE(SUM(nf.valor_total), 0)        AS total_value,
                COALESCE(SUM(nf.valor_mercadorias), 0)  AS merchandise_value,
                COALESCE(SUM(nf.valor_descontos), 0)    AS discount_value
         $fromSql $whereSql"
    );
    $stmtSum->execute($params);
    $sum = $stmtSum->fetch(PDO::FETCH_ASSOC);

    $nfCount  = (int)   ($sum['nf_count']         ?? 0);
    $baseVal  = (float) ($sum['base_value']        ?? 0);
    $totVal   = (float) ($sum['total_value']       ?? 0);
    $mercVal  = (float) ($sum['merchandise_value'] ?? 0);
    $discVal  = (float) ($sum['discount_value']    ?? 0);

    // ── Fetch page ────────────────────────────────────────────────────────────
    $offset     = ($page - 1) * $perPage;
    $totalPages = max(1, (int) ceil($nfCount / $perPage));

    // Fiscal basis columns added conditionally to avoid errors on pre-migration DBs.
    $fiscalSelectCols = $hasFiscalBasis
        ? ",\n            nf.valor_ipi, nf.valor_frete, nf.valor_outras_despesas, nf.valor_seguro,\n            {$baseSumExpr} AS valor_base_comercial"
        : ",\n            0 AS valor_ipi, 0 AS valor_frete, 0 AS valor_outras_despesas, 0 AS valor_seguro,\n            nf.valor_total AS valor_base_comercial";

    $stmtData = $pdo->prepare(
        "SELECT
            nf.nota_key,
            nf.numero_nf,
            nf.serie_nf,
            nf.codigo_pedido_origem,
            nf.nome_cliente,
            nf.documento_cliente,
            nf.codigo_cliente_omie,
            COALESCE(v.nome_vendedor, nf.nome_vendedor, '') AS nome_vendedor,
            nf.codigo_vendedor_omie,
            nf.status_fiscal,
            nf.data_emissao,
            nf.data_competencia,
            nf.valor_total,
            nf.valor_mercadorias,
            nf.valor_descontos,
            nf.quantidade_itens,
            nf.operation_scope{$fiscalSelectCols}
         $fromSql $whereSql
         ORDER BY $sortCol $sortDir
         LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $key => $val) {
        $stmtData->bindValue(':' . $key, $val);
    }
    $stmtData->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $stmtData->bindValue(':offset', $offset,  PDO::PARAM_INT);
    $stmtData->execute();
    $rows = $stmtData->fetchAll(PDO::FETCH_ASSOC);

    // ── Normalize rows ────────────────────────────────────────────────────────
    $orders = array_map(static function (array $row): array {
        return [
            'nota_key'              => (string) ($row['nota_key']             ?? ''),
            'numero_nf'             => (string) ($row['numero_nf']            ?? ''),
            'serie_nf'              => (string) ($row['serie_nf']             ?? ''),
            'codigo_pedido_origem'  => (string) ($row['codigo_pedido_origem'] ?? ''),
            'nome_cliente'          => (string) ($row['nome_cliente']         ?? ''),
            'documento_cliente'     => (string) ($row['documento_cliente']    ?? ''),
            'codigo_cliente_omie'   => (string) ($row['codigo_cliente_omie']  ?? ''),
            'nome_vendedor'         => (string) ($row['nome_vendedor']        ?? ''),
            'codigo_vendedor_omie'  => (string) ($row['codigo_vendedor_omie'] ?? ''),
            'status_fiscal'         => (string) ($row['status_fiscal']        ?? ''),
            'data_emissao'          => (string) ($row['data_emissao']         ?? ''),
            'data_competencia'      => (string) ($row['data_competencia']     ?? ''),
            'valor_total'           => round((float) ($row['valor_total']           ?? 0), 2),
            'valor_mercadorias'     => round((float) ($row['valor_mercadorias']      ?? 0), 2),
            'valor_descontos'       => round((float) ($row['valor_descontos']        ?? 0), 2),
            // Fase O: fiscal basis components
            'valor_ipi'             => round((float) ($row['valor_ipi']              ?? 0), 2),
            'valor_frete'           => round((float) ($row['valor_frete']            ?? 0), 2),
            'valor_outras_despesas' => round((float) ($row['valor_outras_despesas']  ?? 0), 2),
            'valor_seguro'          => round((float) ($row['valor_seguro']           ?? 0), 2),
            'valor_base_comercial'  => round((float) ($row['valor_base_comercial']   ?? 0), 2),
            'quantidade_itens'      => (int) ($row['quantidade_itens'] ?? 0),
            'operation_scope'       => (string) ($row['operation_scope'] ?? 'commercial'),
        ];
    }, $rows);

    Http::json([
        'ok'             => true,
        'search_summary' => [
            'nf_count'          => $nfCount,
            'base_value'        => round($baseVal, 2), // Fase O: valor_total - frete - outras - seguro
            'total_value'       => round($totVal,  2), // valor_total bruto NF (diagnóstico)
            'merchandise_value' => round($mercVal, 2),
            'discount_value'    => round($discVal, 2),
            'scope'             => $scope,
        ],
        'orders'     => $orders,
        'pagination' => [
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $nfCount,
            'total_pages' => $totalPages,
            'has_next'    => $page < $totalPages,
            'has_prev'    => $page > 1,
        ],
        'sort' => [
            'field'     => $sortRaw,
            'direction' => $sortDir === 'ASC' ? 'asc' : 'desc',
        ],
        'request_id' => RequestContext::id(),
    ]);
} catch (Throwable $e) {
    $logger->error('Falha no orders_search.', ['message' => $e->getMessage(), 'request_id' => RequestContext::id()]);
    Http::json(['ok' => false, 'message' => 'Não foi possível executar a busca.', 'request_id' => RequestContext::id()], 500);
}
