<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Cache;
use BIomie\CanonicalRepository;
use BIomie\Database;
use BIomie\OmieApiReconciliationService;
use BIomie\OmieClient;
use BIomie\OmieConsolidatedCsvService;
use BIomie\OmieMethodPolicyRegistry;
use BIomie\OmieRepository;
use BIomie\Support;

require __DIR__ . '/app/page_bootstrap.php';
Auth::requireCapability('fiscal.view', false);

$message = null;
$messageType = 'success';
$report = null;
$cache = new Cache(__DIR__ . '/storage/cache');
$currentUser = Auth::currentUser();
$csrf = Auth::csrfToken();
$canonical = null;
$recentRuns = [];
$selectedRunId = isset($_GET['run_id']) ? max(0, (int) $_GET['run_id']) : 0;
$formStart = isset($_REQUEST['start']) ? (Support::formatDate((string) $_REQUEST['start']) ?? date('Y-m-01')) : date('Y-m-01');
$formEnd = isset($_REQUEST['end']) ? (Support::formatDate((string) $_REQUEST['end']) ?? date('Y-m-t')) : date('Y-m-t');

try {
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (Database::isConfigured($config, $connection)) {
        $pdo = Database::connect($config, $connection);
        $canonical = new CanonicalRepository($pdo, $logger);
        $canonical->ensureFiscalSchema();
        $recentRuns = $canonical->listRecentReconciliationSnapshots(12);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $report = $canonical->loadReconciliationSnapshot($selectedRunId > 0 ? $selectedRunId : null);
            if (is_array($report) && $selectedRunId <= 0) {
                $selectedRunId = (int) ($report['saved']['run_id'] ?? 0);
            }
            if (is_array($report)) {
                $formStart = Support::formatDate((string) ($report['source']['period']['start'] ?? '')) ?? $formStart;
                $formEnd = Support::formatDate((string) ($report['source']['period']['end'] ?? '')) ?? $formEnd;
            }
        }
    }
} catch (Throwable $e) {
    $message = 'Nao foi possivel carregar o historico de conciliacao.';
    $messageType = 'error';
    $logger->error('Falha ao carregar historico da conciliacao fiscal.', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireAnyCapability(['fiscal.sync', 'jobs.run'], false);

    try {
        if (!Auth::verifyCsrf($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Sessao do formulario expirada. Atualize a pagina e tente novamente.');
        }

        if (!$canonical instanceof CanonicalRepository) {
            throw new RuntimeException('Banco canonico nao configurado.');
        }

        $action = (string) ($_POST['action'] ?? 'run_api');

        if ($action === 'run_api') {
            $formStart = Support::formatDate((string) ($_POST['start'] ?? '')) ?? '';
            $formEnd = Support::formatDate((string) ($_POST['end'] ?? '')) ?? '';

            if ($formStart === '' || $formEnd === '') {
                throw new RuntimeException('Informe um periodo valido para consultar a API do Omie.');
            }

            if ($formStart > $formEnd) {
                throw new RuntimeException('A data inicial nao pode ser maior que a final.');
            }

            if (!$config->hasOmieCredentials()) {
                throw new RuntimeException('Credenciais do Omie nao configuradas para a conciliacao automatica.');
            }

            $client = new OmieClient(
                (string) $config->get('omie.app_key'),
                (string) $config->get('omie.app_secret'),
                (int) $config->get('omie.request_timeout', 20),
                $logger,
                new OmieMethodPolicyRegistry($config),
                $cache,
            );
            $repository = new OmieRepository($client, $config);
            $service = new OmieApiReconciliationService($repository, $logger);
            $systemRows = $canonical->listFiscalDocumentsByIssueDate($formStart, $formEnd);
            $report = $service->buildPeriodReport($formStart, $formEnd, $systemRows);
        } elseif ($action === 'run_csv') {
            if (!isset($_FILES['consolidado_csv']) || !is_array($_FILES['consolidado_csv'])) {
                throw new RuntimeException('Envie o CSV consolidado do Omie para continuar.');
            }

            $file = $_FILES['consolidado_csv'];
            if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Falha no upload do CSV. Tente selecionar o arquivo novamente.');
            }

            $tmpName = (string) ($file['tmp_name'] ?? '');
            if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                throw new RuntimeException('Arquivo de upload invalido.');
            }

            $service = new OmieConsolidatedCsvService();
            $parsed = $service->parseFile($tmpName, (string) ($file['name'] ?? 'consolidado.csv'));
            $formStart = Support::formatDate((string) ($parsed['period']['start'] ?? '')) ?? $formStart;
            $formEnd = Support::formatDate((string) ($parsed['period']['end'] ?? '')) ?? $formEnd;
            $systemRows = $canonical->listFiscalDocumentsByIssueDate($formStart, $formEnd);
            $report = $service->compareWithSystem($parsed, $systemRows);
            $report['source']['source_type'] = 'omie_csv';
        } else {
            throw new RuntimeException('Acao de conciliacao invalida.');
        }

        $selectedRunId = $canonical->saveReconciliationSnapshot($report, $currentUser);
        $report = $canonical->loadReconciliationSnapshot($selectedRunId) ?? $report;
        $recentRuns = $canonical->listRecentReconciliationSnapshots(12);
        $message = 'Conciliacao concluida e salva no banco. A tela abre esse snapshot sem reenviar arquivo.';
        $messageType = 'success';
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'error';
        $logger->error('Falha na conciliacao fiscal.', [
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}

echo \BIomie\PageRenderer::renderPhp('reconciliacao.html', [
    'page_key' => 'conciliacao',
    'user' => $currentUser,
    'is_admin' => Auth::isAdmin(),
    'csrf' => $csrf,
    'message' => $message,
    'messageType' => $messageType,
    'report' => $report,
    'recentRuns' => $recentRuns,
    'selectedRunId' => $selectedRunId,
    'formStart' => $formStart,
    'formEnd' => $formEnd,
]);
