<?php

declare(strict_types=1);

/**
 * Exporta compradores de um CNAE no recorte territorial ativo do mapa.
 *
 * Parametros:
 *   territory_type, cidade_key, bairro_key, filter_city
 *   date_from, date_to, seller_id, scope
 *   cnae, cnae_description, document_type=all|cnpj|cpf
 *   limit max 5000
 */

use BIomie\Acl\AuditLogger;
use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\Support\BiVisibilityScope;
use BIomie\Support\DocumentMask;
use BIomie\Support\MapFilterSupport;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('geo', true);

if (!Auth::canExport()) {
    Http::json(['ok' => false, 'error' => 'export_forbidden', 'message' => 'Seu perfil nao pode exportar dados.'], 403);
}

try {
    $f = MapFilterSupport::fromRequest($_GET);
    $cidadeKey = strtolower(trim((string) ($_GET['cidade_key'] ?? '')));
    $bairroKey = strtolower(trim((string) ($_GET['bairro_key'] ?? '')));
    $ttype = trim((string) ($_GET['territory_type'] ?? ''));
    $cnae = trim((string) ($_GET['cnae'] ?? ''));
    $cnaeDescription = trim((string) ($_GET['cnae_description'] ?? ''));
    $territoryLabel = trim((string) ($_GET['territory_label'] ?? ''));
    $filterCity = strtolower(trim((string) ($_GET['filter_city'] ?? '')));
    $limit = min(5000, max(1, (int) ($_GET['limit'] ?? 2000)));
    $isNoGeo = in_array($ttype, ['sem_geo', 'sem_bairro'], true) || $bairroKey === '__sem_bairro__';
    $documentTypeFilter = geoCnaeExportNormalizeDocumentType((string) ($_GET['document_type'] ?? 'all'));

    if ($cnae === '') {
        Http::json(['ok' => false, 'error' => 'missing_cnae', 'message' => 'Selecione um CNAE antes de exportar.'], 400);
    }

    if ($cidadeKey === '' && !$isNoGeo) {
        Http::json(['ok' => false, 'error' => 'missing_cidade_key', 'message' => 'Territorio sem cidade_key para exportacao.'], 400);
    }

    [$pdo] = Database::getGeoConnection($config);
    $canViewDocs = method_exists(Auth::class, 'canViewFullDocuments') ? Auth::canViewFullDocuments() : false;
    $maskFn = class_exists(DocumentMask::class) && method_exists(DocumentMask::class, 'maskDocument')
        ? [DocumentMask::class, 'maskDocument']
        : null;

    $rows = geoCnaeExportRowsFromMart(
        $pdo,
        $f,
        $ttype,
        $cidadeKey,
        $bairroKey,
        $filterCity,
        $cnae,
        $documentTypeFilter,
        $limit
    );
    $sourceMode = 'mart_compradores_por_geo';

    if ($rows === []) {
        $rows = geoCnaeExportRowsFromCanonical(
            $pdo,
            $f,
            $ttype,
            $cidadeKey,
            $bairroKey,
            $filterCity,
            $cnae,
            $documentTypeFilter,
            $limit
        );
        $sourceMode = 'canonical_fallback';
    }

    $rows = array_map(
        static function (array $row) use ($canViewDocs, $maskFn, $cnaeDescription, $sourceMode): array {
            $doc = (string) ($row['document_raw'] ?? '');
            $documentType = geoCnaeExportDocumentType($doc);
            $documentMasked = geoCnaeExportMaskDocument($doc, $canViewDocs, $maskFn);
            $revenue = (float) ($row['revenue'] ?? 0);
            $invoices = (int) ($row['invoices'] ?? 0);

            return [
                'customer_name' => (string) ($row['customer_name'] ?? ''),
                'document' => $documentMasked,
                'document_type' => $documentType,
                'document_label' => geoCnaeExportDocumentLabel($documentType),
                'codigo_cliente_omie' => $row['codigo_cliente_omie'] ?? null,
                'cidade' => (string) ($row['cidade_norm'] ?? ''),
                'bairro' => (string) ($row['bairro_norm'] ?? ''),
                'cnae' => (string) ($row['cnae'] ?? ''),
                'cnae_descricao' => (string) (($row['cnae_descricao'] ?? '') ?: $cnaeDescription),
                'invoices' => $invoices,
                'revenue' => $revenue,
                'average_ticket' => $invoices > 0 ? round($revenue / $invoices, 2) : 0.0,
                'last_purchase' => $row['last_purchase'] ?? null,
                'source_mode' => $sourceMode,
            ];
        },
        $rows
    );

    AuditLogger::logExport($pdo, 'csv', 'geo_cnae_buyers', count($rows), [
        'date_from' => $f['date_from'],
        'date_to' => $f['date_to'],
        'territory_type' => $ttype,
        'cidade_key' => $cidadeKey,
        'bairro_key' => $bairroKey,
        'cnae' => $cnae,
        'document_type' => $documentTypeFilter,
        'source_mode' => $sourceMode,
    ]);

    $filename = geoCnaeExportFilename($cnae, $documentTypeFilter, $f['date_from']);
    header_remove('Content-Type');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'wb');
    if ($out === false) {
        throw new RuntimeException('Falha ao abrir saida CSV.');
    }

    fputcsv($out, [
        'territorio',
        'tipo_territorio',
        'cnae',
        'descricao_cnae',
        'tipo_documento',
        'cliente',
        'documento',
        'cidade',
        'bairro',
        'notas',
        'faturamento',
        'ticket_medio',
        'ultima_compra',
        'codigo_cliente_omie',
        'fonte',
    ], ';');

    foreach ($rows as $row) {
        fputcsv($out, [
            $territoryLabel,
            $ttype,
            $row['cnae'] !== '' ? $row['cnae'] : $cnae,
            $row['cnae_descricao'],
            $row['document_label'],
            $row['customer_name'],
            $row['document'],
            $row['cidade'],
            $row['bairro'],
            $row['invoices'],
            number_format((float) $row['revenue'], 2, ',', ''),
            number_format((float) $row['average_ticket'], 2, ',', ''),
            $row['last_purchase'],
            $row['codigo_cliente_omie'],
            $row['source_mode'],
        ], ';');
    }

    fclose($out);
    exit;
} catch (Throwable $exception) {
    Http::jsonError('geo_territory_cnae_buyers_export_error', $exception->getMessage());
}

