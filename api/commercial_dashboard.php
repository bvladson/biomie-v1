<?php

declare(strict_types=1);

use BIomie\Database;
use BIomie\Http;
use BIomie\Support\BiVisibilityScope;
use BIomie\Support\Filters\FilterNormalizer;
use BIomie\Support\Filters\Pagination;
use BIomie\Support\Filters\Presets\DashboardFilters;
use BIomie\Support\FiscalValueBasis;

require __DIR__ . '/bootstrap.php';
\BIomie\Auth::requireCapability('sales.view', true);

set_time_limit(60);

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    Http::methodNotAllowed(['GET']);
}

try {
    $range = Http::resolveDateRange();
    Http::validateDateRange($range['start'], $range['end']);

    $start = $range['start'];
    $end   = $range['end'];

    // ── Params ───────────────────────────────────────────────────────────────
    $sortWl    = DashboardFilters::sortWhitelist('nf');
    $rawSort   = FilterNormalizer::text((string) (Http::query('sort') ?? 'emissao'), 40);
    $sortExpr  = $sortWl->resolve($rawSort);
    $sortDir   = FilterNormalizer::direction((string) (Http::query('direction') ?? 'desc'));
    $page      = max(1, (int) (Http::query('page') ?? 1));
    $perPage   = min(200, max(1, (int) (Http::query('per_page') ?? 50)));

    $fVendedor = FilterNormalizer::text((string) (Http::query('vendedor') ?? ''), 100);
    $fCliente  = FilterNormalizer::text((string) (Http::query('cliente') ?? ''), 100);
    $fProduto  = FilterNormalizer::text((string) (Http::query('produto') ?? ''), 100);
    $fQuick    = FilterNormalizer::text((string) (Http::query('quick') ?? ''), 30);

    // ── Connect ──────────────────────────────────────────────────────────────
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo        = Database::connect($config, $connection);

    $t0 = microtime(true);

    // ── WHERE conditions ─────────────────────────────────────────────────────
    $table   = 'biomie_notas_fiscais_canonicas';
    $visCond = BiVisibilityScope::getCondition($pdo, $table, 'nf');
    $baseSql = FiscalValueBasis::preComputedBaseSql('nf');

    $where  = [
        "nf.status_fiscal = 'autorizada'",
        "nf.operation_scope = 'commercial'",
        "COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end",
        $visCond,
    ];
    $params = [':start' => $start, ':end' => $end];

    if ($fVendedor !== '') {
        $where[]             = 'nf.nome_vendedor LIKE :vendedor';
        $params[':vendedor'] = '%' . $fVendedor . '%';
    }
    if ($fCliente !== '') {
        $where[]            = 'nf.nome_cliente LIKE :cliente';
        $params[':cliente'] = '%' . $fCliente . '%';
    }
    if ($fProduto !== '') {
        $where[]            = 'EXISTS (SELECT 1 FROM biomie_notas_fiscais_itens_canonicos i'
                            . ' WHERE i.nota_key = nf.nota_key AND i.descricao_produto LIKE :produto)';
        $params[':produto'] = '%' . $fProduto . '%';
    }

    // Quick filters (non-commissionable components / linked order)
    if ($fQuick === 'com_componentes') {
        $where[] = '(COALESCE(nf.valor_frete,0) > 0'
                 . ' OR COALESCE(nf.valor_outras_despesas,0) > 0'
                 . ' OR COALESCE(nf.valor_seguro,0) > 0)';
    } elseif ($fQuick === 'sem_pedido') {
        $where[] = '(nf.codigo_pedido_origem IS NULL OR nf.codigo_pedido_origem = \'\')';
    }

    $whereStr = implode(' AND ', $where);

    // ── KPIs ─────────────────────────────────────────────────────────────────
    $stmtKpi = $pdo->prepare(
        "SELECT
            COUNT(*) AS nfs,
            COUNT(DISTINCT nf.documento_cliente) AS clientes,
            COALESCE(SUM({$baseSql}), 0) AS base_comercial_nf,
            COALESCE(SUM(nf.valor_frete), 0) AS frete,
            COALESCE(SUM(nf.valor_outras_despesas), 0) AS outras_despesas,
            COALESCE(SUM(nf.valor_seguro), 0) AS seguro
         FROM {$table} nf
         WHERE {$whereStr}"
    );
    $stmtKpi->execute($params);
    $kpiRow = $stmtKpi->fetch(\PDO::FETCH_ASSOC) ?: [];

    $baseComercial    = (float) ($kpiRow['base_comercial_nf'] ?? 0);
    $nfsCount         = (int)   ($kpiRow['nfs'] ?? 0);
    $clientesCount    = (int)   ($kpiRow['clientes'] ?? 0);
    $freteTotal       = (float) ($kpiRow['frete'] ?? 0);
    $outrasTotal      = (float) ($kpiRow['outras_despesas'] ?? 0);
    $seguroTotal      = (float) ($kpiRow['seguro'] ?? 0);
    $ticketMedio      = $nfsCount > 0 ? $baseComercial / $nfsCount : 0.0;
    $naoComissionavel = $freteTotal + $outrasTotal + $seguroTotal;

    // ── Series diária ────────────────────────────────────────────────────────
    $stmtDia = $pdo->prepare(
        "SELECT
            DATE(COALESCE(nf.data_competencia, nf.data_emissao)) AS dia,
            COUNT(*) AS nfs,
            COALESCE(SUM({$baseSql}), 0) AS base_comercial
         FROM {$table} nf
         WHERE {$whereStr}
         GROUP BY dia
         ORDER BY dia"
    );
    $stmtDia->execute($params);
    $seriesDia = $stmtDia->fetchAll(\PDO::FETCH_ASSOC);

    // ── Series mensal ────────────────────────────────────────────────────────
    $stmtMes = $pdo->prepare(
        "SELECT
            DATE_FORMAT(COALESCE(nf.data_competencia, nf.data_emissao), '%Y-%m') AS mes,
            COUNT(*) AS nfs,
            COALESCE(SUM({$baseSql}), 0) AS base_comercial
         FROM {$table} nf
         WHERE {$whereStr}
         GROUP BY mes
         ORDER BY mes"
    );
    $stmtMes->execute($params);
    $seriesMes = $stmtMes->fetchAll(\PDO::FETCH_ASSOC);

    // ── Ranking vendedores (top 10) ───────────────────────────────────────────
    $stmtVnd = $pdo->prepare(
        "SELECT
            nf.nome_vendedor,
            COUNT(*) AS nfs,
            COUNT(DISTINCT nf.documento_cliente) AS clientes,
            COALESCE(SUM({$baseSql}), 0) AS base_comercial
         FROM {$table} nf
         WHERE {$whereStr}
         GROUP BY nf.nome_vendedor
         ORDER BY base_comercial DESC
         LIMIT 10"
    );
    $stmtVnd->execute($params);
    $topVendedores = $stmtVnd->fetchAll(\PDO::FETCH_ASSOC);

    // ── Ranking clientes (top 10) ─────────────────────────────────────────────
    $stmtCli = $pdo->prepare(
        "SELECT
            nf.nome_cliente,
            COUNT(*) AS nfs,
            COALESCE(SUM({$baseSql}), 0) AS base_comercial,
            MAX(COALESCE(nf.data_competencia, nf.data_emissao)) AS ultima_compra
         FROM {$table} nf
         WHERE {$whereStr}
         GROUP BY nf.nome_cliente
         ORDER BY base_comercial DESC
         LIMIT 10"
    );
    $stmtCli->execute($params);
    $topClientes = $stmtCli->fetchAll(\PDO::FETCH_ASSOC);

    // ── Ranking produtos (top 10, via itens) ─────────────────────────────────
    $stmtProd = $pdo->prepare(
        "SELECT
            i.descricao_produto AS produto,
            i.codigo_produto AS codigo,
            COUNT(DISTINCT i.nota_key) AS nfs,
            SUM(i.quantidade) AS quantidade,
            COALESCE(SUM(i.valor_total), 0) AS receita
         FROM biomie_notas_fiscais_itens_canonicos i
         JOIN {$table} nf ON nf.nota_key = i.nota_key
         WHERE {$whereStr}
         GROUP BY i.codigo_produto, i.descricao_produto
         ORDER BY receita DESC
         LIMIT 10"
    );
    $stmtProd->execute($params);
    $topProdutos = $stmtProd->fetchAll(\PDO::FETCH_ASSOC);

    // ── Top NFs (paginado) ────────────────────────────────────────────────────
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM {$table} nf WHERE {$whereStr}");
    $stmtCount->execute($params);
    $totalNfs = (int) $stmtCount->fetchColumn();

    $pagination = new Pagination($totalNfs, $page, $perPage);

    $stmtNfs = $pdo->prepare(
        "SELECT
            nf.numero_nf,
            nf.data_emissao,
            nf.nome_cliente,
            nf.nome_vendedor,
            nf.codigo_pedido_origem,
            nf.valor_total,
            nf.valor_frete,
            nf.valor_outras_despesas,
            nf.valor_seguro,
            COALESCE(nf.valor_base_comercial_nf, {$baseSql}) AS base_comercial_nf
         FROM {$table} nf
         WHERE {$whereStr}
         ORDER BY {$sortExpr} {$sortDir}
         LIMIT :limit OFFSET :offset"
    );
    $stmtNfs->bindValue(':limit',  $pagination->perPage, \PDO::PARAM_INT);
    $stmtNfs->bindValue(':offset', $pagination->offset,  \PDO::PARAM_INT);
    foreach ($params as $k => $v) {
        $stmtNfs->bindValue($k, $v);
    }
    $stmtNfs->execute();
    $topNfs = $stmtNfs->fetchAll(\PDO::FETCH_ASSOC);

    $queryMs = round((microtime(true) - $t0) * 1000, 1);

    Http::json([
        'ok'   => true,
        'kpis' => [
            'base_comercial_nf'  => round($baseComercial, 2),
            'nfs'                => $nfsCount,
            'clientes'           => $clientesCount,
            'ticket_medio'       => round($ticketMedio, 2),
            'frete'              => round($freteTotal, 2),
            'outras_despesas'    => round($outrasTotal, 2),
            'seguro'             => round($seguroTotal, 2),
            'nao_comissionavel'  => round($naoComissionavel, 2),
        ],
        'series' => [
            'evolucao_diaria'  => array_map(static fn (array $r): array => [
                'dia'            => (string) ($r['dia'] ?? ''),
                'nfs'            => (int)    $r['nfs'],
                'base_comercial' => round((float) $r['base_comercial'], 2),
            ], $seriesDia),
            'evolucao_mensal'  => array_map(static fn (array $r): array => [
                'mes'            => (string) ($r['mes'] ?? ''),
                'nfs'            => (int)    $r['nfs'],
                'base_comercial' => round((float) $r['base_comercial'], 2),
            ], $seriesMes),
        ],
        'rankings' => [
            'top_vendedores' => array_map(static fn (array $r): array => [
                'nome'           => (string) ($r['nome_vendedor'] ?? ''),
                'nfs'            => (int)    $r['nfs'],
                'clientes'       => (int)    $r['clientes'],
                'base_comercial' => round((float) $r['base_comercial'], 2),
            ], $topVendedores),
            'top_clientes' => array_map(static fn (array $r): array => [
                'nome'           => (string) ($r['nome_cliente'] ?? ''),
                'nfs'            => (int)    $r['nfs'],
                'base_comercial' => round((float) $r['base_comercial'], 2),
                'ultima_compra'  => (string) ($r['ultima_compra'] ?? ''),
            ], $topClientes),
            'top_produtos' => array_map(static fn (array $r): array => [
                'produto'        => (string) ($r['produto'] ?? ''),
                'codigo'         => (string) ($r['codigo'] ?? ''),
                'nfs'            => (int)    $r['nfs'],
                'quantidade'     => (float)  $r['quantidade'],
                'receita'        => round((float) $r['receita'], 2),
            ], $topProdutos),
            'top_nfs' => array_map(static fn (array $r): array => [
                'numero_nf'           => (string) ($r['numero_nf'] ?? ''),
                'data_emissao'        => (string) ($r['data_emissao'] ?? ''),
                'nome_cliente'        => (string) ($r['nome_cliente'] ?? ''),
                'nome_vendedor'       => (string) ($r['nome_vendedor'] ?? ''),
                'codigo_pedido'       => (string) ($r['codigo_pedido_origem'] ?? ''),
                'base_comercial_nf'   => round((float) ($r['base_comercial_nf'] ?? 0), 2),
                'valor_frete'         => round((float) ($r['valor_frete'] ?? 0), 2),
                'valor_outras'        => round((float) ($r['valor_outras_despesas'] ?? 0), 2),
                'valor_seguro'        => round((float) ($r['valor_seguro'] ?? 0), 2),
            ], $topNfs),
        ],
        'pagination' => $pagination->toArray(),
        'filters'    => [
            'vendedor'  => $fVendedor,
            'cliente'   => $fCliente,
            'produto'   => $fProduto,
            'quick'     => $fQuick,
            'sort'      => $rawSort,
            'direction' => $sortDir,
            'page'      => $page,
            'per_page'  => $perPage,
        ],
        'diagnostics' => [
            'query_ms'   => $queryMs,
            'source'     => 'canonical_nf',
            'base'       => 'Base Comercial NF',
            'visibility' => 'BiVisibilityScope',
        ],
    ]);
} catch (\InvalidArgumentException $e) {
    Http::json(['ok' => false, 'message' => $e->getMessage()], 400);
} catch (\Throwable $e) {
    $logger->warning('commercial_dashboard.php failed', ['message' => $e->getMessage()]);
    Http::json(['ok' => false, 'message' => 'Erro interno no painel comercial.'], 500);
}
