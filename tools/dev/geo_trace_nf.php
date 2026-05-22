<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Support\GeoTraceDiagnostics;
use BIomie\Support\NeighborhoodNormalizer;
use BIomie\Support\SecretMask;

require dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$options = getopt('', ['nf:', 'chave:', 'pedido:', 'cliente:', 'json']);
$asJson = isset($options['json']);
$nf = trim((string) ($options['nf'] ?? ''));
$chave = trim((string) ($options['chave'] ?? ''));
$pedido = trim((string) ($options['pedido'] ?? ''));
$cliente = trim((string) ($options['cliente'] ?? ''));

if ($nf === '' && $chave === '' && $pedido === '' && $cliente === '') {
    fwrite(STDERR, "Informe --nf, --chave, --pedido ou --cliente.\n");
    exit(2);
}

$dbError = null;
$pdo = null;
try {
    $pdo = Database::getGeoConnection(new Config())[0];
} catch (Throwable $exception) {
    $dbError = SecretMask::sanitizeErrorMessage($exception->getMessage());
}

function traceOne(?PDO $pdo, string $sql, array $params = []): array
{
    if (!$pdo instanceof PDO) {
        return [];
    }
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    } catch (Throwable) {
        return [];
    }
}

function traceRows(?PDO $pdo, string $sql, array $params = []): array
{
    if (!$pdo instanceof PDO) {
        return [];
    }
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (Throwable) {
        return [];
    }
}

function traceTable(?PDO $pdo, string $table): bool
{
    return $pdo instanceof PDO && GeoTraceDiagnostics::tableExists($pdo, $table);
}