function geoCnaeExportNormalizeDocumentType(string $value): string
{
    $value = strtolower(trim($value));
    return match ($value) {
        'pj' => 'cnpj',
        'pf' => 'cpf',
        'cnpj', 'cpf' => $value,
        default => 'all',
    };
}

function geoCnaeExportDocumentDigits(?string $document): string
{
    return preg_replace('/\D+/', '', (string) ($document ?? '')) ?? '';
}

function geoCnaeExportDocumentType(?string $document): string
{
    return match (strlen(geoCnaeExportDocumentDigits($document))) {
        14 => 'cnpj',
        11 => 'cpf',
        default => 'unknown',
    };
}

function geoCnaeExportDocumentLabel(string $documentType): string
{
    return match ($documentType) {
        'cnpj' => 'CNPJ',
        'cpf' => 'CPF',
        default => 'Documento',
    };
}

function geoCnaeExportMaskDocument(?string $document, bool $canViewDocs, ?callable $maskFn): string
{
    $digits = geoCnaeExportDocumentDigits($document);
    if ($digits === '') {
        return 'Nao informado';
    }

    if ($canViewDocs) {
        return (string) $document;
    }

    if ($maskFn) {
        $masked = (string) call_user_func($maskFn, $document, 'Nao informado');
        if ($masked !== '' && $masked !== 'Nao informado') {
            return $masked;
        }
    }

    return strlen($digits) > 6 ? substr($digits, 0, 3) . '***' . substr($digits, -2) : '***';
}

