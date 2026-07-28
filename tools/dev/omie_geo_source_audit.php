<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Cache;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\OmieClient;
use BIomie\OmieMethodPolicyRegistry;
use BIomie\OmiePaginator;
use BIomie\Support\GeoTraceDiagnostics;
use BIomie\Support\NeighborhoodNormalizer;
use BIomie\Support\SecretMask;

require dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$options = getopt('', ['start:', 'end:', 'city:', 'json', 'no-network', 'live-omie', 'max-pages:']);
$start = (string) ($options['start'] ?? date('Y-m-01'));
$end = (string) ($options['end'] ?? date('Y-m-t'));
$city = (string) ($options['city'] ?? 'Fortaleza');
$asJson = isset($options['json']);
$liveOmie = isset($options['live-omie']);
$noNetwork = !$liveOmie || isset($options['no-network']);
$maxLivePages = max(1, min(5, (int) ($options['max-pages'] ?? 1)));

$config = new Config();
$dbError = null;
$pdo = null;
try {
    $pdo = Database::getGeoConnection($config)[0];
} catch (Throwable $exception) {
    $dbError = SecretMask::sanitizeErrorMessage($exception->getMessage());
}

function auditRow(?PDO $pdo, string $sql, array $params = []): array
{
    if (!$pdo instanceof PDO) {
        return [];
    }
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    } catch (Throwable $exception) {
        return ['_error' => SecretMask::sanitizeErrorMessage($exception->getMessage())];
    }
}

function auditRows(?PDO $pdo, string $sql, array $params = []): array
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

function hasTable(?PDO $pdo, string $table): bool
{
    return $pdo instanceof PDO && GeoTraceDiagnostics::tableExists($pdo, $table);
}

