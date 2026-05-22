<?php

declare(strict_types=1);

/**
 * Fase H — H1: Diagnóstico de escopo de operação Omie
 *
 * Lê os payloads brutos do bronze layer (biomie_omie_bronze) para identificar
 * quais campos do payload Omie distinguem "Orçamento - PD" de "Remessa de Produto".
 *
 * NÃO chama a API Omie em tempo real. NÃO usa CSV ou planilha.
 * Fonte: bronze layer local (payloads JSON armazenados durante sync).
 *
 * Uso:
 *   php tools/dev/operation_scope_debug.php [opcoes]
 *
 * Opcoes:
 *   --json          Saida JSON pura
 *   --invoices      Analisa apenas NFs (padrao: ambos)
 *   --orders        Analisa apenas pedidos (padrao: ambos)
 *   --limit=N       Numero de registros a amostrar por dominio (padrao: 50)
 *   --raw           Exibe payload bruto de 1 amostra de cada tipo
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

// ── Argumentos ─────────────────────────────────────────────────────────────────

$opts       = getopt('', ['json', 'invoices', 'orders', 'limit:', 'raw']);
$isJson     = isset($opts['json']);
$doInvoices = isset($opts['invoices']) || !isset($opts['orders']);
$doOrders   = isset($opts['orders'])   || !isset($opts['invoices']);
$limit      = max(1, (int) ($opts['limit'] ?? 50));
$showRaw    = isset($opts['raw']);

// ── Bootstrap ──────────────────────────────────────────────────────────────────

$config = Config::fromEnvironment();
$logger = new Logger('operation-scope-debug', $config);

$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
if (!Database::isConfigured($config, $connection)) {
    fwrite(STDERR, "ERRO: Banco de dados nao configurado.\n");
    exit(1);
}

$pdo = Database::connect($config, $connection);
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

// ── Helpers ────────────────────────────────────────────────────────────────────

function deepGet(array $data, string $dotPath): mixed
{
    $keys = explode('.', $dotPath);
    $cur = $data;
    foreach ($keys as $key) {
        if (!is_array($cur) || !array_key_exists($key, $cur)) {
            return null;
        }
        $cur = $cur[$key];
    }
    return $cur;
}

function collectDistinct(array $rows, array $paths): array
{
    $buckets = [];
    foreach ($paths as $p) {
        $buckets[$p] = [];
    }

    foreach ($rows as $row) {
        $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);
        if (!is_array($payload)) {
            continue;
        }
        foreach ($paths as $path) {
            $val = deepGet($payload, $path);
            if ($val === null || $val === '') {
                continue;
            }
            $str = is_string($val) ? trim($val) : (string) $val;
            if ($str === '') {
                continue;
            }
            $buckets[$path][$str] = ($buckets[$path][$str] ?? 0) + 1;
        }
    }

    $result = [];
    foreach ($buckets as $path => $counts) {
        arsort($counts);
        $result[$path] = $counts;
    }
    return $result;
}

function hasBronzeTable(\PDO $pdo): bool
{
    try {
        $pdo->query("SELECT 1 FROM biomie_omie_bronze LIMIT 1");
        return true;
    } catch (\Throwable) {
        return false;
    }
}

// ── Verificar bronze layer ─────────────────────────────────────────────────────

if (!hasBronzeTable($pdo)) {
    $msg = ['error' => 'Tabela biomie_omie_bronze nao existe. Bronze layer nao disponivel neste ambiente.'];
    if ($isJson) {
        echo json_encode($msg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "ERRO: " . $msg['error'] . "\n";
        echo "Execute um sync primeiro para popular a camada bronze.\n";
    }
    exit(1);
}

// ── Contagem geral ─────────────────────────────────────────────────────────────

$countSql = "SELECT domain_name, entity_name, COUNT(*) as total, MAX(ingested_at) as last_ingested
             FROM biomie_omie_bronze
             GROUP BY domain_name, entity_name
             ORDER BY domain_name, entity_name";
$bronzeSummary = $pdo->query($countSql)->fetchAll(\PDO::FETCH_ASSOC);

$report = [
    'bronze_summary'  => $bronzeSummary,
    'invoice_scope'   => null,
    'order_scope'     => null,
];

// ── Análise: NFs (invoices) ────────────────────────────────────────────────────

if ($doInvoices) {
    $stmt = $pdo->prepare(
        "SELECT payload_json FROM biomie_omie_bronze
         WHERE domain_name = 'invoices' AND entity_name = 'invoice'
         ORDER BY ingested_at DESC
         LIMIT :lim"
    );
    $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
    $stmt->execute();
    $invoiceRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    // Campos NF-e relevantes para identificar tipo de operação:
    // ide.natOp    = Natureza da Operação (campo oficial NF-e, texto livre)
    // ide.finNFe   = Finalidade (1=Normal, 2=Complementar, 3=Ajuste, 4=Devolução)
    // ide.tpNF     = Tipo (0=Entrada, 1=Saída)
    // compl.nIdPedido = ID do pedido de venda origem (chave para JOIN com orders)
    // infAdic.infCpl  = Informações adicionais (às vezes contém tipo de operação)
    // pedido.cNumPedido = Número do pedido (texto)
    $invoicePaths = [
        'ide.natOp',
        'ide.finNFe',
        'ide.tpNF',
        'ide.mod',
        'infAdic.infCpl',
        'compl.cNatOp',
    ];

    $invoiceValues  = collectDistinct($invoiceRows, $invoicePaths);
    $invoiceSample  = null;

    if ($showRaw && !empty($invoiceRows)) {
        $sample = json_decode((string) ($invoiceRows[0]['payload_json'] ?? '{}'), true);
        // Mostrar apenas chaves de primeiro nível + ide + compl + infAdic
        $invoiceSample = [
            'top_level_keys' => array_keys(is_array($sample) ? $sample : []),
            'ide'    => $sample['ide']    ?? null,
            'compl'  => $sample['compl']  ?? null,
            'infAdic' => $sample['infAdic'] ?? null,
            'pedido' => $sample['pedido'] ?? null,
        ];
    }

    $report['invoice_scope'] = [
        'records_analyzed' => count($invoiceRows),
        'field_values'     => $invoiceValues,
        'raw_sample'       => $invoiceSample,
    ];
}

// ── Análise: Pedidos (orders) ──────────────────────────────────────────────────

if ($doOrders) {
    $stmt = $pdo->prepare(
        "SELECT payload_json FROM biomie_omie_bronze
         WHERE domain_name = 'orders' AND entity_name = 'order'
         ORDER BY ingested_at DESC
         LIMIT :lim"
    );
    $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
    $stmt->execute();
    $orderRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    // Campos de pedido Omie relevantes para tipo de operação:
    // cabecalho.codigo_tipo_atividade  = código do tipo de atividade (ex: 1=Orçamento, 2=Remessa)
    // cabecalho.nome_tipo_atividade    = nome legível ("Orçamento - PD", "Remessa de Produto")
    // cabecalho.etapa                  = etapa numérica (já mapeada)
    // informacoes_adicionais.cTipoAtividade = alternativa para tipo de atividade
    // departamentos[0].cDescricao      = departamento (pode discriminar tipo)
    $orderPaths = [
        'cabecalho.codigo_tipo_atividade',
        'cabecalho.nome_tipo_atividade',
        'cabecalho.cTipoAtividade',
        'cabecalho.cOperacaoFiscal',
        'cabecalho.etapa',
        'informacoes_adicionais.cTipoAtividade',
        'informacoes_adicionais.cOperacaoFiscal',
    ];

    $orderValues = collectDistinct($orderRows, $orderPaths);
    $orderSample = null;

    if ($showRaw && !empty($orderRows)) {
        $sample = json_decode((string) ($orderRows[0]['payload_json'] ?? '{}'), true);
        $orderSample = [
            'top_level_keys'      => array_keys(is_array($sample) ? $sample : []),
            'cabecalho'           => $sample['cabecalho'] ?? null,
            'informacoes_adicionais' => $sample['informacoes_adicionais'] ?? null,
        ];
    }

    // Também procurar em todos os registros quais chaves existem no cabecalho
    $cabecalhoKeys = [];
    foreach (array_slice($orderRows, 0, 20) as $row) {
        $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);
        if (is_array($payload) && isset($payload['cabecalho']) && is_array($payload['cabecalho'])) {
            foreach (array_keys($payload['cabecalho']) as $k) {
                $cabecalhoKeys[$k] = ($cabecalhoKeys[$k] ?? 0) + 1;
            }
        }
    }
    arsort($cabecalhoKeys);

    $infAddKeys = [];
    foreach (array_slice($orderRows, 0, 20) as $row) {
        $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);
        if (is_array($payload) && isset($payload['informacoes_adicionais']) && is_array($payload['informacoes_adicionais'])) {
            foreach (array_keys($payload['informacoes_adicionais']) as $k) {
                $infAddKeys[$k] = ($infAddKeys[$k] ?? 0) + 1;
            }
        }
    }
    arsort($infAddKeys);

    $report['order_scope'] = [
        'records_analyzed'     => count($orderRows),
        'field_values'         => $orderValues,
        'cabecalho_keys_found' => $cabecalhoKeys,
        'infoadd_keys_found'   => $infAddKeys,
        'raw_sample'           => $orderSample,
    ];
}

// ── Saída ──────────────────────────────────────────────────────────────────────

if ($isJson) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

// Human-readable output
echo "\n=== Fase H — Diagnóstico de Escopo de Operação Omie ===\n\n";

echo "--- Bronze Layer ---\n";
if (empty($bronzeSummary)) {
    echo "  Nenhum registro no bronze layer.\n";
    echo "  Execute um sync primeiro: php api/sync.php ou via cron.\n\n";
} else {
    foreach ($bronzeSummary as $row) {
        echo sprintf(
            "  %-12s / %-12s  %5d registros  (ultimo: %s)\n",
            $row['domain_name'],
            $row['entity_name'],
            (int) $row['total'],
            $row['last_ingested'] ?? '?'
        );
    }
    echo "\n";
}

if ($report['invoice_scope'] !== null) {
    $inv = $report['invoice_scope'];
    echo "--- NFs analisadas: {$inv['records_analyzed']} registros ---\n";

    foreach ($inv['field_values'] as $path => $counts) {
        if (empty($counts)) {
            echo "  $path: (nenhum valor encontrado)\n";
            continue;
        }
        echo "  $path:\n";
        foreach ($counts as $val => $cnt) {
            echo "    [$cnt]  $val\n";
        }
    }

    if ($inv['raw_sample'] !== null) {
        echo "\n  --- Raw NF sample ---\n";
        echo "  Top-level keys: " . implode(', ', $inv['raw_sample']['top_level_keys']) . "\n";
        echo "  ide: " . json_encode($inv['raw_sample']['ide'], JSON_UNESCAPED_UNICODE) . "\n";
        echo "  compl: " . json_encode($inv['raw_sample']['compl'], JSON_UNESCAPED_UNICODE) . "\n";
        echo "  infAdic: " . json_encode($inv['raw_sample']['infAdic'], JSON_UNESCAPED_UNICODE) . "\n";
    }
    echo "\n";
}

if ($report['order_scope'] !== null) {
    $ord = $report['order_scope'];
    echo "--- Pedidos analisados: {$ord['records_analyzed']} registros ---\n";

    echo "  Chaves encontradas em cabecalho:\n";
    foreach ($ord['cabecalho_keys_found'] as $key => $cnt) {
        echo "    [$cnt]  $key\n";
    }
    echo "\n";

    echo "  Chaves encontradas em informacoes_adicionais:\n";
    if (empty($ord['infoadd_keys_found'])) {
        echo "    (nenhuma)\n";
    }
    foreach ($ord['infoadd_keys_found'] as $key => $cnt) {
        echo "    [$cnt]  $key\n";
    }
    echo "\n";

    foreach ($ord['field_values'] as $path => $counts) {
        if (empty($counts)) {
            echo "  $path: (nenhum valor encontrado)\n";
            continue;
        }
        echo "  $path:\n";
        foreach ($counts as $val => $cnt) {
            echo "    [$cnt]  $val\n";
        }
    }

    if ($ord['raw_sample'] !== null) {
        echo "\n  --- Raw order sample ---\n";
        echo "  Top-level keys: " . implode(', ', $ord['raw_sample']['top_level_keys']) . "\n";
        echo "  cabecalho: " . json_encode($ord['raw_sample']['cabecalho'], JSON_UNESCAPED_UNICODE) . "\n";
        echo "  informacoes_adicionais: " . json_encode($ord['raw_sample']['informacoes_adicionais'], JSON_UNESCAPED_UNICODE) . "\n";
    }
    echo "\n";
}

echo "=== Fim do diagnóstico ===\n";
echo "\nPróximo passo: com os campos identificados acima, execute H2+H3 (OmieOperationScope + migration).\n\n";