function geoCnaeExportApplyTerritoryFilter(
    array &$where,
    array &$params,
    string $alias,
    string $ttype,
    string $cidadeKey,
    string $bairroKey,
    string $filterCity,
    bool $isNoGeo
): void {
    if ($isNoGeo) {
        $where[] = "({$alias}.cidade_norm IS NULL OR TRIM({$alias}.cidade_norm) = '' OR {$alias}.bairro_norm IS NULL OR TRIM({$alias}.bairro_norm) = '' OR LOWER(TRIM({$alias}.bairro_norm)) IN ('nao informado', 'sem bairro', 'sem_bairro'))";
        if ($filterCity !== '') {
            $where[] = "(LOWER(TRIM({$alias}.cidade_norm)) = :filter_city OR {$alias}.cidade_norm IS NULL OR TRIM({$alias}.cidade_norm) = '')";
            $params[':filter_city'] = $filterCity;
        }
        return;
    }

    if ($ttype === 'fortaleza_bairro' && $bairroKey !== '') {
        $where[] = "LOWER(TRIM({$alias}.cidade_norm)) = 'fortaleza'";
        $where[] = "{$alias}.bairro_norm COLLATE utf8mb4_general_ci = :bairro";
        $params[':bairro'] = $bairroKey;
        return;
    }

    if ($ttype === 'metro_bairro' && $bairroKey !== '') {
        $where[] = "LOWER(TRIM({$alias}.cidade_norm)) = :cidade";
        $where[] = "{$alias}.bairro_norm COLLATE utf8mb4_general_ci = :bairro";
        $params[':cidade'] = $cidadeKey;
        $params[':bairro'] = $bairroKey;
        return;
    }

    $where[] = "LOWER(TRIM({$alias}.cidade_norm)) = :cidade";
    $params[':cidade'] = $cidadeKey;
}

function geoCnaeExportApplyCnaeFilter(array &$where, array &$params, string $rawExpr, ?string $descExpr, string $cnae): void
{
    if (strtoupper(trim($cnae)) === 'SEM_CNAE') {
        $where[] = "({$rawExpr} IS NULL OR TRIM({$rawExpr}) = '' OR UPPER(TRIM({$rawExpr})) = 'SEM_CNAE')";
        return;
    }

    $cnaeNorm = preg_replace('/\D+/', '', $cnae) ?? '';
    $parts = ["{$rawExpr} = :cnae_code"];
    $params[':cnae_code'] = $cnae;
    if ($cnaeNorm !== '') {
        $parts[] = "REGEXP_REPLACE({$rawExpr}, '[^0-9]', '') = :cnae_norm";
        $params[':cnae_norm'] = $cnaeNorm;
    }
    if ($descExpr !== null) {
        $parts[] = "LOWER({$descExpr}) LIKE :cnae_like";
        $params[':cnae_like'] = '%' . mb_strtolower($cnae) . '%';
    }
    $where[] = '(' . implode(' OR ', $parts) . ')';
}

function geoCnaeExportApplyDocumentTypeFilter(array &$where, string $documentExpr, string $documentType): void
{
    if ($documentType === 'all') {
        return;
    }
    $digitsExpr = "REGEXP_REPLACE(COALESCE({$documentExpr}, ''), '[^0-9]', '')";
    $where[] = $documentType === 'cnpj'
        ? "CHAR_LENGTH({$digitsExpr}) = 14"
        : "CHAR_LENGTH({$digitsExpr}) = 11";
}

