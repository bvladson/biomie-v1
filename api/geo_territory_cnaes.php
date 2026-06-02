<?php

declare(strict_types=1);

/**
 * api/geo_territory_cnaes.php
 *
 * GEO.R1.9 — CNAEs de um território específico (corrige o bug de CNAEs vazios no modo composto).
 *
 * O bug: api/sales_by_cnae.php lê $_GET['city'] mas currentFilters() no modo composto
 * só emite 'layer=mapa_composto_ceara', nunca 'city='. Este endpoint lê 'cidade_key'
 * diretamente para contornar essa divergência.
 *
 * Parâmetros:
 *   territory_type, cidade_key, bairro_key, date_from, date_to, scope, vendor
 *   territory_type=sem_geo lista registros com cidade/bairro ausentes.
 *
 * Fonte: mart_compradores_por_geo (com cnae_codigo) + fallback canonical
 */

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\Support\MapFilterSupport;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('geo', true);

try {
    $f         = MapFilterSupport::fromRequest($_GET);
    $cidadeKey = strtolower(trim((string) ($_GET['cidade_key']    ?? '')));
    $bairroKey = strtolower(trim((string) ($_GET['bairro_key']    ?? '')));
    $ttype     = trim((string) ($_GET['territory_type'] ?? ''));
    $isNoGeo   = in_array($ttype, ['sem_geo', 'sem_bairro'], true) || $bairroKey === '__sem_bairro__';
    $filterCity = strtolower(trim((string) ($_GET['filter_city'] ?? '')));

    if ($cidadeKey === '' && !$isNoGeo) {
        Http::json(['ok' => false, 'error' => 'missing_cidade_key'], 400);
        return;
    }

    $connectionData = Database::getGeoConnection($config);
    $pdo = $connectionData[0];

    $martTable = 'mart_compradores_por_geo';
    if (!Http::tableExists($pdo, $martTable)) {
        Http::json(['ok' => true, 'items' => [], 'empty_reason' => 'mart_compradores_por_geo não materializado']);
        return;
    }

    $hasCnaeMart = Http::columnExists($pdo, $martTable, 'cnae_codigo');
    $hasDimFast  = Http::tableExists($pdo, 'biomie_clientes_dim');
    $dimJoinFast = $hasDimFast
        ? 'LEFT JOIN biomie_clientes_dim dim ON dim.codigo_cliente_omie = m.codigo_cliente_omie'
        : '';
    $hasCnaeDim = Http::tableExists($pdo, 'biomie_cnae_dim');
    $martCnaeExpr = $hasCnaeMart ? "NULLIF(TRIM(m.cnae_codigo), '')" : 'NULL';
    $martDescExpr = Http::columnExists($pdo, $martTable, 'cnae_descricao') ? "NULLIF(NULLIF(TRIM(m.cnae_descricao), ''), 'CNAE não informado')" : 'NULL';
    $martSegExpr  = Http::columnExists($pdo, $martTable, 'segmento_macro') ? "NULLIF(TRIM(m.segmento_macro), '')" : 'NULL';
    $dimCnaeExpr = $hasDimFast ? "NULLIF(TRIM(dim.cnae_codigo), '')" : 'NULL';
    $dimDescExpr = $hasDimFast ? "NULLIF(NULLIF(TRIM(dim.cnae_descricao), ''), 'CNAE não informado')" : 'NULL';
    $dimSegExpr  = $hasDimFast ? "NULLIF(TRIM(dim.segmento_macro), '')" : 'NULL';
    $rawCnaeExpr = $hasDimFast
        ? "COALESCE({$martCnaeExpr}, {$dimCnaeExpr})"
        : "{$martCnaeExpr}";
    $cnaeDimJoin = $hasCnaeDim
        ? "LEFT JOIN biomie_cnae_dim cdim ON cdim.cnae_codigo_normalizado = REGEXP_REPLACE({$rawCnaeExpr}, '[^0-9]', '')"
        : '';
    $cnaeExpr = "COALESCE({$rawCnaeExpr}, 'SEM_CNAE')";
    $descParts = [];
    if ($hasCnaeDim) {
        $descParts[] = "NULLIF(TRIM(cdim.cnae_descricao), '')";
    }
    $descParts[] = $martDescExpr;
    if ($hasDimFast) {
        $descParts[] = $dimDescExpr;
    }
    $descParts[] = "'CNAE não informado'";
    $descExpr = 'COALESCE(' . implode(', ', $descParts) . ')';
    $segExpr = $hasDimFast
        ? "COALESCE({$martSegExpr}, {$dimSegExpr}, 'Outros')"
        : "COALESCE({$martSegExpr}, 'Outros')";

    $startComp = substr($f['date_from'], 0, 7);
    $endComp   = substr($f['date_to'],   0, 7);

    // ── Query via mart (fast path) ────────────────────────────────────────────
    if ($hasCnaeMart || $hasDimFast) {
        $where  = ['m.competencia BETWEEN :s AND :e'];
        $params = [':s' => $startComp, ':e' => $endComp];

        if ($isNoGeo) {
            $where[] = "(m.cidade_norm IS NULL OR TRIM(m.cidade_norm) = '' OR m.bairro_norm IS NULL OR TRIM(m.bairro_norm) = '' OR LOWER(TRIM(m.bairro_norm)) IN ('nao informado', 'sem bairro', 'sem_bairro'))";
            if ($filterCity !== '') {
                $where[] = "(LOWER(TRIM(m.cidade_norm)) = :filter_city OR m.cidade_norm IS NULL OR TRIM(m.cidade_norm) = '')";
                $params[':filter_city'] = $filterCity;
            }
        } elseif ($ttype === 'fortaleza_bairro' && $bairroKey !== '') {
            $where[] = "LOWER(TRIM(m.cidade_norm)) = 'fortaleza'";
            $where[] = 'm.bairro_norm COLLATE utf8mb4_general_ci = :bairro';
            $params[':bairro'] = $bairroKey;
        } elseif ($ttype === 'metro_bairro' && $bairroKey !== '') {
            $where[] = 'LOWER(TRIM(m.cidade_norm)) = :cidade';
            $where[] = 'm.bairro_norm COLLATE utf8mb4_general_ci = :bairro';
            $params[':cidade'] = $cidadeKey;
            $params[':bairro'] = $bairroKey;
        } else {
            $where[] = 'LOWER(TRIM(m.cidade_norm)) = :cidade';
            $params[':cidade'] = $cidadeKey;
        }

        // Vendedor opcional
        if (!empty($f['seller_id'])) {
            $hasSeller = Http::columnExists($pdo, $martTable, 'nome_vendedor');
            if ($hasSeller) {
                $where[] = 'LOWER(m.nome_vendedor) LIKE :vendedor';
                $params[':vendedor'] = '%' . mb_strtolower(trim($f['seller_id'])) . '%';
            }
        }

        if (!empty($f['cnae'])) {
            $cnaeNorm = preg_replace('/\D+/', '', $f['cnae']);
            $cnaeWhere = ["{$cnaeExpr} = :cnae_code", "LOWER({$descExpr}) LIKE :cnae_like"];
            $params[':cnae_code'] = $f['cnae'];
            $params[':cnae_like'] = '%' . mb_strtolower($f['cnae']) . '%';
            if ($cnaeNorm !== '') {
                $cnaeWhere[] = "REGEXP_REPLACE({$cnaeExpr}, '[^0-9]', '') = :cnae_norm";
                $params[':cnae_norm'] = $cnaeNorm;
            }
            $where[] = '(' . implode(' OR ', $cnaeWhere) . ')';
        }

        $whereSql = implode(' AND ', $where);

        $stmt = $pdo->prepare("
            SELECT
                {$cnaeExpr} AS cnae_codigo,
                {$descExpr} AS cnae_descricao,
                {$segExpr} AS segmento_macro,
                COUNT(DISTINCT COALESCE(CAST(m.codigo_cliente_omie AS CHAR), m.documento, m.cliente_nome)) AS clientes,
                SUM(m.qtd_notas)                                              AS notas,
                SUM(m.faturamento)                                            AS faturamento,
                IF(SUM(m.qtd_notas) > 0, SUM(m.faturamento) / SUM(m.qtd_notas), 0) AS ticket_medio
            FROM {$martTable} m
            {$dimJoinFast}
            {$cnaeDimJoin}
            WHERE {$whereSql}
            GROUP BY {$cnaeExpr}, {$descExpr}, {$segExpr}
            ORDER BY faturamento DESC
            LIMIT 20
        ");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($rows) {
            $items = array_map(fn($r) => [
                'cnae_codigo'   => $r['cnae_codigo'],
                'cnae_descricao'=> $r['cnae_descricao'],
                'segmento_macro'=> $r['segmento_macro'],
                'clientes'      => (int)$r['clientes'],
                'notas'         => (int)$r['notas'],
                'faturamento'   => (float)$r['faturamento'],
                'ticket_medio'  => (float)$r['ticket_medio'],
                'source'        => 'mart',
            ], $rows);

            Http::json(['ok' => true, 'items' => $items, 'source' => 'mart_compradores_por_geo']);
            return;
        }

        // GEO.R1.9.2 — mart returned no CNAE rows but may have sales; add cnae_nao_informado bucket
        if ($hasCnaeMart) {
            try {
                $stmtCheck = $pdo->prepare("SELECT COALESCE(SUM(m.qtd_notas),0) AS total_notas, COALESCE(SUM(m.faturamento),0) AS total_fat FROM {$martTable} m {$dimJoinFast} {$cnaeDimJoin} WHERE {$whereSql}");
                foreach ($params as $k => $v) $stmtCheck->bindValue($k, $v);
                $stmtCheck->execute();
                $totals = $stmtCheck->fetch(\PDO::FETCH_ASSOC) ?: [];
                if ((int)($totals['total_notas'] ?? 0) > 0) {
                    Http::json(['ok' => true, 'items' => [[
                        'cnae_codigo'    => 'SEM_CNAE',
                        'cnae_descricao' => 'CNAE não informado',
                        'segmento_macro' => 'Outros',
                        'clientes'       => 0,
                        'notas'          => (int)$totals['total_notas'],
                        'faturamento'    => (float)$totals['total_fat'],
                        'ticket_medio'   => (int)$totals['total_notas'] > 0 ? (float)$totals['total_fat'] / (int)$totals['total_notas'] : 0.0,
                        'source'         => 'mart_bucket',
                    ]], 'source' => 'mart_cnae_bucket']);
                    return;
                }
            } catch (\Throwable) {}
        }
    }

    // ── Fallback: canonical NF + clientes_dim ────────────────────────────────
    $nfTable  = 'biomie_notas_fiscais_canonicas';
    $geoTable = 'biomie_clientes_geo_dim';
    $dimTable = 'biomie_clientes_dim';

    if (!Http::tableExists($pdo, $nfTable) || !Http::tableExists($pdo, $geoTable)) {
        Http::json(['ok' => true, 'items' => [], 'empty_reason' => 'Tabelas canônicas não disponíveis', 'source' => 'fallback_unavailable']);
        return;
    }

    $hasDim    = Http::tableExists($pdo, $dimTable);
    $hasValor  = Http::columnExists($pdo, $nfTable, 'valor_total') ? 'valor_total'
               : (Http::columnExists($pdo, $nfTable, 'valor_nfe') ? 'valor_nfe' : null);
    if (!$hasValor) {
        Http::json(['ok' => true, 'items' => [], 'empty_reason' => 'Coluna de valor não encontrada']);
        return;
    }

    $hasCnaeDimFallback = Http::tableExists($pdo, 'biomie_cnae_dim');
    $dimJoin  = $hasDim ? "LEFT JOIN {$dimTable} dim ON dim.codigo_cliente_omie = nf.codigo_cliente_omie" : '';
    $cnaeDimFallbackJoin = ($hasDim && $hasCnaeDimFallback)
        ? "LEFT JOIN biomie_cnae_dim cdim ON cdim.cnae_codigo_normalizado = REGEXP_REPLACE(NULLIF(TRIM(dim.cnae_codigo), ''), '[^0-9]', '')"
        : '';
    $cnaeCol  = $hasDim ? "COALESCE(NULLIF(TRIM(dim.cnae_codigo),''),'SEM_CNAE')" : "'SEM_CNAE'";
    $descCol  = $hasDim
        ? "COALESCE(" . ($hasCnaeDimFallback ? "NULLIF(TRIM(cdim.cnae_descricao), ''), " : '') . "NULLIF(NULLIF(TRIM(dim.cnae_descricao),''), 'CNAE não informado'),'CNAE não informado')"
        : "'CNAE não informado'";

    $where  = ["nf.status_fiscal = 'autorizada'", 'DATE(COALESCE(nf.data_competencia, nf.data_emissao)) BETWEEN :df AND :dt'];
    $params = [':df' => $f['date_from'], ':dt' => $f['date_to']];

    if ($isNoGeo) {
        $where[] = "(geo.cidade_norm IS NULL OR TRIM(geo.cidade_norm) = '' OR geo.bairro_norm IS NULL OR TRIM(geo.bairro_norm) = '' OR LOWER(TRIM(geo.bairro_norm)) IN ('nao informado', 'sem bairro', 'sem_bairro'))";
        if ($filterCity !== '') {
            $where[] = "(LOWER(TRIM(geo.cidade_norm)) = :filter_city OR geo.cidade_norm IS NULL OR TRIM(geo.cidade_norm) = '')";
            $params[':filter_city'] = $filterCity;
        }
    } elseif ($ttype === 'fortaleza_bairro' && $bairroKey !== '') {
        $where[] = "LOWER(TRIM(geo.cidade_norm)) = 'fortaleza'";
        $where[] = 'geo.bairro_norm COLLATE utf8mb4_general_ci = :bairro';
        $params[':bairro'] = $bairroKey;
    } elseif ($ttype === 'metro_bairro' && $bairroKey !== '') {
        $where[] = 'LOWER(TRIM(geo.cidade_norm)) = :cidade';
        $where[] = 'geo.bairro_norm COLLATE utf8mb4_general_ci = :bairro';
        $params[':cidade'] = $cidadeKey;
        $params[':bairro'] = $bairroKey;
    } else {
        $where[] = 'LOWER(TRIM(geo.cidade_norm)) = :cidade';
        $params[':cidade'] = $cidadeKey;
    }

    if (!empty($f['cnae'])) {
        $cnaeNorm = preg_replace('/\D+/', '', $f['cnae']);
        $cnaeWhere = ["{$cnaeCol} = :cnae_code", "LOWER({$descCol}) LIKE :cnae_like"];
        $params[':cnae_code'] = $f['cnae'];
        $params[':cnae_like'] = '%' . mb_strtolower($f['cnae']) . '%';
        if ($cnaeNorm !== '') {
            $cnaeWhere[] = "REGEXP_REPLACE({$cnaeCol}, '[^0-9]', '') = :cnae_norm";
            $params[':cnae_norm'] = $cnaeNorm;
        }
        $where[] = '(' . implode(' OR ', $cnaeWhere) . ')';
    }

    $whereSql = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT
            {$cnaeCol} AS cnae_codigo,
            {$descCol} AS cnae_descricao,
            COUNT(DISTINCT COALESCE(CAST(nf.codigo_cliente_omie AS CHAR), nf.documento_cliente, nf.nome_cliente)) AS clientes,
            COUNT(DISTINCT nf.id) AS notas,
            COALESCE(SUM(nf.{$hasValor}), 0) AS faturamento
        FROM {$nfTable} nf
        INNER JOIN {$geoTable} geo ON geo.codigo_cliente_omie = nf.codigo_cliente_omie
        {$dimJoin}
        {$cnaeDimFallbackJoin}
        WHERE {$whereSql}
        GROUP BY {$cnaeCol}, {$descCol}
        ORDER BY faturamento DESC
        LIMIT 20
    ");
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $items = array_map(fn($r) => [
        'cnae_codigo'    => $r['cnae_codigo'],
        'cnae_descricao' => $r['cnae_descricao'],
        'segmento_macro' => null,
        'clientes'       => (int)$r['clientes'],
        'notas'          => (int)$r['notas'],
        'faturamento'    => (float)$r['faturamento'],
        'ticket_medio'   => (int)$r['notas'] > 0 ? (float)$r['faturamento'] / (int)$r['notas'] : 0.0,
        'source'         => 'canonical',
    ], $rows);

    Http::json(['ok' => true, 'items' => $items, 'source' => 'canonical_fallback']);

} catch (\Throwable $e) {
    Http::jsonError('geo_territory_cnaes_error', $e->getMessage());
}
