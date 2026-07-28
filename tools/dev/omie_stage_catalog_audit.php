<?php

declare(strict_types=1);

/**
 * COM.R1.2 FASE 3 — Catálogo de Etapas Omie (ListarEtapasFaturamento)
 *
 * Lê cache/bronze existente para etapas de faturamento.
 * Com --refresh-api: chama ListarEtapasFaturamento de forma controlada (read-only).
 *
 * Objetivo: capturar os rótulos reais do funil Omie para distinguir
 *   "Pedidos Varejo", "Pedidos Revenda", "Orçamento - PD", etc.
 *
 * Uso:
 *   php tools/dev/omie_stage_catalog_audit.php [--pretty] [--json] [--sample=20]
 *   php tools/dev/omie_stage_catalog_audit.php --refresh-api --pretty
 *
 * Read-only. Sem escrita em tabelas canônicas ou marts.
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Support\CommercialPipelineClassifier;

$opts       = getopt('', ['pretty', 'json', 'sample:', 'refresh-api']);
$pretty     = isset($opts['pretty']);
$jsonMode   = isset($opts['json']);
$sampleN    = max(1, min(50, (int) ($opts['sample'] ?? 20)));
$refreshApi = isset($opts['refresh-api']);

$t0 = microtime(true);
$clf = new CommercialPipelineClassifier();

// ── DB ────────────────────────────────────────────────────────────────────────
try {
    $config = new Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    fwrite(STDERR, 'DB: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

function tblExistsS(PDO $pdo, string $t): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t LIMIT 1");
    $s->execute([':t' => $t]);
    return (int)$s->fetchColumn() > 0;
}

$hasBronze = tblExistsS($pdo, 'biomie_omie_bronze');
$catalog   = [];
$source    = 'none';
$warning   = '';

// ── 1. Check existing bronze for stage catalog ────────────────────────────────
if ($hasBronze) {
    // Look for stage catalog entity
    $sCat = $pdo->query(
        "SELECT b.natural_key, b.payload_json, b.ingested_at
         FROM biomie_omie_bronze b
         WHERE b.entity_name IN ('stage_catalog','etapa_catalog','etapas_faturamento','order_stage_catalog')
            OR b.omie_method LIKE '%EtapasFaturamento%'
            OR b.omie_method LIKE '%EtapasOrcamento%'
         ORDER BY b.ingested_at DESC
         LIMIT 50"
    );
    $catRows = $sCat->fetchAll(PDO::FETCH_ASSOC);
    if ($catRows) {
        foreach ($catRows as $r) {
            $p = json_decode($r['payload_json'], true);
            if (is_array($p)) {
                $catalog[] = ['payload' => $p, 'ingested_at' => $r['ingested_at'], 'natural_key' => $r['natural_key']];
            }
        }
        $source = 'bronze_stage_catalog';
    }

    // Also check order_stage for unique etapa codes/values
    $sStages = $pdo->query(
        "SELECT DISTINCT
             JSON_UNQUOTE(JSON_EXTRACT(b.payload_json, '$.cEtapa')) AS etapa_code,
             COUNT(*) AS cnt
         FROM biomie_omie_bronze b
         WHERE b.entity_name = 'order_stage'
           AND JSON_EXTRACT(b.payload_json, '$.cEtapa') IS NOT NULL
         GROUP BY etapa_code
         ORDER BY cnt DESC
         LIMIT 20"
    );
    $stageEvents = $sStages->fetchAll(PDO::FETCH_ASSOC);
}

// ── 2. Check canonical for distinct etapa values ──────────────────────────────
$canonicalEtapas = [];
if (tblExistsS($pdo, 'biomie_pedidos_canonicos')) {
    $sEt = $pdo->query(
        "SELECT COALESCE(etapa_omie,'') AS etapa_omie,
                status_comercial,
                COUNT(*) AS cnt,
                SUM(valor_total) AS valor_total
         FROM biomie_pedidos_canonicos
         GROUP BY etapa_omie, status_comercial
         ORDER BY cnt DESC
         LIMIT 20"
    );
    $canonicalEtapas = $sEt->fetchAll(PDO::FETCH_ASSOC);
}

// ── 3. Build classifier-based mapping ────────────────────────────────────────
$classifierMapping = [];
foreach (['10','20','50','60','70','80'] as $code) {
    $entry = $clf->classifyEtapa($code);
    $classifierMapping[$code] = $entry;
}

// ── 4. Optional: call API for stage catalog ───────────────────────────────────
$apiResult = null;
$apiError  = null;
if ($refreshApi) {
    try {
        $omieConfig = $config->get('omie', []);
        $appKey    = (string) ($omieConfig['app_key']    ?? $omieConfig['key']    ?? '');
        $appSecret = (string) ($omieConfig['app_secret'] ?? $omieConfig['secret'] ?? '');

        if ($appKey === '' || $appSecret === '') {
            $apiError = 'Credenciais Omie não configuradas (omie.app_key / omie.app_secret ausentes).';
        } else {
            $payload = json_encode([
                'call'       => 'ListarEtapasFaturamento',
                'app_key'    => $appKey,
                'app_secret' => $appSecret,
                'param'      => [['nPagina' => 1, 'nRegPorPagina' => 50]],
            ]);

            $ctx = stream_context_create(['http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $payload,
                'timeout' => 15,
            ]]);

            $resp = @file_get_contents('https://app.omie.com.br/api/v1/produtos/pedido/', false, $ctx);
            if ($resp === false) {
                $apiError = 'Falha ao chamar ListarEtapasFaturamento.';
            } else {
                $apiResult = json_decode($resp, true);
                if (!is_array($apiResult)) {
                    $apiError = 'Resposta inválida da API Omie.';
                    $apiResult = null;
                } else {
                    $source = 'api_ListarEtapasFaturamento';
                    // Extract stage entries
                    $stages = $apiResult['lista_etapas'] ?? $apiResult['etapas'] ?? [];
                    foreach (is_array($stages) ? $stages : [] as $s) {
                        $code = trim((string) ($s['codigo'] ?? $s['cCodigo'] ?? $s['nCodigo'] ?? ''));
                        $label = trim((string) ($s['descricao'] ?? $s['cDescricao'] ?? $s['label'] ?? ''));
                        if ($code !== '') {
                            $entry = $clf->classifyEtapa($code);
                            $catalog[] = [
                                'codigo_etapa'   => $code,
                                'descricao_etapa'=> $label,
                                'ativo'          => (bool) ($s['ativo'] ?? $s['cAtivo'] ?? true),
                                'ordem'          => (int) ($s['ordem'] ?? $s['nOrdem'] ?? 0),
                                'has_varejo'     => str_contains(mb_strtolower($label), 'varejo'),
                                'has_revenda'    => str_contains(mb_strtolower($label), 'revenda'),
                                'has_orcamento'  => str_contains(mb_strtolower($label), 'orçamento') || str_contains(mb_strtolower($label), 'orcamento'),
                                'has_faturado'   => str_contains(mb_strtolower($label), 'faturado'),
                                'has_entrega'    => str_contains(mb_strtolower($label), 'entrega'),
                                'classifier_scope' => $entry['scope'],
                                'confidence'     => $entry['confidence'] ?? 90,
                            ];
                        }
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        $apiError = 'Exceção ao chamar API: ' . $e->getMessage();
    }
}

$elapsed = round(microtime(true) - $t0, 2);

// ── JSON output ───────────────────────────────────────────────────────────────
if ($jsonMode) {
    echo json_encode([
        'omie_stage_catalog_audit' => [
            'version'          => 'COM.R1.2',
            'source'           => $source,
            'generated_at'     => date('Y-m-d H:i:s'),
            'elapsed_s'        => $elapsed,
            'api_called'       => $refreshApi,
            'api_error'        => $apiError,
            'catalog'          => $catalog,
            'canonical_etapas' => $canonicalEtapas,
            'classifier_mapping' => $classifierMapping,
            'stage_events'     => $stageEvents ?? [],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

// ── Pretty output ─────────────────────────────────────────────────────────────
$hr = str_repeat('═', 72);
echo PHP_EOL . $hr . PHP_EOL;
echo '  COM.R1.2 — Catálogo de Etapas Omie (ListarEtapasFaturamento)' . PHP_EOL;
echo $hr . PHP_EOL . PHP_EOL;

echo '  Fonte     : ' . ($source !== 'none' ? $source : 'bronze vazio (sem dados de catálogo ainda)') . PHP_EOL;
echo '  API chamada: ' . ($refreshApi ? 'SIM' : 'NÃO (use --refresh-api para buscar da API)') . PHP_EOL;
if ($apiError) echo '  ERRO API  : ' . $apiError . PHP_EOL;
echo PHP_EOL;

// Classifier mapping
echo str_repeat('─', 72) . PHP_EOL;
echo '  Mapeamento Oficial CommercialPipelineClassifier' . PHP_EOL;
echo str_repeat('─', 72) . PHP_EOL;
echo PHP_EOL;
echo '  ' . str_pad('etapa', 8) . str_pad('label', 24) . str_pad('scope', 26) . 'realizado  carteira' . PHP_EOL;
echo '  ' . str_repeat('-', 68) . PHP_EOL;
foreach ($classifierMapping as $code => $e) {
    echo '  ' . str_pad((string) $code, 8) . str_pad((string) $e['label'], 24) . str_pad((string) $e['scope'], 26)
        . str_pad($e['realized'] ? 'SIM' : 'não', 11)
        . ($e['portfolio'] ? 'SIM' : 'não') . PHP_EOL;
}

// Stage events from order_stage bronze
if (!empty($stageEvents)) {
    echo PHP_EOL . str_repeat('─', 72) . PHP_EOL;
    echo '  Etapas em order_stage bronze (ListarEtapasPedido)' . PHP_EOL;
    echo str_repeat('─', 72) . PHP_EOL;
    echo PHP_EOL;
    foreach ($stageEvents as $e) {
        $code = (string) ($e['etapa_code'] ?? '');
        $label = $classifierMapping[$code]['label'] ?? '(desconhecido)';
        echo '  cEtapa=' . str_pad($code, 6) . str_pad($label, 24) . ' eventos: ' . number_format((int)$e['cnt'], 0, ',', '.') . PHP_EOL;
    }
}

// Canonical etapas
echo PHP_EOL . str_repeat('─', 72) . PHP_EOL;
echo '  Etapas no Canônico (biomie_pedidos_canonicos)' . PHP_EOL;
echo str_repeat('─', 72) . PHP_EOL;
echo PHP_EOL;
foreach ($canonicalEtapas as $r) {
    $code = (string) ($r['etapa_omie'] ?? '');
    $clsEntry = $classifierMapping[$code] ?? null;
    $scope = $clsEntry ? $clsEntry['scope'] : '(desconhecido)';
    $problema = '';
    if ($code === '60' && $r['status_comercial'] === 'faturado') {
        $problema = ' ← DIVERGENTE (etapa 60 como faturado)';
    }
    echo '  etapa=' . str_pad($code, 6) . str_pad($r['status_comercial'], 14)
        . str_pad('→ ' . $scope, 28)
        . number_format((int)$r['cnt'], 0, ',', '.') . ' pedidos'
        . $problema . PHP_EOL;
}

// Catalog from API (if called)
if ($catalog && $refreshApi) {
    echo PHP_EOL . str_repeat('─', 72) . PHP_EOL;
    echo '  Catálogo da API Omie (ListarEtapasFaturamento)' . PHP_EOL;
    echo str_repeat('─', 72) . PHP_EOL;
    foreach ($catalog as $c) {
        if (!isset($c['codigo_etapa'])) continue;
        $flags = [];
        if ($c['has_varejo'])   $flags[] = 'VAREJO';
        if ($c['has_revenda'])  $flags[] = 'REVENDA';
        if ($c['has_orcamento']) $flags[] = 'ORCAMENTO';
        if ($c['has_faturado']) $flags[] = 'FATURADO';
        if ($c['has_entrega'])  $flags[] = 'ENTREGA';
        echo PHP_EOL . '  [' . $c['codigo_etapa'] . '] ' . ($c['descricao_etapa'] ?? '') . PHP_EOL;
        echo '    scope: ' . $c['classifier_scope'] . '  |  flags: ' . implode(', ', $flags ?: ['—']) . PHP_EOL;
    }
}

// Pending finding
echo PHP_EOL . str_repeat('─', 72) . PHP_EOL;
echo '  Status da Detecção Varejo/Revenda' . PHP_EOL;
echo str_repeat('─', 72) . PHP_EOL;
echo PHP_EOL;
$hasVarejoData = false;
foreach ($catalog as $c) {
    if (($c['has_varejo'] ?? false) || ($c['has_revenda'] ?? false)) {
        $hasVarejoData = true;
        break;
    }
}
if ($hasVarejoData) {
    echo '  ✓ Rótulos Varejo/Revenda encontrados no catálogo!' . PHP_EOL;
} else {
    echo '  ✗ Rótulos Varejo/Revenda NÃO encontrados ainda.' . PHP_EOL;
    echo '  Execute com --refresh-api para buscar ListarEtapasFaturamento da API Omie.' . PHP_EOL;
    echo '  Sem essa chamada, a distinção Varejo/Revenda permanece indisponível.' . PHP_EOL;
}

echo PHP_EOL . $hr . PHP_EOL;
echo '  Concluído em ' . $elapsed . 's' . PHP_EOL;
echo $hr . PHP_EOL . PHP_EOL;
