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
Auth::requireAdmin(false);

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

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function brl(float|int|null $value): string
{
    return 'R$ ' . number_format((float) $value, 2, ',', '.');
}

function num(int|float|null $value, int $decimals = 0): string
{
    return number_format((float) $value, $decimals, ',', '.');
}

function deltaClass(float|int $value): string
{
    return abs((float) $value) <= 0.05 ? 'neutral' : ((float) $value > 0 ? 'up' : 'down');
}

function sourceLabel(string $sourceType): string
{
    return $sourceType === 'omie_api' ? 'API' : 'CSV';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="assets/js/theme-init.js?v=20260413dark1"></script>
  <meta name="color-scheme" content="light dark" />
  <meta name="theme-color" content="#f6f3f2" media="(prefers-color-scheme: light)" />
  <meta name="theme-color" content="#12151b" media="(prefers-color-scheme: dark)" />
  <title>Conciliacao Fiscal | BIomie</title>
  <link rel="stylesheet" href="assets/css/theme-tokens.css?v=20260413dark2" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <style>
    :root{--bg:var(--bg-base);--card:var(--bg-card);--text:var(--text-1);--muted:var(--text-2);--border:var(--border);--brand:var(--red);--ok:var(--success-strong);--ok-soft:var(--success-soft);--warn:var(--warning-strong);--warn-soft:var(--warning-soft);--error:var(--danger-strong);--error-soft:var(--danger-soft);--shadow:var(--shadow-lg)}
    *{box-sizing:border-box}
    body{margin:0;background:var(--bg);color:var(--text);font-family:var(--font-body)}
    .wrap{max-width:1460px;margin:0 auto;padding:24px}
    .top{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;margin-bottom:24px}
    .top h1{margin:0 0 8px;font-size:2rem;line-height:1.05}
    .muted{color:var(--muted)}
    .actions{display:flex;gap:12px;flex-wrap:wrap}
    .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:12px 16px;border-radius:16px;border:1px solid var(--border);background:var(--bg-surface);color:var(--text);font-weight:700;text-decoration:none;cursor:pointer}
    .btn.primary{background:var(--brand);border-color:var(--brand);color:#fff}
    .btn:hover{opacity:.96}
    .panel{background:var(--card);border:1px solid var(--border);border-radius:24px;padding:20px;box-shadow:var(--shadow)}
    .hero{display:grid;grid-template-columns:1.25fr .95fr;gap:20px;margin-bottom:20px}
    .hero h2{margin:0 0 10px;font-size:1.25rem}
    .hero p{margin:0;color:var(--muted);line-height:1.65}
    .form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
    .form-actions{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
    .stack{display:grid;gap:12px}
    label{display:block;margin-bottom:8px;font-size:.9rem;font-weight:700}
    input[type="date"],input[type="file"]{width:100%;padding:14px;border:1px solid var(--border);border-radius:18px;background:var(--bg-surface);color:var(--text)}
    input[type="file"]{border-style:dashed}
    details{border:1px solid var(--border);border-radius:18px;padding:14px 16px;background:var(--bg-surface)}
    summary{cursor:pointer;font-weight:700}
    .hint{font-size:.84rem;color:var(--muted)}
    .msg{margin-bottom:18px;padding:14px 16px;border-radius:16px}
    .msg.success{background:var(--ok-soft);color:var(--ok)}
    .msg.error{background:var(--error-soft);color:var(--error)}
    .grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}
    .card-kpi{padding:18px;border-radius:20px;background:var(--bg-surface);border:1px solid var(--border)}
    .card-kpi .label{display:block;font-size:.78rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);margin-bottom:8px}
    .card-kpi .value{font-size:1.7rem;font-weight:800;line-height:1.05}
    .card-kpi .sub{margin-top:8px;font-size:.88rem;color:var(--muted)}
    .split{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px}
    .summary-list{display:grid;gap:10px}
    .summary-row{display:flex;justify-content:space-between;gap:10px;padding:12px 0;border-bottom:1px solid var(--border)}
    .summary-row:last-child{border-bottom:none}
    .summary-row strong{font-size:1.02rem}
    .delta{font-weight:800}
    .delta.up{color:var(--warn)}
    .delta.down{color:var(--error)}
    .delta.neutral{color:var(--ok)}
    .section{margin-top:20px}
    .section h2{margin:0 0 8px;font-size:1.16rem}
    .section p{margin:0 0 14px;color:var(--muted)}
    table{width:100%;border-collapse:collapse}
    th,td{padding:12px 10px;border-bottom:1px solid var(--border);text-align:left;vertical-align:top;font-size:.92rem}
    th{font-size:.8rem;color:var(--muted);letter-spacing:.05em;text-transform:uppercase}
    .table-wrap{overflow:auto}
    .tag{display:inline-flex;padding:6px 10px;border-radius:999px;font-size:.78rem;font-weight:800}
    .tag.mismatch{background:var(--warn-soft);color:var(--warn)}
    .tag.missing{background:var(--error-soft);color:var(--error)}
    .tag.source{background:var(--bg-muted);color:var(--text-2)}
    .issues{display:flex;flex-wrap:wrap;gap:8px}
    .issue{display:inline-flex;padding:6px 10px;border-radius:999px;background:var(--bg-muted);color:var(--text-2);font-size:.75rem;font-weight:700}
    .row-soft{background:var(--bg-surface)}
    .warn-box{margin-top:12px;padding:12px 14px;border-radius:14px;background:var(--warn-soft);color:var(--warn)}
    @media (max-width:1200px){.hero,.split,.grid{grid-template-columns:1fr 1fr}}
    @media (max-width:900px){.form-grid{grid-template-columns:1fr}}
    @media (max-width:820px){.hero,.split,.grid{grid-template-columns:1fr}.wrap{padding:14px}.top h1{font-size:1.6rem}}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top">
      <div>
        <h1>Conciliacao fiscal</h1>
        <div class="muted">Logado como <?php echo h((string) ($currentUser['full_name'] ?? '')); ?> - <?php echo h((string) ($currentUser['username'] ?? '')); ?></div>
      </div>
      <div class="actions">
        <a class="btn" href="admin.php">Admin</a>
        <a class="btn" href="observabilidade.php">Observabilidade</a>
        <a class="btn" href="integracoes.php">Conexao</a>
        <a class="btn" href="index.php">Painel</a>
        <a class="btn" href="logout.php">Sair</a>
      </div>
    </div>

    <?php if ($message !== null): ?>
      <div class="msg <?php echo h($messageType); ?>"><?php echo h($message); ?></div>
    <?php endif; ?>

    <div class="hero">
      <div class="panel stack">
        <div>
          <h2>Rodar direto do Omie</h2>
          <p>Usa a API <strong>ListarNF</strong> por <strong>data de emissao</strong>, separando autorizadas e canceladas no mesmo periodo. O resultado fica salvo em banco para consulta rapida depois.</p>
        </div>
        <form class="stack" method="post">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>" />
          <input type="hidden" name="action" value="run_api" />
          <div class="form-grid">
            <div>
              <label for="start">Data inicial</label>
              <input id="start" name="start" type="date" value="<?php echo h($formStart); ?>" required />
            </div>
            <div>
              <label for="end">Data final</label>
              <input id="end" name="end" type="date" value="<?php echo h($formEnd); ?>" required />
            </div>
          </div>
          <div class="form-actions">
            <button class="btn primary" type="submit">Conciliar periodo</button>
            <span class="hint">Nao depende de Excel, CSV ou upload. A leitura da API eh ao vivo.</span>
          </div>
        </form>
        <details>
          <summary>Usar CSV so como contingencia</summary>
          <form class="stack" method="post" enctype="multipart/form-data" style="margin-top:14px">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>" />
            <input type="hidden" name="action" value="run_csv" />
            <div>
              <label for="consolidado_csv">CSV consolidado</label>
              <input id="consolidado_csv" name="consolidado_csv" type="file" accept=".csv,text/csv" />
            </div>
            <div class="hint">Mantido apenas como validacao externa ou contingencia operacional.</div>
            <div><button class="btn" type="submit">Conciliar CSV</button></div>
          </form>
        </details>
      </div>

      <div class="panel">
        <h2>Ultimos snapshots</h2>
        <p>A tela abre o ultimo snapshot salvo automaticamente. Para gerar outro, basta informar o periodo.</p>
        <div class="summary-list" style="margin-top:10px">
          <?php if ($recentRuns === []): ?>
            <div class="summary-row"><span>Historico</span><strong>Nenhum snapshot salvo</strong></div>
          <?php else: ?>
            <?php foreach ($recentRuns as $run): ?>
              <?php
                $runId = (int) ($run['id'] ?? 0);
                $runSourceType = (string) ($run['source_type'] ?? 'omie_csv');
              ?>
              <div class="summary-row">
                <span>
                  <a href="reconciliacao.php?run_id=<?php echo $runId; ?>" style="color:inherit;text-decoration:none;font-weight:700">
                    <?php echo h((string) (($run['period_start'] ?? '') . ' a ' . ($run['period_end'] ?? ''))); ?>
                  </a>
                  <div class="muted" style="font-size:.82rem">
                    <span class="tag source"><?php echo h(sourceLabel($runSourceType)); ?></span>
                    <?php echo h((string) ($run['created_at'] ?? '')); ?>
                  </div>
                </span>
                <strong style="<?php echo $selectedRunId === $runId ? 'color:var(--brand)' : ''; ?>">
                  <?php echo num((int) ($run['differences_count'] ?? 0)); ?> dif.
                </strong>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if (is_array($report)): ?>
      <?php
        $omie = $report['omie_summary'] ?? [];
        $system = $report['system_summary'] ?? [];
        $metrics = $report['metrics'] ?? [];
        $source = $report['source'] ?? [];
        $vendorComparison = is_array($report['vendor_comparison'] ?? null) ? $report['vendor_comparison'] : [];
        $differences = is_array($report['differences'] ?? null) ? $report['differences'] : [];
        $sourceType = (string) ($source['source_type'] ?? 'omie_csv');
        $sourceOrigin = $sourceType === 'omie_api'
            ? 'Fechamento lido direto da API do Omie. Se houver um CSV mais antigo do mesmo dia, a API pode trazer NF novas emitidas depois da exportacao.'
            : 'Fechamento lido a partir do consolidado enviado.';
        $sourceName = (string) ($source['source_file'] ?? 'Origem externa');
        $notesOmie = (int) ($metrics['notes_omie'] ?? $metrics['notes_csv'] ?? 0);
        $warningTitle = $sourceType === 'omie_api' ? 'Alertas da leitura' : 'Alertas do arquivo';
      ?>
      <div class="grid">
        <div class="card-kpi">
          <span class="label">Periodo</span>
          <div class="value" style="font-size:1.1rem"><?php echo h((string) (($source['period']['label'] ?? '-') ?: '-')); ?></div>
          <div class="sub"><?php echo h($sourceName); ?></div>
        </div>
        <div class="card-kpi">
          <span class="label">NF Omie</span>
          <div class="value"><?php echo num($notesOmie); ?></div>
          <div class="sub"><?php echo $sourceType === 'omie_api' ? 'NF unicas vindas da API' : 'NF unicas no consolidado'; ?></div>
        </div>
        <div class="card-kpi">
          <span class="label">NF BIomie</span>
          <div class="value"><?php echo num((int) ($metrics['notes_system'] ?? 0)); ?></div>
          <div class="sub">NF encontradas na base canonica</div>
        </div>
        <div class="card-kpi">
          <span class="label">Diferencas</span>
          <div class="value"><?php echo num((int) ($metrics['differences'] ?? 0)); ?></div>
          <div class="sub"><?php echo num((int) ($metrics['matches'] ?? 0)); ?> NF ja batendo</div>
        </div>
      </div>

      <div class="split">
        <div class="panel">
          <h2>Omie</h2>
          <p><?php echo h($sourceOrigin); ?></p>
          <div class="summary-list">
            <div class="summary-row"><span>Autorizadas</span><strong><?php echo num((int) ($omie['authorized_notes'] ?? 0)); ?> NF</strong></div>
            <div class="summary-row"><span>Total NF autorizada</span><strong><?php echo brl((float) ($omie['authorized_invoice_total'] ?? 0)); ?></strong></div>
            <div class="summary-row"><span>Mercadoria autorizada</span><strong><?php echo brl((float) ($omie['authorized_merchandise_total'] ?? 0)); ?></strong></div>
            <div class="summary-row"><span>Desconto autorizado</span><strong><?php echo brl((float) ($omie['authorized_discount_total'] ?? 0)); ?></strong></div>
            <div class="summary-row"><span>Canceladas</span><strong><?php echo num((int) ($omie['cancelled_notes'] ?? 0)); ?> NF</strong></div>
            <div class="summary-row"><span>Total NF cancelada</span><strong><?php echo brl((float) ($omie['cancelled_invoice_total'] ?? 0)); ?></strong></div>
          </div>
        </div>

        <div class="panel">
          <h2>BIomie</h2>
          <p>Leitura fiscal da camada canonica no mesmo recorte de emissao.</p>
          <div class="summary-list">
            <div class="summary-row"><span>Autorizadas</span><strong><?php echo num((int) ($system['authorized_notes'] ?? 0)); ?> NF</strong><span class="delta <?php echo deltaClass((int) ($system['authorized_notes'] ?? 0) - (int) ($omie['authorized_notes'] ?? 0)); ?>"><?php echo num((int) ($system['authorized_notes'] ?? 0) - (int) ($omie['authorized_notes'] ?? 0)); ?></span></div>
            <div class="summary-row"><span>Total NF autorizada</span><strong><?php echo brl((float) ($system['authorized_invoice_total'] ?? 0)); ?></strong><span class="delta <?php echo deltaClass((float) ($system['authorized_invoice_total'] ?? 0) - (float) ($omie['authorized_invoice_total'] ?? 0)); ?>"><?php echo brl((float) ($system['authorized_invoice_total'] ?? 0) - (float) ($omie['authorized_invoice_total'] ?? 0)); ?></span></div>
            <div class="summary-row"><span>Mercadoria autorizada</span><strong><?php echo brl((float) ($system['authorized_merchandise_total'] ?? 0)); ?></strong><span class="delta <?php echo deltaClass((float) ($system['authorized_merchandise_total'] ?? 0) - (float) ($omie['authorized_merchandise_total'] ?? 0)); ?>"><?php echo brl((float) ($system['authorized_merchandise_total'] ?? 0) - (float) ($omie['authorized_merchandise_total'] ?? 0)); ?></span></div>
            <div class="summary-row"><span>Desconto autorizado</span><strong><?php echo brl((float) ($system['authorized_discount_total'] ?? 0)); ?></strong><span class="delta <?php echo deltaClass((float) ($system['authorized_discount_total'] ?? 0) - (float) ($omie['authorized_discount_total'] ?? 0)); ?>"><?php echo brl((float) ($system['authorized_discount_total'] ?? 0) - (float) ($omie['authorized_discount_total'] ?? 0)); ?></span></div>
            <div class="summary-row"><span>Canceladas</span><strong><?php echo num((int) ($system['cancelled_notes'] ?? 0)); ?> NF</strong><span class="delta <?php echo deltaClass((int) ($system['cancelled_notes'] ?? 0) - (int) ($omie['cancelled_notes'] ?? 0)); ?>"><?php echo num((int) ($system['cancelled_notes'] ?? 0) - (int) ($omie['cancelled_notes'] ?? 0)); ?></span></div>
            <div class="summary-row"><span>Total NF cancelada</span><strong><?php echo brl((float) ($system['cancelled_invoice_total'] ?? 0)); ?></strong><span class="delta <?php echo deltaClass((float) ($system['cancelled_invoice_total'] ?? 0) - (float) ($omie['cancelled_invoice_total'] ?? 0)); ?>"><?php echo brl((float) ($system['cancelled_invoice_total'] ?? 0) - (float) ($omie['cancelled_invoice_total'] ?? 0)); ?></span></div>
          </div>
        </div>
      </div>

      <?php if (!empty($source['warnings']) && is_array($source['warnings'])): ?>
        <div class="panel">
          <h2><?php echo h($warningTitle); ?></h2>
          <?php foreach ($source['warnings'] as $warning): ?>
            <div class="warn-box"><?php echo h((string) $warning); ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="section panel">
        <h2>Mesa de diferencas</h2>
        <p>Somente NF com divergencia, falta ou sobra.</p>
        <?php if ($differences === []): ?>
          <div class="warn-box" style="background:var(--ok-soft);color:var(--ok)">Nenhuma divergencia encontrada. O Omie esta alinhado com a base fiscal do BIomie neste recorte.</div>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>NF</th>
                  <th>Tipo</th>
                  <th>Omie</th>
                  <th>BIomie</th>
                  <th>Deltas</th>
                  <th>Leitura</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($differences as $row): ?>
                  <?php $type = (string) ($row['type'] ?? 'mismatch'); $tagClass = $type === 'missing_in_system' || $type === 'extra_in_system' ? 'missing' : 'mismatch'; ?>
                  <tr class="row-soft">
                    <td><strong><?php echo h((string) ($row['note_ref'] ?? '-')); ?></strong><div class="muted"><?php echo h((string) (($row['csv_issue_date'] ?? $row['system_issue_date']) ?? '-')); ?></div></td>
                    <td><span class="tag <?php echo h($tagClass); ?>"><?php echo h($type); ?></span></td>
                    <td><strong><?php echo h((string) ($row['csv_vendor_name'] ?? '-')); ?></strong><div class="muted"><?php echo h((string) ($row['csv_status'] ?? '-')); ?></div><div><?php echo brl((float) ($row['csv_invoice_total'] ?? 0)); ?></div><div class="muted">Merc.: <?php echo brl((float) ($row['csv_merchandise_total'] ?? 0)); ?> | Desc.: <?php echo brl((float) ($row['csv_discount_total'] ?? 0)); ?></div></td>
                    <td><strong><?php echo h((string) ($row['system_vendor_name'] ?? '-')); ?></strong><div class="muted"><?php echo h((string) ($row['system_status'] ?? '-')); ?></div><div><?php echo brl((float) ($row['system_invoice_total'] ?? 0)); ?></div><div class="muted">Merc.: <?php echo brl((float) ($row['system_merchandise_total'] ?? 0)); ?> | Desc.: <?php echo brl((float) ($row['system_discount_total'] ?? 0)); ?></div></td>
                    <td><div class="delta <?php echo deltaClass((float) ($row['system_invoice_total'] ?? 0) - (float) ($row['csv_invoice_total'] ?? 0)); ?>">NF: <?php echo brl((float) ($row['system_invoice_total'] ?? 0) - (float) ($row['csv_invoice_total'] ?? 0)); ?></div><div class="delta <?php echo deltaClass((float) ($row['system_merchandise_total'] ?? 0) - (float) ($row['csv_merchandise_total'] ?? 0)); ?>">Merc.: <?php echo brl((float) ($row['system_merchandise_total'] ?? 0) - (float) ($row['csv_merchandise_total'] ?? 0)); ?></div><div class="delta <?php echo deltaClass((float) ($row['system_discount_total'] ?? 0) - (float) ($row['csv_discount_total'] ?? 0)); ?>">Desc.: <?php echo brl((float) ($row['system_discount_total'] ?? 0) - (float) ($row['csv_discount_total'] ?? 0)); ?></div></td>
                    <td><div class="issues"><?php foreach (($row['issues'] ?? []) as $issue): ?><span class="issue"><?php echo h((string) $issue); ?></span><?php endforeach; ?></div></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <div class="section panel">
        <h2>Resumo por vendedor</h2>
        <p>Omie x BIomie por autorizado e cancelado.</p>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Vendedor</th>
                <th>Omie autorizado</th>
                <th>BIomie autorizado</th>
                <th>Delta autorizado</th>
                <th>Omie cancelado</th>
                <th>BIomie cancelado</th>
                <th>Delta cancelado</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($vendorComparison as $row): ?>
                <tr class="<?php echo !empty($row['has_difference']) ? 'row-soft' : ''; ?>">
                  <td><strong><?php echo h((string) ($row['vendor_name'] ?? '-')); ?></strong></td>
                  <td><?php echo num((int) ($row['omie_authorized_notes'] ?? 0)); ?> NF | <?php echo brl((float) ($row['omie_authorized_total'] ?? 0)); ?></td>
                  <td><?php echo num((int) ($row['system_authorized_notes'] ?? 0)); ?> NF | <?php echo brl((float) ($row['system_authorized_total'] ?? 0)); ?></td>
                  <td><span class="delta <?php echo deltaClass((float) ($row['delta_authorized_total'] ?? 0)); ?>"><?php echo num((int) ($row['delta_authorized_notes'] ?? 0)); ?> NF | <?php echo brl((float) ($row['delta_authorized_total'] ?? 0)); ?></span></td>
                  <td><?php echo num((int) ($row['omie_cancelled_notes'] ?? 0)); ?> NF | <?php echo brl((float) ($row['omie_cancelled_total'] ?? 0)); ?></td>
                  <td><?php echo num((int) ($row['system_cancelled_notes'] ?? 0)); ?> NF | <?php echo brl((float) ($row['system_cancelled_total'] ?? 0)); ?></td>
                  <td><span class="delta <?php echo deltaClass((float) ($row['delta_cancelled_total'] ?? 0)); ?>"><?php echo num((int) ($row['delta_cancelled_notes'] ?? 0)); ?> NF | <?php echo brl((float) ($row['delta_cancelled_total'] ?? 0)); ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
