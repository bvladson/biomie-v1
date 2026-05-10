<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\GeoMapSupport;
use BIomie\Support\MapFilterSupport;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('geo', true);

try {
    $filters      = MapFilterSupport::fromRequest($_GET);
    $cityNorm     = $filters['city'];
    $neighborNorm = $filters['neighborhood'];
    $cnae         = $filters['cnae'];
    $limit        = (int) (Http::query('limit', '50') ?? 50);
    if ($limit < 1 || $limit > 100) {
        $limit = 50;
    }

    $connectionData = Database::getGeoConnection($config);
    $pdo = $connectionData[0];

    if (!Http::tableExists($pdo, 'mart_vendas_por_cnae_cnpj')) {
        Http::jsonConflictMissingTable('mart_vendas_por_cnae_cnpj');
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
            COALESCE(NULLIF(TRIM(cnae_codigo), \'\'), \'SEM_CNAE\') AS cnae,
            COALESCE(NULLIF(TRIM(cnae_descricao), \'\'), \'PJ sem CNAE\') AS cnae_description,
            COALESCE(NULLIF(TRIM(segmento_macro), \'\'), \'Nao classificado\') AS segment,
            SUM(faturamento) AS revenue,
            COUNT(DISTINCT COALESCE(CAST(codigo_cliente_omie AS CHAR), documento_normalizado, cliente_nome)) AS customers,
            SUM(qtd_notas) AS invoices
        FROM mart_vendas_por_cnae_cnpj
        WHERE ' . implode(' AND ', $where) . '
        GROUP BY 1, 2, 3
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
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $revenue     = (float) ($row['revenue'] ?? 0);
        $customers   = (int) ($row['customers'] ?? 0);
        $invoices    = (int) ($row['invoices'] ?? 0);
        $cnaeCode    = (string) ($row['cnae'] ?? 'SEM_CNAE');
        $description = (string) ($row['cnae_description'] ?? 'PJ sem CNAE');

        $data[] = [
            'cnae'             => $cnaeCode,
            'cnae_description' => $description,
            'segment'          => $row['segment'] ?? null,
            'revenue'          => $revenue,
            'customers'        => $customers,
            'invoices'         => $invoices,
            'cnae_codigo'      => $cnaeCode,
            'cnae_descricao'   => $description,
            'segmento_macro'   => $row['segment'] ?? null,
            'faturamento'      => $revenue,
            'qtd_clientes'     => $customers,
            'qtd_notas'        => $invoices,
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
            'source_table' => 'mart_vendas_por_cnae_cnpj',
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
    $logger?->error('sales_by_cnae api error', ['exception' => $exception->getMessage()]);

    Http::json([
        'ok'         => false,
        'error'      => 'api_error',
        'message'    => 'Nao foi possivel carregar vendas por CNAE agora.',
        'request_id' => RequestContext::id(),
    ], 500);
}
