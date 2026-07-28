<?php

declare(strict_types=1);

use BIomie\Database;
use BIomie\Http;
use BIomie\Support\BiVisibilityScope;
use BIomie\Support\Filters\FilterNormalizer;
use BIomie\Support\Filters\Pagination;
use BIomie\Support\Filters\Presets\ReconciliationFilters;
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

    // ── Params ────────────────────────────────────────────────────────────────
    $sortWl   = ReconciliationFilters::sortWhitelist();
    $rawSort  = FilterNormalizer::text((string) (Http::query('sort')      ?? 'emissao'), 30);
    $sortExpr = $sortWl->resolve($rawSort);
    $sortDir  = FilterNormalizer::direction((string) (Http::query('direction') ?? 'desc'));
    $page     = max(1, (int) (Http::query('page')     ?? 1));
    $perPage  = max(1, min(100, (int) (Http::query('per_page') ?? 25)));

    $fQ          = FilterNormalizer::text((string) (Http::query('q')           ?? ''), 100);
    $fVendedor   = FilterNormalizer::text((string) (Http::query('vendedor')    ?? ''), 100);
    $fVendedorId = FilterNormalizer::text((string) (Http::query('vendedor_id') ?? ''), 60);
    $fCliente    = FilterNormalizer::text((string) (Http::query('cliente')     ?? ''), 100);
    $fPedido     = FilterNormalizer::text((string) (Http::query('pedido')      ?? ''), 60);
    $fNf         = FilterNormalizer::text((string) (Http::query('nf')          ?? ''), 40);
    $fTitulo     = FilterNormalizer::text((string) (Http::query('titulo')      ?? ''), 60);
    $fStatusConc = FilterNormalizer::text((string) (Http::query('status_conciliacao') ?? ''), 30);
    $fStatusTit  = FilterNormalizer::text((string) (Http::query('status_titulo')      ?? ''), 30);
    $fSoDiverg   = ((Http::query('somente_divergencias') ?? '') === '1');
    $fMinValor   = Http::query('min_valor')    !== null ? (float) Http::query('min_valor')    : null;
    $fMaxValor   = Http::query('max_valor')    !== null ? (float) Http::query('max_valor')    : null;
    $fMinDif     = Http::query('min_diferenca') !== null ? (float) Http::query('min_diferenca') : null;
    $fMaxDif     = Http::query('max_diferenca') !== null ? (float) Http::query('max_diferenca') : null;

    if (!in_array($fStatusConc, array_merge(ReconciliationFilters::allowedStatusConciliacao(), ['']), true)) {
        $fStatusConc = '';
    }
    if (!in_array($fStatusTit, array_merge(ReconciliationFilters::allowedStatusTitulo(), ['']), true)) {
        $fStatusTit = '';
    }

    // ── Connect ───────────────────────────────────────────────────────────────
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo        = Database::connect($config, $connection);

    $t0 = microtime(true);

    // ── Bridge/summary detection (priority: summary > bridge > fallback) ───────
    $summaryExists = (bool) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biomie_bridge_nf_titulo_summary'"
    )->fetchColumn();

    $bridgeExists = (bool) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biomie_bridge_nf_titulo'"
    )->fetchColumn();

    // ── BiVisibilityScope ─────────────────────────────────────────────────────
    $visCondNf  = BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf');
    $visCondPed = BiVisibilityScope::getCondition($pdo, 'biomie_pedidos_canonicos',       'p');

    // ── FiscalValueBasis ──────────────────────────────────────────────────────
    $baseSql = FiscalValueBasis::preComputedBaseSql('nf');

    // ── Join / expression strategy ────────────────────────────────────────────
    if ($summaryExists) {
        // Best path: all titulos aggregated; compares soma vs base_comercial
        $titleJoinA   = "LEFT JOIN biomie_bridge_nf_titulo_summary s
          ON s.company_id = nf.company_id AND s.nota_key = nf.nota_key";
        $qtdTitulosA  = "COALESCE(s.qtd_titulos, 0)";
        $totalFinA    = "COALESCE(s.valor_titulos_total, 0)";
        $recebidoA    = "COALESCE(s.valor_titulos_recebido, 0)";
        $abertoA      = "COALESCE(s.valor_titulos_aberto, 0)";
        $proxVtoA     = "s.menor_vencimento";
        $tituloKeyA   = "''";
        $tituloNumA   = "''";
        $liquidacaoA  = "NULL";
        $statusTitA   = "''";
        $matchMethodA = "COALESCE(s.best_match_method, '')";
        $confidenceA  = "COALESCE(s.min_confidence_score, 0)";
        $diferencaA   = "ABS({$baseSql} - COALESCE(s.valor_titulos_total, 0))";
        $statusCaseA  = "
            CASE
                WHEN (nf.pedido_id_omie IS NULL OR nf.pedido_id_omie = 0)
                    THEN CASE
                        WHEN s.nota_key IS NULL OR COALESCE(s.qtd_titulos, 0) = 0 THEN 'nf_sem_titulo'
                        WHEN COALESCE(s.min_confidence_score, 100) < 100 THEN 'titulo_baixa_confianca'
                        ELSE 'nf_sem_pedido'
                         END
                WHEN s.nota_key IS NULL OR COALESCE(s.qtd_titulos, 0) = 0 THEN 'nf_sem_titulo'
                WHEN COALESCE(s.min_confidence_score, 100) < 100 THEN 'titulo_baixa_confianca'
                WHEN ABS({$baseSql} - COALESCE(s.valor_titulos_total, 0)) > 0.01 THEN 'valor_divergente'
                ELSE 'ok'
            END";
    } elseif ($bridgeExists) {
        // Legacy path: old single-titulo bridge (Q.10 compat while summary builds)
        $titleJoinA   = "LEFT JOIN biomie_bridge_nf_titulo br
          ON br.company_id = nf.company_id AND br.nota_key = nf.nota_key
        LEFT JOIN biomie_titulos_financeiros_canonicos t
          ON t.company_id = br.company_id AND t.titulo_key = br.titulo_key";
        $qtdTitulosA  = "IF(t.titulo_key IS NOT NULL, 1, 0)";
        $totalFinA    = "COALESCE(t.valor_liquido, 0)";
        $recebidoA    = "GREATEST(0, COALESCE(t.valor_liquido, 0) - COALESCE(t.valor_aberto, 0))";
        $abertoA      = "COALESCE(t.valor_aberto, 0)";
        $proxVtoA     = "t.data_vencimento";
        $tituloKeyA   = "COALESCE(t.titulo_key, '')";
        $tituloNumA   = "COALESCE(t.numero_documento, '')";
        $liquidacaoA  = "t.data_liquidacao";
        $statusTitA   = "COALESCE(t.status_titulo, '')";
        $matchMethodA = "COALESCE(br.match_method, '')";
        $confidenceA  = "COALESCE(br.confidence_score, 0)";
        $diferencaA   = "ABS({$baseSql} - COALESCE(t.valor_liquido, 0))";
        $statusCaseA  = "
            CASE
                WHEN (nf.pedido_id_omie IS NULL OR nf.pedido_id_omie = 0)
                    THEN CASE
                        WHEN t.titulo_key IS NULL THEN 'nf_sem_titulo'
                        WHEN COALESCE(br.confidence_score, 100) < 100 THEN 'titulo_baixa_confianca'
                        ELSE 'nf_sem_pedido'
                         END
                WHEN t.titulo_key IS NULL THEN 'nf_sem_titulo'
                WHEN COALESCE(br.confidence_score, 100) < 100 THEN 'titulo_baixa_confianca'
                WHEN ABS({$baseSql} - COALESCE(t.valor_liquido, 0)) > 0.01 THEN 'valor_divergente'
                ELSE 'ok'
            END";
    } else {
        // Fallback: direct chave_nfe join (no bridge built yet)
        $titleJoinA   = "LEFT JOIN biomie_titulos_financeiros_canonicos t
               ON t.company_id = nf.company_id
              AND t.chave_nfe = nf.chave_nfe
              AND nf.chave_nfe IS NOT NULL AND nf.chave_nfe != ''";
        $qtdTitulosA  = "IF(t.titulo_key IS NOT NULL, 1, 0)";
        $totalFinA    = "COALESCE(t.valor_liquido, 0)";
        $recebidoA    = "GREATEST(0, COALESCE(t.valor_liquido, 0) - COALESCE(t.valor_aberto, 0))";
        $abertoA      = "COALESCE(t.valor_aberto, 0)";
        $proxVtoA     = "t.data_vencimento";
        $tituloKeyA   = "COALESCE(t.titulo_key, '')";
        $tituloNumA   = "COALESCE(t.numero_documento, '')";
        $liquidacaoA  = "t.data_liquidacao";
        $statusTitA   = "COALESCE(t.status_titulo, '')";
        $matchMethodA = "''";
        $confidenceA  = "CASE WHEN t.titulo_key IS NOT NULL THEN 100 ELSE 0 END";
        $diferencaA   = "ABS({$baseSql} - COALESCE(t.valor_liquido, 0))";
        $statusCaseA  = "
            CASE
                WHEN (nf.pedido_id_omie IS NULL OR nf.pedido_id_omie = 0)
                    THEN CASE WHEN t.titulo_key IS NULL THEN 'nf_sem_titulo' ELSE 'nf_sem_pedido' END
                WHEN t.titulo_key IS NULL THEN 'nf_sem_titulo'
                WHEN ABS({$baseSql} - COALESCE(t.valor_liquido, 0)) > 0.01 THEN 'valor_divergente'
                ELSE 'ok'
            END";
    }

    // ── Base inner UNION SQL ──────────────────────────────────────────────────
    $tenantFilterNf  = \BIomie\Support\TenantContext::sqlFilter('nf');
    $tenantFilterPed = \BIomie\Support\TenantContext::sqlFilter('p');
    $innerSql = <<<SQL
        -- Part A: Authorized commercial NFs → links to orders + titles
        SELECT
            COALESCE(nf.data_competencia, nf.data_emissao)          AS nf_emissao,
            COALESCE(p.pedido_id_omie, 0)                           AS pedido_id_omie,
            COALESCE(p.codigo_pedido, nf.codigo_pedido_origem, '')   AS codigo_pedido,
            nf.nota_key,
            COALESCE(nf.numero_nf, '')                               AS numero_nf,
            COALESCE(nf.serie_nf, '')                                AS serie_nf,
            {$tituloKeyA}                                            AS titulo_key,
            {$tituloNumA}                                            AS titulo_num,
            {$statusTitA}                                            AS status_titulo,
            {$proxVtoA}                                              AS proximo_vencimento,
            {$liquidacaoA}                                           AS data_liquidacao,
            nf.codigo_vendedor_omie,
            nf.nome_vendedor,
            nf.codigo_cliente_omie,
            nf.nome_cliente,
            {$baseSql}                                               AS base_comercial,
            {$qtdTitulosA}                                           AS qtd_titulos,
            {$totalFinA}                                             AS valor_titulos_total,
            {$recebidoA}                                             AS valor_titulos_recebido,
            {$abertoA}                                               AS valor_titulos_aberto,
            {$matchMethodA}                                          AS match_method,
            {$confidenceA}                                           AS confidence_score,
            {$statusCaseA}                                           AS status_conciliacao,
            {$diferencaA}                                            AS diferenca
        FROM biomie_notas_fiscais_canonicas nf
        LEFT JOIN biomie_pedidos_canonicos p
               ON p.pedido_id_omie = nf.pedido_id_omie AND nf.pedido_id_omie > 0
        {$titleJoinA}
        WHERE nf.status_fiscal   = 'autorizada'
          AND nf.operation_scope = 'commercial'
          AND {$tenantFilterNf}
          AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
          AND {$visCondNf}

        UNION ALL

        -- Part B: Orders with no authorized commercial NF (PEDIDO_SEM_NF)
        SELECT
            COALESCE(p.data_faturamento, p.data_emissao) AS nf_emissao,
            p.pedido_id_omie,
            COALESCE(p.codigo_pedido, '')                AS codigo_pedido,
            ''                                           AS nota_key,
            ''                                           AS numero_nf,
            ''                                           AS serie_nf,
            ''                                           AS titulo_key,
            ''                                           AS titulo_num,
            ''                                           AS status_titulo,
            NULL                                         AS proximo_vencimento,
            NULL                                         AS data_liquidacao,
            p.codigo_vendedor_omie,
            p.nome_vendedor,
            p.codigo_cliente_omie,
            p.nome_cliente,
            0                                            AS base_comercial,
            0                                            AS qtd_titulos,
            0                                            AS valor_titulos_total,
            0                                            AS valor_titulos_recebido,
            0                                            AS valor_titulos_aberto,
            ''                                           AS match_method,
            0                                            AS confidence_score,
            'pedido_sem_nf'                              AS status_conciliacao,
            0                                            AS diferenca
        FROM biomie_pedidos_canonicos p
        LEFT JOIN biomie_notas_fiscais_canonicas nf2
               ON nf2.pedido_id_omie = p.pedido_id_omie
              AND nf2.status_fiscal   = 'autorizada'
              AND nf2.operation_scope = 'commercial'
        WHERE nf2.pedido_id_omie IS NULL
          AND p.status_comercial NOT IN ('cancelado', 'cancelada')
          AND {$tenantFilterPed}
          AND COALESCE(p.data_faturamento, p.data_emissao) BETWEEN :start2 AND :end2
          AND {$visCondPed}
        SQL;

    // ── Outer WHERE (on _raw aliases) ─────────────────────────────────────────
    $outerWhere = [];
    $params = [
        ':start'  => $start, ':end'  => $end,
        ':start2' => $start, ':end2' => $end,
    ];

    $searchTerm = $fQ !== '' ? $fQ : '';
    if ($searchTerm !== '') {
        $outerWhere[]   = '(nome_cliente LIKE :q OR nome_vendedor LIKE :q OR numero_nf LIKE :q OR codigo_pedido LIKE :q OR titulo_num LIKE :q)';
        $params[':q']   = '%' . $searchTerm . '%';
    }
    if ($fVendedor !== '') {
        $outerWhere[]          = 'nome_vendedor LIKE :vendedor';
        $params[':vendedor']   = '%' . $fVendedor . '%';
    }
    if ($fVendedorId !== '') {
        $outerWhere[]            = 'codigo_vendedor_omie = :vendedor_id';
        $params[':vendedor_id']  = $fVendedorId;
    }
    if ($fCliente !== '') {
        $outerWhere[]         = 'nome_cliente LIKE :cliente';
        $params[':cliente']   = '%' . $fCliente . '%';
    }
    if ($fPedido !== '') {
        $outerWhere[]       = 'codigo_pedido LIKE :pedido';
        $params[':pedido']  = '%' . $fPedido . '%';
    }
    if ($fNf !== '') {
        $outerWhere[]      = 'numero_nf LIKE :nf_num';
        $params[':nf_num'] = '%' . $fNf . '%';
    }
    if ($fTitulo !== '') {
        $outerWhere[]          = 'titulo_num LIKE :titulo_num';
        $params[':titulo_num'] = '%' . $fTitulo . '%';
    }
    if ($fStatusConc !== '') {
        $outerWhere[]           = 'status_conciliacao = :status_conc';
        $params[':status_conc'] = $fStatusConc;
    }
    if ($fStatusTit !== '') {
        $outerWhere[]          = 'status_titulo = :status_tit';
        $params[':status_tit'] = $fStatusTit;
    }
    if ($fSoDiverg) {
        $outerWhere[] = "status_conciliacao NOT IN ('ok', 'cancelado')";
    }
    if ($fMinValor !== null) {
        $outerWhere[]           = 'base_comercial >= :min_valor';
        $params[':min_valor']   = $fMinValor;
    }
    if ($fMaxValor !== null) {
        $outerWhere[]           = 'base_comercial <= :max_valor';
        $params[':max_valor']   = $fMaxValor;
    }
    if ($fMinDif !== null) {
        $outerWhere[]        = 'diferenca >= :min_dif';
        $params[':min_dif']  = $fMinDif;
    }
    if ($fMaxDif !== null) {
        $outerWhere[]        = 'diferenca <= :max_dif';
        $params[':max_dif']  = $fMaxDif;
    }

    $whereStr = $outerWhere ? 'WHERE ' . implode(' AND ', $outerWhere) : '';

    $mainSql = "SELECT * FROM ({$innerSql}) _raw {$whereStr}";

    // ── COUNT ─────────────────────────────────────────────────────────────────
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ({$mainSql}) _cnt");
    foreach ($params as $k => $v) {
        $countStmt->bindValue($k, $v);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();

    $pagination = new Pagination($total, $page, $perPage);

    // ── Data page ─────────────────────────────────────────────────────────────
    $dataStmt = $pdo->prepare("{$mainSql} ORDER BY {$sortExpr} {$sortDir} LIMIT :limit OFFSET :offset");
    $dataStmt->bindValue(':limit',  $pagination->perPage, \PDO::PARAM_INT);
    $dataStmt->bindValue(':offset', $pagination->offset,  \PDO::PARAM_INT);
    foreach ($params as $k => $v) {
        $dataStmt->bindValue($k, $v);
    }
    $dataStmt->execute();
    $rows = $dataStmt->fetchAll(\PDO::FETCH_ASSOC);

    // ── Summary aggregate ─────────────────────────────────────────────────────
    $sumStmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total_registros,
            COUNT(CASE WHEN status_conciliacao = 'ok'                    THEN 1 END) AS ok_count,
            COUNT(CASE WHEN status_conciliacao NOT IN ('ok','cancelado')  THEN 1 END) AS divergentes,
            COUNT(CASE WHEN status_conciliacao = 'pedido_sem_nf'          THEN 1 END) AS pedido_sem_nf,
            COUNT(CASE WHEN status_conciliacao = 'nf_sem_pedido'          THEN 1 END) AS nf_sem_pedido,
            COUNT(CASE WHEN status_conciliacao = 'nf_sem_titulo'          THEN 1 END) AS nf_sem_titulo,
            COUNT(CASE WHEN status_conciliacao = 'titulo_sem_nf'          THEN 1 END) AS titulo_sem_nf,
            COUNT(CASE WHEN status_conciliacao = 'valor_divergente'       THEN 1 END) AS valor_divergente,
            COUNT(CASE WHEN status_conciliacao = 'titulo_baixa_confianca' THEN 1 END) AS titulo_baixa_confianca,
            COUNT(CASE WHEN qtd_titulos > 1                               THEN 1 END) AS parcelado,
            COALESCE(SUM(CASE WHEN status_conciliacao = 'valor_divergente' THEN diferenca ELSE 0 END), 0) AS valor_total_divergente
         FROM ({$mainSql}) _sum"
    );
    foreach ($params as $k => $v) {
        $sumStmt->bindValue($k, $v);
    }
    $sumStmt->execute();
    $sumRow = $sumStmt->fetch(\PDO::FETCH_ASSOC) ?: [];

    $queryMs = round((microtime(true) - $t0) * 1000, 1);

    Http::json([
        'ok'   => true,
        'data' => array_map(static fn (array $r): array => [
            'nf_emissao'              => (string) ($r['nf_emissao']             ?? ''),
            'pedido_id'               => (int)    ($r['pedido_id_omie']         ?? 0),
            'pedido'                  => (string) ($r['codigo_pedido']          ?? ''),
            'nota_key'                => (string) ($r['nota_key']               ?? ''),
            'nf'                      => (string) ($r['numero_nf']              ?? ''),
            'serie'                   => (string) ($r['serie_nf']               ?? ''),
            'titulo_key'              => (string) ($r['titulo_key']             ?? ''),
            'titulo_num'              => (string) ($r['titulo_num']             ?? ''),
            'status_titulo'           => (string) ($r['status_titulo']          ?? ''),
            'proximo_vencimento'      => (string) ($r['proximo_vencimento']     ?? ''),
            'liquidacao'              => (string) ($r['data_liquidacao']        ?? ''),
            'vendedor_id'             => (string) ($r['codigo_vendedor_omie']   ?? ''),
            'vendedor'                => (string) ($r['nome_vendedor']          ?? ''),
            'cliente_id'              => (string) ($r['codigo_cliente_omie']    ?? ''),
            'cliente'                 => (string) ($r['nome_cliente']           ?? ''),
            'base_comercial'          => round((float) ($r['base_comercial']          ?? 0), 2),
            'qtd_titulos'             => (int)    ($r['qtd_titulos']            ?? 0),
            'valor_titulos_total'     => round((float) ($r['valor_titulos_total']     ?? 0), 2),
            'valor_titulos_recebido'  => round((float) ($r['valor_titulos_recebido']  ?? 0), 2),
            'valor_titulos_aberto'    => round((float) ($r['valor_titulos_aberto']    ?? 0), 2),
            'diferenca'               => round((float) ($r['diferenca']               ?? 0), 2),
            'match_method'            => (string) ($r['match_method']           ?? ''),
            'confidence_score'        => (int)    ($r['confidence_score']       ?? 0),
            'status_conciliacao'      => (string) ($r['status_conciliacao']     ?? ''),
        ], $rows),
        'summary' => [
            'total_registros'         => (int)   ($sumRow['total_registros']          ?? 0),
            'ok'                      => (int)   ($sumRow['ok_count']                 ?? 0),
            'divergentes'             => (int)   ($sumRow['divergentes']              ?? 0),
            'pedido_sem_nf'           => (int)   ($sumRow['pedido_sem_nf']            ?? 0),
            'nf_sem_pedido'           => (int)   ($sumRow['nf_sem_pedido']            ?? 0),
            'nf_sem_titulo'           => (int)   ($sumRow['nf_sem_titulo']            ?? 0),
            'titulo_sem_nf'           => (int)   ($sumRow['titulo_sem_nf']            ?? 0),
            'valor_divergente'        => (int)   ($sumRow['valor_divergente']         ?? 0),
            'titulo_baixa_confianca'  => (int)   ($sumRow['titulo_baixa_confianca']   ?? 0),
            'parcelado'               => (int)   ($sumRow['parcelado']                ?? 0),
            'valor_total_divergente'  => round((float) ($sumRow['valor_total_divergente'] ?? 0), 2),
        ],
        'pagination'  => $pagination->toArray(),
        'filters'     => [
            'q'                    => $searchTerm,
            'vendedor'             => $fVendedor,
            'vendedor_id'          => $fVendedorId,
            'cliente'              => $fCliente,
            'pedido'               => $fPedido,
            'nf'                   => $fNf,
            'titulo'               => $fTitulo,
            'status_conciliacao'   => $fStatusConc,
            'status_titulo'        => $fStatusTit,
            'somente_divergencias' => $fSoDiverg,
            'start'                => $start,
            'end'                  => $end,
        ],
        'sort'        => ['field' => $rawSort, 'direction' => $sortDir],
        'diagnostics' => [
            'query_ms'       => $queryMs,
            'summary_active' => $summaryExists,
            'bridge_active'  => $bridgeExists,
            'source'         => 'biomie_notas_fiscais_canonicas + biomie_pedidos_canonicos + biomie_titulos_financeiros_canonicos',
            'base'           => 'Base Comercial NF',
            'visibility'     => 'BiVisibilityScope',
        ],
    ]);

} catch (\InvalidArgumentException $e) {
    Http::json([
        'ok'    => false,
        'error' => ['code' => 'RECONCILIATION_SEARCH_FAILED', 'message' => $e->getMessage(), 'details' => null],
    ], 400);
} catch (\Throwable $e) {
    $logger->warning('reconciliation_search.php failed', ['message' => $e->getMessage()]);
    Http::json([
        'ok'    => false,
        'error' => [
            'code'    => 'RECONCILIATION_SEARCH_FAILED',
            'message' => 'Não foi possível atualizar a conciliação agora.',
            'details' => null,
        ],
    ], 500);
}
