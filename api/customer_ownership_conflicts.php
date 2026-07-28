<?php

declare(strict_types=1);

/**
 * COM.R1.4 FASE 7 — API de Conflitos de Carteirização
 *
 * Retorna dados de venda fora da carteira por vendedor/cliente/NF.
 * Modos: summary | vendors | clients | invoices
 *
 * GET params:
 *   start, end, mode, vendor, owner, conflict_only, limit, page, sort
 */

use BIomie\Database;
use BIomie\Http;
use BIomie\Support\CustomerOwnershipResolver;

require __DIR__ . '/bootstrap.php';
\BIomie\Auth::requireCapability('sales.view', true);

try {
    $range = Http::resolveDateRange();
    $start = $range['start'];
    $end   = $range['end'];

    $mode         = Http::query('mode', 'summary');
    $vendorFilter = Http::query('vendor', '');
    $ownerFilter  = Http::query('owner', '');
    $conflictOnly = Http::boolQuery('conflict_only', false);
    $limit        = max(1, min(200, (int) Http::query('limit', '50')));
    $page         = max(1, (int) Http::query('page', '1'));
    $sortRaw      = Http::query('sort', 'valor');
    $sortAllowed  = ['valor', 'qtd_nfs', 'nome_cliente', 'sale_vendor', 'owner_vendor'];
    $sort         = in_array($sortRaw, $sortAllowed, true) ? $sortRaw : 'valor';

    $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo  = Database::connect($config, $conn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $res    = new CustomerOwnershipResolver();
    $nfDate = "COALESCE(nf.data_competencia, nf.data_emissao)";

    function tblExists2(PDO $p, string $t): bool {
        $s = $p->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t LIMIT 1");
        $s->execute([':t' => $t]);
        return (int)$s->fetchColumn() > 0;
    }

    $hasDim = tblExists2($pdo, 'biomie_clientes_dim');
    $hasNf  = tblExists2($pdo, 'biomie_notas_fiscais_canonicas');

    if (!$hasDim || !$hasNf) {
        Http::json(['ok' => true, 'mode' => $mode, 'data' => [], 'message' => 'Tabelas necessárias não encontradas.']);
    }

    // ── Build WHERE ────────────────────────────────────────────────────────────
    $tenantFilter = \BIomie\Support\TenantContext::sqlFilter('nf');
    $where  = [$tenantFilter, "{$nfDate} BETWEEN :start AND :end", "nf.status_fiscal = 'autorizada'"];
    $params = [':start' => $start, ':end' => $end];

    if ($vendorFilter !== '') {
        $where[]           = 'nf.nome_vendedor = :vendor';
        $params[':vendor'] = $vendorFilter;
    }
    if ($ownerFilter !== '') {
        $where[]           = 'cd.vendedor_responsavel_nome = :owner';
        $params[':owner']  = $ownerFilter;
    }
    // conflict_only filter: vendor ≠ owner
    if ($conflictOnly) {
        $where[] = "nf.nome_vendedor != COALESCE(cd.vendedor_responsavel_nome,'')";
        $where[] = "cd.vendedor_responsavel_nome IS NOT NULL AND cd.vendedor_responsavel_nome != '' AND cd.vendedor_responsavel_nome != 'Sem vendedor'";
    }

    $whereClause = implode(' AND ', $where);

    // ── Sort expression (whitelist already applied) ────────────────────────────
    $sortExpr = match ($sort) {
        'qtd_nfs'     => 'qtd_nfs DESC',
        'nome_cliente'=> 'nf.nome_cliente ASC',
        'sale_vendor' => 'nf.nome_vendedor ASC',
        'owner_vendor'=> 'cd.vendedor_responsavel_nome ASC',
        default       => 'valor DESC',
    };

    $offset = ($page - 1) * $limit;

    if ($mode === 'summary') {
        // ── Summary mode ───────────────────────────────────────────────────────
        $sSumm = $pdo->prepare(
            "SELECT
                 COUNT(DISTINCT nf.nota_key)                           AS total_nfs,
                 COUNT(DISTINCT nf.codigo_cliente_omie)                AS total_clientes,
                 SUM(nf.valor_mercadorias)                             AS total_valor,
                 SUM(CASE WHEN nf.nome_vendedor != COALESCE(cd.vendedor_responsavel_nome,'')
                           AND cd.vendedor_responsavel_nome IS NOT NULL
                           AND cd.vendedor_responsavel_nome != ''
                           AND cd.vendedor_responsavel_nome != 'Sem vendedor'
                          THEN 1 ELSE 0 END)                           AS conflict_nfs,
                 SUM(CASE WHEN nf.nome_vendedor != COALESCE(cd.vendedor_responsavel_nome,'')
                           AND cd.vendedor_responsavel_nome IS NOT NULL
                           AND cd.vendedor_responsavel_nome != ''
                           AND cd.vendedor_responsavel_nome != 'Sem vendedor'
                          THEN nf.valor_mercadorias ELSE 0 END)        AS conflict_valor
             FROM biomie_notas_fiscais_canonicas nf
             LEFT JOIN biomie_clientes_dim cd
               ON cd.codigo_cliente_omie = nf.codigo_cliente_omie
              AND cd.company_id = nf.company_id
             WHERE {$tenantFilter}
               AND {$nfDate} BETWEEN :start AND :end
               AND nf.status_fiscal = 'autorizada'"
        );
        $sSumm->execute([':start' => $start, ':end' => $end]);
        $sumRow = $sSumm->fetch(PDO::FETCH_ASSOC) ?: [];

        Http::json([
            'ok'   => true,
            'mode' => 'summary',
            'period' => ['start' => $start, 'end' => $end],
            'data' => [
                'total_nfs'        => (int)   ($sumRow['total_nfs']     ?? 0),
                'total_clientes'   => (int)   ($sumRow['total_clientes'] ?? 0),
                'total_valor'      => round((float)($sumRow['total_valor']    ?? 0), 2),
                'conflict_nfs'     => (int)   ($sumRow['conflict_nfs']   ?? 0),
                'conflict_valor'   => round((float)($sumRow['conflict_valor']  ?? 0), 2),
                'conflict_pct'     => (float)($sumRow['total_valor'] ?? 0) > 0
                    ? round((float)($sumRow['conflict_valor'] ?? 0) / (float)($sumRow['total_valor'] ?? 1) * 100, 1)
                    : 0.0,
                'note' => 'Conflito = vendedor da NF ≠ dono do cliente em biomie_clientes_dim. Não bloqueia venda; apenas sinaliza governança comercial.',
            ],
        ]);
    }

    // ── Invoice/detail mode (default) ─────────────────────────────────────────
    $sCount = $pdo->prepare(
        "SELECT COUNT(*)
         FROM biomie_notas_fiscais_canonicas nf
         LEFT JOIN biomie_clientes_dim cd
           ON cd.codigo_cliente_omie = nf.codigo_cliente_omie
          AND cd.company_id = nf.company_id
         WHERE {$whereClause}"
    );
    $sCount->execute($params);
    $totalCount = (int)$sCount->fetchColumn();

    $sData = $pdo->prepare(
        "SELECT nf.nota_key, nf.numero_nf, nf.pedido_id_omie,
                nf.codigo_cliente_omie, nf.nome_cliente, nf.documento_cliente,
                nf.nome_vendedor        AS sale_vendor,
                nf.valor_mercadorias    AS valor,
                {$nfDate}               AS data_nf,
                COALESCE(cd.vendedor_responsavel_nome,'')      AS owner_vendor,
                COALESCE(cd.vendedor_responsavel_confidence,0) AS owner_confidence,
                COALESCE(cd.vendedor_responsavel_origem,'')    AS owner_source
         FROM biomie_notas_fiscais_canonicas nf
         LEFT JOIN biomie_clientes_dim cd
           ON cd.codigo_cliente_omie = nf.codigo_cliente_omie
          AND cd.company_id = nf.company_id
         WHERE {$whereClause}
         ORDER BY {$sortExpr}
         LIMIT :lim OFFSET :off"
    );
    $sData->bindValue(':lim', $limit,  PDO::PARAM_INT);
    $sData->bindValue(':off', $offset, PDO::PARAM_INT);
    foreach ($params as $k => $v) $sData->bindValue($k, $v, PDO::PARAM_STR);
    $sData->execute();
    $dataRows = $sData->fetchAll(PDO::FETCH_ASSOC);

    // Resolve ownership for each row
    $enriched = array_map(function (array $r) use ($res): array {
        $ownership = $res->resolve([
            'dim_vendor_name'        => (string) ($r['owner_vendor']     ?? ''),
            'dim_vendor_confidence'  => (int)    ($r['owner_confidence']  ?? 0),
            'dim_vendor_source'      => (string) ($r['owner_source']      ?? ''),
            'sale_vendor_name'       => (string) ($r['sale_vendor']       ?? ''),
        ]);
        return [
            'nota_key'         => (string) $r['nota_key'],
            'numero_nf'        => (string) ($r['numero_nf']        ?? ''),
            'pedido_id_omie'   => $r['pedido_id_omie'],
            'cliente_id'       => $r['codigo_cliente_omie'],
            'nome_cliente'     => (string) ($r['nome_cliente']      ?? ''),
            'documento'        => (string) ($r['documento_cliente'] ?? ''),
            'sale_vendor'      => (string) ($r['sale_vendor']       ?? ''),
            'owner_vendor'     => $ownership['owner_vendor_name'],
            'owner_source'     => $ownership['owner_source'],
            'owner_confidence' => $ownership['owner_confidence'],
            'valor'            => round((float)($r['valor'] ?? 0), 2),
            'data_nf'          => (string) ($r['data_nf']           ?? ''),
            'is_out_of_portfolio' => $ownership['is_out_of_portfolio'],
            'conflict_type'    => $ownership['conflict_type'],
            'conflict_label'   => $ownership['conflict_label'],
            'display_badge'    => $ownership['display_badge'],
            'severity'         => $ownership['severity'],
        ];
    }, $dataRows);

    Http::json([
        'ok'         => true,
        'mode'       => $mode,
        'period'     => ['start' => $start, 'end' => $end],
        'pagination' => [
            'total'       => $totalCount,
            'page'        => $page,
            'per_page'    => $limit,
            'total_pages' => (int) ceil($totalCount / $limit),
        ],
        'data'       => $enriched,
    ]);

} catch (\Throwable $e) {
    $logger->error('customer_ownership_conflicts error', ['message' => $e->getMessage()]);
    Http::json(['ok' => false, 'error' => ['message' => 'Erro interno.', 'code' => 'INTERNAL_ERROR']], 500);
}
