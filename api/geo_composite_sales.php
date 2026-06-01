<?php

declare(strict_types=1);

/**
 * api/geo_composite_sales.php
 *
 * GEO.R1.7 — Endpoint de vendas para o Mapa Composto Inteligente do Ceará.
 * Retorna dados de todos os tipos territoriais em uma única requisição:
 *
 *   - fortaleza_bairro    : bairros de Fortaleza
 *   - metro_bairro        : bairros metropolitanos (Grande Fortaleza)
 *   - rmf_municipio_fallback : municípios RMF sem bairro IBGE
 *   - ceara_municipio     : demais municípios do Ceará
 *
 * Join keys:
 *   - fortaleza bairro     → bairro_norm   (ex: "aldeota")
 *   - metro bairro         → cidade:bairro (ex: "eusebio:olho_dagua")
 *   - municipality         → cidade_norm   (ex: "aquiraz")
 *
 * Uso: GET api/geo_composite_sales.php?date_from=2026-01-01&date_to=2026-05-31
 */

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\Geo\GeoKeyNormalizer;
use BIomie\Support\MapFilterSupport;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('geo', true);

try {
    $f     = MapFilterSupport::fromRequest($_GET);
    $range = ['date_from' => $f['date_from'], 'date_to' => $f['date_to']];

    $connectionData = Database::getGeoConnection($config);
    $pdo = $connectionData[0];

    if (!Http::tableExists($pdo, 'mart_compradores_por_geo')) {
        Http::jsonConflictMissingTable('mart_compradores_por_geo');
    }

    // ── Listas de municípios RMF por tipo (nomes normalizados) ────────────────
    // Municípios RMF com bairros IBGE 2022 (camada grande_fortaleza_bairros)
    $rmfWithBairros = [
        'fortaleza', 'caucaia', 'maracanau', 'maranguape', 'eusebio',
        'guaiuba', 'itaitinga', 'pacatuba', 'trairi',
    ];

    // Municípios RMF sem bairros IBGE (fallback municipal)
    $rmfFallback = [
        'aquiraz', 'horizonte', 'pacajus', 'chorozinho', 'cascavel',
        'pindoretama', 'paracuru', 'paraipaba', 'sao_goncalo_do_amarante',
        'sao_luis_do_curu',
    ];

    $rmfAll = array_merge($rmfWithBairros, $rmfFallback);

    // ── Query principal: agrega por (cidade_norm, bairro_norm) ────────────────
    // Sem filtro de cidade — retorna todo o Ceará
    $where = ['competencia BETWEEN :start_comp AND :end_comp'];
    $params = [
        'start_comp' => substr($range['date_from'], 0, 7),
        'end_comp'   => substr($range['date_to'],   0, 7),
    ];

    // Filtros opcionais
    $sellerId = $f['seller_id'];
    if ($sellerId !== null && Http::tableExists($pdo, 'mart_compradores_por_geo') &&
        Http::columnExists($pdo, 'mart_compradores_por_geo', 'codigo_vendedor_omie')) {
        $where[]                  = 'LOWER(nome_vendedor) LIKE :seller_pct';
        $params['seller_pct']     = '%' . mb_strtolower(trim($sellerId)) . '%';
    }

    $cnae = $f['cnae'];
    if ($cnae !== null) {
        $where[]       = 'cnae_codigo = :cnae';
        $params['cnae'] = $cnae;
    }

    $whereSql = implode(' AND ', $where);

    $sql = "
        SELECT
            LOWER(TRIM(COALESCE(NULLIF(cidade_norm,''), 'sem_cidade'))) AS cidade_key,
            LOWER(TRIM(COALESCE(NULLIF(bairro_norm,''), '')))           AS bairro_raw,
            SUM(faturamento)                                             AS revenue,
            COUNT(DISTINCT COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome)) AS customers,
            SUM(qtd_notas)                                               AS invoices,
            IF(SUM(qtd_notas)>0, SUM(faturamento)/SUM(qtd_notas), 0)   AS ticket_medio,
            MAX(refreshed_at)                                            AS refreshed_at
        FROM mart_compradores_por_geo
        WHERE {$whereSql}
        GROUP BY LOWER(TRIM(cidade_norm)), LOWER(TRIM(bairro_norm))
        ORDER BY revenue DESC
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue(':' . $k, $v);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    // ── Classifica e indexa por join_key ──────────────────────────────────────
    $territories  = [];
    $totalRevenue = 0.0;
    $totalCustomers = 0;
    $totalInvoices  = 0;
    $semBairro      = 0;

    // Agrega receita total por município (para revenue do polígono municipal)
    $muniRevenue = [];

    foreach ($rows as $row) {
        $cidadeKey  = (string)($row['cidade_key'] ?? '');
        $bairroRaw  = (string)($row['bairro_raw'] ?? '');
        $revenue    = (float)($row['revenue'] ?? 0);
        $customers  = (int)($row['customers'] ?? 0);
        $invoices   = (int)($row['invoices'] ?? 0);
        $ticket     = (float)($row['ticket_medio'] ?? 0);

        $totalRevenue   += $revenue;
        $totalCustomers += $customers;
        $totalInvoices  += $invoices;

        if ($bairroRaw === '') {
            $semBairro += $invoices;
        }

        // Classifica tipo territorial
        if ($cidadeKey === 'fortaleza' && $bairroRaw !== '') {
            // Bairro de Fortaleza
            $type    = 'fortaleza_bairro';
            $joinKey = $bairroRaw;
        } elseif (in_array($cidadeKey, $rmfWithBairros, true) && $bairroRaw !== '') {
            // Bairro metropolitano
            $type    = 'metro_bairro';
            $joinKey = $cidadeKey . ':' . $bairroRaw;
        } else {
            // Município — determinar subtipo RMF vs CE
            $type    = in_array($cidadeKey, $rmfFallback, true)
                       ? 'rmf_municipio_fallback'
                       : (in_array($cidadeKey, $rmfAll, true) ? 'rmf_municipio_fallback' : 'ceara_municipio');
            $joinKey = $cidadeKey;

            // Acumula receita total do município (para polígono municipal)
            $muniRevenue[$cidadeKey] = ($muniRevenue[$cidadeKey] ?? 0.0) + $revenue;
        }

        if (isset($territories[$joinKey])) {
            // Agrupa linhas com mesmo join_key
            $territories[$joinKey]['revenue']   += $revenue;
            $territories[$joinKey]['customers'] += $customers;
            $territories[$joinKey]['invoices']  += $invoices;
            if ($territories[$joinKey]['invoices'] > 0) {
                $territories[$joinKey]['ticket_medio'] =
                    $territories[$joinKey]['revenue'] / $territories[$joinKey]['invoices'];
            }
        } else {
            $territories[$joinKey] = [
                'join_key'       => $joinKey,
                'territory_type' => $type,
                'cidade_key'     => $cidadeKey,
                'bairro_norm'    => $bairroRaw ?: null,
                'revenue'        => $revenue,
                'customers'      => $customers,
                'invoices'       => $invoices,
                'ticket_medio'   => $ticket,
                'refreshed_at'   => $row['refreshed_at'] ?? null,
            ];
        }
    }

    // Para municípios (polígono): usar receita agregada total da cidade
    foreach ($muniRevenue as $cidadeKey => $totalMuniRev) {
        if (isset($territories[$cidadeKey])) {
            $territories[$cidadeKey]['revenue'] = $totalMuniRev;
        }
    }

    // ── Escalas por tipo territorial (para coloração proporcional) ────────────
    $scales = [
        'fortaleza_bairro'        => ['max' => 0.0],
        'metro_bairro'            => ['max' => 0.0],
        'rmf_municipio_fallback'  => ['max' => 0.0],
        'ceara_municipio'         => ['max' => 0.0],
    ];
    foreach ($territories as $t) {
        $type = $t['territory_type'] ?? '';
        $rev  = (float)($t['revenue'] ?? 0);
        if (isset($scales[$type]) && $rev > $scales[$type]['max']) {
            $scales[$type]['max'] = $rev;
        }
    }

    // ── Ranking: top 50 territórios com venda ─────────────────────────────────
    $ranking = array_values(array_filter($territories, fn($t) => ($t['revenue'] ?? 0) > 0));
    usort($ranking, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
    $ranking = array_slice($ranking, 0, 50);

    $withVenda = count(array_filter($territories, fn($t) => ($t['revenue'] ?? 0) > 0));

    Http::json([
        'ok'        => true,
        'layer'     => 'mapa_composto_ceara',
        'date_from' => $range['date_from'],
        'date_to'   => $range['date_to'],
        'summary'   => [
            'total_vendido'         => $totalRevenue,
            'territorios_com_venda' => $withVenda,
            'clientes'              => $totalCustomers,
            'notas'                 => $totalInvoices,
            'sem_bairro'            => $semBairro,
        ],
        'territories' => $territories,
        'scales'      => $scales,
        'ranking'     => $ranking,
    ]);
} catch (\Throwable $e) {
    Http::jsonError('geo_composite_sales_error', $e->getMessage());
}
