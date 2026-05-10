<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\DocumentMask;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('geo', true);

try {
    $range = Http::resolveDateRangeOrCompetencia();
    Http::validateDateRange($range['start'], $range['end']);
    
    $start = $range['start'];
    $end = $range['end'];
    
    $cnae = trim((string) Http::query('cnae', ''));
    $uf = trim((string) Http::query('uf', ''));
    $cidade = trim((string) Http::query('cidade', ''));
    $bairro = trim((string) Http::query('bairro', ''));
    $limit = (int) Http::query('limit', '15');
    if ($limit < 1 || $limit > 100) $limit = 15;

    $connectionData = Database::getGeoConnection($config);
    $pdo = $connectionData[0];

    if (!Http::tableExists($pdo, 'mart_vendas_por_cnae_cnpj')) {
        Http::jsonConflictMissingTable('mart_vendas_por_cnae_cnpj');
    }

    $where = [];
    $params = [];

    $startComp = substr($start, 0, 7);
    $endComp = substr($end, 0, 7);

    if ($cnae !== '') {
        if ($cnae === 'SEM_CNAE') {
            $where[] = '(cnae_codigo IS NULL OR TRIM(cnae_codigo) = "")';
        } else {
            $where[] = 'cnae_codigo = :cnae';
            $params['cnae'] = $cnae;
        }
    }
    
    if ($uf !== '' && $uf !== 'ALL') {
        $where[] = 'uf = :uf';
        $params['uf'] = $uf;
    }
    if ($cidade !== '') {
        $where[] = 'cidade_norm = :cidade';
        $params['cidade'] = $cidade;
    }
    if ($bairro !== '') {
        $where[] = 'bairro_norm = :bairro';
        $params['bairro'] = $bairro;
    }

    $whereClause = 'WHERE competencia BETWEEN :startComp AND :endComp';
    $params['startComp'] = $startComp;
    $params['endComp'] = $endComp;

    if (!empty($where)) {
        $whereClause .= ' AND ' . implode(' AND ', $where);
    }

    $sql = <<<SQL
        SELECT 
            MAX(cliente_nome) AS cliente_nome,
            documento_normalizado,
            COALESCE(NULLIF(TRIM(MAX(cnae_codigo)), ''), 'SEM_CNAE') AS cnae_codigo,
            COALESCE(NULLIF(TRIM(MAX(segmento_macro)), ''), 'Não classificado') AS segmento_macro,
            SUM(faturamento) AS faturamento,
            SUM(qtd_notas) AS qtd_notas,
            MAX(ultima_compra) AS ultima_compra
        FROM mart_vendas_por_cnae_cnpj
        {$whereClause}
        GROUP BY documento_normalizado
        ORDER BY faturamento DESC
        LIMIT :limit
SQL;

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue(':' . $key, $val);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($rows as &$row) {
        $row['documento_mascarado'] = DocumentMask::maskDocument(
            is_string($row['documento_normalizado'] ?? null) ? $row['documento_normalizado'] : null,
            'Não informado'
        ) ?? 'Não informado';
        unset($row['documento_normalizado']); // Security
    }
    unset($row);

    Http::json([
        'ok' => true,
        'data' => $rows,
        'meta' => [
            'request_id' => RequestContext::id(),
            'generated_at' => date(DATE_ATOM),
            'filters' => [
                'start' => $start,
                'end' => $end,
                'cnae' => $cnae,
                'uf' => $uf,
                'cidade' => $cidade,
                'bairro' => $bairro,
            ],
            'count' => count($rows),
        ],
    ]);
} catch (\Throwable $exception) {
    global $logger;
    if ($logger) $logger->error('top_clients_by_cnae api error', ['exception' => $exception->getMessage()]);
    
    Http::json([
        'ok' => false,
        'message' => 'Erro interno ao carregar top clientes por CNAE.',
        'request_id' => RequestContext::id(),
    ], 500);
}