function localClientAudit(?PDO $pdo, string $cityKey): array
{
    $raw = hasTable($pdo, 'biomie_omie_bronze')
        ? auditRow($pdo, "
            SELECT
                COUNT(*) AS total_clientes,
                SUM(CASE WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.bairro')), '') <> '' THEN 1 ELSE 0 END) AS clientes_com_bairro,
                SUM(CASE WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.bairro')), '') = '' THEN 1 ELSE 0 END) AS clientes_sem_bairro,
                SUM(CASE WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.cidade')), '') <> '' THEN 1 ELSE 0 END) AS clientes_com_cidade,
                SUM(CASE WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.cidade')), '') = '' THEN 1 ELSE 0 END) AS clientes_sem_cidade,
                SUM(CASE WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.estado')), '') = 'CE' THEN 1 ELSE 0 END) AS clientes_uf_ce,
                SUM(CASE WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.cep')), '') <> '' THEN 1 ELSE 0 END) AS clientes_com_cep,
                SUM(CASE WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.cep')), '') = '' THEN 1 ELSE 0 END) AS clientes_sem_cep,
                MAX(ingested_at) AS last_ingested_at
            FROM biomie_omie_bronze
            WHERE entity_name IN ('client','cliente')
        ")
        : ['available' => false];

    $canonical = hasTable($pdo, 'biomie_clientes_dim')
        ? auditRow($pdo, "
            SELECT
                COUNT(*) AS total_clientes,
                SUM(CASE WHEN COALESCE(bairro, '') <> '' THEN 1 ELSE 0 END) AS clientes_com_bairro_raw,
                SUM(CASE WHEN COALESCE(bairro_normalizado, '') <> '' THEN 1 ELSE 0 END) AS clientes_com_bairro_key,
                SUM(CASE WHEN COALESCE(cidade, '') <> '' THEN 1 ELSE 0 END) AS clientes_com_cidade_raw,
                SUM(CASE WHEN COALESCE(cidade_normalizada, '') <> '' THEN 1 ELSE 0 END) AS clientes_com_cidade_key,
                SUM(CASE WHEN COALESCE(cidade_normalizada, '') = :city OR cidade LIKE '%FORTALEZA%' THEN 1 ELSE 0 END) AS clientes_fortaleza_reconhecidos,
                SUM(CASE WHEN COALESCE(cidade_normalizada, '') <> '' AND COALESCE(cidade_normalizada, '') <> :city AND cidade NOT LIKE '%FORTALEZA%' THEN 1 ELSE 0 END) AS clientes_fora_fortaleza,
                SUM(CASE WHEN COALESCE(cidade_normalizada, '') = '' AND COALESCE(cidade, '') <> '' THEN 1 ELSE 0 END) AS clientes_cidade_ambigua,
                MAX(sincronizado_em) AS last_sync_at
            FROM biomie_clientes_dim
        ", ['city' => $cityKey])
        : ['available' => false];

    $quality = [
        'top_20_clientes_sem_bairro_maior_faturamento' => hasTable($pdo, 'mart_compradores_por_geo')
            ? auditRows($pdo, "
                SELECT cliente_nome, codigo_cliente_omie, COALESCE(SUM(faturamento), 0) AS faturamento, COALESCE(SUM(qtd_notas), 0) AS notas
                FROM mart_compradores_por_geo
                WHERE bairro_norm IS NULL OR TRIM(bairro_norm) = '' OR LOWER(bairro_norm) = 'nao informado'
                GROUP BY cliente_nome, codigo_cliente_omie
                ORDER BY faturamento DESC
                LIMIT 20
            ")
            : [],
        'top_20_clientes_cidade_vazia' => hasTable($pdo, 'biomie_clientes_dim')
            ? auditRows($pdo, "
                SELECT codigo_cliente_omie, nome_cliente, cidade, uf, bairro, cep, total_receita_autorizada
                FROM biomie_clientes_dim
                WHERE cidade IS NULL OR TRIM(cidade) = ''
                ORDER BY total_receita_autorizada DESC
                LIMIT 20
            ")
            : [],
        'top_20_clientes_bairro_nao_reconhecido' => hasTable($pdo, 'biomie_clientes_dim')
            ? auditRows($pdo, "
                SELECT codigo_cliente_omie, nome_cliente, cidade, uf, bairro, bairro_normalizado, total_receita_autorizada
                FROM biomie_clientes_dim
                WHERE bairro IS NOT NULL AND TRIM(bairro) <> ''
                  AND (bairro_normalizado IS NULL OR TRIM(bairro_normalizado) = '')
                ORDER BY total_receita_autorizada DESC
                LIMIT 20
            ")
            : [],
    ];

    return ['raw' => $raw, 'canonical' => $canonical, 'quality' => $quality];
}

function localInvoiceAudit(?PDO $pdo, string $start, string $end): array
{
    $raw = hasTable($pdo, 'biomie_omie_bronze')
        ? auditRow($pdo, "
            SELECT
                COUNT(*) AS total_nfs_periodo,
                SUM(CASE WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.ide.dCan')), '') = '' THEN 1 ELSE 0 END) AS nfs_nao_canceladas,
                SUM(CASE WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.ide.dCan')), '') <> '' THEN 1 ELSE 0 END) AS nfs_canceladas,
                SUM(CASE WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.ide.tpNF')), '') = '1' THEN 1 ELSE 0 END) AS nfs_saida,
                SUM(CASE WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.nfDestInt.nCodCli')), '') <> '' THEN 1 ELSE 0 END) AS nfs_com_cliente,
                SUM(CASE WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.nfDestInt.nCodCli')), '') = '' THEN 1 ELSE 0 END) AS nfs_sem_cliente,
                SUM(CASE WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.compl.nIdPedido')), '') <> '' THEN 1 ELSE 0 END) AS nfs_com_pedido,
                SUM(CASE WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.compl.nIdPedido')), '') = '' THEN 1 ELSE 0 END) AS nfs_sem_pedido,
                MAX(ingested_at) AS last_ingested_at
            FROM biomie_omie_bronze
            WHERE entity_name = 'invoice'
              AND STR_TO_DATE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.ide.dEmi')), '%d/%m/%Y') BETWEEN :start AND :end
        ", ['start' => $start, 'end' => $end])
        : ['available' => false];

    $canonical = hasTable($pdo, 'biomie_notas_fiscais_canonicas')
        ? auditRow($pdo, "
            SELECT
                COUNT(*) AS total_nfs,
                SUM(CASE WHEN status_fiscal = 'autorizada' THEN 1 ELSE 0 END) AS nfs_validas_mapa,
                SUM(CASE WHEN status_fiscal = 'cancelada' THEN 1 ELSE 0 END) AS nfs_canceladas,
                SUM(CASE WHEN codigo_cliente_omie IS NOT NULL AND codigo_cliente_omie > 0 THEN 1 ELSE 0 END) AS total_com_cliente_id,
                SUM(CASE WHEN codigo_cliente_omie IS NULL OR codigo_cliente_omie = 0 THEN 1 ELSE 0 END) AS total_sem_cliente_id,
                SUM(CASE WHEN pedido_id_omie IS NOT NULL AND pedido_id_omie > 0 THEN 1 ELSE 0 END) AS total_com_pedido_id,
                SUM(CASE WHEN pedido_id_omie IS NULL OR pedido_id_omie = 0 THEN 1 ELSE 0 END) AS total_sem_pedido_id,
                COALESCE(SUM(valor_total), 0) AS receita_bruta,
                COALESCE(SUM(CASE WHEN status_fiscal = 'autorizada' THEN valor_mercadorias ELSE 0 END), 0) AS receita_valida_mapa,
                MIN(data_competencia) AS competencia_inicial,
                MAX(data_competencia) AS competencia_final,
                MAX(sincronizado_em) AS last_sync_at
            FROM biomie_notas_fiscais_canonicas
            WHERE COALESCE(data_competencia, data_emissao) BETWEEN :start AND :end
        ", ['start' => $start, 'end' => $end])
        : ['available' => false];

    return ['raw' => $raw, 'canonical' => $canonical];
}

function localOrderAudit(?PDO $pdo, string $start, string $end): array
{
    $raw = hasTable($pdo, 'biomie_omie_bronze')
        ? auditRow($pdo, "
            SELECT
                COUNT(*) AS total_pedidos_periodo,
                SUM(CASE WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.cabecalho.codigo_cliente')), '') <> '' THEN 1 ELSE 0 END) AS pedidos_com_cliente,
                SUM(CASE WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.cabecalho.codigo_cliente')), '') = '' THEN 1 ELSE 0 END) AS pedidos_sem_cliente,
                SUM(CASE WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.informacoes_adicionais.codVend')), '') <> '' THEN 1 ELSE 0 END) AS pedidos_com_vendedor,
                SUM(CASE WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.informacoes_adicionais.codVend')), '') = '' THEN 1 ELSE 0 END) AS pedidos_sem_vendedor,
                SUM(CASE WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.cabecalho.etapa')), '') = '70' THEN 1 ELSE 0 END) AS pedidos_faturados,
                MAX(ingested_at) AS last_ingested_at
            FROM biomie_omie_bronze
            WHERE entity_name = 'order'
              AND STR_TO_DATE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.cabecalho.data_previsao')), '%d/%m/%Y') BETWEEN :start AND :end
        ", ['start' => $start, 'end' => $end])
        : ['available' => false];

    $canonical = hasTable($pdo, 'biomie_pedidos_canonicos')
        ? auditRow($pdo, "
            SELECT
                COUNT(*) AS total_pedidos,
                SUM(CASE WHEN codigo_cliente_omie IS NOT NULL AND codigo_cliente_omie > 0 THEN 1 ELSE 0 END) AS pedidos_com_cliente,
                SUM(CASE WHEN codigo_cliente_omie IS NULL OR codigo_cliente_omie = 0 THEN 1 ELSE 0 END) AS pedidos_sem_cliente,
                SUM(CASE WHEN codigo_vendedor_omie IS NOT NULL AND codigo_vendedor_omie <> '' THEN 1 ELSE 0 END) AS pedidos_com_vendedor,
                SUM(CASE WHEN codigo_vendedor_omie IS NULL OR codigo_vendedor_omie = '' THEN 1 ELSE 0 END) AS pedidos_sem_vendedor,
                SUM(CASE WHEN status_comercial = 'faturado' THEN 1 ELSE 0 END) AS pedidos_faturados,
                MAX(sincronizado_em) AS last_sync_at
            FROM biomie_pedidos_canonicos
            WHERE COALESCE(data_faturamento, data_emissao) BETWEEN :start AND :end
        ", ['start' => $start, 'end' => $end])
        : ['available' => false];

    $cross = hasTable($pdo, 'biomie_notas_fiscais_canonicas') && hasTable($pdo, 'biomie_pedidos_canonicos')
        ? auditRow($pdo, "
            SELECT
                COUNT(*) AS nfs,
                SUM(CASE WHEN p.id IS NOT NULL THEN 1 ELSE 0 END) AS nf_pedido_ok,
                SUM(CASE WHEN p.id IS NULL THEN 1 ELSE 0 END) AS nf_sem_pedido_canonico,
                SUM(CASE WHEN p.codigo_cliente_omie IS NOT NULL AND p.codigo_cliente_omie = nf.codigo_cliente_omie THEN 1 ELSE 0 END) AS pedido_cliente_ok,
                SUM(CASE WHEN p.codigo_vendedor_omie IS NOT NULL AND p.codigo_vendedor_omie <> '' THEN 1 ELSE 0 END) AS nf_pedido_vendedor_ok
            FROM biomie_notas_fiscais_canonicas nf
            LEFT JOIN biomie_pedidos_canonicos p
              ON p.pedido_id_omie = nf.pedido_id_omie
              OR p.codigo_pedido = nf.codigo_pedido_origem
            WHERE COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
        ", ['start' => $start, 'end' => $end])
        : [];

    return ['raw' => $raw, 'canonical' => $canonical, 'cross' => $cross];
}

function localCityAudit(?PDO $pdo, string $cityKey): array
{
    $rawCities = hasTable($pdo, 'biomie_omie_bronze')
        ? auditRow($pdo, "
            SELECT COUNT(*) AS raw_city_payloads, MAX(ingested_at) AS last_ingested_at
            FROM biomie_omie_bronze
            WHERE omie_method = 'PesquisarCidades'
        ")
        : ['available' => false];

    $clients = hasTable($pdo, 'biomie_clientes_dim')
        ? auditRow($pdo, "
            SELECT
                COUNT(*) AS clientes,
                SUM(CASE WHEN cidade_normalizada = :city OR cidade LIKE '%FORTALEZA%' THEN 1 ELSE 0 END) AS cidade_reconhecida_fortaleza,
                SUM(CASE WHEN uf = 'CE' THEN 1 ELSE 0 END) AS uf_ce,
                SUM(CASE WHEN cep IS NOT NULL AND TRIM(cep) <> '' THEN 1 ELSE 0 END) AS com_cep,
                SUM(CASE WHEN bairro IS NOT NULL AND TRIM(bairro) <> '' AND (cidade IS NULL OR TRIM(cidade) = '') THEN 1 ELSE 0 END) AS bairro_fortaleza_cidade_vazia
            FROM biomie_clientes_dim
        ", ['city' => $cityKey])
        : ['available' => false];

    return [
        'omie_city_cache' => $rawCities,
        'clientes' => $clients,
        'fortaleza_ibge_referencia' => '2304400',
        'confidence_rule' => [
            'alta' => 'cidade Fortaleza + UF CE + codigo IBGE quando disponivel',
            'media' => 'cidade parecida com Fortaleza + UF CE',
            'pendencia' => 'bairro de Fortaleza com cidade vazia; nao corrigir automaticamente',
        ],
    ];
}

function liveOmieProbe(Config $config, int $maxPages): array
{
    $appKey = (string) $config->get('omie.app_key', '');
    $appSecret = (string) $config->get('omie.app_secret', '');
    if (!SecretMask::isConfigured($appKey) || !SecretMask::isConfigured($appSecret)) {
        return ['status' => 'skipped', 'reason' => 'credenciais_omie_nao_configuradas'];
    }

    $logger = new Logger(dirname(__DIR__, 2) . '/storage/logs/biomie.log');
    $policyRegistry = new OmieMethodPolicyRegistry($config);
    $client = new OmieClient(
        $appKey,
        $appSecret,
        (int) $config->get('omie.request_timeout', 20),
        $logger,
        $policyRegistry,
        new Cache(dirname(__DIR__, 2) . '/storage/cache'),
        'cli'
    );
    $paginator = new OmiePaginator($client, $policyRegistry);

    return [
        'status' => 'executed',
        'max_pages' => $maxPages,
        'invoices_first_pages' => $paginator->paginate((string) $config->get('omie.endpoints.invoices'), 'ListarNF', [
            'filtrar_por_status' => 'N',
            'tpNF' => '1',
            'cDetalhesPedido' => 'S',
            'cApenasResumo' => 'S',
            'dEmiInicial' => date('d/m/Y'),
            'dEmiFinal' => date('d/m/Y'),
        ], ['max_pages' => $maxPages, 'records_key' => 'nfCadastro']),
    ];
}

$cityKey = NeighborhoodNormalizer::normalizeCity($city);
$trace = $pdo instanceof PDO ? GeoTraceDiagnostics::build($pdo, $start, $end, $city) : [];
$payload = [
    'status' => $pdo instanceof PDO ? (($trace['map_status']['map_status'] ?? 'unknown') === 'ok' ? 'ok' : 'warning') : 'partial',
    'network_mode' => $noNetwork ? 'no-network' : 'live-omie',
    'period' => ['start' => $start, 'end' => $end, 'city' => $city, 'city_key' => $cityKey],
    'database_available' => $pdo instanceof PDO,
    'database_error' => $dbError,
    'omie' => [
        'local_status' => $trace['omie'] ?? [],
        'bronze' => $trace['bronze'] ?? [],
        'live_probe' => $liveOmie && !$noNetwork ? liveOmieProbe($config, $maxLivePages) : ['status' => 'skipped', 'reason' => 'default_no_network'],
    ],
    'clientes' => localClientAudit($pdo, $cityKey),
    'nfs' => localInvoiceAudit($pdo, $start, $end),
    'pedidos' => localOrderAudit($pdo, $start, $end),
    'cidades' => localCityAudit($pdo, $cityKey),
    'canonical' => $trace['canonical'] ?? [],
    'mart_cliente_periodo' => $trace['mart_cliente_periodo'] ?? [],
    'mart_compradores_por_geo' => $trace['mart_compradores_por_geo'] ?? [],
    'api' => $trace['api'] ?? [],
    'map' => $trace['map'] ?? [],
    'diagnosis' => $trace['diagnosis'] ?? [],
    'next_actions' => [
        $trace['next_action'] ?? 'Rodar auditorias locais e verificar freshness.',
        'Clientes sem bairro: php tools/dev/geo_quality_report.php --start=' . $start . ' --end=' . $end . ' --city=' . $city,
        'Diferenca entre camadas: php tools/dev/geo_layer_diff.php --start=' . $start . ' --end=' . $end . ' --city=' . $city,
    ],
];

if ($asJson) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

echo "--- OMIE GEO SOURCE AUDIT ---\n";
echo "Status: {$payload['status']}\n";
echo "Modo rede: {$payload['network_mode']}\n";
echo "Periodo: {$start} ate {$end} | Cidade: {$city}\n";
echo "NFs canonicas: " . ($payload['canonical']['nfs_no_periodo'] ?? 0) . " | validas mapa: " . ($payload['canonical']['nfs_validas_mapa'] ?? 0) . " | receita: " . number_format((float) ($payload['canonical']['receita_total'] ?? 0), 2, ',', '.') . "\n";
echo "mart_cliente_periodo: rows=" . ($payload['mart_cliente_periodo']['rows'] ?? 0) . " receita=" . number_format((float) ($payload['mart_cliente_periodo']['receita'] ?? 0), 2, ',', '.') . "\n";
echo "mart_compradores_por_geo: rows=" . ($payload['mart_compradores_por_geo']['rows'] ?? 0) . " bairros=" . ($payload['mart_compradores_por_geo']['bairros'] ?? 0) . " receita=" . number_format((float) ($payload['mart_compradores_por_geo']['receita'] ?? 0), 2, ',', '.') . "\n";
echo "Mapa: " . ($payload['map']['matched_neighborhoods'] ?? 0) . "/" . ($payload['map']['api_neighborhoods'] ?? 0) . " bairros com match\n";
echo "Diagnostico: " . ($payload['diagnosis']['technical_message'] ?? '-') . "\n";
echo "Proxima acao: " . ($payload['diagnosis']['next_action'] ?? '-') . "\n";
if ($dbError !== null) {
    echo "Aviso banco: {$dbError}\n";
}
