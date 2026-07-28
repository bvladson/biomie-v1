<?php

declare(strict_types=1);

/**
 * api/geo_opportunities.php
 *
 * GEO.R1.8.4 — Insights e oportunidades comerciais geográficas.
 *
 * Computa insights automáticos a partir do mart_compradores_por_geo:
 *   - Melhor território do período
 *   - Territórios com maior concentração (risco)
 *   - Municípios sem venda
 *   - Municípios com venda mas sem bairro
 *   - Oportunidades por volume de clientes sem bairro
 *
 * Uso: GET api/geo_opportunities.php?date_from=2026-01-01&date_to=2026-05-31
 */

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\Support\MapFilterSupport;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('geo', true);

try {
    $f     = MapFilterSupport::fromRequest($_GET);
    $range = ['date_from' => $f['date_from'], 'date_to' => $f['date_to']];

    $connectionData = Database::getGeoConnection($config);
    $pdo = $connectionData[0];

    if (!Http::tableExists($pdo, 'mart_compradores_por_geo')) {
        Http::json([
            'ok'      => true,
            'layer'   => 'geo_opportunities',
            'insights' => [],
            'empty_reason' => 'mart_compradores_por_geo não materializado',
        ]);
        return;
    }

    $startComp = substr($range['date_from'], 0, 7);
    $endComp   = substr($range['date_to'],   0, 7);

    // ── Agregação por cidade + bairro ─────────────────────────────────────
    $sql = "
        SELECT
            LOWER(TRIM(COALESCE(NULLIF(cidade_norm,''), 'sem_cidade'))) AS cidade_key,
            LOWER(TRIM(COALESCE(NULLIF(bairro_norm,''), '')))           AS bairro_raw,
            SUM(faturamento)                                             AS revenue,
            COUNT(DISTINCT COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome)) AS customers,
            SUM(qtd_notas)                                               AS invoices
        FROM mart_compradores_por_geo
        WHERE competencia BETWEEN :s AND :e
          AND " . \BIomie\Support\CommercialMartScope::condition($pdo, 'mart_compradores_por_geo') . "
        GROUP BY LOWER(TRIM(cidade_norm)), LOWER(TRIM(bairro_norm))
        ORDER BY revenue DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':s', $startComp);
    $stmt->bindValue(':e', $endComp);
    $stmt->execute();
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    // ── Estatísticas base ─────────────────────────────────────────────────
    $totalRevenue   = 0.0;
    $totalInvoices  = 0;
    $semBairroNfs   = 0;
    $semBairroRev   = 0.0;
    $territories    = [];

    $rmfWithBairros = ['fortaleza','caucaia','maracanau','maranguape','eusebio','guaiuba','itaitinga','pacatuba','trairi'];

    foreach ($rows as $row) {
        $cKey    = (string)($row['cidade_key'] ?? '');
        $bRaw    = (string)($row['bairro_raw'] ?? '');
        $rev     = (float)($row['revenue'] ?? 0);
        $inv     = (int)($row['invoices'] ?? 0);

        $totalRevenue  += $rev;
        $totalInvoices += $inv;

        if ($bRaw === '') {
            $semBairroNfs += $inv;
            $semBairroRev += $rev;
            continue;
        }

        // Classify territory
        if ($cKey === 'fortaleza') {
            $type    = 'fortaleza_bairro';
            $joinKey = $bRaw;
            $label   = ucfirst(str_replace('_', ' ', $bRaw)) . ' — Fortaleza';
        } elseif (in_array($cKey, $rmfWithBairros, true)) {
            $type    = 'metro_bairro';
            $joinKey = $cKey . ':' . $bRaw;
            $label   = ucfirst(str_replace('_', ' ', $bRaw)) . ' — ' . ucfirst($cKey);
        } else {
            $type    = 'ceara_municipio';
            $joinKey = $cKey;
            $label   = ucfirst(str_replace('_', ' ', $cKey));
        }

        if (isset($territories[$joinKey])) {
            $territories[$joinKey]['revenue']  += $rev;
            $territories[$joinKey]['invoices'] += $inv;
        } else {
            $territories[$joinKey] = [
                'join_key'       => $joinKey,
                'territory_type' => $type,
                'label'          => $label,
                'revenue'        => $rev,
                'invoices'       => $inv,
                'customers'      => (int)($row['customers'] ?? 0),
            ];
        }
    }

    // Sort territories by revenue
    usort($territories, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

    // ── Gera insights ──────────────────────────────────────────────────────
    $insights = [];

    // 1. Melhor território
    if (!empty($territories)) {
        $best = $territories[0];
        $insights[] = [
            'type'        => 'growth',
            'severity'    => 'positive',
            'icon'        => 'workspace_premium',
            'title'       => 'Melhor território: ' . $best['label'],
            'description' => 'Maior faturamento do período.',
            'value'       => (float)$best['revenue'],
            'territory_key' => $best['join_key'],
        ];
    }

    // 2. Concentração alta (top 3 > 50%)
    if (count($territories) >= 3 && $totalRevenue > 0) {
        $top3Rev = array_sum(array_column(array_slice($territories, 0, 3), 'revenue'));
        $pct = round($top3Rev / $totalRevenue * 100, 1);
        if ($pct > 50) {
            $insights[] = [
                'type'        => 'concentration',
                'severity'    => 'warning',
                'icon'        => 'warning',
                'title'       => "Alta concentração: top 3 respondem por {$pct}% do faturamento",
                'description' => 'Diversificar territórios reduz risco de perda de receita.',
                'value'       => $pct,
                'territory_key' => null,
            ];
        }
    }

    // 3. Sem bairro
    if ($semBairroNfs > 0 && $totalInvoices > 0) {
        $semBairroPct = round($semBairroNfs / $totalInvoices * 100, 1);
        $insights[] = [
            'type'        => 'data_quality',
            'severity'    => $semBairroPct > 20 ? 'danger' : 'warning',
            'icon'        => 'location_off',
            'title'       => "{$semBairroNfs} NFs sem bairro ({$semBairroPct}% do total)",
            'description' => 'Corrija o cadastro Omie para aumentar a cobertura geográfica do mapa.',
            'value'       => (float)$semBairroRev,
            'territory_key' => null,
        ];
    }

    // 4. Quantidade de territórios ativos
    $withVenda = count(array_filter($territories, fn($t) => ($t['revenue'] ?? 0) > 0));
    if ($withVenda > 0) {
        $insights[] = [
            'type'        => 'coverage',
            'severity'    => 'info',
            'icon'        => 'pin_drop',
            'title'       => "{$withVenda} territórios com venda no período",
            'description' => 'Territórios que geraram faturamento no recorte selecionado.',
            'value'       => $withVenda,
            'territory_key' => null,
        ];
    }

    // 5. Territórios sem venda (apenas municípios CE — os que não aparecem no ranking)
    // Estimado: se < 50 municípios com venda, há oportunidade
    if ($withVenda < 50 && $withVenda > 0) {
        $semVenda = 184 - $withVenda;
        if ($semVenda > 0) {
            $insights[] = [
                'type'        => 'opportunity',
                'severity'    => 'info',
                'icon'        => 'explore',
                'title'       => "~{$semVenda} municípios do Ceará sem venda",
                'description' => 'Municípios do interior sem movimentação no período — potencial de prospecção.',
                'value'       => $semVenda,
                'territory_key' => null,
            ];
        }
    }

    Http::json([
        'ok'           => true,
        'layer'        => 'geo_opportunities',
        'date_from'    => $range['date_from'],
        'date_to'      => $range['date_to'],
        'total_revenue' => $totalRevenue,
        'territories_with_sales' => $withVenda,
        'sem_bairro_nfs' => $semBairroNfs,
        'sem_bairro_rev' => $semBairroRev,
        'insights'     => $insights,
    ]);

} catch (\Throwable $e) {
    Http::jsonError('geo_opportunities_error', $e->getMessage());
}
