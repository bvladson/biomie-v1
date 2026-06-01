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

    if ($cidadeKey === '') {
        Http::json(['ok' => false, 'error' => 'missing_cidade_key'], 400);
    }

    $connectionData = Database::getGeoConnection($config);
    $pdo = $connectionData[0];

    $martTable = 'mart_compradores_por_geo';
    if (!Http::tableExists($pdo, $martTable)) {
        Http::json(['ok' => true, 'items' => [], 'empty_reason' => 'mart_compradores_por_geo não materializado']);
        return;
    }

    $hasCnaeMart = Http::columnExists($pdo, $martTable, 'cnae_codigo');

    $startComp = substr($f['date_from'], 0, 7);
    $endComp   = substr($f['date_to'],   0, 7);

    // ── Query via mart (fast path) ────────────────────────────────────────────
    if ($hasCnaeMart) {
        $where  = ['competencia BETWEEN :s AND :e'];
        $params = [':s' => $startComp, ':e' => $endComp];

        if ($ttype === 'fortaleza_bairro' && $bairroKey !== '') {
            $where[] = "LOWER(TRIM(cidade_norm)) = 'fortaleza'";
            $where[] = 'bairro_norm COLLATE utf8mb4_general_ci = :bairro';
            $params[':bairro'] = $bairroKey;
        } elseif ($ttype === 'metro_bairro' && $bairroKey !== '') {
            $where[] = 'LOWER(TRIM(cidade_norm)) = :cidade';
            $where[] = 'bairro_norm COLLATE utf8mb4_general_ci = :bairro';
            $params[':cidade'] = $cidadeKey;
            $params[':bairro'] = $bairroKey;
        } else {
            $where[] = 'LOWER(TRIM(cidade_norm)) = :cidade';
            $params[':cidade'] = $cidadeKey;
        }

        // Vendedor opcional
        if (!empty($f['seller_id'])) {
            $hasSeller = Http::columnExists($pdo, $martTable, 'nome_vendedor');
            if ($hasSeller) {
                $where[] = 'LOWER(nome_vendedor) LIKE :vendedor';
                $params[':vendedor'] = '%' . mb_strtolower(trim($f['seller_id'])) . '%';
            }
        }

        $whereSql = implode(' AND ', $where);

        $stmt = $pdo->prepare("
            SELECT
                COALESCE(NULLIF(TRIM(cnae_codigo), ''), 'SEM_CNAE')       AS cnae_codigo,
                COALESCE(NULLIF(TRIM(cnae_descricao), ''), 'CNAE não informado') AS cnae_descricao,
                COALESCE(NULLIF(TRIM(segmento_macro), ''), 'Outros')        AS segmento_macro,
                COUNT(DISTINCT COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome)) AS clientes,
                SUM(qtd_notas)                                              AS notas,
                SUM(faturamento)                                            AS faturamento,
                IF(SUM(qtd_notas) > 0, SUM(faturamento) / SUM(qtd_notas), 0) AS ticket_medio
            FROM {$martTable}
            WHERE {$whereSql}
            GROUP BY cnae_codigo, cnae_descricao, segmento_macro
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

    $dimJoin  = $hasDim ? "LEFT JOIN {$dimTable} dim ON dim.codigo_cliente_omie = nf.codigo_cliente_omie" : '';
    $cnaeCol  = $hasDim ? "COALESCE(NULLIF(TRIM(dim.cnae_codigo),''),'SEM_CNAE')" : "'SEM_CNAE'";
    $descCol  = $hasDim ? "COALESCE(NULLIF(TRIM(dim.cnae_descricao),''),'CNAE não informado')" : "'CNAE não informado'";

    $where  = ["nf.status_fiscal = 'autorizada'", 'DATE(COALESCE(nf.data_competencia, nf.data_emissao)) BETWEEN :df AND :dt'];
    $params = [':df' => $f['date_from'], ':dt' => $f['date_to']];

    if ($ttype === 'fortaleza_bairro' && $bairroKey !== '') {
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
