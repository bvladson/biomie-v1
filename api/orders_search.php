<?php

/**
 * api/orders_search.php — Busca de NFs (Fase Q.2).
 *
 * Parâmetros globais:
 *   q          — busca livre: NF, pedido, cliente, CNPJ/CPF, vendedor
 *   scope      — commercial | remittance | all  (padrão: commercial)
 *   start      — data emissão início YYYY-MM-DD
 *   end        — data emissão fim YYYY-MM-DD
 *   sort       — campo de ordenação (whitelist — ver InvoiceFilters::sortWhitelist)
 *   direction  — asc | desc  (padrão: desc)
 *   page       — página 1-based  (padrão: 1)
 *   per_page   — itens/página  (padrão: 50, máx: 200)
 *
 * Filtros de coluna (via filters[]):
 *   filters[nf][op]=contains&filters[nf][val]=1340
 *   filters[cliente][op]=contains&filters[cliente][val]=SATOMI
 *   filters[vendedor][op]=contains&filters[vendedor][val]=SAMANTHA
 *   filters[pedido][op]=contains&filters[pedido][val]=1608
 *   filters[tipo][op]=equals&filters[tipo][val]=commercial
 *   filters[base_comercial][op]=gte&filters[base_comercial][val]=1000
 *   filters[base_comercial][op]=lte&filters[base_comercial][val]=5000
 *   filters[has_frete][val]=1
 *   filters[has_outras][val]=1
 *   filters[has_seguro][val]=1
 *   filters[sem_pedido][val]=1
 *   filters[com_desconto][val]=1
 *
 * Backward-compat (top-level):
 *   vendor, min_value, max_value
 *
 * Regras fiscais (Fase O — não alterar):
 *   - Base Comercial NF = valor_total − frete − outras − seguro
 *   - IPI permanece dentro da base
 *   - Remessas excluídas quando scope=commercial
 */

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\BiVisibilityScope;
use BIomie\Support\FiscalValueBasis;
use BIomie\Support\Filters\FilterNormalizer;
use BIomie\Support\Filters\Pagination;
use BIomie\Support\Filters\QueryStringMapper;
use BIomie\Support\Filters\Presets\InvoiceFilters;

require __DIR__ . '/bootstrap.php';

Auth::requireAnyCapability(['sales.view', 'fiscal.view'], true);

$t0 = hrtime(true);

