<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\GeoTraceDiagnostics;
use BIomie\Support\NeighborhoodNormalizer;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('admin', true);

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    Http::json(['ok' => false, 'error' => 'method_not_allowed', 'message' => 'Use GET.'], 405);
}

$nf      = trim((string) ($_GET['nf']         ?? ''));
$chave   = trim((string) ($_GET['chave']       ?? ''));
$pedido  = trim((string) ($_GET['pedido']      ?? ''));
$cliente = trim((string) ($_GET['cliente_id']  ?? $_GET['cliente'] ?? ''));

if ($nf === '' && $chave === '' && $pedido === '' && $cliente === '') {
    Http::json(['ok' => false, 'error' => 'missing_param', 'message' => 'Informe ao menos um dos parametros: nf, chave, pedido, cliente_id.'], 400);
}

try {
    $pdo = Database::getGeoConnection($config)[0];
    $tenantFilter = \BIomie\Support\TenantContext::sqlFilter();
} catch (Throwable $e) {
    Http::json([
        'ok' => false,
        'error' => 'db_error',
        'message' => 'Nao foi possivel conectar ao banco de dados.',
        'request_id' => RequestContext::id(),
    ], 503);
}

function gtnFetchOne(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    } catch (Throwable) {
        return [];
    }
}

function gtnFetchRows(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (Throwable) {
        return [];
    }
}

// ── Step 1: Raw Omie bronze ────────────────────────────────────────────────
$rawWhere  = [];
$rawParams = [];
if ($nf !== '') {
    $rawWhere[]              = "JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.ide.nNF')) IN (:nf, :nf_padded)";
    $rawParams['nf']         = $nf;
    $rawParams['nf_padded']  = str_pad($nf, 8, '0', STR_PAD_LEFT);
}
if ($chave !== '') {
    $rawWhere[]              = "JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.compl.cChaveNFe')) = :chave";
    $rawParams['chave']      = $chave;
}
if ($pedido !== '') {
    $rawWhere[]              = "CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.compl.nIdPedido')) AS CHAR) = :pedido";
    $rawParams['pedido']     = $pedido;
}
if ($cliente !== '') {
    $rawWhere[]              = "CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.nfDestInt.nCodCli')) AS CHAR) = :cliente";
    $rawParams['cliente']    = $cliente;
}

