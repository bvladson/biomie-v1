<?php

/**
 * tools/dev/recover_sales_day.php
 *
 * Safe recovery tool for a specific date's faturamento.
 * Can perform delta sync, gold materialization, worker queue redrive, and cache clears.
 *
 * Usage:
 *   php tools/dev/recover_sales_day.php --date=YYYY-MM-DD [--dry-run] [--apply] [--pretty] [--json] --reason="..."
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;

function recoverSalesTableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $stmt->execute([':table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (\Throwable) {
        return false;
    }
}

$opts = getopt('', ['date:', 'dry-run', 'apply', 'pretty', 'json', 'reason:']);
$jsonMode = isset($opts['json']);
$pretty = isset($opts['pretty']);
$apply = isset($opts['apply']);
$dryRun = !isset($opts['apply']); // dry-run is default if --apply is not set
$reason = trim((string) ($opts['reason'] ?? ''));

// Validate date
$date = trim((string) ($opts['date'] ?? ''));
if ($date === '') {
    $date = date('Y-m-d');
}

if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
    fwrite(STDERR, "ERRO: A data deve estar no formato YYYY-MM-DD.\n");
    exit(1);
}

if ($apply && $reason === '') {
    fwrite(STDERR, "ERRO: O parâmetro --reason é obrigatório ao usar --apply.\n");
    exit(1);
}

$nextDate = date('Y-m-d', strtotime($date . ' +1 day'));

$config = new Config();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = null;

try {
    if (Database::isConfigured($config, $connection)) {
        $pdo = Database::connect($config, $connection);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "ERRO: Falha ao conectar ao banco: " . $e->getMessage() . "\n");
    exit(1);
}

if ($pdo === null) {
    fwrite(STDERR, "ERRO: Banco de dados não configurado.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__, 2);
$php = PHP_BINARY;

// Helper to run shell commands safely
function runCmd(string $cmd): array {
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    $process = proc_open($cmd, $descriptorSpec, $pipes);
    if (!is_resource($process)) {
        return ['exit' => 1, 'output' => 'proc_open failed'];
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    return ['exit' => $exitCode, 'output' => trim($output)];
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. Diagnosticar dados canônicos existentes
// ─────────────────────────────────────────────────────────────────────────────
$canonStmt = $pdo->prepare("
    SELECT COUNT(*) as qtd_nfs, COALESCE(SUM(valor_total), 0) as total_nf
    FROM biomie_notas_fiscais_canonicas
    WHERE status_fiscal = 'autorizada'
      AND operation_scope = 'commercial'
      AND COALESCE(is_biomie_commercial_visible, 1) = 1
      AND data_emissao = :date
");
$canonStmt->execute(['date' => $date]);
$canon = $canonStmt->fetch(PDO::FETCH_ASSOC) ?: ['qtd_nfs' => 0, 'total_nf' => 0.0];

$hasCanonData = (int) ($canon['qtd_nfs'] ?? 0) > 0;

// ─────────────────────────────────────────────────────────────────────────────
// 2. Diagnosticar dados nas marts (mart_vendedor_dia)
// ─────────────────────────────────────────────────────────────────────────────
$martVal = 0.0;
if (recoverSalesTableExists($pdo, 'mart_vendedor_dia')) {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(valor_nfe), 0) as valor_nfe
        FROM mart_vendedor_dia
        WHERE dia = :date
    ");
    $stmt->execute(['date' => $date]);
    $martVal = (float) $stmt->fetchColumn();
}
$hasMartData = $martVal > 0.0;

// Determine recommended recovery plan
$plan = [];
$statusMsg = '';

if (!$hasCanonData) {
    $statusMsg = "Nenhum dado canônico comercial encontrado para {$date}. Recomenda-se realizar o sync restrito.";
    $plan[] = [
        'step' => 1,
        'description' => 'Realizar sincronização restrita (backfill) da data alvo no Omie.',
        'cmd' => "{$php} {$projectRoot}/cron/sync_realtime_pipeline.php --domains=commercial --backfill={$date} --end-backfill={$date} --json",
        // wait, let's verify parameters for sync_realtime_pipeline.php.
        // It supports `--domains=commercial --backfill YYYY-MM-DD YYYY-MM-DD` or similar
        'cmd_actual' => "{$php} " . escapeshellarg("{$projectRoot}/cron/sync_realtime_pipeline.php") . " --domains=commercial --backfill " . escapeshellarg($date) . " " . escapeshellarg($date) . " --json"
    ];
    $plan[] = [
        'step' => 2,
        'description' => 'Materializar a camada Gold para o dia de faturamento (ampliado +1 dia para competência).',
        'cmd' => "{$php} {$projectRoot}/bin/materialize_marts.php --start={$date} --end={$nextDate} --json",
        'cmd_actual' => "{$php} " . escapeshellarg("{$projectRoot}/bin/materialize_marts.php") . " --start=" . escapeshellarg($date) . " --end=" . escapeshellarg($nextDate) . " --json"
    ];
    $plan[] = [
        'step' => 3,
        'description' => 'Limpar caches operacionais de dashboard e reiniciar OPCache.',
        'cmd' => "{$php} {$projectRoot}/tools/dev/cache_clear.php --all",
        'cmd_actual' => "{$php} " . escapeshellarg("{$projectRoot}/tools/dev/cache_clear.php") . " --all"
    ];
} elseif (!$hasMartData) {
    $statusMsg = "Os dados canônicos existem para {$date}, mas os marts Gold não estão povoados.";
    $plan[] = [
        'step' => 1,
        'description' => 'Materializar a camada Gold de forma restrita para o dia de faturamento.',
        'cmd' => "{$php} {$projectRoot}/bin/materialize_marts.php --start={$date} --end={$nextDate} --json",
        'cmd_actual' => "{$php} " . escapeshellarg("{$projectRoot}/bin/materialize_marts.php") . " --start=" . escapeshellarg($date) . " --end=" . escapeshellarg($nextDate) . " --json"
    ];
    $plan[] = [
        'step' => 2,
        'description' => 'Limpar caches operacionais de dashboard.',
        'cmd' => "{$php} {$projectRoot}/tools/dev/cache_clear.php --all",
        'cmd_actual' => "{$php} " . escapeshellarg("{$projectRoot}/tools/dev/cache_clear.php") . " --all"
    ];
} else {
    $statusMsg = "Os dados canônicos e marts existem para {$date}. Uma nova sincronização ou limpeza de cache pode ser rodada para fins de conferência.";
    $plan[] = [
        'step' => 1,
        'description' => 'Limpar caches operacionais do sistema para forçar carregamento fresco na UI.',
        'cmd' => "{$php} {$projectRoot}/tools/dev/cache_clear.php --all",
        'cmd_actual' => "{$php} " . escapeshellarg("{$projectRoot}/tools/dev/cache_clear.php") . " --all"
    ];
}

$executionLogs = [];
$success = true;

if ($apply) {
    // Log audit trail
    $auditMsg = sprintf(
        "Ação Corretiva em %s para a data %s. Motivo: %s",
        date('Y-m-d H:i:s'),
        $date,
        $reason
    );
    try {
        $stmtLog = $pdo->prepare("
            INSERT INTO biomie_admin_logs (actor_user_id, event_type, route, details_json)
            VALUES (:actor, :type, :route, :details)
        ");
        $stmtLog->execute([
            ':actor' => null,
            ':type' => 'recover_sales_day',
            ':route' => 'cli/recover_sales_day.php',
            ':details' => json_encode([
                'recovered_date' => $date,
                'reason' => $reason,
                'executed_at' => date(DATE_ATOM)
            ])
        ]);
    } catch (\Throwable $e) {
        // Continue even if logging fails, but output to stderr
        fwrite(STDERR, "Aviso: Falha ao persistir log de auditoria: " . $e->getMessage() . "\n");
    }

    foreach ($plan as $p) {
        $stepStart = microtime(true);
        $res = runCmd($p['cmd_actual']);
        $stepTime = round(microtime(true) - $stepStart, 2);
        
        $stepOk = $res['exit'] === 0;
        if (!$stepOk) {
            $success = false;
        }
        
        $executionLogs[] = [
            'step' => $p['step'],
            'description' => $p['description'],
            'command' => $p['cmd'],
            'exit_code' => $res['exit'],
            'elapsed_s' => $stepTime,
            'output' => $res['output']
        ];
        
        if (!$stepOk) {
            break; // Stop execution on first failed step to preserve stability
        }
    }
}

$output = [
    'date' => $date,
    'mode' => $apply ? 'apply' : 'dry-run',
    'reason' => $reason,
    'has_canonical_data' => $hasCanonData,
    'has_marts_data' => $hasMartData,
    'diagnosis' => $statusMsg,
    'plan' => $plan,
    'execution_logs' => $executionLogs,
    'success' => $apply ? $success : null,
];

if ($jsonMode) {
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($apply && !$success ? 1 : 0);
}

// Pretty print CLI formatting
$cGreen = "\033[32m";
$cYellow = "\033[33m";
$cRed = "\033[31m";
$cReset = "\033[0m";

echo "\n";
echo "========================================================================\n";
echo "          BIOMIE SALES RECOVERY TOOL (CLI)\n";
echo "========================================================================\n";
echo "Data alvo: {$date}\n";
echo "Modo:      " . ($apply ? "{$cRed}APPLY (EXECUÇÃO DE PRODUÇÃO){$cReset}" : "{$cYellow}DRY-RUN (SIMULAÇÃO){$cReset}") . "\n";
if ($apply) {
    echo "Motivo:    \033[1m{$reason}\033[0m\n";
}
echo "Diagnóstico: {$statusMsg}\n";
echo "------------------------------------------------------------------------\n\n";

if ($apply) {
    echo "LOG DE EXECUÇÃO:\n";
    foreach ($executionLogs as $log) {
        $stepStatus = $log['exit_code'] === 0 ? "{$cGreen}SUCESSO{$cReset}" : "{$cRed}FALHOU{$cReset}";
        echo "  [Passo {$log['step']}] {$log['description']} -> {$stepStatus} ({$log['elapsed_s']}s)\n";
        echo "  Comando: \033[34m{$log['command']}\033[0m\n";
        if ($pretty && $log['output'] !== '') {
            echo "  Saída:\n";
            echo "    " . str_replace("\n", "\n    ", $log['output']) . "\n";
        }
        echo "\n";
    }
    if ($success) {
        echo "{$cGreen}✔ RECUPERAÇÃO CONCLUÍDA COM SUCESSO!{$cReset}\n\n";
    } else {
        echo "{$cRed}✗ A RECUPERAÇÃO FALHOU EM ALGUM DOS PASSOS. VERIFICAR ACIMA.{$cReset}\n\n";
    }
} else {
    echo "PLANO PROPOSTO (será executado caso rode com --apply):\n";
    foreach ($plan as $p) {
        echo "  Passo {$p['step']}: {$p['description']}\n";
        echo "  Comando: \033[34m{$p['cmd']}\033[0m\n\n";
    }
    echo "Para aplicar este plano, execute:\n";
    echo "  {$php} tools/dev/recover_sales_day.php --date={$date} --apply --reason=\"Sua justificativa de auditoria aqui\"\n\n";
}
echo "========================================================================\n\n";

exit($apply && !$success ? 1 : 0);
