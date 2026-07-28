<?php

declare(strict_types=1);

/**
 * Backfill histórico para ativar uma nova empresa no BIomie.
 *
 * Executa em sequência:
 *   1. Verifica que a empresa tem integração Omie ativa
 *   2. Roda sync_canonical.php mês a mês (bronze + canonical)
 *   3. Materializa os marts gold (bin/materialize_marts.php)
 *
 * Uso:
 *   php bin/initial_sync_company.php --company=expert --from=2024-01
 *   php bin/initial_sync_company.php --company=uni_design --from=2025-01 --to=2026-06
 *   php bin/initial_sync_company.php --company=mag_matriz --from=2024-06 --pause=10 --dry-run
 *
 * Flags:
 *   --company=SLUG    obrigatório
 *   --from=YYYY-MM    obrigatório - mês de início do backfill
 *   --to=YYYY-MM      opcional   - mês final (default: mês atual)
 *   --pause=N         opcional   - segundos de pausa entre meses (default: 8)
 *   --skip-marts      opcional   - pula a materialização de marts
 *   --dry-run         opcional   - não executa, só mostra o plano
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo    = Database::connect($config, $conn);

set_time_limit(0);
ini_set('memory_limit', '256M');

// ── Argumentos ────────────────────────────────────────────────────────────────
$rawArgs   = array_slice($argv ?? [], 1);
$opts      = [];
foreach ($rawArgs as $arg) {
    if (preg_match('/^--([a-z0-9_-]+)=(.+)$/i', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    } elseif (preg_match('/^--([a-z0-9_-]+)$/', $arg, $m)) {
        $opts[$m[1]] = true;
    }
}

$companyArg = (string) ($opts['company'] ?? '');
$fromArg    = (string) ($opts['from'] ?? '');
$toArg      = (string) ($opts['to'] ?? date('Y-m'));
$pauseSec   = max(0, (int) ($opts['pause'] ?? 8));
$skipMarts  = isset($opts['skip-marts']);
$dryRun     = isset($opts['dry-run']);

if ($companyArg === '') {
    fwrite(STDERR, "Erro: --company=SLUG é obrigatório.\n");
    fwrite(STDERR, "Uso: php bin/initial_sync_company.php --company=expert --from=2024-01\n");
    exit(2);
}
if ($fromArg === '' || !preg_match('/^\d{4}-\d{2}$/', $fromArg)) {
    fwrite(STDERR, "Erro: --from=YYYY-MM é obrigatório (ex: --from=2024-01).\n");
    exit(2);
}
if (!preg_match('/^\d{4}-\d{2}$/', $toArg)) {
    fwrite(STDERR, "Erro: --to deve ser YYYY-MM.\n");
    exit(2);
}

// ── Valida empresa e integração ───────────────────────────────────────────────
$stmt = $pdo->prepare(
    "SELECT c.id, c.slug, c.canal_padrao,
            i.active AS integration_active,
            (i.app_key IS NOT NULL AND i.app_key != '') AS has_key,
            (i.app_secret_encrypted IS NOT NULL AND i.app_secret_encrypted != '') AS has_secret
       FROM biomie_companies c
       LEFT JOIN biomie_company_integrations i
         ON i.company_id = c.id AND i.provider = 'omie'
      WHERE c.ativo = 1 AND (c.id = :sel_id OR c.slug = :sel_slug)
      LIMIT 1"
);
$stmt->execute([
    'sel_id'   => ctype_digit($companyArg) ? (int) $companyArg : 0,
    'sel_slug' => strtoupper($companyArg),
]);
$company = $stmt->fetch(\PDO::FETCH_ASSOC);

if (!is_array($company)) {
    fwrite(STDERR, "Empresa ativa não encontrada: {$companyArg}\n");
    exit(2);
}
if (!$company['integration_active'] || !$company['has_key'] || !$company['has_secret']) {
    fwrite(STDERR, "Empresa {$company['slug']} não tem integração Omie ativa.\n");
    fwrite(STDERR, "Execute antes: php bin/company_integration.php register --company={$companyArg} --app-key=KEY --app-secret=SECRET\n");
    exit(2);
}

$slug  = (string) $company['slug'];
$cid   = (int) $company['id'];
$canal = (string) ($company['canal_padrao'] ?: 'varejo');

// ── Plano ─────────────────────────────────────────────────────────────────────
$fromDate = $fromArg . '-01';
$toDate   = date('Y-m-t', strtotime($toArg . '-01'));

// Gera lista de meses
$months = [];
$cursor = strtotime($fromDate);
$end    = strtotime($toDate);
while ($cursor <= $end) {
    $months[] = [
        'start' => date('Y-m-01', $cursor),
        'end'   => date('Y-m-t', $cursor),
    ];
    $cursor = strtotime('+1 month', $cursor);
}

echo "═══════════════════════════════════════════════════════\n";
echo " BIomie — Backfill Inicial de Empresa\n";
echo "═══════════════════════════════════════════════════════\n";
echo " Empresa  : {$slug} (id={$cid}, canal={$canal})\n";
echo " Período  : {$fromArg} → {$toArg} (" . count($months) . " meses)\n";
echo " Pausa    : {$pauseSec}s entre meses\n";
echo " Marts    : " . ($skipMarts ? 'pular' : 'materializar ao final') . "\n";
echo " Modo     : " . ($dryRun ? 'DRY-RUN (nada executado)' : 'EXECUÇÃO REAL') . "\n";
echo "═══════════════════════════════════════════════════════\n\n";

if ($dryRun) {
    echo "Meses a processar:\n";
    foreach ($months as $i => $m) {
        echo sprintf("  %2d. %s → %s\n", $i + 1, $m['start'], $m['end']);
    }
    echo "\nExemplo de comando gerado:\n";
    echo "  php cron/sync_canonical.php --company={$slug} --from={$fromArg} --to={$toArg} --pause={$pauseSec}\n";
    if (!$skipMarts) {
        echo "  php bin/materialize_marts.php --company={$slug} --start={$fromDate} --end={$toDate} --months=1\n";
    }
    exit(0);
}

// ── Canonical Sync (bronze + canonical) ───────────────────────────────────────
$phpBin        = PHP_BINARY;
$canonicalScript = dirname(__DIR__) . '/cron/sync_canonical.php';

echo "[" . date('Y-m-d H:i:s') . "] Iniciando canonical sync...\n";

$syncCmd = [
    $phpBin,
    $canonicalScript,
    "--company={$slug}",
    "--from={$fromArg}",
    "--to={$toArg}",
    "--pause={$pauseSec}",
];

// Rate-limit da Omie é esperado em backfills longos: os retries precisam ser
// pacientes (aguardam o tempo anunciado) para o processo não morrer no meio.
$maxRetries = 12;
$attempt = 0;
$syncExit = 1;
$syncStderr = '';

while ($attempt < $maxRetries) {
    $attempt++;
    if ($attempt > 1) {
        echo "[" . date('Y-m-d H:i:s') . "] Tentativa {$attempt}/{$maxRetries} após rate-limit...\n";
        flush();
    }

    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($syncCmd, $desc, $pipes, dirname(__DIR__));

    if (!is_resource($proc)) {
        fwrite(STDERR, "Falha ao iniciar sync_canonical.php\n");
        exit(1);
    }

    fclose($pipes[0]);
    while (!feof($pipes[1])) {
        $line = fgets($pipes[1]);
        if ($line !== false) {
            echo $line;
            flush();
        }
    }

    $syncStderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $syncExit = proc_close($proc);

    if ($syncExit === 0) {
        break;
    }

    // Detecta rate-limit/redundância Omie e aguarda antes de retentar.
    // As mensagens variam: "Tente novamente em N segundos" (Omie 425),
    // "reagende em N segundos" (limiter global do BIomie) e
    // "Aguarde N segundos para tentar novamente (REDUNDANT)" (anti-redundância).
    // Match por palavra-chave + qualquer "N segundos" — antes o backfill morria
    // no meio quando a mensagem não casava (causa dos históricos vazios).
    $isThrottle = preg_match('/rate limit|redundante|redundant|tente novamente|reagende|aguarde/i', $syncStderr) === 1;
    if ($isThrottle && preg_match('/(\d+)\s*segundos/i', $syncStderr, $m)) {
        $waitSec = (int) $m[1] + 15;
        echo "[" . date('Y-m-d H:i:s') . "] Rate-limit/redundância Omie: aguardando {$waitSec}s antes de retentar ({$attempt}/{$maxRetries})...\n";
        flush();
        sleep($waitSec);
        continue;
    }
    if ($isThrottle) {
        // Sem tempo anunciado: espera conservadora
        echo "[" . date('Y-m-d H:i:s') . "] Rate-limit Omie sem tempo anunciado: aguardando 90s ({$attempt}/{$maxRetries})...\n";
        flush();
        sleep(90);
        continue;
    }

    break; // Erro não é rate-limit — não retentar
}

if ($syncExit !== 0) {
    fwrite(STDERR, "\nErro no canonical sync (exit={$syncExit}):\n");
    if ($syncStderr !== '') {
        fwrite(STDERR, $syncStderr . "\n");
    }
    exit(1);
}

echo "\n[" . date('Y-m-d H:i:s') . "] Canonical sync concluído.\n";

// ── Materialização dos Marts ───────────────────────────────────────────────────
if (!$skipMarts) {
    echo "[" . date('Y-m-d H:i:s') . "] Materializando marts gold...\n";

    $martsCmd = [
        $phpBin,
        dirname(__DIR__) . '/bin/materialize_marts.php',
        "--company={$slug}",
        "--start={$fromDate}",
        "--end={$toDate}",
        '--months=1',
    ];

    $proc2 = proc_open($martsCmd, $desc, $pipes2, dirname(__DIR__));
    if (is_resource($proc2)) {
        fclose($pipes2[0]);
        while (!feof($pipes2[1])) {
            $line = fgets($pipes2[1]);
            if ($line !== false) {
                echo $line;
                flush();
            }
        }
        fclose($pipes2[1]);
        fclose($pipes2[2]);
        $martsExit = proc_close($proc2);
        if ($martsExit !== 0) {
            fwrite(STDERR, "Aviso: materialize_marts saiu com código {$martsExit}\n");
        }
    } else {
        fwrite(STDERR, "Aviso: não foi possível iniciar materialize_marts.php\n");
    }
}

echo "\n[" . date('Y-m-d H:i:s') . "] ✓ Backfill de {$slug} concluído.\n";
echo "  Próximo passo: php bin/classify_channels.php --company=" . strtolower($slug) . "\n";
exit(0);
