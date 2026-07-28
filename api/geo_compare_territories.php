<?php

declare(strict_types=1);

/**
 * api/geo_compare_territories.php
 *
 * GEO.R1.8.6 — Comparação detalhada de 2 a 4 territórios.
 *
 * Parâmetros GET:
 *   keys[]     = join_keys dos territórios (ex: "aldeota", "eusebio:olho_dagua")
 *   date_from  = YYYY-MM-DD
 *   date_to    = YYYY-MM-DD
 *
 * Retorna dados agregados por join_key para comparação lado a lado.
 */

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\Support\MapFilterSupport;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('geo', true);

try {
    $f     = MapFilterSupport::fromRequest($_GET);
    $keys  = array_values(array_filter(array_map('trim', (array)($_GET['keys'] ?? []))));

    if (count($keys) < 2 || count($keys) > 4) {
        Http::json(['ok' => false, 'error' => 'invalid_key_count', 'message' => 'Envie entre 2 e 4 join_keys.'], 400);
    }

    $start = substr($f['date_from'], 0, 7);
    $end   = substr($f['date_to'],   0, 7);

    $connectionData = Database::getGeoConnection($config);
    $pdo = $connectionData[0];

    if (!Http::tableExists($pdo, 'mart_compradores_por_geo')) {
        Http::json(['ok' => true, 'items' => [], 'empty_reason' => 'mart não materializado']);
        return;
    }

    $rmfWithBairros = ['fortaleza','caucaia','maracanau','maranguape','eusebio','guaiuba','itaitinga','pacatuba','trairi'];

    $items = [];
    foreach ($keys as $joinKey) {
        // Determine query strategy from join_key format
        if (str_contains($joinKey, ':')) {
            // metro_bairro: "cidade:bairro"
            [$cidade, $bairro] = explode(':', $joinKey, 2);
            $where  = 'LOWER(TRIM(cidade_norm)) = :cidade AND LOWER(TRIM(bairro_norm)) = :bairro AND competencia BETWEEN :s AND :e';
            $params = [':cidade' => $cidade, ':bairro' => $bairro, ':s' => $start, ':e' => $end];
            $type   = 'metro_bairro';
            $label  = ucfirst(str_replace('_', ' ', $bairro)) . ' — ' . ucfirst($cidade);
        } elseif ($joinKey === 'fortaleza' || (strlen($joinKey) < 20 && !str_contains($joinKey, ':'))) {
            // Could be fortaleza bairro or municipality
            // Try fortaleza bairro first (when it contains underscores typical of bairro)
            if (str_contains($joinKey, '_') || strlen($joinKey) > 8) {
                // Likely a bairro
                $where  = 'LOWER(TRIM(cidade_norm)) = :cidade AND LOWER(TRIM(bairro_norm)) = :bairro AND competencia BETWEEN :s AND :e';
                $params = [':cidade' => 'fortaleza', ':bairro' => $joinKey, ':s' => $start, ':e' => $end];
                $type   = 'fortaleza_bairro';
                $label  = ucfirst(str_replace('_', ' ', $joinKey)) . ' — Fortaleza';
            } else {
                // Municipality
                $where  = 'LOWER(TRIM(cidade_norm)) = :cidade AND competencia BETWEEN :s AND :e';
                $params = [':cidade' => $joinKey, ':s' => $start, ':e' => $end];
                $type   = 'ceara_municipio';
                $label  = ucfirst(str_replace('_', ' ', $joinKey));
            }
        } else {
            $where  = 'LOWER(TRIM(cidade_norm)) = :cidade AND competencia BETWEEN :s AND :e';
            $params = [':cidade' => $joinKey, ':s' => $start, ':e' => $end];
            $type   = 'ceara_municipio';
            $label  = ucfirst(str_replace('_', ' ', $joinKey));
        }

        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(faturamento), 0)                                             AS revenue,
                COALESCE(SUM(qtd_notas), 0)                                               AS invoices,
                COUNT(DISTINCT COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome)) AS customers
            FROM mart_compradores_por_geo
            WHERE {$where}
              AND " . \BIomie\Support\CommercialMartScope::condition($pdo, 'mart_compradores_por_geo') . "
        ");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $rev = (float)($row['revenue']   ?? 0);
        $inv = (int)  ($row['invoices']  ?? 0);
        $cus = (int)  ($row['customers'] ?? 0);
        $tkt = $inv > 0 ? $rev / $inv : 0.0;

        $items[] = [
            'join_key'       => $joinKey,
            'label'          => $label,
            'territory_type' => $type,
            'revenue'        => $rev,
            'invoices'       => $inv,
            'customers'      => $cus,
            'ticket_medio'   => $tkt,
        ];
    }

    Http::json([
        'ok'    => true,
        'items' => $items,
    ]);

} catch (\Throwable $e) {
    Http::jsonError('geo_compare_territories_error', $e->getMessage());
}
