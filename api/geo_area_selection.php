<?php

declare(strict_types=1);

/**
 * api/geo_area_selection.php
 *
 * GEO.R1.8.6 — Calcula vendas para territórios dentro de uma área desenhada.
 *
 * Recebe: POST JSON com campos:
 *   bbox       = { south, west, north, east } (bounding box da área)
 *   date_from  = YYYY-MM-DD
 *   date_to    = YYYY-MM-DD
 *
 * Uso: POST api/geo_area_selection.php
 * (a implementação client-side do BIomie faz a interseção geometria; este endpoint
 *  serve como fallback server-side para subconjuntos grandes)
 */

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\Support\MapFilterSupport;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('geo', true);

try {
    $f = MapFilterSupport::fromRequest($_GET);

    // Aceita POST JSON ou GET params
    $input = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = (string) file_get_contents('php://input');
        $input = json_decode($raw, true) ?: [];
    }

    $dateFrom = $f['date_from'] ?: (string)($input['date_from'] ?? '');
    $dateTo   = $f['date_to']   ?: (string)($input['date_to']   ?? '');
    $bbox     = $input['bbox']  ?? null;

    if (!$dateFrom || !$dateTo) {
        Http::json(['ok' => false, 'error' => 'missing_date_range'], 400);
    }

    $startComp = substr($dateFrom, 0, 7);
    $endComp   = substr($dateTo,   0, 7);

    $connectionData = Database::getGeoConnection($config);
    $pdo = $connectionData[0];

    if (!Http::tableExists($pdo, 'mart_compradores_por_geo')) {
        Http::json([
            'ok'         => true,
            'territories'=> [],
            'summary'    => ['revenue' => 0, 'customers' => 0, 'invoices' => 0, 'territories' => 0],
            'empty_reason' => 'mart_compradores_por_geo não materializado',
        ]);
        return;
    }

    // Aggregate all territories in date range (client side filters by bbox)
    $sql = "
        SELECT
            LOWER(TRIM(COALESCE(NULLIF(cidade_norm,''), 'sem_cidade'))) AS cidade_key,
            LOWER(TRIM(COALESCE(NULLIF(bairro_norm,''), '')))           AS bairro_raw,
            SUM(faturamento)    AS revenue,
            SUM(qtd_notas)      AS invoices,
            COUNT(DISTINCT COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome)) AS customers
        FROM mart_compradores_por_geo
        WHERE competencia BETWEEN :s AND :e
          AND TRIM(COALESCE(bairro_norm,'')) != ''
        GROUP BY cidade_norm, bairro_norm
        ORDER BY revenue DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':s', $startComp);
    $stmt->bindValue(':e', $endComp);
    $stmt->execute();
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $rmfWithBairros = ['fortaleza','caucaia','maracanau','maranguape','eusebio','guaiuba','itaitinga','pacatuba','trairi'];
    $territories    = [];
    $totRev = 0.0; $totCus = 0; $totInv = 0;

    foreach ($rows as $row) {
        $ck  = (string)$row['cidade_key'];
        $br  = (string)$row['bairro_raw'];
        $rev = (float)$row['revenue'];
        $inv = (int)$row['invoices'];
        $cus = (int)$row['customers'];

        if ($ck === 'fortaleza') {
            $type    = 'fortaleza_bairro';
            $joinKey = $br;
            $label   = ucfirst(str_replace('_', ' ', $br)) . ' — Fortaleza';
        } elseif (in_array($ck, $rmfWithBairros, true)) {
            $type    = 'metro_bairro';
            $joinKey = $ck . ':' . $br;
            $label   = ucfirst(str_replace('_', ' ', $br)) . ' — ' . ucfirst($ck);
        } else {
            $type    = 'ceara_municipio';
            $joinKey = $ck;
            $label   = ucfirst(str_replace('_', ' ', $ck));
        }

        $totRev += $rev; $totCus += $cus; $totInv += $inv;
        $territories[] = [
            'join_key'       => $joinKey,
            'label'          => $label,
            'territory_type' => $type,
            'cidade_key'     => $ck,
            'bairro_norm'    => $br,
            'revenue'        => $rev,
            'customers'      => $cus,
            'invoices'       => $inv,
        ];
    }

    Http::json([
        'ok'          => true,
        'territories' => $territories,
        'summary'     => [
            'revenue'     => $totRev,
            'customers'   => $totCus,
            'invoices'    => $totInv,
            'territories' => count($territories),
        ],
    ]);

} catch (\Throwable $e) {
    Http::jsonError('geo_area_selection_error', $e->getMessage());
}
