<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\Filters\FilterNormalizer;
use BIomie\Support\Filters\Pagination;
use BIomie\Support\Filters\Presets\FinanceCollectionFilters;
use BIomie\Support\Filters\QueryStringMapper;
use BIomie\Support\FinanceReceivableIdentityResolver;

require __DIR__ . '/bootstrap.php';

Auth::requireCapability('finance.view', true);

/**
 * @param array<string,mixed> $params
 */
function bindCollectionParams(PDOStatement $stmt, array $params): void
{
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
}

function collectionLike(string $value): string
{
    return '%' . str_replace(['%', '_'], ['\\%', '\\_'], $value) . '%';
}

function collectionBucketForRow(?string $dueDate, string $ref): string
{
    if ($dueDate === null || $dueDate === '') {
        return 'sem_data';
    }

    if ($dueDate > $ref) {
        return 'a_vencer';
    }

    if ($dueDate === $ref) {
        return 'vence_hoje';
    }

    $due = DateTimeImmutable::createFromFormat('Y-m-d', $dueDate);
    $today = DateTimeImmutable::createFromFormat('Y-m-d', $ref);
    if (!$due || !$today) {
        return 'sem_data';
    }

    $days = (int) $due->diff($today)->days;
    if ($days <= 7) {
        return '1_7';
    }
    if ($days <= 15) {
        return '8_15';
    }
    if ($days <= 30) {
        return '16_30';
    }
    if ($days <= 60) {
        return '31_60';
    }

    return '60_plus';
}

function collectionAction(array $row): string
{
    if ((int) ($row['is_vendor_missing'] ?? 0) === 1) {
        return 'Revisar vinculo financeiro';
    }

    $status = strtolower((string) ($row['status'] ?? ''));
    if (in_array($status, ['liquidado', 'recebido', 'pago'], true) && (float) ($row['valor_aberto'] ?? 0) > 0) {
        return 'Validar baixa no caixa';
    }

    $days = (int) ($row['dias_atraso'] ?? 0);
    if ($days > 0 || ($row['vencimento'] ?? null) === date('Y-m-d')) {
        return 'Cobrar hoje';
    }

    return 'Acompanhar vencimento';
}

function collectionOriginRef(array $row): string
{
    $parts = [];
    $nota = trim((string) ($row['nota_key'] ?? ''));
    $origin = trim((string) ($row['documento_origem_ref'] ?? ''));
    $pedido = trim((string) ($row['codigo_pedido_origem'] ?? ''));
    $pedidoId = trim((string) ($row['pedido_id_omie'] ?? ''));

    if ($nota !== '') {
        $parts[] = 'NF ' . $nota;
    }
    if ($origin !== '' && !in_array($origin, $parts, true)) {
        $parts[] = $origin;
    }
    if ($pedido !== '') {
        $parts[] = 'Pedido ' . $pedido;
    } elseif ($pedidoId !== '' && $pedidoId !== '0') {
        $parts[] = 'Pedido ' . $pedidoId;
    }

    return implode(' | ', array_values(array_unique($parts)));
}