$rawRow = GeoTraceDiagnostics::tableExists($pdo, 'biomie_omie_bronze') && $rawWhere !== []
    ? gtnFetchOne($pdo, "
        SELECT
            id,
            natural_key,
            JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.ide.nNF'))           AS numero_nf,
            JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.compl.cChaveNFe'))   AS chave_nfe,
            JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.compl.nIdPedido'))   AS pedido_id_omie,
            JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.nfDestInt.nCodCli')) AS codigo_cliente_omie,
            JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.ide.dEmi'))          AS data_emissao,
            JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.ide.dCan'))          AS data_cancelamento,
            JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.ide.tpNF'))          AS tipo_nf,
            ingested_at
        FROM biomie_omie_bronze
        WHERE entity_name = 'invoice' AND (" . implode(' OR ', $rawWhere) . ")
        ORDER BY ingested_at DESC
        LIMIT 1
    ", $rawParams)
    : [];

// ── Step 2: Canonical NF ───────────────────────────────────────────────────
$canonWhere  = [];
$canonParams = [];
if ($nf !== '') {
    $canonWhere[]               = 'numero_nf IN (:nf, :nf_padded)';
    $canonParams['nf']          = $nf;
    $canonParams['nf_padded']   = str_pad($nf, 8, '0', STR_PAD_LEFT);
}
if ($chave !== '') {
    $canonWhere[]               = 'chave_nfe = :chave';
    $canonParams['chave']       = $chave;
}
if ($pedido !== '') {
    $canonWhere[]               = '(CAST(pedido_id_omie AS CHAR) = :pedido OR codigo_pedido_origem = :pedido)';
    $canonParams['pedido']      = $pedido;
}
if ($cliente !== '') {
    $canonWhere[]               = 'CAST(codigo_cliente_omie AS CHAR) = :cliente';
    $canonParams['cliente']     = $cliente;
}
// Fallback: use identifiers found in bronze
foreach (['numero_nf' => 'nf', 'chave_nfe' => 'chave', 'pedido_id_omie' => 'pedido', 'codigo_cliente_omie' => 'cliente'] as $rawKey => $paramKey) {
    if (($canonParams[$paramKey] ?? '') === '' && ($rawRow[$rawKey] ?? '') !== '') {
        if ($paramKey === 'nf') {
            $canonWhere[]                     = 'numero_nf IN (:raw_nf, :raw_nf_padded)';
            $canonParams['raw_nf']            = (string) $rawRow[$rawKey];
            $canonParams['raw_nf_padded']     = str_pad((string) $rawRow[$rawKey], 8, '0', STR_PAD_LEFT);
        } elseif ($paramKey === 'chave') {
            $canonWhere[]                     = 'chave_nfe = :raw_chave';
            $canonParams['raw_chave']         = (string) $rawRow[$rawKey];
        } elseif ($paramKey === 'pedido') {
            $canonWhere[]                     = 'CAST(pedido_id_omie AS CHAR) = :raw_pedido';
            $canonParams['raw_pedido']        = (string) $rawRow[$rawKey];
        } elseif ($paramKey === 'cliente') {
            $canonWhere[]                     = 'CAST(codigo_cliente_omie AS CHAR) = :raw_cliente';
            $canonParams['raw_cliente']       = (string) $rawRow[$rawKey];
        }
    }
}

$canonicalRow = GeoTraceDiagnostics::tableExists($pdo, 'biomie_notas_fiscais_canonicas') && $canonWhere !== []
    ? gtnFetchOne($pdo, "
        SELECT *
        FROM biomie_notas_fiscais_canonicas
        WHERE {$tenantFilter} AND (" . implode(' OR ', $canonWhere) . ")
        ORDER BY COALESCE(data_competencia, data_emissao) DESC, id DESC
        LIMIT 1
    ", $canonParams)
    : [];

$pedidoKey   = $pedido !== '' ? $pedido : (string) ($canonicalRow['pedido_id_omie'] ?? $canonicalRow['codigo_pedido_origem'] ?? $rawRow['pedido_id_omie'] ?? '');
$clienteKey  = $cliente !== '' ? $cliente : (string) ($canonicalRow['codigo_cliente_omie'] ?? $rawRow['codigo_cliente_omie'] ?? '');
$competencia = '';
if (!empty($canonicalRow['data_competencia'])) {
    $competencia = substr((string) $canonicalRow['data_competencia'], 0, 7);
} elseif (!empty($canonicalRow['data_emissao'])) {
    $competencia = substr((string) $canonicalRow['data_emissao'], 0, 7);
}

// ── Step 3: Pedido canônico ────────────────────────────────────────────────
$orderRow = $pedidoKey !== '' && GeoTraceDiagnostics::tableExists($pdo, 'biomie_pedidos_canonicos')
    ? gtnFetchOne($pdo, "
        SELECT pedido_id_omie, codigo_pedido, codigo_vendedor_omie, nome_vendedor, status_comercial, codigo_cliente_omie
        FROM biomie_pedidos_canonicos
        WHERE {$tenantFilter} AND (CAST(pedido_id_omie AS CHAR) = :pedido OR codigo_pedido = :pedido OR codigo_integracao = :pedido)
        ORDER BY id DESC
        LIMIT 1
    ", ['pedido' => $pedidoKey])
    : [];

// ── Step 4: Cliente dim + geo dim ─────────────────────────────────────────
$clientRow = $clienteKey !== '' && GeoTraceDiagnostics::tableExists($pdo, 'biomie_clientes_dim')
    ? gtnFetchOne($pdo, "
        SELECT codigo_cliente_omie, cliente_nome_exibicao, nome_cliente, cidade, bairro, cidade_normalizada, bairro_normalizado
        FROM biomie_clientes_dim
        WHERE CAST(codigo_cliente_omie AS CHAR) = :cliente
        LIMIT 1
    ", ['cliente' => $clienteKey])
    : [];

$clientGeoRow = $clienteKey !== '' && GeoTraceDiagnostics::tableExists($pdo, 'biomie_clientes_geo_dim')
    ? gtnFetchOne($pdo, "
        SELECT codigo_cliente_omie, bairro_norm, cidade_norm, cidade_raw, bairro_oficial_nome_fortaleza, logradouro, cep
        FROM biomie_clientes_geo_dim
        WHERE CAST(codigo_cliente_omie AS CHAR) = :cliente
        LIMIT 1
    ", ['cliente' => $clienteKey])
    : [];

// ── Step 5: Mart rows ──────────────────────────────────────────────────────
$martClienteRows = $clienteKey !== '' && GeoTraceDiagnostics::tableExists($pdo, 'mart_cliente_periodo')
    ? gtnFetchRows($pdo, "
        SELECT competencia, valor_nfe, nfes_emitidas, pedidos_faturados, refreshed_at
        FROM mart_cliente_periodo
        WHERE CAST(codigo_cliente_omie AS CHAR) = :cliente
          AND (:competencia = '' OR competencia = :competencia)
        ORDER BY competencia DESC
        LIMIT 6
    ", ['cliente' => $clienteKey, 'competencia' => $competencia])
    : [];

$geoMartRows = $clienteKey !== '' && GeoTraceDiagnostics::tableExists($pdo, 'mart_compradores_por_geo')
    ? gtnFetchRows($pdo, "
        SELECT competencia, bairro_norm, cidade_norm, faturamento, qtd_notas, refreshed_at
        FROM mart_compradores_por_geo
        WHERE CAST(codigo_cliente_omie AS CHAR) = :cliente
          AND " . \BIomie\Support\CommercialMartScope::condition($pdo, 'mart_compradores_por_geo') . "
          AND (:competencia = '' OR competencia = :competencia)
        ORDER BY competencia DESC
        LIMIT 6
    ", ['cliente' => $clienteKey, 'competencia' => $competencia])
    : [];

// ── Step 6: GeoJSON + normalization ───────────────────────────────────────
$bairroRaw   = (string) ($clientGeoRow['bairro_oficial_nome_fortaleza'] ?? $clientGeoRow['bairro_norm'] ?? $clientRow['bairro'] ?? '');
$bairroKey   = NeighborhoodNormalizer::normalizeKey($bairroRaw);
$cidadeRaw   = (string) ($clientGeoRow['cidade_raw'] ?? $clientRow['cidade'] ?? '');
$cidadeKey   = NeighborhoodNormalizer::normalizeCity($cidadeRaw);
$geoSummary  = GeoTraceDiagnostics::geoJsonSummary();
$geojsonMatch = $bairroKey !== '' && isset($geoSummary['keys'][$bairroKey]);

$apiMatch = $bairroKey !== '' && GeoTraceDiagnostics::tableExists($pdo, 'biomie_bairros_fortaleza_dim')
    ? gtnFetchOne($pdo, 'SELECT bairro_nome FROM biomie_bairros_fortaleza_dim WHERE bairro_nome_normalizado = :key OR bairro_nome = :raw LIMIT 1', ['key' => $bairroKey, 'raw' => $bairroRaw])
    : [];

// ── Build verdict ──────────────────────────────────────────────────────────
$steps = [
    ['step' => 'omie_bronze',       'ok' => $rawRow !== [],      'label' => 'NF encontrada no Omie (bronze)'],
    ['step' => 'canonical_nf',      'ok' => $canonicalRow !== [], 'label' => 'NF encontrada no canonical'],
    ['step' => 'status_fiscal',     'ok' => ($canonicalRow['status_fiscal'] ?? '') === 'autorizada', 'label' => 'Status fiscal: autorizada'],
    ['step' => 'cliente_vinculado', 'ok' => $clienteKey !== '',   'label' => 'Cliente vinculado a NF'],
    ['step' => 'cliente_dim',       'ok' => $clientRow !== [],    'label' => 'Cliente encontrado em clientes_dim'],
    ['step' => 'cliente_geo_dim',   'ok' => $clientGeoRow !== [], 'label' => 'Cliente enriquecido em clientes_geo_dim'],
    ['step' => 'bairro_normalizado','ok' => $bairroKey !== '',    'label' => 'Bairro normalizado'],
    ['step' => 'cidade_fortaleza',  'ok' => $cidadeKey === 'fortaleza', 'label' => 'Cidade reconhecida como Fortaleza'],
    ['step' => 'mart_cliente',      'ok' => $martClienteRows !== [], 'label' => 'Cliente materializado em mart_cliente_periodo'],
    ['step' => 'mart_geo',          'ok' => $geoMartRows !== [],  'label' => 'Cliente materializado em mart_compradores_por_geo'],
    ['step' => 'api_neighborhood',  'ok' => $apiMatch !== [],     'label' => 'Bairro encontrado na tabela de dimensao'],
    ['step' => 'geojson_match',     'ok' => $geojsonMatch,        'label' => 'Bairro casa com GeoJSON'],
];

$firstFailed = null;
foreach ($steps as $step) {
    if (!$step['ok']) {
        $firstFailed = $step['step'];
        break;
    }
}

$resultado = $firstFailed === null ? 'deveria_aparecer_no_mapa' : 'nao_confirmado_no_mapa';
$motivoFalha = match ($firstFailed) {
    'omie_bronze'        => 'NF nao encontrada no bronze Omie. Verifique se a NF foi sincronizada.',
    'canonical_nf'       => 'NF nao encontrada na camada canonica. Verifique o pipeline de ingestao.',
    'status_fiscal'      => 'NF nao esta autorizada (status: ' . ($canonicalRow['status_fiscal'] ?? '-') . '). NFs canceladas nao aparecem no mapa.',
    'cliente_vinculado'  => 'NF nao possui cliente vinculado. Verifique o campo codigo_cliente_omie.',
    'cliente_dim'        => 'Cliente nao encontrado em biomie_clientes_dim. Aguarde a proxima sincronizacao de clientes.',
    'cliente_geo_dim'    => 'Cliente sem enriquecimento geografico (sem_endereco). Execute o enriquecimento de clientes.',
    'bairro_normalizado' => 'Cliente possui geo_dim mas bairro_norm esta vazio (sem_bairro). Verifique o endereco do cliente.',
    'cidade_fortaleza'   => 'Cidade do cliente nao reconhecida como Fortaleza (cidade: ' . ($cidadeRaw ?: '-') . '). Somente clientes de Fortaleza aparecem no mapa.',
    'mart_cliente'       => 'Cliente nao materializado em mart_cliente_periodo para a competencia ' . ($competencia ?: 'desconhecida') . '. Execute rebuild do mart.',
    'mart_geo'           => 'Cliente nao materializado em mart_compradores_por_geo. Execute rebuild geografico.',
    'api_neighborhood'   => 'Bairro "' . $bairroKey . '" nao encontrado na dimensao de bairros. Pode ser alias ausente.',
    'geojson_match'      => 'Bairro "' . $bairroKey . '" nao casa com nenhuma feature do GeoJSON (bairro_nao_reconhecido). Revise aliases em NeighborhoodAliases.php.',
    null                 => null,
    default              => 'Falha desconhecida no passo: ' . $firstFailed,
};

$payload = [
    'ok'         => true,
    'request_id' => RequestContext::id(),
    'input'      => ['nf' => $nf, 'chave' => $chave, 'pedido' => $pedido, 'cliente_id' => $cliente],
    'steps'      => $steps,
    'resultado'  => $resultado,
    'primeiro_passo_falho' => $firstFailed,
    'motivo_falha' => $motivoFalha,
    'raw_omie' => [
        'found'               => $rawRow !== [],
        'numero_nf'           => $rawRow['numero_nf']          ?? null,
        'chave_nfe'           => $rawRow['chave_nfe']           ?? null,
        'pedido_id_omie'      => $rawRow['pedido_id_omie']      ?? null,
        'codigo_cliente_omie' => $rawRow['codigo_cliente_omie'] ?? null,
        'data_emissao'        => $rawRow['data_emissao']        ?? null,
        'cancelada'           => trim((string) ($rawRow['data_cancelamento'] ?? '')) !== '',
        'tipo_nf'             => $rawRow['tipo_nf']             ?? null,
        'ingested_at'         => $rawRow['ingested_at']         ?? null,
    ],
    'canonical_nf' => [
        'found'               => $canonicalRow !== [],
        'status_fiscal'       => $canonicalRow['status_fiscal']       ?? null,
        'valor_total'         => isset($canonicalRow['valor_total'])        ? (float) $canonicalRow['valor_total']        : null,
        'valor_mercadorias'   => isset($canonicalRow['valor_mercadorias'])  ? (float) $canonicalRow['valor_mercadorias']  : null,
        'codigo_cliente_omie' => $canonicalRow['codigo_cliente_omie'] ?? null,
        'pedido_id_omie'      => $canonicalRow['pedido_id_omie']      ?? null,
        'competencia'         => $competencia ?: null,
    ],
    'pedido' => [
        'found'               => $orderRow !== [],
        'pedido_id_omie'      => $orderRow['pedido_id_omie']      ?? null,
        'codigo_vendedor_omie'=> $orderRow['codigo_vendedor_omie']?? null,
        'nome_vendedor'       => $orderRow['nome_vendedor']        ?? null,
        'status_comercial'    => $orderRow['status_comercial']     ?? null,
    ],
    'cliente' => [
        'found'                     => $clientRow !== [],
        'codigo_cliente_omie'       => $clienteKey ?: null,
        'nome'                      => $clientRow['cliente_nome_exibicao'] ?? $clientRow['nome_cliente'] ?? null,
        'cidade_raw'                => $cidadeRaw,
        'cidade_key'                => $cidadeKey,
        'bairro_raw'                => $bairroRaw,
        'bairro_key'                => $bairroKey,
        'bairro_normalizado'        => $bairroKey !== '',
        'cidade_reconhecida_fortaleza' => $cidadeKey === 'fortaleza',
        'tem_geo_dim'               => $clientGeoRow !== [],
    ],
    'mart_cliente_periodo' => [
        'found' => $martClienteRows !== [],
        'rows'  => $martClienteRows,
    ],
    'mart_compradores_por_geo' => [
        'found' => $geoMartRows !== [],
        'rows'  => $geoMartRows,
    ],
    'api_sales_by_neighborhood' => [
        'would_match_neighborhood' => $apiMatch !== [],
        'bairro_key'               => $bairroKey,
    ],
    'geojson' => [
        'features'      => $geoSummary['features'],
        'name_property' => $geoSummary['name_property'],
        'match'         => $geojsonMatch,
        'bairro_key'    => $bairroKey,
    ],
    'meta' => [
        'generated_at' => date(DATE_ATOM),
    ],
];

Http::json($payload);
