<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Http;
use BIomie\Logger;
use BIomie\Modules\Fiscal\Infrastructure\FiscalTaxExtractorService;
use BIomie\Support\TenantCliContext;
use BIomie\Support\TenantContext;

$isCli = PHP_SAPI === 'cli';
if ($isCli) {
    require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
    \BIomie\Support\CliGuard::requireCli();
    require dirname(__DIR__) . '/app/Bootstrap.php';
    Bootstrap::init();
    $config = new Config();
    $logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');
} else {
    require __DIR__ . '/bootstrap.php';
    Auth::requireCapability('admin', true);
}

$isDry = in_array('--dry-run', $argv ?? [], true);
$batch = 500;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with((string) $arg, '--batch=')) {
        $batch = max(1, min(2000, (int) substr((string) $arg, 8)));
    }
}

$conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
if (!Database::isConfigured($config, $conn)) {
    if ($isCli) {
        fwrite(STDERR, "[FiscalTaxExtractor] Banco de dados nao configurado.\n");
        exit(1);
    }
    Http::json(['ok' => false, 'message' => 'Banco de dados não configurado.'], 503);
}

$pdo = Database::connect($config, $conn);
if ($isCli) {
    TenantCliContext::boot($pdo, $argv ?? []);
}
if (TenantContext::isConsolidado()) {
    if ($isCli) {
        fwrite(STDERR, "[FiscalTaxExtractor] Selecione uma empresa, nao o consolidado.\n");
        exit(2);
    }
    Http::json(['ok' => false, 'message' => 'Selecione uma empresa para executar a extração.'], 422);
}

$svc = new FiscalTaxExtractorService(static fn () => $pdo);
$tenantFilterNf = TenantContext::sqlFilter('nf');
$tenantFilterXml = TenantContext::sqlFilter('xml');
$pending = (int) $pdo->query(<<<SQL
    SELECT COUNT(*)
      FROM biomie_notas_fiscais_canonicas nf
      JOIN biomie_documentos_xml_canonicos xml
        ON xml.company_id = nf.company_id
       AND xml.chave_nfe = nf.chave_nfe
     WHERE {$tenantFilterNf}
       AND {$tenantFilterXml}
       AND nf.tax_extracted_at IS NULL
       AND xml.xml_payload IS NOT NULL
       AND TRIM(xml.xml_payload) <> ''
SQL)->fetchColumn();

if ($isCli) {
    echo "[FiscalTaxExtractor] Pendentes: {$pending} NFs\n";
    echo "[FiscalTaxExtractor] Batch size: {$batch}\n";
    if ($isDry) {
        echo "[FiscalTaxExtractor] DRY-RUN — nenhuma atualização será feita.\n";
        exit(0);
    }
}

if ($pending === 0) {
    $message = 'Nenhuma NF pendente de extração de impostos.';
    if ($isCli) {
        echo "[FiscalTaxExtractor] {$message}\n";
        exit(0);
    }
    Http::json(['ok' => true, 'message' => $message, 'pending' => 0, 'updated' => 0]);
}

$result = $svc->extractBatch($batch);
$paymentUpdated = $svc->backfillPaymentMethods();
$message = "Extração concluída: {$result['updated']} NFs atualizadas; restantes: {$result['pending']}.";

if ($isCli) {
    echo "[FiscalTaxExtractor] {$message}\n";
    echo "[FiscalTaxExtractor] Formas de pagamento atualizadas: {$paymentUpdated}\n";
    exit(0);
}

Http::json([
    'ok' => true,
    'message' => $message,
    'pending' => $result['pending'],
    'updated' => $result['updated'],
    'payment_updated' => $paymentUpdated,
]);