try {
    $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $conn)) {
        Http::json(['ok' => false, 'error' => ['code' => 'DB_NOT_CONFIGURED', 'message' => 'Banco de dados não configurado.'], 'request_id' => RequestContext::id()], 503);
    }
    $pdo = Database::connect($config, $conn);

    // ── Parse FilterState ────────────────────────────────────────────────────
    $mapper = new QueryStringMapper(200);
    $state  = $mapper->map($_GET);

    // Backward-compat top-level params (merged into filter state when present)
    $vendorCompat   = FilterNormalizer::text((string) Http::query('vendor', ''));
    $minValueCompat = FilterNormalizer::money((string) Http::query('min_value', ''));
    $maxValueCompat = FilterNormalizer::money((string) Http::query('max_value', ''));

    // ── Fiscal basis detection (Fase O) ─────────────────────────────────────
    $hasFiscalBasis = (bool) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'biomie_notas_fiscais_canonicas'
           AND COLUMN_NAME  = 'valor_frete'"
    )->fetchColumn();

    $hasPreComputed = (bool) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'biomie_notas_fiscais_canonicas'
           AND COLUMN_NAME  = 'valor_base_comercial_nf'"
    )->fetchColumn();

    $baseExpr = $hasFiscalBasis
        ? FiscalValueBasis::commercialBaseSql('nf')
        : 'nf.valor_total';

    // ── Sort whitelist (base_comercial → baseExpr) ───────────────────────────
    $whitelist = InvoiceFilters::sortWhitelist($baseExpr);
    $sortCol   = $whitelist->resolve($state->sortField);
    $sortDir   = strtoupper($state->sortDir);

    // ── FROM + JOIN (fixed: v.codigo_omie, not v.codigo_vendedor_omie) ───────
    $fromSql = 'FROM biomie_notas_fiscais_canonicas nf
                LEFT JOIN biomie_vendedores_omie v ON v.codigo_omie = nf.codigo_vendedor_omie';

    // ── WHERE builder ────────────────────────────────────────────────────────
    $where  = [];
    $params = [];

    // 1. Scope (operation_scope) — never skipped unless scope=all
    if ($state->scope === 'commercial') {
        $where[] = "nf.operation_scope = 'commercial'";
    } elseif ($state->scope === 'remittance') {
        $where[] = "nf.operation_scope = 'remittance'";
    }

    // Fase L: exclude non-commercial business units (e.g. Licitação) by default.
    $where[] = BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf');

    // 2. Date range
    if ($state->start !== '') { $where[] = 'nf.data_emissao >= :start'; $params['start'] = $state->start; }
    if ($state->end   !== '') { $where[] = 'nf.data_emissao <= :end';   $params['end']   = $state->end;   }

    // 3. Global q — searches: NF number, NF/serie, pedido, cliente, CNPJ, vendedor
    if ($state->hasQ()) {
        $q        = $state->q;
        $qLike    = '%' . $q . '%';
        $params['q_like'] = $qLike;

        $qConds = [
            'nf.numero_nf LIKE :q_like',
            'nf.nome_cliente LIKE :q_like',
            "COALESCE(v.nome, nf.nome_vendedor, '') LIKE :q_like",
            'nf.codigo_pedido_origem LIKE :q_like',
        ];

        // NF/serie exact match (e.g. "1340/002")
        $params['q_like_serie'] = $qLike;
        $qConds[] = "CONCAT(LPAD(nf.numero_nf, 9, '0'), '/', nf.serie_nf) LIKE :q_like_serie";
        $qConds[] = "CONCAT(nf.numero_nf, '/', nf.serie_nf) LIKE :q_like_serie";

        // CNPJ/CPF — digits only
        $qDigits = preg_replace('/[^0-9]/', '', $q);
        if ($qDigits !== '') {
            $params['q_cnpj'] = '%' . $qDigits . '%';
            $qConds[] = "REPLACE(REPLACE(REPLACE(nf.documento_cliente, '.', ''), '-', ''), '/', '') LIKE :q_cnpj";
        }

        // Numeric NF match without leading zeros (e.g. "1340" → NF 1340)
        $qNumStr = ltrim($q, '0') ?: '0';
        if (ctype_digit($qNumStr) && $qNumStr !== '0') {
            $params['q_num'] = (int) $qNumStr;
            $qConds[] = 'CAST(nf.numero_nf AS UNSIGNED) = :q_num';
        }

        $where[] = '(' . implode(' OR ', $qConds) . ')';
    }

    // 4. Column-level filters (filters[field][op|val])
    $columnFilters = $state->filters;

    // Also merge backward-compat top-level params into column filters
    if ($vendorCompat !== '' && !isset($columnFilters['vendedor'])) {
        $columnFilters['vendedor'] = ['op' => 'contains', 'val' => $vendorCompat];
    }
    if ($minValueCompat !== null && !isset($columnFilters['base_comercial_gte'])) {
        $columnFilters['base_comercial_gte'] = ['op' => 'gte', 'val' => $minValueCompat];
    }
    if ($maxValueCompat !== null && !isset($columnFilters['base_comercial_lte'])) {
        $columnFilters['base_comercial_lte'] = ['op' => 'lte', 'val' => $maxValueCompat];
    }

    foreach ($columnFilters as $field => $def) {
        $op  = (string) ($def['op']  ?? 'contains');
        $val = $def['val'] ?? null;
        if ($val === null || $val === '') continue;

        switch ($field) {
            case 'nf':
                $params['f_nf_like'] = '%' . $val . '%';
                $nfNum = ltrim((string) $val, '0') ?: '0';
                if (ctype_digit($nfNum) && $nfNum !== '0') {
                    $params['f_nf_num'] = (int) $nfNum;
                    $where[] = '(nf.numero_nf LIKE :f_nf_like OR CAST(nf.numero_nf AS UNSIGNED) = :f_nf_num)';
                } else {
                    $where[] = 'nf.numero_nf LIKE :f_nf_like';
                }
                break;

            case 'cliente':
                $params['f_cliente'] = '%' . $val . '%';
                $digits = preg_replace('/[^0-9]/', '', (string) $val);
                if ($digits !== '') {
                    $params['f_cliente_doc'] = '%' . $digits . '%';
                    $where[] = "(nf.nome_cliente LIKE :f_cliente OR REPLACE(REPLACE(REPLACE(nf.documento_cliente, '.', ''), '-', ''), '/', '') LIKE :f_cliente_doc)";
                } else {
                    $where[] = 'nf.nome_cliente LIKE :f_cliente';
                }
                break;

            case 'vendedor':
                $params['f_vendedor'] = '%' . $val . '%';
                $where[] = "COALESCE(v.nome, nf.nome_vendedor, '') LIKE :f_vendedor";
                break;

            case 'pedido':
                $params['f_pedido'] = '%' . $val . '%';
                $where[] = 'nf.codigo_pedido_origem LIKE :f_pedido';
                break;

            case 'tipo':
                if (in_array($val, ['commercial', 'remittance', 'unknown'], true)) {
                    $params['f_tipo'] = $val;
                    $where[] = 'nf.operation_scope = :f_tipo';
                }
                break;

            case 'base_comercial':
            case 'base_comercial_gte':
                if ($op === 'gte' || $field === 'base_comercial_gte') {
                    $m = FilterNormalizer::money((string) $val);
                    if ($m !== null) { $params['f_bc_gte'] = $m; $where[] = "({$baseExpr}) >= :f_bc_gte"; }
                }
                if ($op === 'lte') {
                    $m = FilterNormalizer::money((string) $val);
                    if ($m !== null) { $params['f_bc_lte'] = $m; $where[] = "({$baseExpr}) <= :f_bc_lte"; }
                }
                break;

            case 'base_comercial_lte':
                $m = FilterNormalizer::money((string) $val);
                if ($m !== null) { $params['f_bc_lte'] = $m; $where[] = "({$baseExpr}) <= :f_bc_lte"; }
                break;

            case 'has_frete':
                if ($hasFiscalBasis && FilterNormalizer::boolean($val) === true) {
                    $where[] = 'COALESCE(nf.valor_frete, 0) > 0';
                }
                break;

            case 'has_outras':
                if ($hasFiscalBasis && FilterNormalizer::boolean($val) === true) {
                    $where[] = 'COALESCE(nf.valor_outras_despesas, 0) > 0';
                }
                break;

            case 'has_seguro':
                if ($hasFiscalBasis && FilterNormalizer::boolean($val) === true) {
                    $where[] = 'COALESCE(nf.valor_seguro, 0) > 0';
                }
                break;

            case 'com_desconto':
                if (FilterNormalizer::boolean($val) === true) {
                    $where[] = 'COALESCE(nf.valor_descontos, 0) > 0';
                }
                break;

            case 'sem_pedido':
                if (FilterNormalizer::boolean($val) === true) {
                    $where[] = "(nf.codigo_pedido_origem IS NULL OR TRIM(nf.codigo_pedido_origem) = '')";
                }
                break;
        }
    }

    $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : 'WHERE 1=1';

    // ── Aggregate (count + sums for the active filter set) ───────────────────
    $stmtSum = $pdo->prepare(
        "SELECT
            COUNT(*) AS nf_count,
            COALESCE(SUM({$baseExpr}), 0)       AS base_value,
            COALESCE(SUM(nf.valor_total), 0)     AS total_value,
            COALESCE(SUM(nf.valor_mercadorias), 0) AS merchandise_value,
            COALESCE(SUM(nf.valor_descontos), 0) AS discount_value
         {$fromSql} {$whereSql}"
    );
    $stmtSum->execute($params);
    $sum = $stmtSum->fetch(PDO::FETCH_ASSOC);

    $nfCount = (int)   ($sum['nf_count']          ?? 0);
    $baseVal  = (float) ($sum['base_value']         ?? 0);
    $totVal   = (float) ($sum['total_value']        ?? 0);
    $mercVal  = (float) ($sum['merchandise_value']  ?? 0);
    $discVal  = (float) ($sum['discount_value']     ?? 0);

    // ── Paginate ─────────────────────────────────────────────────────────────
    $pag = new Pagination($nfCount, $state->page, $state->perPage);

    // ── SELECT fiscal columns ────────────────────────────────────────────────
    $fiscalCols = $hasFiscalBasis
        ? "nf.valor_ipi, nf.valor_frete, nf.valor_outras_despesas, nf.valor_seguro,
           {$baseExpr} AS valor_base_comercial"
        : "0 AS valor_ipi, 0 AS valor_frete, 0 AS valor_outras_despesas, 0 AS valor_seguro,
           nf.valor_total AS valor_base_comercial";

    $precompCols = $hasPreComputed
        ? ',nf.valor_base_comercial_nf, nf.has_frete, nf.has_outras_despesas, nf.has_seguro'
        : ',NULL AS valor_base_comercial_nf, 0 AS has_frete, 0 AS has_outras_despesas, 0 AS has_seguro';

    $stmtData = $pdo->prepare(
        "SELECT
            nf.nota_key,
            nf.numero_nf,
            nf.serie_nf,
            nf.codigo_pedido_origem,
            nf.nome_cliente,
            nf.documento_cliente,
            nf.codigo_cliente_omie,
            COALESCE(v.nome, nf.nome_vendedor, '') AS nome_vendedor,
            nf.codigo_vendedor_omie,
            nf.status_fiscal,
            nf.data_emissao,
            nf.data_competencia,
            nf.valor_total,
            nf.valor_mercadorias,
            nf.valor_descontos,
            nf.quantidade_itens,
            nf.operation_scope,
            {$fiscalCols}
            {$precompCols}
         {$fromSql} {$whereSql}
         ORDER BY {$sortCol} {$sortDir}
         LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $key => $val) {
        $stmtData->bindValue(':' . $key, $val);
    }
    $stmtData->bindValue(':limit',  $pag->perPage, PDO::PARAM_INT);
    $stmtData->bindValue(':offset', $pag->offset,  PDO::PARAM_INT);
    $stmtData->execute();
    $rows = $stmtData->fetchAll(PDO::FETCH_ASSOC);

    // ── Normalize rows ────────────────────────────────────────────────────────
    $data = array_map(static function (array $row): array {
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
            'valor_total'           => round((float) ($row['valor_total']              ?? 0), 2),
            'valor_mercadorias'     => round((float) ($row['valor_mercadorias']         ?? 0), 2),
            'valor_descontos'       => round((float) ($row['valor_descontos']           ?? 0), 2),
            // Fase O fiscal components
            'valor_ipi'             => round((float) ($row['valor_ipi']               ?? 0), 2),
            'valor_frete'           => round((float) ($row['valor_frete']             ?? 0), 2),
            'valor_outras_despesas' => round((float) ($row['valor_outras_despesas']   ?? 0), 2),
            'valor_seguro'          => round((float) ($row['valor_seguro']            ?? 0), 2),
            // Fase O primary KPI: Base Comercial NF
            'valor_base_comercial'  => round((float) ($row['valor_base_comercial']    ?? 0), 2),
            'has_frete'             => (bool) ($row['has_frete']          ?? ($row['valor_frete']             ?? 0) > 0),
            'has_outras_despesas'   => (bool) ($row['has_outras_despesas'] ?? ($row['valor_outras_despesas']  ?? 0) > 0),
            'has_seguro'            => (bool) ($row['has_seguro']         ?? ($row['valor_seguro']            ?? 0) > 0),
            'quantidade_itens'      => (int) ($row['quantidade_itens'] ?? 0),
            'operation_scope'       => (string) ($row['operation_scope'] ?? 'commercial'),
        ];
    }, $rows);

    $queryMs = (int) round((hrtime(true) - $t0) / 1e6);

    Http::json([
        'ok'   => true,

        // Primary response (Fase Q contract)
        'data'       => $data,
        'pagination' => $pag->toArray(),
        'filters'    => $state->toApiArray(),
        'sort'       => ['field' => $state->sortField, 'direction' => $state->sortDir],
        'diagnostics' => [
            'query_ms'         => $queryMs,
            'source'           => 'canonical',
            'base'             => 'Base Comercial NF',
            'fiscal_basis'     => $hasFiscalBasis,
            'precomputed_base' => $hasPreComputed,
        ],

        // Backward-compat aliases
        'orders'         => $data,
        'search_summary' => [
            'nf_count'          => $nfCount,
            'base_value'        => round($baseVal, 2),
            'total_value'       => round($totVal,  2),
            'merchandise_value' => round($mercVal, 2),
            'discount_value'    => round($discVal, 2),
            'scope'             => $state->scope,
        ],

        'request_id' => RequestContext::id(),
    ]);
} catch (Throwable $e) {
    $logger->error('Falha no orders_search.', ['message' => $e->getMessage(), 'request_id' => RequestContext::id()]);
    Http::json([
        'ok'    => false,
        'error' => [
            'code'    => 'ORDERS_SEARCH_FAILED',
            'message' => 'Não foi possível aplicar a busca agora.',
            'details' => (Auth::isAdmin() ?? false) ? $e->getMessage() : null,
        ],
        'request_id' => RequestContext::id(),
    ], 500);
}
