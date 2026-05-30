<?php

declare(strict_types=1);

/**
 * tools/dev/omie_stock_capability_probe.php — EST.R1.1
 *
 * Diagnóstico completo das 3 camadas de estoque Omie API-only.
 *
 * Camada 1 — Endpoints oficiais de estoque:
 *   Testa geral/estoque/ e geral/locaisestoque/ com chamada live à API.
 *   Classifica: 200_with_data | 200_empty | 404_not_found | 403_forbidden | error
 *
 * Camada 2 — Snapshot de produto:
 *   Verifica ListarProdutos → campos quantidade_estoque / estoque_minimo.
 *   Classifica: has_nonzero | all_zero | api_error
 *
 * Camada 3 — Document ledger:
 *   Verifica tabelas canônicas locais.
 *   Classifica: available | empty | table_missing
 *
 * Usage:
 *   php tools/dev/omie_stock_capability_probe.php --pretty
 *   php tools/dev/omie_stock_capability_probe.php --json
 *   php tools/dev/omie_stock_capability_probe.php --sample=5 --pretty
 *
 * Exit: 0=document_ledger disponível  1=nada disponível  2=erro DB
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Stock\OmieStockSourceResolver;

$opts     = getopt('', ['pretty', 'json', 'sample:']);
$isPretty = isset($opts['pretty']);
$isJson   = isset($opts['json']);
$sampleN  = isset($opts['sample']) ? max(1, (int)$opts['sample']) : 3;

try {
    $config = new Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);
    $appKey    = (string) $config->get('omie.app_key',    '');
    $appSecret = (string) $config->get('omie.app_secret', '');
} catch (\Throwable $e) {
    echo ($isJson ? json_encode(['error' => $e->getMessage()]) : 'ERRO DB: ' . $e->getMessage()) . "\n";
    exit(2);
}

// ── Layer 1: Official Omie Stock API ──────────────────────────────────────────

function omieCallProbe(string $url, string $call, array $params, string $k, string $s): array
{
    $payload = json_encode(['call' => $call, 'app_key' => $k, 'app_secret' => $s, 'param' => [$params]], JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_POSTFIELDS=>$payload, CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>true]);
    $resp     = (string) curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data     = (array)(json_decode($resp, true) ?? []);
    $fault    = $data['faultcode'] ?? $data['code'] ?? '';
    $listKey  = array_key_first(array_filter(['locais_cadastro', 'estoque', 'movimentos', 'registros', 'produto_servico_cadastro'], fn($k) => isset($data[$k]))) ?? '';
    $items    = $listKey ? (array)($data[$listKey] ?? []) : [];
    $total    = (int)($data['total_de_registros'] ?? $data['nTotRegistros'] ?? count($items));

    $status = match (true) {
        $httpCode === 404            => '404_not_found',
        $httpCode === 403            => '403_forbidden',
        $httpCode === 200 && !$fault && $total > 0 => '200_with_data',
        $httpCode === 200 && !$fault              => '200_empty',
        $httpCode === 200 && $fault               => '500_fault',
        default                                   => "error_{$httpCode}",
    };

    return [
        'call'   => $call,
        'url'    => $url,
        'http'   => $httpCode,
        'status' => $status,
        'total'  => $total,
        'fault'  => $fault,
        'fields' => !empty($items) ? array_keys($items[0] ?? []) : [],
    ];
}

$layer1 = [];
$stockEp = 'https://app.omie.com.br/api/v1/geral/estoque/';
$locEp   = 'https://app.omie.com.br/api/v1/geral/locaisestoque/';

$layer1[] = omieCallProbe($locEp,   'ListarLocaisEstoque',        ['nPagina'=>1,'nRegPorPagina'=>$sampleN], $appKey, $appSecret);
$layer1[] = omieCallProbe($stockEp, 'ResumoEstoque',              ['nPagina'=>1,'nRegPorPagina'=>$sampleN], $appKey, $appSecret);
$layer1[] = omieCallProbe($stockEp, 'ConsultarMovimentoEstoque',  ['nPagina'=>1,'nRegPorPagina'=>$sampleN,'dDtInicio'=>date('d/m/Y',strtotime('-30 days')),'dDtFim'=>date('d/m/Y')], $appKey, $appSecret);

$layer1Official = array_filter($layer1, fn($r) => $r['status'] === '200_with_data');
$layer1Status   = !empty($layer1Official) ? 'available' : (array_filter($layer1, fn($r) => $r['status'] === '200_empty') ? 'empty' : 'unavailable');

// ── Layer 2: Product Snapshot ─────────────────────────────────────────────────

$layer2 = ['status' => 'checking', 'products_checked' => 0, 'nonzero_count' => 0, 'sample_nonzero' => []];
try {
    $prodPayload = json_encode(['call'=>'ListarProdutos','app_key'=>$appKey,'app_secret'=>$appSecret,'param'=>[['pagina'=>1,'registros_por_pagina'=>100,'apenas_importado_api'=>'N','filtrar_apenas_omiepdv'=>'N','inativo'=>'N']]], JSON_UNESCAPED_UNICODE);
    $ch = curl_init('https://app.omie.com.br/api/v1/geral/produtos/');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>$prodPayload,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true]);
    $prodResp = (array)(json_decode((string)curl_exec($ch),true)??[]);
    curl_close($ch);
    $prods = $prodResp['produto_servico_cadastro'] ?? [];
    $total = (int)($prodResp['total_de_registros'] ?? 0);
    $nonZero = array_filter($prods, fn($p) => (float)($p['quantidade_estoque']??0) > 0 || (float)($p['estoque_minimo']??0) > 0);
    $layer2 = [
        'status'          => count($nonZero) > 0 ? 'has_nonzero' : 'all_zero',
        'total_products'  => $total,
        'products_checked' => count($prods),
        'nonzero_count'   => count($nonZero),
        'fields_available' => ['quantidade_estoque','estoque_minimo'],
        'sample_nonzero'  => array_slice(array_map(fn($p) => ['codigo'=>$p['codigo']??'','desc'=>substr($p['descricao']??'',0,50),'qty'=>$p['quantidade_estoque']??0,'min'=>$p['estoque_minimo']??0], $nonZero), 0, 3),
    ];
} catch (\Throwable $e) {
    $layer2['status'] = 'api_error';
    $layer2['error']  = $e->getMessage();
}

// ── Layer 3: Document Ledger ──────────────────────────────────────────────────

$resolver = new OmieStockSourceResolver($pdo);
$diagnostics = $resolver->diagnostics();
$layer3 = [
    'status'           => $diagnostics['tables']['biomie_notas_fiscais_itens_canonicos']['rows'] > 0 ? 'available' : 'empty',
    'items_count'      => $diagnostics['tables']['biomie_notas_fiscais_itens_canonicos']['rows'],
    'saldos_table'     => $diagnostics['tables']['biomie_produtos_estoque_saldos'],
    'movimentos_table' => $diagnostics['tables']['biomie_produtos_estoque_movimentos'],
];

// ── Recommended source ─────────────────────────────────────────────────────────

$resolvedSource = $diagnostics['resolved_source'];
$resolvedLabel  = $diagnostics['source_label'];

$output = [
    'tool'     => 'omie_stock_capability_probe',
    'date'     => date('Y-m-d H:i:s'),
    'layer1_official_api'  => ['overall' => $layer1Status, 'calls' => $layer1],
    'layer2_product_snapshot' => $layer2,
    'layer3_document_ledger'  => $layer3,
    'recommended_source'   => $resolvedSource,
    'recommended_label'    => $resolvedLabel,
    'recommendations'      => $diagnostics['recommendations'],
    'official_unavailable_reason' => ($layer1Status === 'unavailable' ? 'omie_stock_module_unavailable' : null),
];

if ($isJson) {
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($resolvedSource === OmieStockSourceResolver::SOURCE_UNAVAILABLE ? 1 : 0);
}

$bar = str_repeat('═', 72);
$sep = str_repeat('─', 72);
echo "\n{$bar}\n Omie Stock Capability Probe — EST.R1.1\n{$bar}\n\n";

echo " CAMADA 1 — API Oficial de Estoque Omie:\n";
foreach ($layer1 as $r) {
    $ok  = str_starts_with($r['status'], '200');
    $icon = $ok ? "\033[32m{$r['status']}\033[0m" : "\033[31m{$r['status']}\033[0m";
    printf("   [%s]  %-38s  total=%d  fields=%d\n", $icon, $r['call'], $r['total'], count($r['fields']));
}
printf("   → Resultado: %s\n\n", $layer1Status === 'available' ? "\033[32mAVAILABLE\033[0m" : "\033[33m{$layer1Status}\033[0m");

echo " CAMADA 2 — Snapshot de Produto Omie (ListarProdutos):\n";
$l2icon = $layer2['status'] === 'has_nonzero' ? "\033[32m" : "\033[33m";
printf("   [%s%s\033[0m]  %d produtos analisados  |  %d com saldo/mínimo > 0\n\n",
    $l2icon, strtoupper($layer2['status']), $layer2['products_checked'] ?? 0, $layer2['nonzero_count'] ?? 0);

echo " CAMADA 3 — Document Ledger (NFs canônicas locais):\n";
$l3icon = $layer3['status'] === 'available' ? "\033[32m" : "\033[31m";
printf("   [%s%s\033[0m]  %d itens de NF canônicas disponíveis para análise\n\n",
    $l3icon, strtoupper($layer3['status']), $layer3['items_count'] ?? 0);

echo "{$sep}\n";
printf(" Fonte recomendada: \033[36m%s\033[0m\n %s\n", strtoupper($resolvedSource), $resolvedLabel);
if (!empty($output['recommendations'])) {
    echo "\n Recomendações:\n";
    foreach ($output['recommendations'] as $r) echo "   · {$r}\n";
}
echo "\n{$bar}\n\n";

exit($resolvedSource === OmieStockSourceResolver::SOURCE_UNAVAILABLE ? 1 : 0);
