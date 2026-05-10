<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\DocumentMask;
use BIomie\Support\GeoMapSupport;
use BIomie\Support\MapFilterSupport;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('geo', true);

try {
    $filters      = MapFilterSupport::fromRequest($_GET);
    $cityNorm     = $filters['city'];
    $neighborNorm = $filters['neighborhood'];
    $cnae         = $filters['cnae'];
    $limit        = (int) (Http::query('limit', '10') ?? 10);
    if ($limit < 1 || $limit > 100) {
        $limit = 10;
    }

    $connectionData = Database::getGeoConnection($config);
    $pdo = $connectionData[0];

    if (!Http::tableExists($pdo, 'mart_compradores_por_geo')) {
        Http::jsonConflictMissingTable('mart_compradores_por_geo');
    }

    $where = ['competencia BETWEEN :start_comp AND :end_comp'];
    $params = [
        'start_comp' => $filters['start_comp'],
        'end_comp'   => $filters['end_comp'],
    ];

    if ($cityNorm !== null) {
        $where[] = "(LOWER(cidade_norm) = :city OR cidade_norm IS NULL OR TRIM(cidade_norm) = '')";
        $params['city'] = $cityNorm;
    }

    if ($neighborNorm !== null) {
        $where[] = 'LOWER(bairro_norm) = :neighborhood';
        $params['neighborhood'] = $neighborNorm;
    }

    if ($cnae !== null) {
        if (strtoupper($cnae) === 'SEM_CNAE') {
            $where[] = "(cnae_codigo IS NULL OR TRIM(cnae_codigo) = '')";
        } else {
            $where[] = 'cnae_codigo = :cnae';
            $params['cnae'] = $cnae;
        }
    }

    $sql = '
        SELECT
            MAX(codigo_cliente_omie) AS codigo_cliente_omie,
            MAX(cliente_nome) AS customer_name,
            MAX(documento) AS document_raw,
            MAX(bairro_norm) AS bairro_norm,
            MAX(cidade_norm) AS cidade_norm,
            SUM(faturamento) AS revenue,
            SUM(qtd_notas) AS invoices,
            IF(SUM(qtd_notas) > 0, SUM(faturamento) / SUM(qtd_notas), 0) AS average_ticket,
            MAX(ultima_compra) AS last_purchase,
            MAX(cnae_codigo) AS cnae,
            MAX(segmento_macro) AS segment
        FROM mart_compradores_por_geo
        WHERE ' . implode(' AND ', $where) . '
        GROUP BY COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome)
        ORDER BY revenue DESC
        LIMIT :limit
    ';

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $data = [];
    $canViewFullDocuments = Auth::canViewFullDocuments();
    $canProbeInvoices = Http::tableExists($pdo, 'biomie_notas_fiscais_canonicas');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $documentRaw = is_string($row['document_raw'] ?? null) ? $row['document_raw'] : '';
        $documentNorm = topBuyersDocumentDigits($documentRaw);
        $documentMasked = DocumentMask::maskDocument(
            $documentRaw !== '' ? $documentRaw : null,
            'Nao informado'
        ) ?? 'Nao informado';
        $revenue       = (float) ($row['revenue'] ?? 0);
        $invoices      = (int) ($row['invoices'] ?? 0);
        $averageTicket = (float) ($row['average_ticket'] ?? 0);
        $customerName  = (string) ($row['customer_name'] ?? 'Sem nome');
        $codigoCliente = (string) ($row['codigo_cliente_omie'] ?? '');
        $latestInvoice = $canProbeInvoices
            ? topBuyersLatestInvoice($pdo, $codigoCliente, $documentNorm, $customerName, $filters['date_from'], $filters['date_to'])
            : ['vendedor_principal' => '', 'top_nf' => ''];

        $data[] = [
            'customer_name'       => $customerName,
            'document_masked'     => $documentMasked,
            'codigo_cliente_omie' => $codigoCliente,
            'cliente_nome'        => $customerName,
            'cliente_documento'   => $canViewFullDocuments ? $documentRaw : $documentMasked,
            'documento_norm'      => $canViewFullDocuments ? $documentNorm : '',
            'bairro_norm'         => (string) ($row['bairro_norm'] ?? ''),
            'cidade_norm'         => (string) ($row['cidade_norm'] ?? ''),
            'revenue'             => $revenue,
            'invoices'            => $invoices,
            'average_ticket'      => $averageTicket,
            'last_purchase'       => $row['last_purchase'] ?? null,
            'ultima_compra'       => $row['last_purchase'] ?? null,
            'vendedor_principal'  => (string) ($latestInvoice['vendedor_principal'] ?? ''),
            'top_nf'              => (string) ($latestInvoice['top_nf'] ?? ''),
            'cnae'                => $row['cnae'] ?? null,
            'segment'             => $row['segment'] ?? null,
            'cliente'             => $customerName,
            'documento_mascarado' => $documentMasked,
            'faturamento'         => $revenue,
            'notas'               => $invoices,
            'qtd_notas'           => $invoices,
            'ticket_medio'        => $averageTicket,
            'cnae_codigo'         => $row['cnae'] ?? null,
            'segmento_macro'      => $row['segment'] ?? null,
        ];
    }

    $payload = [
        'ok'      => true,
        'filters' => [
            'date_from'    => $filters['date_from'],
            'date_to'      => $filters['date_to'],
            'city'         => $cityNorm,
            'neighborhood' => $neighborNorm,
            'cnae'         => $cnae,
            'seller_id'    => $filters['seller_id'],
        ],
        'data' => $data,
        'meta' => [
            'request_id'   => RequestContext::id(),
            'generated_at' => date(DATE_ATOM),
            'source_table' => 'mart_compradores_por_geo',
            'source_mode'  => 'gold_mart',
            'count'        => count($data),
        ],
    ];

    if ($data === []) {
        $payload['empty_state'] = GeoMapSupport::emptyState();
    }

    Http::json($payload);
} catch (\Throwable $exception) {
    global $logger;
    $logger?->error('top_buyers_by_geo api error', ['exception' => $exception->getMessage()]);

    Http::json([
        'ok'         => false,
        'error'      => 'api_error',
        'message'    => 'Nao foi possivel carregar os principais compradores agora.',
        'request_id' => RequestContext::id(),
    ], 500);
}

