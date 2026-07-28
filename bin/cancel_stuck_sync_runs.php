<?php

/**
 * Cancela runs de sincronização presos em status 'running' há mais de N minutos.
 *
 * Uso:
 *   php bin/cancel_stuck_sync_runs.php [--threshold=60] [--dry-run] [--company=SLUG]
 *
 * Opções:
 *   --threshold=N  Minutos para considerar um run como preso (padrão: 60)
 *   --dry-run      Apenas lista os runs presos sem cancelar
 *   --company=SLUG Filtra por empresa específica (opcional; sem filtro = todas as empresas)
 *
 * Saída: JSON com lista de runs afetados.
 */

declare(strict_types=1);

use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

// ── Parse de argumentos ───────────────────────────────────────────────────────

$opts = [];
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--([a-z0-9_-]+)=(.+)$/', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    } elseif (preg_match('/^--([a-z0-9_-]+)$/', $arg, $m)) {
        $opts[$m[1]] = true;
    }
}

$thresholdMinutes = isset($opts['threshold']) ? max(1, (int) $opts['threshold']) : 60;
$dryRun           = isset($opts['dry-run']);
$companySlugFilter= isset($opts['company']) ? strtoupper(trim((string) $opts['company'])) : null;

// ── Bootstrap ────────────────────────────────────────────────────────────────

$logPath = dirname(__DIR__) . '/storage/logs/cancel_stuck_sync_runs.log';
$logger  = new Logger($logPath);
$config  = new Config();

$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';

if (!Database::isConfigured($config, $connection)) {
    echo "[ERRO] Banco de dados não configurado.\n";
    exit(1);
}

$pdo = Database::connect($config, $connection);

echo sprintf(
    "[%s] cancel_stuck_sync_runs: threshold=%dmin dry_run=%s company_filter=%s\n",
    date('Y-m-d H:i:s'),
    $thresholdMinutes,
    $dryRun ? 'true' : 'false',
    $companySlugFilter ?? 'todas'
);

// ── Descobrir company_id pelo slug (se filtro especificado) ──────────────────

$companyIdFilter = null;
if ($companySlugFilter !== null) {
    try {
        $slugStmt = $pdo->prepare(
            "SELECT id FROM biomie_companies WHERE UPPER(slug) = :slug LIMIT 1"
        );
        $slugStmt->execute([':slug' => $companySlugFilter]);
        $row = $slugStmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            $companyIdFilter = (int) $row['id'];
        } else {
            echo "[AVISO] Empresa '$companySlugFilter' não encontrada. Sem filtro de empresa.\n";
        }
    } catch (\Throwable) {
        echo "[AVISO] Não foi possível buscar empresa. Sem filtro de empresa.\n";
    }
}

// ── Listar runs presos ───────────────────────────────────────────────────────

try {
    $whereExtra = '';
    $params = [':threshold' => $thresholdMinutes];

    if ($companyIdFilter !== null) {
        $whereExtra = ' AND r.company_id = :company_id';
        $params[':company_id'] = $companyIdFilter;
    }

    $listSql = "
        SELECT r.id, r.company_id, r.domain_name, r.run_type, r.started_at,
               TIMESTAMPDIFF(MINUTE, r.started_at, NOW()) AS stuck_minutes,
               c.slug AS company_slug
        FROM biomie_sync_runs r
        LEFT JOIN biomie_companies c ON c.id = r.company_id
        WHERE r.status = 'running'
          AND r.started_at < NOW() - INTERVAL :threshold MINUTE
        {$whereExtra}
        ORDER BY r.started_at ASC
    ";

    $listStmt = $pdo->prepare($listSql);
    foreach ($params as $k => $v) $listStmt->bindValue($k, $v);
    $listStmt->execute();
    $stuckRuns = $listStmt->fetchAll(\PDO::FETCH_ASSOC);

} catch (\Throwable $e) {
    echo "[ERRO] Falha ao listar runs presos: " . $e->getMessage() . "\n";
    exit(1);
}

if (empty($stuckRuns)) {
    echo "[OK] Nenhum run preso encontrado (threshold={$thresholdMinutes}min).\n";
    exit(0);
}

echo sprintf("[INFO] %d run(s) preso(s) encontrado(s):\n", count($stuckRuns));
foreach ($stuckRuns as $run) {
    echo sprintf(
        "  id=%-6d company=%-15s domain=%-12s type=%-10s started=%s stuck=%dmin\n",
        $run['id'],
        $run['company_slug'] ?? "id:{$run['company_id']}",
        $run['domain_name'],
        $run['run_type'],
        $run['started_at'],
        $run['stuck_minutes']
    );
}

if ($dryRun) {
    echo "[DRY-RUN] Nenhum run cancelado.\n";
    exit(0);
}

// ── Cancelar runs presos ─────────────────────────────────────────────────────

$ids = array_column($stuckRuns, 'id');
$cancelled = [];
$errors    = [];

try {
    // Cancelar runs
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $cancelStmt = $pdo->prepare("
        UPDATE biomie_sync_runs
        SET status        = 'failed_terminal',
            error_summary = 'Cancelado automaticamente: run preso > {$thresholdMinutes} minutos',
            finished_at   = NOW(),
            updated_at    = NOW()
        WHERE id IN ({$placeholders})
          AND status = 'running'
    ");
    $cancelStmt->execute($ids);
    $affected = $cancelStmt->rowCount();

    echo sprintf("[OK] %d run(s) cancelado(s).\n", $affected);
    $cancelled = array_map(fn($r) => (int)$r['id'], $stuckRuns);

    // Também resetar cursores presos (se a tabela existir)
    try {
        $cursorCheck = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biomie_sync_cursors'"
        );
        if ((int) $cursorCheck->fetchColumn() > 0) {
            $cursorWhere = "status = 'running' AND last_run_started_at < NOW() - INTERVAL {$thresholdMinutes} MINUTE";
            if ($companyIdFilter !== null) {
                $cursorWhere .= " AND company_id = {$companyIdFilter}";
            }
            $cursorStmt = $pdo->prepare("
                UPDATE biomie_sync_cursors
                SET status = 'idle', updated_at = NOW()
                WHERE {$cursorWhere}
            ");
            $cursorStmt->execute();
            $cursorsReset = $cursorStmt->rowCount();
            if ($cursorsReset > 0) {
                echo sprintf("[OK] %d cursor(es) resetado(s) para idle.\n", $cursorsReset);
            }
        }
    } catch (\Throwable) {
        // Cursores são opcionais
    }

} catch (\Throwable $e) {
    echo "[ERRO] Falha ao cancelar runs: " . $e->getMessage() . "\n";
    $errors[] = $e->getMessage();
}

$result = [
    'ok'        => empty($errors),
    'found'     => count($stuckRuns),
    'cancelled' => $cancelled,
    'errors'    => $errors,
    'threshold_minutes' => $thresholdMinutes,
    'company_filter' => $companySlugFilter,
    'dry_run'   => false,
    'executed_at' => date('Y-m-d H:i:s'),
];

$logger->info('cancel_stuck_sync_runs: concluido.', $result);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
exit(empty($errors) ? 0 : 1);