$rawWhere = [];
$rawParams = [];
if ($nf !== '') {
    $rawWhere[] = "JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.ide.nNF')) IN (:nf, :nf_padded)";
    $rawParams['nf'] = $nf;
    $rawParams['nf_padded'] = str_pad($nf, 8, '0', STR_PAD_LEFT);
}
if ($chave !== '') {
    $rawWhere[] = "JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.compl.cChaveNFe')) = :chave";
    $rawParams['chave'] = $chave;
}
if ($pedido !== '') {
    $rawWhere[] = "CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.compl.nIdPedido')) AS CHAR) = :pedido";
    $rawParams['pedido'] = $pedido;
}
if ($cliente !== '') {
    $rawWhere[] = "CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.nfDestInt.nCodCli')) AS CHAR) = :cliente";
    $rawParams['cliente'] = $cliente;
}
$raw = traceTable($pdo, 'biomie_omie_bronze') && $rawWhere !== []
    ? traceOne($pdo, "
        SELECT
            id,
            natural_key,
            provider_key,
            JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.ide.nNF')) AS numero_nf,
            JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.compl.cChaveNFe')) AS chave_nfe,
            JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.compl.nIdPedido')) AS pedido_id_omie,
            JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.nfDestInt.nCodCli')) AS codigo_cliente_omie,
            JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.ide.dEmi')) AS data_emissao,
            JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.ide.dCan')) AS data_cancelamento,
            JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.ide.tpNF')) AS tipo_nf,
            ingested_at
        FROM biomie_omie_bronze
        WHERE entity_name = 'invoice' AND (" . implode(' OR ', $rawWhere) . ")
        ORDER BY ingested_at DESC
        LIMIT 1
    ", $rawParams)
    : [];

$canonWhere = [];
$canonParams = [];
if ($nf !== '') {
    $canonWhere[] = 'numero_nf IN (:nf, :nf_padded)';
    $canonParams['nf'] = $nf;
    $canonParams['nf_padded'] = str_pad($nf, 8, '0', STR_PAD_LEFT);
}
if ($chave !== '') {
    $canonWhere[] = 'chave_nfe = :chave';
    $canonParams['chave'] = $chave;
}
if ($pedido !== '') {
    $canonWhere[] = '(CAST(pedido_id_omie AS CHAR) = :pedido OR codigo_pedido_origem = :pedido)';
    $canonParams['pedido'] = $pedido;
}
if ($cliente !== '') {
    $canonWhere[] = 'CAST(codigo_cliente_omie AS CHAR) = :cliente';
    $canonParams['cliente'] = $cliente;
}
foreach (['numero_nf' => 'nf', 'chave_nfe' => 'chave', 'pedido_id_omie' => 'pedido', 'codigo_cliente_omie' => 'cliente'] as $rawKey => $paramKey) {
    if (($canonParams[$paramKey] ?? '') === '' && ($raw[$rawKey] ?? '') !== '') {
        if ($paramKey === 'nf') {
            $canonWhere[] = 'numero_nf IN (:raw_nf, :raw_nf_padded)';
            $canonParams['raw_nf'] = (string) $raw[$rawKey];
            $canonParams['raw_nf_padded'] = str_pad((string) $raw[$rawKey], 8, '0', STR_PAD_LEFT);
        } elseif ($paramKey === 'chave') {
            $canonWhere[] = 'chave_nfe = :raw_chave';
            $canonParams['raw_chave'] = (string) $raw[$rawKey];
        } elseif ($paramKey === 'pedido') {
            $canonWhere[] = 'CAST(pedido_id_omie AS CHAR) = :raw_pedido';
            $canonParams['raw_pedido'] = (string) $raw[$rawKey];
        } elseif ($paramKey === 'cliente') {
            $canonWhere[] = 'CAST(codigo_cliente_omie AS CHAR) = :raw_cliente';
            $canonParams['raw_cliente'] = (string) $raw[$rawKey];
        }
    }
}

$canonical = traceTable($pdo, 'biomie_notas_fiscais_canonicas') && $canonWhere !== []
    ? traceOne($pdo, "
        SELECT *
        FROM biomie_notas_fiscais_canonicas
        WHERE " . implode(' OR ', $canonWhere) . "
        ORDER BY COALESCE(data_competencia, data_emissao) DESC, id DESC
        LIMIT 1
    ", $canonParams)
    : [];

$pedidoKey = $pedido !== '' ? $pedido : (string) ($canonical['pedido_id_omie'] ?? $canonical['codigo_pedido_origem'] ?? $raw['pedido_id_omie'] ?? '');
$clienteKey = $cliente !== '' ? $cliente : (string) ($canonical['codigo_cliente_omie'] ?? $raw['codigo_cliente_omie'] ?? '');
$competencia = '';
if (!empty($canonical['data_competencia'])) {
    $competencia = substr((string) $canonical['data_competencia'], 0, 7);
} elseif (!empty($canonical['data_emissao'])) {
    $competencia = substr((string) $canonical['data_emissao'], 0, 7);
}

$order = $pedidoKey !== '' && traceTable($pdo, 'biomie_pedidos_canonicos')
    ? traceOne($pdo, "
        SELECT *
        FROM biomie_pedidos_canonicos
        WHERE CAST(pedido_id_omie AS CHAR) = :pedido OR codigo_pedido = :pedido OR codigo_integracao = :pedido
        ORDER BY id DESC
        LIMIT 1
    ", ['pedido' => $pedidoKey])
    : [];
$client = $clienteKey !== '' && traceTable($pdo, 'biomie_clientes_dim')
    ? traceOne($pdo, "
        SELECT *
        FROM biomie_clientes_dim
        WHERE CAST(codigo_cliente_omie AS CHAR) = :cliente
        LIMIT 1
    ", ['cliente' => $clienteKey])
    : [];
$clientGeo = $clienteKey !== '' && traceTable($pdo, 'biomie_clientes_geo_dim')
    ? traceOne($pdo, "
        SELECT *
        FROM biomie_clientes_geo_dim
        WHERE CAST(codigo_cliente_omie AS CHAR) = :cliente
        LIMIT 1
    ", ['cliente' => $clienteKey])
    : [];

$martCliente = $clienteKey !== '' && traceTable($pdo, 'mart_cliente_periodo')
    ? traceRows($pdo, "
        SELECT *
        FROM mart_cliente_periodo
        WHERE CAST(codigo_cliente_omie AS CHAR) = :cliente
          AND (:competencia = '' OR competencia = :competencia)
        ORDER BY competencia DESC
        LIMIT 12
    ", ['cliente' => $clienteKey, 'competencia' => $competencia])
    : [];
$geoMart = $clienteKey !== '' && traceTable($pdo, 'mart_compradores_por_geo')
    ? traceRows($pdo, "
        SELECT *
        FROM mart_compradores_por_geo
        WHERE CAST(codigo_cliente_omie AS CHAR) = :cliente
          AND (:competencia = '' OR competencia = :competencia)
        ORDER BY competencia DESC
        LIMIT 12
    ", ['cliente' => $clienteKey, 'competencia' => $competencia])
    : [];

$bairroRaw = (string) ($clientGeo['bairro_oficial_nome_fortaleza'] ?? $clientGeo['bairro_norm'] ?? $client['bairro'] ?? '');
$bairroKey = NeighborhoodNormalizer::normalizeKey($bairroRaw);
$cidadeRaw = (string) ($clientGeo['cidade_raw'] ?? $client['cidade'] ?? '');
$cidadeKey = NeighborhoodNormalizer::normalizeCity($cidadeRaw);
$geoSummary = GeoTraceDiagnostics::geoJsonSummary();
$geojsonMatch = $bairroKey !== '' && isset($geoSummary['keys'][$bairroKey]);
$apiMatch = $bairroKey !== '' && traceTable($pdo, 'biomie_bairros_fortaleza_dim')
    ? traceOne($pdo, 'SELECT bairro_nome FROM biomie_bairros_fortaleza_dim WHERE bairro_nome_normalizado = :key OR bairro_nome = :raw LIMIT 1', ['key' => $bairroKey, 'raw' => $bairroRaw])
    : [];

$result = !empty($canonical) && !empty($martCliente) && !empty($geoMart) && $geojsonMatch
    ? 'deveria_aparecer_no_mapa'
    : 'nao_confirmado_no_mapa';

$payload = [
    'status' => $pdo instanceof PDO ? 'ok' : 'partial',
    'input' => ['nf' => $nf, 'chave' => $chave, 'pedido' => $pedido, 'cliente' => $cliente],
    'database_error' => $dbError,
    'raw_omie' => [
        'found' => $raw !== [],
        'numero_nf' => $raw['numero_nf'] ?? null,
        'chave_nfe' => $raw['chave_nfe'] ?? null,
        'pedido_id_omie' => $raw['pedido_id_omie'] ?? null,
        'codigo_cliente_omie' => $raw['codigo_cliente_omie'] ?? null,
        'data_emissao' => $raw['data_emissao'] ?? null,
        'cancelada' => trim((string) ($raw['data_cancelamento'] ?? '')) !== '',
        'tipo_nf' => $raw['tipo_nf'] ?? null,
        'ingested_at' => $raw['ingested_at'] ?? null,
    ],
    'canonical_nf' => [
        'found' => $canonical !== [],
        'status_fiscal' => $canonical['status_fiscal'] ?? null,
        'valor_total' => isset($canonical['valor_total']) ? (float) $canonical['valor_total'] : null,
        'valor_mercadorias' => isset($canonical['valor_mercadorias']) ? (float) $canonical['valor_mercadorias'] : null,
        'codigo_cliente_omie' => $canonical['codigo_cliente_omie'] ?? null,
        'pedido_id_omie' => $canonical['pedido_id_omie'] ?? null,
        'competencia' => $competencia ?: null,
    ],
    'pedido' => [
        'found' => $order !== [],
        'codigo_pedido' => $order['codigo_pedido'] ?? null,
        'pedido_id_omie' => $order['pedido_id_omie'] ?? null,
        'codigo_vendedor_omie' => $order['codigo_vendedor_omie'] ?? null,
        'nome_vendedor' => $order['nome_vendedor'] ?? null,
        'status_comercial' => $order['status_comercial'] ?? null,
    ],
    'cliente' => [
        'found' => $client !== [],
        'codigo_cliente_omie' => $clienteKey ?: null,
        'nome' => $client['cliente_nome_exibicao'] ?? $client['nome_cliente'] ?? null,
        'cidade_raw' => $cidadeRaw,
        'cidade_key' => $cidadeKey,
        'bairro_raw' => $bairroRaw,
        'bairro_key' => $bairroKey,
        'bairro_normalizado' => $bairroKey !== '',
        'cidade_reconhecida_fortaleza' => $cidadeKey === 'fortaleza',
    ],
    'mart_cliente_periodo' => [
        'found' => $martCliente !== [],
        'rows' => $martCliente,
    ],
    'mart_compradores_por_geo' => [
        'found' => $geoMart !== [],
        'rows' => $geoMart,
    ],
    'api_sales_by_neighborhood' => [
        'would_match_neighborhood' => $apiMatch !== [],
        'bairro_key' => $bairroKey,
    ],
    'geojson' => [
        'features' => $geoSummary['features'],
        'name_property' => $geoSummary['name_property'],
        'match' => $geojsonMatch,
    ],
    'resultado' => $result,
];

if ($asJson) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$title = $nf !== '' ? "NF {$nf}" : ($chave !== '' ? "Chave {$chave}" : ($pedido !== '' ? "Pedido {$pedido}" : "Cliente {$cliente}"));
echo "--- GEO TRACE NF ---\n";
echo "{$title}\n";
echo "Omie raw: " . ($payload['raw_omie']['found'] ? 'OK' : 'NAO ENCONTRADA') . "\n";
echo "Canonical NF: " . ($payload['canonical_nf']['found'] ? 'OK' : 'NAO ENCONTRADA') . "\n";
echo "Status fiscal: " . ($payload['canonical_nf']['status_fiscal'] ?? '-') . "\n";
echo "Pedido vinculado: " . ($payload['pedido']['found'] ? 'OK' : 'NAO ENCONTRADO') . "\n";
echo "Cliente vinculado: " . ($payload['cliente']['found'] ? 'OK' : 'NAO ENCONTRADO') . "\n";
echo "Cidade: " . ($payload['cliente']['cidade_raw'] ?: '-') . " [" . ($payload['cliente']['cidade_key'] ?: '-') . "]\n";
echo "Bairro: " . ($payload['cliente']['bairro_raw'] ?: '-') . " [" . ($payload['cliente']['bairro_key'] ?: '-') . "]\n";
echo "mart_cliente_periodo: " . ($payload['mart_cliente_periodo']['found'] ? 'OK' : 'NAO') . "\n";
echo "mart_compradores_por_geo: " . ($payload['mart_compradores_por_geo']['found'] ? 'OK' : 'NAO') . "\n";
echo "API sales_by_neighborhood: " . ($payload['api_sales_by_neighborhood']['would_match_neighborhood'] ? 'OK' : 'NAO') . "\n";
echo "GeoJSON match: " . ($payload['geojson']['match'] ? 'OK' : 'NAO') . "\n";
echo "Resultado: {$payload['resultado']}\n";
if ($dbError !== null) {
    echo "Aviso banco: {$dbError}\n";
}