/** @return list<array<string,mixed>> */
function geoCnaeExportRowsFromMart(
    PDO $pdo,
    array $filters,
    string $ttype,
    string $cidadeKey,
    string $bairroKey,
    string $filterCity,
    string $cnae,
    string $documentType,
    int $limit
): array {
    $martTable = 'mart_compradores_por_geo';
    if (!Http::tableExists($pdo, $martTable)) {
        return [];
    }

    $hasClientDim = Http::tableExists($pdo, 'biomie_clientes_dim');
    $dimJoin = $hasClientDim ? 'LEFT JOIN biomie_clientes_dim dim ON dim.codigo_cliente_omie = m.codigo_cliente_omie' : '';
    $martDocExpr = Http::columnExists($pdo, $martTable, 'documento') ? "NULLIF(TRIM(m.documento), '')" : 'NULL';
    $dimDocExpr = $hasClientDim && Http::columnExists($pdo, 'biomie_clientes_dim', 'documento_normalizado')
        ? "NULLIF(TRIM(dim.documento_normalizado), '')"
        : 'NULL';
    $documentExpr = $hasClientDim ? "COALESCE({$martDocExpr}, {$dimDocExpr})" : $martDocExpr;
    $martCnaeExpr = Http::columnExists($pdo, $martTable, 'cnae_codigo') ? "NULLIF(TRIM(m.cnae_codigo), '')" : 'NULL';
    $dimCnaeExpr = $hasClientDim && Http::columnExists($pdo, 'biomie_clientes_dim', 'cnae_codigo') ? "NULLIF(TRIM(dim.cnae_codigo), '')" : 'NULL';
    $rawCnaeExpr = $hasClientDim ? "COALESCE({$martCnaeExpr}, {$dimCnaeExpr})" : $martCnaeExpr;
    $martDescExpr = Http::columnExists($pdo, $martTable, 'cnae_descricao') ? "NULLIF(TRIM(m.cnae_descricao), '')" : 'NULL';
    $dimDescExpr = $hasClientDim && Http::columnExists($pdo, 'biomie_clientes_dim', 'cnae_descricao') ? "NULLIF(TRIM(dim.cnae_descricao), '')" : 'NULL';
    $descExpr = $hasClientDim ? "COALESCE({$martDescExpr}, {$dimDescExpr})" : $martDescExpr;
    $groupDocumentExpr = Http::columnExists($pdo, $martTable, 'documento') ? 'm.documento' : 'm.cliente_nome';

    $where = ['m.competencia BETWEEN :s AND :e'];
    $params = [':s' => substr((string) $filters['date_from'], 0, 7), ':e' => substr((string) $filters['date_to'], 0, 7)];
    geoCnaeExportApplyTerritoryFilter($where, $params, 'm', $ttype, $cidadeKey, $bairroKey, $filterCity, $ttype === 'sem_geo' || $ttype === 'sem_bairro' || $bairroKey === '__sem_bairro__');

    if (!empty($filters['seller_id']) && Http::columnExists($pdo, $martTable, 'nome_vendedor')) {
        $where[] = 'LOWER(m.nome_vendedor) LIKE :vendedor';
        $params[':vendedor'] = '%' . mb_strtolower(trim((string) $filters['seller_id'])) . '%';
    }
    geoCnaeExportApplyDocumentTypeFilter($where, $documentExpr, $documentType);
    geoCnaeExportApplyCnaeFilter($where, $params, $rawCnaeExpr, $descExpr, $cnae);

    $stmt = $pdo->prepare(
        "SELECT
            MAX(m.cliente_nome) AS customer_name,
            MAX({$documentExpr}) AS document_raw,
            COALESCE(SUM(m.faturamento), 0) AS revenue,
            COALESCE(SUM(m.qtd_notas), 0) AS invoices,
            MAX(m.ultima_compra) AS last_purchase,
            MAX({$rawCnaeExpr}) AS cnae,
            MAX({$descExpr}) AS cnae_descricao,
            MAX(m.codigo_cliente_omie) AS codigo_cliente_omie,
            MAX(m.cidade_norm) AS cidade_norm,
            MAX(m.bairro_norm) AS bairro_norm
         FROM {$martTable} m
         {$dimJoin}
         WHERE " . implode(' AND ', $where) . "
         GROUP BY COALESCE(CAST(m.codigo_cliente_omie AS CHAR), {$groupDocumentExpr}, m.cliente_nome)
         ORDER BY revenue DESC
         LIMIT :lim"
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return list<array<string,mixed>> */
function geoCnaeExportRowsFromCanonical(
    PDO $pdo,
    array $filters,
    string $ttype,
    string $cidadeKey,
    string $bairroKey,
    string $filterCity,
    string $cnae,
    string $documentType,
    int $limit
): array {
    $nfTable = 'biomie_notas_fiscais_canonicas';
    $geoTable = 'biomie_clientes_geo_dim';
    if (!Http::tableExists($pdo, $nfTable) || !Http::tableExists($pdo, $geoTable)) {
        return [];
    }

    $valueCol = Http::columnExists($pdo, $nfTable, 'valor_total') ? 'valor_total'
        : (Http::columnExists($pdo, $nfTable, 'valor_nfe') ? 'valor_nfe' : null);
    if ($valueCol === null) {
        return [];
    }

    $hasClientDim = Http::tableExists($pdo, 'biomie_clientes_dim');
    $dimJoin = $hasClientDim ? 'LEFT JOIN biomie_clientes_dim dim ON dim.codigo_cliente_omie = nf.codigo_cliente_omie' : '';
    $documentExpr = $hasClientDim && Http::columnExists($pdo, 'biomie_clientes_dim', 'documento_normalizado')
        ? "COALESCE(NULLIF(TRIM(nf.documento_cliente), ''), NULLIF(TRIM(dim.documento_normalizado), ''))"
        : "NULLIF(TRIM(nf.documento_cliente), '')";
    $nfCnaeExpr = Http::columnExists($pdo, $nfTable, 'cnae_codigo') ? "NULLIF(TRIM(nf.cnae_codigo), '')" : 'NULL';
    $geoCnaeExpr = Http::columnExists($pdo, $geoTable, 'cnae_codigo') ? "NULLIF(TRIM(geo.cnae_codigo), '')" : 'NULL';
    $dimCnaeExpr = $hasClientDim && Http::columnExists($pdo, 'biomie_clientes_dim', 'cnae_codigo') ? "NULLIF(TRIM(dim.cnae_codigo), '')" : 'NULL';
    $rawCnaeExpr = "COALESCE({$nfCnaeExpr}, {$geoCnaeExpr}, {$dimCnaeExpr})";
    $dimDescExpr = $hasClientDim && Http::columnExists($pdo, 'biomie_clientes_dim', 'cnae_descricao') ? "NULLIF(TRIM(dim.cnae_descricao), '')" : 'NULL';

    $where = ["nf.status_fiscal = 'autorizada'", 'DATE(COALESCE(nf.data_competencia, nf.data_emissao)) BETWEEN :date_from AND :date_to'];
    $params = [':date_from' => $filters['date_from'], ':date_to' => $filters['date_to']];
    geoCnaeExportApplyTerritoryFilter($where, $params, 'geo', $ttype, $cidadeKey, $bairroKey, $filterCity, $ttype === 'sem_geo' || $ttype === 'sem_bairro' || $bairroKey === '__sem_bairro__');
    if (!empty($filters['seller_id']) && Http::columnExists($pdo, $nfTable, 'nome_vendedor')) {
        $where[] = 'LOWER(nf.nome_vendedor) LIKE :vendedor';
        $params[':vendedor'] = '%' . mb_strtolower(trim((string) $filters['seller_id'])) . '%';
    }
    geoCnaeExportApplyDocumentTypeFilter($where, $documentExpr, $documentType);
    geoCnaeExportApplyCnaeFilter($where, $params, $rawCnaeExpr, null, $cnae);

    $visScope = '';
    if (class_exists(BiVisibilityScope::class)) {
        try {
            $visScope = BiVisibilityScope::getCondition($pdo, $nfTable, 'nf');
            $visScope = $visScope ? ' AND ' . $visScope : '';
        } catch (Throwable) {
            $visScope = '';
        }
    }

    $stmt = $pdo->prepare(
        "SELECT
            MAX(nf.nome_cliente) AS customer_name,
            MAX({$documentExpr}) AS document_raw,
            COALESCE(SUM(nf.{$valueCol}), 0) AS revenue,
            COUNT(DISTINCT nf.id) AS invoices,
            MAX(DATE(COALESCE(nf.data_competencia, nf.data_emissao))) AS last_purchase,
            MAX({$rawCnaeExpr}) AS cnae,
            MAX({$dimDescExpr}) AS cnae_descricao,
            MAX(nf.codigo_cliente_omie) AS codigo_cliente_omie,
            MAX(geo.cidade_norm) AS cidade_norm,
            MAX(geo.bairro_norm) AS bairro_norm
         FROM {$nfTable} nf
         INNER JOIN {$geoTable} geo ON geo.codigo_cliente_omie = nf.codigo_cliente_omie
         {$dimJoin}
         WHERE " . implode(' AND ', $where) . $visScope . "
         GROUP BY COALESCE(CAST(nf.codigo_cliente_omie AS CHAR), {$documentExpr}, nf.nome_cliente)
         ORDER BY revenue DESC
         LIMIT :lim"
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function geoCnaeExportFilename(string $cnae, string $documentType, string $dateFrom): string
{
    $slug = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $cnae) ?: 'cnae';
    $date = preg_replace('/[^0-9-]+/', '', $dateFrom) ?: date('Y-m-d');
    return 'biomie-compradores-cnae-' . trim($slug, '-') . '-' . $documentType . '-' . $date . '.csv';
}