try {
    $today = date('Y-m-d');
    $mapper = new QueryStringMapper(100);
    $state = $mapper->map($_GET);

    $start = $state->start;
    $end = $state->end;
    if ($start === '' || $end === '') {
        Http::json([
            'ok' => false,
            'error' => ['code' => 'MISSING_PERIOD', 'message' => 'start e end sao obrigatorios.', 'details' => null],
        ], 400);
    }

    Http::validateDateRange($start, $end);

    $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $conn)) {
        Http::json([
            'ok' => false,
            'error' => ['code' => 'DB_UNAVAILABLE', 'message' => 'Banco de dados nao configurado.', 'details' => null],
        ], 503);
    }

    $pdo = Database::connect($config, $conn);
    if (!Http::tableExists($pdo, 'biomie_titulos_financeiros_canonicos')) {
        Http::json([
            'ok' => true,
            'data' => [],
            'ranking' => [],
            'aging' => [],
            'pagination' => ['page' => 1, 'per_page' => 25, 'total' => 0, 'filtered' => 0, 'total_pages' => 1, 'has_next' => false, 'has_prev' => false],
            'kpis' => [
                'total_aberto' => 0,
                'total_vencido' => 0,
                'total_a_vencer' => 0,
                'vendedores_com_pendencia' => 0,
                'clientes_com_pendencia' => 0,
                'titulos_sem_vendedor' => 0,
                'valor_sem_vendedor' => 0,
                'maior_atraso_dias' => 0,
            ],
            'diagnostics' => ['source' => 'table_missing', 'request_id' => RequestContext::id()],
        ]);
    }

    $where = [
        'data_vencimento BETWEEN :start AND :end',
        \BIomie\Support\TenantContext::sqlFilter(),
        FinanceCollectionFilters::openCondition(),
    ];
    $params = [
        'start' => $start,
        'end' => $end,
    ];

    $status = FilterNormalizer::text((string) ($_GET['status'] ?? ''), 30);
    $statusMap = FinanceCollectionFilters::statusConditions();
    if ($status !== '' && isset($statusMap[$status])) {
        $where[] = $statusMap[$status];
        if ($status === 'vencido') {
            $params['ref_status_vencido'] = $today;
        } elseif ($status === 'vence_hoje') {
            $params['ref_status_hoje'] = $today;
        } elseif ($status === 'a_vencer') {
            $params['ref_status_a_vencer'] = $today;
        }
    }

    $aging = FilterNormalizer::text((string) ($_GET['aging'] ?? ''), 30);
    $agingMap = FinanceCollectionFilters::agingConditions();
    if ($aging !== '' && isset($agingMap[$aging])) {
        $where[] = $agingMap[$aging];
        if ($aging === 'a_vencer') {
            $params['ref_aging_a_vencer'] = $today;
        } elseif ($aging === 'vence_hoje') {
            $params['ref_aging_hoje'] = $today;
        } else {
            $params['ref_aging_' . $aging] = $today;
        }
    }

    $onlyOverdue = FilterNormalizer::boolean($_GET['only_overdue'] ?? null);
    if ($onlyOverdue === true) {
        $where[] = 'data_vencimento < :ref_only_overdue';
        $params['ref_only_overdue'] = $today;
    }

    $onlyNoVendor = FilterNormalizer::boolean($_GET['only_no_vendor'] ?? null);
    if ($onlyNoVendor === true) {
        $where[] = FinanceCollectionFilters::vendorMissingCondition();
    }

    if ($state->hasQ()) {
        $qLike = collectionLike($state->q);
        $qColumns = [
            'nome_cliente',
            'documento_cliente',
            'numero_documento',
            'nome_vendedor',
            'titulo_key',
            'CAST(titulo_id_omie AS CHAR)',
            "COALESCE(nota_key, '')",
            "COALESCE(documento_origem_ref, '')",
            "COALESCE(codigo_pedido_origem, '')",
            'CAST(COALESCE(pedido_id_omie, 0) AS CHAR)',
        ];
        $qParts = [];
        foreach ($qColumns as $idx => $column) {
            $key = 'q_like_' . $idx;
            $params[$key] = $qLike;
            $qParts[] = $column . ' LIKE :' . $key;
        }
        $where[] = '(' . implode(' OR ', $qParts) . ')';
    }

    if ($state->hasFilter('vendedor')) {
        $vendor = FilterNormalizer::text((string) $state->filterVal('vendedor'), 120);
        if ($vendor !== '') {
            $params['vendor_like'] = collectionLike($vendor);
            $where[] = FinanceCollectionFilters::vendorNameExpression() . ' LIKE :vendor_like';
        }
    }

    if ($state->hasFilter('cliente')) {
        $client = FilterNormalizer::text((string) $state->filterVal('cliente'), 120);
        if ($client !== '') {
            $params['client_name_like'] = collectionLike($client);
            $params['client_doc_like'] = collectionLike($client);
            $where[] = '(nome_cliente LIKE :client_name_like OR documento_cliente LIKE :client_doc_like)';
        }
    }

    $valueMin = FilterNormalizer::money((string) ($_GET['valor_min'] ?? ''));
    $valueMax = FilterNormalizer::money((string) ($_GET['valor_max'] ?? ''));
    if ($valueMin !== null) {
        $params['value_min'] = $valueMin;
        $where[] = 'COALESCE(valor_aberto, 0) >= :value_min';
    }
    if ($valueMax !== null) {
        $params['value_max'] = $valueMax;
        $where[] = 'COALESCE(valor_aberto, 0) <= :value_max';
    }

    $whereClause = implode(' AND ', $where);
    $vendorNameExpr = FinanceCollectionFilters::vendorNameExpression();
    $vendorKeyExpr = FinanceCollectionFilters::vendorKeyExpression();
    $vendorMissingExpr = FinanceCollectionFilters::vendorMissingCondition();
    $sortWhitelist = FinanceCollectionFilters::sortWhitelist();
    $sortField = $state->sortField !== '' ? $state->sortField : 'atraso';
    if (!$sortWhitelist->contains($sortField)) {
        $sortField = 'atraso';
    }
    $sortExpr = $sortWhitelist->resolve($sortField);
    $sortDir = strtoupper($state->sortDir) === 'ASC' ? 'ASC' : 'DESC';
    if ($sortField === 'atraso') {
        $sortDir = $sortDir === 'DESC' ? 'ASC' : 'DESC';
    }

    $t0 = hrtime(true);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos WHERE {$whereClause}");
    bindCollectionParams($countStmt, $params);
    $countStmt->execute();
    $total = (int) ($countStmt->fetchColumn() ?: 0);

    $pagination = new Pagination($total, $state->page, max(1, min(100, $state->perPage ?: 25)));

    $summarySql = <<<SQL
        SELECT
            COALESCE(SUM(COALESCE(valor_aberto, 0)), 0) AS total_aberto,
            COALESCE(SUM(CASE WHEN data_vencimento < :ref_summary_overdue THEN COALESCE(valor_aberto, 0) ELSE 0 END), 0) AS total_vencido,
            COALESCE(SUM(CASE WHEN data_vencimento >= :ref_summary_due THEN COALESCE(valor_aberto, 0) ELSE 0 END), 0) AS total_a_vencer,
            COUNT(*) AS qtd_titulos,
            COUNT(CASE WHEN data_vencimento < :ref_summary_overdue_count THEN 1 END) AS qtd_vencidos,
            COUNT(DISTINCT CASE WHEN NOT {$vendorMissingExpr} THEN {$vendorKeyExpr} END) AS vendedores_com_pendencia,
            COUNT(DISTINCT CONCAT(company_id, ':', COALESCE(NULLIF(CAST(codigo_cliente_omie AS CHAR), ''), NULLIF(documento_cliente, ''), NULLIF(nome_cliente, ''), titulo_key))) AS clientes_com_pendencia,
            COUNT(CASE WHEN {$vendorMissingExpr} THEN 1 END) AS titulos_sem_vendedor,
            COALESCE(SUM(CASE WHEN {$vendorMissingExpr} THEN COALESCE(valor_aberto, 0) ELSE 0 END), 0) AS valor_sem_vendedor,
            COALESCE(MAX(CASE WHEN data_vencimento < :ref_summary_delay THEN DATEDIFF(:ref_summary_delay2, data_vencimento) ELSE 0 END), 0) AS maior_atraso_dias
        FROM biomie_titulos_financeiros_canonicos
        WHERE {$whereClause}
    SQL;
    $summaryParams = $params + [
        'ref_summary_overdue' => $today,
        'ref_summary_due' => $today,
        'ref_summary_overdue_count' => $today,
        'ref_summary_delay' => $today,
        'ref_summary_delay2' => $today,
    ];
    $summaryStmt = $pdo->prepare($summarySql);
    bindCollectionParams($summaryStmt, $summaryParams);
    $summaryStmt->execute();
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $rankingSql = <<<SQL
        SELECT
            {$vendorKeyExpr} AS vendor_key,
            {$vendorNameExpr} AS nome_vendedor,
            CASE WHEN {$vendorMissingExpr} THEN 1 ELSE 0 END AS sem_vendedor,
            COALESCE(SUM(COALESCE(valor_aberto, 0)), 0) AS valor_aberto,
            COALESCE(SUM(CASE WHEN data_vencimento < :ref_rank_overdue THEN COALESCE(valor_aberto, 0) ELSE 0 END), 0) AS valor_vencido,
            COALESCE(SUM(CASE WHEN data_vencimento >= :ref_rank_due THEN COALESCE(valor_aberto, 0) ELSE 0 END), 0) AS valor_a_vencer,
            COUNT(*) AS qtd_titulos,
            COUNT(CASE WHEN data_vencimento < :ref_rank_overdue_count THEN 1 END) AS qtd_vencidos,
            COUNT(DISTINCT CONCAT(company_id, ':', COALESCE(NULLIF(CAST(codigo_cliente_omie AS CHAR), ''), NULLIF(documento_cliente, ''), NULLIF(nome_cliente, ''), titulo_key))) AS qtd_clientes,
            COALESCE(MAX(CASE WHEN data_vencimento < :ref_rank_delay THEN DATEDIFF(:ref_rank_delay2, data_vencimento) ELSE 0 END), 0) AS maior_atraso,
            COALESCE(AVG(COALESCE(valor_aberto, 0)), 0) AS ticket_medio_aberto
        FROM biomie_titulos_financeiros_canonicos
        WHERE {$whereClause}
        GROUP BY vendor_key, nome_vendedor, sem_vendedor
        ORDER BY valor_vencido DESC, valor_aberto DESC, nome_vendedor ASC
        LIMIT 100
    SQL;
    $rankingParams = $params + [
        'ref_rank_overdue' => $today,
        'ref_rank_due' => $today,
        'ref_rank_overdue_count' => $today,
        'ref_rank_delay' => $today,
        'ref_rank_delay2' => $today,
    ];
    $rankingStmt = $pdo->prepare($rankingSql);
    bindCollectionParams($rankingStmt, $rankingParams);
    $rankingStmt->execute();
    $rankingRows = $rankingStmt->fetchAll(PDO::FETCH_ASSOC);

    $bucketExpr = <<<SQL
        CASE
            WHEN data_vencimento IS NULL THEN 'sem_data'
            WHEN data_vencimento > :ref_bucket_due THEN 'a_vencer'
            WHEN data_vencimento = :ref_bucket_today THEN 'vence_hoje'
            WHEN DATEDIFF(:ref_bucket_1_7, data_vencimento) BETWEEN 1 AND 7 THEN '1_7'
            WHEN DATEDIFF(:ref_bucket_8_15, data_vencimento) BETWEEN 8 AND 15 THEN '8_15'
            WHEN DATEDIFF(:ref_bucket_16_30, data_vencimento) BETWEEN 16 AND 30 THEN '16_30'
            WHEN DATEDIFF(:ref_bucket_31_60, data_vencimento) BETWEEN 31 AND 60 THEN '31_60'
            ELSE '60_plus'
        END
    SQL;
    $agingSql = <<<SQL
        SELECT
            {$bucketExpr} AS bucket,
            COUNT(*) AS qtd_titulos,
            COALESCE(SUM(COALESCE(valor_aberto, 0)), 0) AS valor_aberto
        FROM biomie_titulos_financeiros_canonicos
        WHERE {$whereClause}
        GROUP BY bucket
    SQL;
    $agingParams = $params + [
        'ref_bucket_due' => $today,
        'ref_bucket_today' => $today,
        'ref_bucket_1_7' => $today,
        'ref_bucket_8_15' => $today,
        'ref_bucket_16_30' => $today,
        'ref_bucket_31_60' => $today,
    ];
    $agingStmt = $pdo->prepare($agingSql);
    bindCollectionParams($agingStmt, $agingParams);
    $agingStmt->execute();
    $agingRows = $agingStmt->fetchAll(PDO::FETCH_ASSOC);

    $dataSql = <<<SQL
        SELECT
            company_id,
            titulo_key,
            titulo_id_omie,
            numero_documento,
            especie_documento,
            forma_pagamento,
            codigo_cliente_omie,
            nome_cliente,
            documento_cliente,
            codigo_vendedor_omie,
            {$vendorNameExpr} AS vendedor_nome,
            CASE WHEN {$vendorMissingExpr} THEN 1 ELSE 0 END AS is_vendor_missing,
            nota_key,
            COALESCE(chave_nfe, '') AS chave_nfe,
            documento_origem_tipo,
            documento_origem_ref,
            codigo_pedido_origem,
            pedido_id_omie,
            data_emissao,
            data_vencimento,
            data_competencia,
            status_titulo,
            valor_aberto,
            valor_pago,
            valor_liquido,
            CASE WHEN data_vencimento < :ref_data_delay THEN DATEDIFF(:ref_data_delay2, data_vencimento) ELSE 0 END AS dias_atraso
        FROM biomie_titulos_financeiros_canonicos
        WHERE {$whereClause}
        ORDER BY {$sortExpr} {$sortDir}, COALESCE(valor_aberto, 0) DESC, nome_cliente ASC
        LIMIT :per_page OFFSET :offset
    SQL;
    $dataParams = $params + [
        'ref_data_delay' => $today,
        'ref_data_delay2' => $today,
        'per_page' => $pagination->perPage,
        'offset' => $pagination->offset,
    ];
    $dataStmt = $pdo->prepare($dataSql);
    bindCollectionParams($dataStmt, $dataParams);
    $dataStmt->execute();
    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    // --- BATCH IDENTITY RESOLUTION (for current page) ---
    $identityResolver = new FinanceReceivableIdentityResolver($pdo);
    $resolvedByTituloKey = [];
    foreach ($rows as $r) {
        $tKey = (string) ($r['titulo_key'] ?? '');
        if ($tKey !== '') {
            $resolvedByTituloKey[(int) ($r['company_id'] ?? 0) . '|' . $tKey] = $identityResolver->resolveTitle($r);
        }
    }

    // Build unresolved_vendor_bucket from current page
    $unresolvedCount  = 0;
    $unresolvedAmount = 0.0;
    $unresolvedGaps   = [];
    foreach ($resolvedByTituloKey as $rid) {
        if (!($rid['vendedor']['found'] ?? false)) {
            $unresolvedCount++;
            // valor_aberto is in the original row but not in $rid — approximate via risk
            $gap = (string) ($rid['trace_quality']['gap_reason'] ?? 'gap_desconhecido');
            $unresolvedGaps[$gap] = ($unresolvedGaps[$gap] ?? 0) + 1;
        }
    }

    // --- QUERY LATEST ACTIONS FOR PAGE ---
    $latestActions = [];
    if ($rows !== []) {
        $likes = [];
        $likeParams = [];
        foreach ($rows as $idx => $r) {
            $key = ':tkey_' . $idx;
            $likes[] = "l.details_json LIKE " . $key;
            $likeParams[$key] = '%"titulo_key":"' . $r['titulo_key'] . '"%';
        }
        $companyLikes = [];
        foreach (\BIomie\Support\TenantContext::allowedCompanyIds() as $idx => $allowedCompanyId) {
            $key = ':action_company_' . $idx;
            $companyLikes[] = "l.details_json LIKE {$key}";
            $likeParams[$key] = '%"company_id":' . $allowedCompanyId . '%';
        }
        
        $actionsQuery = "
            SELECT l.id, l.created_at, l.details_json, u.username, u.full_name
            FROM biomie_admin_logs l
            LEFT JOIN biomie_users u ON u.id = l.actor_user_id
            WHERE l.event_type = 'finance.collection_action'
              AND (" . implode(" OR ", $likes) . ")
              AND (" . ($companyLikes !== [] ? implode(' OR ', $companyLikes) : '1 = 0') . ")
            ORDER BY l.id ASC
        ";
        
        $actStmt = $pdo->prepare($actionsQuery);
        $actStmt->execute($likeParams);
        $actRows = $actStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        foreach ($actRows as $act) {
            $details = json_decode((string) ($act['details_json'] ?? '{}'), true) ?: [];
            $tkey = (string) ($details['titulo_key'] ?? '');
            $actionCompanyId = (int) ($details['company_id'] ?? 0);
            if ($tkey !== '' && $actionCompanyId > 0) {
                $latestActions[$actionCompanyId . '|' . $tkey] = [
                    'id' => (int) $act['id'],
                    'created_at' => $act['created_at'],
                    'actor_username' => (string) ($act['username'] ?? 'Sistema'),
                    'actor_full_name' => (string) ($act['full_name'] ?? 'Sistema'),
                    'action_type' => (string) ($details['action_type'] ?? ''),
                    'note' => (string) ($details['note'] ?? ''),
                    'next_action_date' => (string) ($details['next_action_date'] ?? ''),
                ];
            }
        }
    }

    // --- QUERY ALL ACTIONS FOR OPEN TITLE KPIs ---
    $allLogsStmt = $pdo->query("
        SELECT created_at, details_json
        FROM biomie_admin_logs
        WHERE event_type = 'finance.collection_action'
        ORDER BY id ASC
    ");
    $allLogs = $allLogsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
    $titleActions = [];
    foreach ($allLogs as $log) {
        $details = json_decode((string) ($log['details_json'] ?? '{}'), true) ?: [];
        $tkey = (string) ($details['titulo_key'] ?? '');
        $actionCompanyId = (int) ($details['company_id'] ?? 0);
        if ($tkey !== '' && $actionCompanyId > 0 && in_array($actionCompanyId, \BIomie\Support\TenantContext::allowedCompanyIds(), true)) {
            $titleActions[$actionCompanyId . '|' . $tkey] = [
                'action_type' => (string) ($details['action_type'] ?? ''),
                'created_at' => $log['created_at'],
            ];
        }
    }

    $openTenantFilter = \BIomie\Support\TenantContext::sqlFilter();
    $openTitlesQuery = $pdo->query("
        SELECT company_id, titulo_key, status_titulo
        FROM biomie_titulos_financeiros_canonicos 
        WHERE {$openTenantFilter}
          AND " . FinanceCollectionFilters::openCondition()
    );
    $openTitles = $openTitlesQuery->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $cobradosHoje = 0;
    $pendentesSemAcao = 0;
    $promessasPagamento = 0;
    $revisoesVinculo = 0;
    $todayDate = date('Y-m-d');

    foreach ($openTitles as $ot) {
        $tkey = $ot['titulo_key'];
        $act = $titleActions[(int) ($ot['company_id'] ?? 0) . '|' . $tkey] ?? null;
        
        if ($act === null) {
            $pendentesSemAcao++;
        } else {
            $type = $act['action_type'];
            $createdAtDay = substr($act['created_at'], 0, 10);
            
            if ($type === 'cobrado_hoje' || $createdAtDay === $todayDate) {
                $cobradosHoje++;
            }
            if ($type === 'promessa_pagamento') {
                $promessasPagamento++;
            }
            if ($type === 'revisar_vinculo_financeiro') {
                $revisoesVinculo++;
            }
        }
    }

    $ranking = array_map(static function (array $row): array {
        $open = (float) ($row['valor_aberto'] ?? 0);
        $overdue = (float) ($row['valor_vencido'] ?? 0);

        return [
            'vendor_key' => (string) ($row['vendor_key'] ?? ''),
            'nome_vendedor' => (string) ($row['nome_vendedor'] ?? FinanceCollectionFilters::VENDOR_LABEL_MISSING),
            'sem_vendedor' => (int) ($row['sem_vendedor'] ?? 0) === 1,
            'valor_aberto' => round($open, 2),
            'valor_vencido' => round($overdue, 2),
            'valor_a_vencer' => round((float) ($row['valor_a_vencer'] ?? 0), 2),
            'qtd_titulos' => (int) ($row['qtd_titulos'] ?? 0),
            'qtd_vencidos' => (int) ($row['qtd_vencidos'] ?? 0),
            'qtd_clientes' => (int) ($row['qtd_clientes'] ?? 0),
            'maior_atraso' => (int) ($row['maior_atraso'] ?? 0),
            'ticket_medio_aberto' => round((float) ($row['ticket_medio_aberto'] ?? 0), 2),
            'percentual_vencido' => $open > 0 ? round(($overdue / $open) * 100, 2) : 0.0,
        ];
    }, $rankingRows);

    $bucketLabels = FinanceCollectionFilters::bucketLabels();
    $agingByBucket = [];
    foreach ($agingRows as $row) {
        $agingByBucket[(string) ($row['bucket'] ?? '')] = $row;
    }
    $agingOut = [];
    foreach ($bucketLabels as $bucket => $label) {
        $row = $agingByBucket[$bucket] ?? [];
        $agingOut[] = [
            'bucket' => $bucket,
            'label' => $label,
            'qtd_titulos' => (int) ($row['qtd_titulos'] ?? 0),
            'valor_aberto' => round((float) ($row['valor_aberto'] ?? 0), 2),
        ];
    }

    $data = array_map(static function (array $row) use ($today, $latestActions, $resolvedByTituloKey): array {
        $tKey = (string) ($row['titulo_key'] ?? '');
        $rid  = $resolvedByTituloKey[(int) ($row['company_id'] ?? 0) . '|' . $tKey] ?? null;

        $nfResolved     = $rid['nf']            ?? [];
        $pedidoResolved = $rid['pedido']         ?? [];
        $vendorResolved = $rid['vendedor']       ?? [];
        $clienteResolved= $rid['cliente']        ?? [];
        $paymentResolved= $rid['payment']        ?? [];
        $traceQuality   = $rid['trace_quality']  ?? [];

        $nfNumero       = (string) ($nfResolved['numero_nf']       ?? '');
        $nfNotaKey      = (string) ($nfResolved['nota_key']        ?? $row['nota_key'] ?? '');
        $nfChaveNfe     = (string) ($nfResolved['chave_nfe']       ?? $row['chave_nfe'] ?? '');
        $nfSource       = (string) ($nfResolved['source']          ?? 'none');
        $nfLabel        = $nfNumero !== '' ? 'NF ' . $nfNumero : ($nfNotaKey !== '' ? 'NF …' . substr($nfNotaKey, -8) : '');

        $pedidoId       = (string) ($pedidoResolved['pedido_id_omie'] ?? $row['pedido_id_omie'] ?? '');
        $pedidoLabel    = $pedidoId !== '' && $pedidoId !== '0' ? 'Ped. ' . $pedidoId : '';

        $clienteNome    = (string) ($clienteResolved['nome'] ?? $row['nome_cliente'] ?? 'Cliente nao informado');
        $clienteDoc     = (string) ($clienteResolved['documento'] ?? $row['documento_cliente'] ?? '');

        $vendorNome     = (string) ($vendorResolved['nome']               ?? $row['vendedor_nome'] ?? FinanceCollectionFilters::VENDOR_LABEL_MISSING);
        $vendorCodigo   = (string) ($vendorResolved['codigo_vendedor_omie'] ?? $row['codigo_vendedor_omie'] ?? '');
        $vendorSource   = (string) ($vendorResolved['source']             ?? 'none');
        $vendorResolvido = (bool) ($vendorResolved['found']              ?? false);

        $paymentLabel   = (string) ($paymentResolved['method_label'] ?? '');

        $traceScore     = (int) ($traceQuality['score']      ?? 0);
        $gapReason      = (string) ($traceQuality['gap_reason'] ?? '');
        $missingFields  = (array) ($traceQuality['missing']   ?? []);

        $normalized = [
            'company_id'            => (int) ($row['company_id'] ?? 0),
            'id'                    => $tKey,
            'titulo_key'            => $tKey,
            'titulo_id_omie'        => $row['titulo_id_omie'] !== null ? (int) $row['titulo_id_omie'] : null,
            'documento'             => (string) ($row['numero_documento'] ?? ''),
            'especie'               => (string) ($row['especie_documento'] ?? 'Titulo'),
            'forma_pagamento'       => (string) ($row['forma_pagamento'] ?? ''),
            // Cliente — resolver preferred
            'cliente_id'            => (string) ($row['codigo_cliente_omie'] ?? ''),
            'cliente'               => $clienteNome !== '' ? $clienteNome : 'Cliente nao informado',
            'cliente_nome_resolvido'=> $clienteNome,
            'cliente_documento'     => $clienteDoc,
            'documento_cliente'     => (string) ($row['documento_cliente'] ?? ''),
            // Vendedor — resolver preferred
            'vendedor_id'           => (string) ($row['codigo_vendedor_omie'] ?? ''),
            'vendedor'              => $vendorNome,
            'vendedor_nome_resolvido' => $vendorNome,
            'vendedor_codigo_resolvido' => $vendorCodigo,
            'vendedor_source'       => $vendorSource,
            'sem_vendedor'          => !$vendorResolvido,
            // NF identity
            'nota_key'              => (string) ($row['nota_key'] ?? ''),
            'nf_numero'             => $nfNumero,
            'nf_label'              => $nfLabel,
            'nf_nota_key'           => $nfNotaKey,
            'nf_chave_nfe'          => $nfChaveNfe,
            'nf_source'             => $nfSource,
            // Pedido identity
            'documento_origem_tipo' => (string) ($row['documento_origem_tipo'] ?? ''),
            'documento_origem_ref'  => (string) ($row['documento_origem_ref'] ?? ''),
            'codigo_pedido_origem'  => (string) ($row['codigo_pedido_origem'] ?? ''),
            'pedido_id_omie'        => $pedidoId !== '' && $pedidoId !== '0' ? $pedidoId : ($row['pedido_id_omie'] !== null ? (string)(int) $row['pedido_id_omie'] : null),
            'pedido_label'          => $pedidoLabel,
            // Payment
            'payment_method_label'  => $paymentLabel,
            // Dates and values
            'emissao'               => $row['data_emissao']    ? (string) $row['data_emissao']    : null,
            'vencimento'            => $row['data_vencimento'] ? (string) $row['data_vencimento'] : null,
            'competencia'           => $row['data_competencia']? (string) $row['data_competencia']: null,
            'status_titulo'         => (string) ($row['status_titulo'] ?? 'aberto'),
            'valor_aberto'          => round((float) ($row['valor_aberto'] ?? 0), 2),
            'valor_pago'            => round((float) ($row['valor_pago']   ?? 0), 2),
            'valor_liquido'         => round((float) ($row['valor_liquido'] ?? 0), 2),
            'dias_atraso'           => max(0, (int) ($row['dias_atraso'] ?? 0)),
            // Trace quality
            'trace_quality_score'   => $traceScore,
            'gap_reason'            => $gapReason,
            'missing_fields'        => $missingFields,
            'identity_key'          => (string) ($rid['identity_key'] ?? ''),
        ];
        $normalized['aging_bucket']    = collectionBucketForRow($normalized['vencimento'], $today);
        $normalized['nf_pedido']       = collectionOriginRef($normalized);
        $normalized['action_label']    = collectionAction([
            'is_vendor_missing' => $normalized['sem_vendedor'] ? 1 : 0,
            'status'            => $normalized['status_titulo'],
            'valor_aberto'      => $normalized['valor_aberto'],
            'dias_atraso'       => $normalized['dias_atraso'],
            'vencimento'        => $normalized['vencimento'],
        ]);
        $normalized['acao_recomendada'] = $normalized['action_label'];
        $normalized['latest_action']    = $latestActions[(int) ($row['company_id'] ?? 0) . '|' . $tKey] ?? null;

        return $normalized;
    }, $rows);

    $elapsedMs = round((hrtime(true) - $t0) / 1e6, 2);

    Http::json([
        'ok' => true,
        'period' => [
            'start' => $start,
            'end' => $end,
            'basis' => 'data_vencimento',
            'reference_date' => $today,
        ],
        'kpis' => [
            'total_aberto' => round((float) ($summary['total_aberto'] ?? 0), 2),
            'total_vencido' => round((float) ($summary['total_vencido'] ?? 0), 2),
            'total_a_vencer' => round((float) ($summary['total_a_vencer'] ?? 0), 2),
            'vendedores_com_pendencia' => (int) ($summary['vendedores_com_pendencia'] ?? 0),
            'clientes_com_pendencia' => (int) ($summary['clientes_com_pendencia'] ?? 0),
            'titulos_sem_vendedor' => (int) ($summary['titulos_sem_vendedor'] ?? 0),
            'valor_sem_vendedor' => round((float) ($summary['valor_sem_vendedor'] ?? 0), 2),
            'maior_atraso_dias' => (int) ($summary['maior_atraso_dias'] ?? 0),
            'qtd_titulos' => (int) ($summary['qtd_titulos'] ?? 0),
            'qtd_vencidos' => (int) ($summary['qtd_vencidos'] ?? 0),
            'titulos_cobrados_hoje' => $cobradosHoje,
            'pendentes_sem_acao' => $pendentesSemAcao,
            'promessas_pagamento' => $promessasPagamento,
            'revisoes_vinculo_abertas' => $revisoesVinculo,
        ],
        'ranking' => $ranking,
        'aging' => $agingOut,
        'data' => $data,
        'pagination' => $pagination->toArray(),
        'filters' => array_merge($state->toApiArray(), [
            'status' => $status !== '' ? $status : null,
            'aging' => $aging !== '' ? $aging : null,
            'valor_min' => $valueMin,
            'valor_max' => $valueMax,
            'only_overdue' => $onlyOverdue === true,
            'only_no_vendor' => $onlyNoVendor === true,
        ]),
        'sort' => ['field' => $sortField, 'direction' => strtolower($sortDir)],
        'unresolved_vendor_bucket' => $unresolvedCount > 0 ? [
            'count'           => $unresolvedCount,
            'page_total'      => count($rows),
            'top_gap_reasons' => array_slice($unresolvedGaps, 0, 5, true),
            'note'            => $unresolvedCount . ' títulos nesta página sem vendedor resolvido',
        ] : null,
        'diagnostics' => [
            'source'       => 'canonical_finance_titles',
            'resolver'     => 'FinanceReceivableIdentityResolver',
            'elapsed_ms'   => $elapsedMs,
            'request_id'   => RequestContext::id(),
        ],
    ]);
} catch (InvalidArgumentException $e) {
    Http::json([
        'ok' => false,
        'error' => ['code' => 'INVALID_PARAMS', 'message' => $e->getMessage(), 'details' => null],
    ], 400);
} catch (Throwable $e) {
    if (isset($logger)) {
        $logger->error('finance_collection_by_vendor falhou.', [
            'message' => $e->getMessage(),
            'request_id' => class_exists(RequestContext::class) ? RequestContext::id() : null,
        ]);
    }

    Http::json([
        'ok' => false,
        'error' => ['code' => 'FINANCE_COLLECTION_ERROR', 'message' => 'Nao foi possivel carregar a carteira de cobranca.', 'details' => null],
    ], 500);
}