function topBuyersDocumentDigits(string $document): string
{
    $digits = preg_replace('/\D+/', '', $document) ?? '';
    return in_array(strlen($digits), [11, 14], true) ? $digits : '';
}

/**
 * @return array{vendedor_principal:string,top_nf:string}
 */
function topBuyersLatestInvoice(PDO $pdo, string $codigoCliente, string $documentNorm, string $customerName, string $start, string $end): array
{
    try {
        $where = ["n.status_fiscal = 'autorizada'", 'COALESCE(n.data_competencia, n.data_emissao) BETWEEN :start AND :end'];
        $params = ['start' => $start, 'end' => $end];

        if ($codigoCliente !== '') {
            $where[] = 'n.codigo_cliente_omie = :codigo_cliente';
            $params['codigo_cliente'] = $codigoCliente;
        } elseif ($documentNorm !== '') {
            $where[] = "REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(n.documento_cliente, ''), '.', ''), '-', ''), '/', ''), ' ', '') = :documento";
            $params['documento'] = $documentNorm;
        } elseif (trim($customerName) !== '') {
            $where[] = 'LOWER(TRIM(n.nome_cliente)) = LOWER(TRIM(:cliente_nome))';
            $params['cliente_nome'] = $customerName;
        } else {
            return ['vendedor_principal' => '', 'top_nf' => ''];
        }

        $stmt = $pdo->prepare(
            'SELECT n.numero_nf, n.serie_nf, n.nome_vendedor
             FROM biomie_notas_fiscais_canonicas n
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY COALESCE(n.data_competencia, n.data_emissao) DESC, n.numero_nf DESC
             LIMIT 1'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $nf = (string) ($row['numero_nf'] ?? '');
        $serie = (string) ($row['serie_nf'] ?? '');
        if ($nf !== '' && $serie !== '') {
            $nf .= '/' . $serie;
        }

        return [
            'vendedor_principal' => (string) ($row['nome_vendedor'] ?? ''),
            'top_nf' => $nf,
        ];
    } catch (Throwable) {
        return ['vendedor_principal' => '', 'top_nf' => ''];
    }
}
