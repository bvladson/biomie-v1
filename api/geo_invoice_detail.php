<?php

declare(strict_types=1);

/**
 * api/geo_invoice_detail.php
 *
 * GEO.R1.9.1 — Detalhe de NF com contexto geográfico + itens.
 *
 * Retorna a NF identificada com:
 *   - dados básicos (número, data, cliente, vendedor, valor)
 *   - itens da NF (produtos, quantidades, valores)
 *   - contexto territorial (cidade_key, bairro_key, territory_type, map_link)
 *
 * Parâmetros:
 *   nota_key, numero_nf, ou id (ao menos um)
 *   date_from / date_to (opcional — para gerar map_link correto)
 *
 * Permissão: geo ou fiscal.view ou sales.view
 */

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\Support\DocumentMask;
use BIomie\Support\MapFilterSupport;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('geo', true);

try {
    $f        = MapFilterSupport::fromRequest($_GET);
    $notaKey  = trim((string) ($_GET['nota_key']  ?? ''));
    $numeroNf = trim((string) ($_GET['numero_nf'] ?? ''));
    $nfId     = (int) ($_GET['id'] ?? 0);

    if ($notaKey === '' && $numeroNf === '' && $nfId === 0) {
        Http::json(['ok' => false, 'error' => 'missing_identifier', 'message' => 'Informe nota_key, numero_nf ou id.'], 400);
    }

    $connectionData = Database::getGeoConnection($config);
    $pdo = $connectionData[0];

    $nfTable  = 'biomie_notas_fiscais_canonicas';
    $geoTable = 'biomie_clientes_geo_dim';

    if (!Http::tableExists($pdo, $nfTable)) {
        Http::json(['ok' => false, 'error' => 'table_missing', 'message' => 'Tabela canônica não disponível.'], 503);
    }

    $canViewDocs = class_exists('BIomie\\Auth') && method_exists('BIomie\\Auth', 'canViewFullDocuments')
        ? \BIomie\Auth::canViewFullDocuments()
        : false;

    $hasValor   = Http::columnExists($pdo, $nfTable, 'valor_total') ? 'valor_total'
                : (Http::columnExists($pdo, $nfTable, 'valor_nfe') ? 'valor_nfe' : 'valor_mercadorias');
    $hasVendedor= Http::columnExists($pdo, $nfTable, 'nome_vendedor');
    $hasChave   = Http::columnExists($pdo, $nfTable, 'chave_nfe');
    $hasSerie   = Http::columnExists($pdo, $nfTable, 'serie_nf');

    // ── Localizar NF ─────────────────────────────────────────────────────────
    $where  = ["nf.status_fiscal = 'autorizada'"];
    $params = [];

    if ($notaKey !== '') {
        // nota_key pode ser "numero_nf" sem série ou chave; tentar numero_nf
        $where[] = '(nf.numero_nf = :nk OR nf.id = :nkid)';
        $params[':nk']   = $notaKey;
        $params[':nkid'] = is_numeric($notaKey) ? (int)$notaKey : 0;
    } elseif ($nfId > 0) {
        $where[] = 'nf.id = :nfid';
        $params[':nfid'] = $nfId;
    } else {
        $where[] = 'nf.numero_nf = :num';
        $params[':num'] = $numeroNf;
    }

    $vendedorCol = $hasVendedor ? 'MAX(nf.nome_vendedor)' : "''";
    $serieCol    = $hasSerie    ? 'MAX(nf.serie_nf)'      : "''";
    $chaveCol    = $hasChave    ? 'MAX(nf.chave_nfe)'     : "''";
    $geoHasTable = Http::tableExists($pdo, $geoTable);
    $geoJoin     = $geoHasTable ? "LEFT JOIN {$geoTable} geo ON geo.codigo_cliente_omie = nf.codigo_cliente_omie" : '';
    $geoSel      = $geoHasTable ? ", MAX(geo.cidade_norm) AS cidade_norm, MAX(geo.bairro_norm) AS bairro_norm" : ", '' AS cidade_norm, '' AS bairro_norm";

    $stmtNf = $pdo->prepare("
        SELECT
            MAX(nf.id)                                    AS id,
            MAX(nf.numero_nf)                             AS numero_nf,
            {$serieCol}                                   AS serie_nf,
            {$chaveCol}                                   AS chave_nfe,
            MAX(COALESCE(nf.data_competencia, nf.data_emissao)) AS data_emissao,
            MAX(nf.nome_cliente)                          AS cliente_nome,
            MAX(nf.documento_cliente)                     AS cliente_documento,
            MAX(nf.codigo_cliente_omie)                   AS codigo_cliente_omie,
            {$vendedorCol}                                AS vendedor_nome,
            COALESCE(SUM(nf.{$hasValor}), 0)             AS valor_total
            {$geoSel}
        FROM {$nfTable} nf
        {$geoJoin}
        WHERE " . implode(' AND ', $where) . "
        LIMIT 1
    ");
    foreach ($params as $k => $v) $stmtNf->bindValue($k, $v);
    $stmtNf->execute();
    $nfRow = $stmtNf->fetch(\PDO::FETCH_ASSOC);

    if (!$nfRow || !$nfRow['numero_nf']) {
        Http::json(['ok' => false, 'error' => 'not_found', 'message' => 'NF não encontrada.'], 404);
        return;
    }

    $maskFn = class_exists('BIomie\\Support\\DocumentMask') ? ['\BIomie\Support\DocumentMask', 'mask'] : null;
    $doc    = (string)($nfRow['cliente_documento'] ?? '');

    // ── Itens da NF ──────────────────────────────────────────────────────────
    $items = [];
    $itemsTable = 'biomie_itens_nfe';
    if (Http::tableExists($pdo, $itemsTable)) {
        $stmtItems = $pdo->prepare("
            SELECT
                codigo_produto,
                descricao_produto,
                quantidade,
                valor_unitario,
                valor_total
            FROM {$itemsTable}
            WHERE numero_nf = :nf
            ORDER BY id ASC
            LIMIT 30
        ");
        $stmtItems->bindValue(':nf', $nfRow['numero_nf']);
        $stmtItems->execute();
        $itemRows = $stmtItems->fetchAll(\PDO::FETCH_ASSOC);
        $items = array_map(fn($r) => [
            'codigo_produto'    => $r['codigo_produto'],
            'descricao_produto' => $r['descricao_produto'],
            'quantidade'        => (float)($r['quantidade']   ?? 0),
            'valor_unitario'    => (float)($r['valor_unitario']?? 0),
            'valor_total'       => (float)($r['valor_total']  ?? 0),
        ], $itemRows);
    }

    // ── Contexto geográfico ───────────────────────────────────────────────────
    $cidadeNorm = strtolower(trim($nfRow['cidade_norm'] ?? ''));
    $bairroNorm = strtolower(trim($nfRow['bairro_norm'] ?? ''));

    $rmfWithBairros = ['fortaleza','caucaia','maracanau','maranguape','eusebio','guaiuba','itaitinga','pacatuba','trairi'];
    if ($cidadeNorm === 'fortaleza' && $bairroNorm !== '') {
        $ttype   = 'fortaleza_bairro';
        $joinKey = $bairroNorm;
    } elseif (in_array($cidadeNorm, $rmfWithBairros, true) && $bairroNorm !== '') {
        $ttype   = 'metro_bairro';
        $joinKey = $cidadeNorm . ':' . $bairroNorm;
    } elseif ($cidadeNorm !== '') {
        $ttype   = 'ceara_municipio';
        $joinKey = $cidadeNorm;
    } else {
        $ttype   = 'sem_geografia';
        $joinKey = '';
    }

    // Map link
    $mapParams = http_build_query(array_filter([
        'layer'     => 'mapa_composto_ceara',
        'date_from' => $f['date_from'],
        'date_to'   => $f['date_to'],
    ]));
    $mapLink = $joinKey ? "mapa_vendas.php?{$mapParams}" : null;

    // Pedidos link (search by NF number in pedidos.php)
    $pedidosParams = http_build_query(array_filter([
        'date_from' => $f['date_from'],
        'date_to'   => $f['date_to'],
        'search'    => $nfRow['numero_nf'],
    ]));
    $pedidosLink = "pedidos.php?{$pedidosParams}";

    Http::json([
        'ok'    => true,
        'nota'  => [
            'id'              => (int)$nfRow['id'],
            'numero_nf'       => $nfRow['numero_nf'],
            'serie'           => $nfRow['serie_nf'] ?: null,
            'data_emissao'    => $nfRow['data_emissao'],
            'cliente_nome'    => $nfRow['cliente_nome'],
            'cliente_documento' => $canViewDocs ? $doc : ($maskFn ? call_user_func($maskFn, $doc) : substr($doc, 0, 3) . '***'),
            'vendedor_nome'   => $nfRow['vendedor_nome'] ?: null,
            'valor_total'     => (float)$nfRow['valor_total'],
        ],
        'itens' => $items,
        'geo'   => [
            'cidade_key'     => $cidadeNorm ?: null,
            'bairro_key'     => $bairroNorm ?: null,
            'territory_type' => $ttype,
            'join_key'       => $joinKey ?: null,
        ],
        'links' => [
            'mapa'    => $mapLink,
            'pedidos' => $pedidosLink,
        ],
    ]);

} catch (\Throwable $e) {
    Http::jsonError('geo_invoice_detail_error', $e->getMessage());
}
