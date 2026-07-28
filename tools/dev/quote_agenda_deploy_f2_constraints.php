<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — deploy Fase 2 (constraints + hold + debounce + barreira).
 *
 * ELIMINA O DRIFT entre banco e runtime SEM reaplicar nada:
 *  · a migration #78 (CHECK constraints) JÁ foi aplicada ao banco — aqui só se
 *    PROMOVE O ARQUIVO para o servidor, para o `status` não ficar órfão. NÃO há
 *    migrate-one, NÃO há DDL.
 *  · runtime: debounce 60→75s, portão da 1ª desativação por webhook (hold),
 *    flag de liberação (OFF), notificação de hold, separação botão×debounce.
 *  · barreira de testes: TestDatabaseGuard + test_pdo + suítes roteadas.
 *
 * Mantém o padrão seguro: allowlist SHA-256, lock global das 5 empresas, backup,
 * checksum pós-upload, promoção atômica (mv), checks remotos com marcador de fim,
 * restauração do backup em qualquer falha. Sem --apply nada é enviado.
 *
 *   php tools/dev/quote_agenda_deploy_f2_constraints.php
 *   php tools/dev/quote_agenda_deploy_f2_constraints.php --apply
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\CronLock;
use BIomie\Database;
use BIomie\Logger;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();
set_time_limit(0);

$opts  = getopt('', ['apply']);
$apply = isset($opts['apply']);

const SSH_HOST  = 'u106688109@82.112.247.199';
const SSH_PORT  = '65002';
const REMOTE    = '/home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie';
const COMPANIES = [1, 2, 3, 4, 5];

/** A migration #78 JÁ está aplicada no banco; aqui é só o ARQUIVO (sem re-run). */
const MIGRATION_VERSION = '20260722010000';

/** Manifesto: migration file (só promoção) + runtime + barreira de testes. */
const MANIFEST = [
    // migration #78 — arquivo, para o schema não ficar órfão do .php
    'database/migrations/Version20260722010000_QuoteOrderRefreshConstraints.php',
    // runtime (instruções 2 e 3)
    'config/env.php',                              // test_db block + flag auto_apply
    'app/QuoteAgenda/QuoteOrderRefresher.php',     // debounce 75 + hold
    'app/Queue/JobRunner.php',                     // hold + notificação
    'api/quote_agenda_refresh_request.php',        // cooldown do botão (separação)
    // barreira de banco de testes (guard/test_db)
    'tests/TestDatabaseGuard.php',
    'tests/TestSupport.php',
    'tests/run_test_database_guard.php',
    'tests/prove_test_barrier_blocks_prod.php',
    'tests/probe_qorl_constraints.php',
    // suítes mutáveis roteadas ao test_pdo (drift local↔servidor)
    'tests/run_quote_order_refresher.php',
    'tests/run_quote_seller_binding.php',
    'tests/run_quote_agenda_notifications.php',
    'tests/run_migration_pending_semantics.php',
    'tests/run_quote_agenda_freshness.php',
    'tests/run_quote_charge_idempotency.php',
    'tests/run_quote_scanner_negative_paths.php',
    'tests/run_multitenant_foundation.php',
];

$root = dirname(__DIR__, 2);

function run(array $cmd): array
{
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { return [1, 'proc_open falhou']; }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);

    return [proc_close($p), trim($out . ($err !== '' ? "\n[stderr] " . $err : ''))];
}
function ssh(string $script): array
{
    return run(['ssh', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=no',
        '-o', 'ConnectTimeout=15', '-p', SSH_PORT, SSH_HOST, str_replace("\r\n", "\n", $script)]);
}
function scpUp(string $local, string $remote): array
{
    return run(['scp', '-q', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=no',
        '-P', SSH_PORT, $local, SSH_HOST . ':' . $remote]);
}

echo str_repeat('═', 78), PHP_EOL;
echo '  DEPLOY AGENDA — FASE 2 (constraints/hold/debounce/barreira) · ',
    $apply ? 'APPLY' : 'DRY-RUN (nada será enviado)', PHP_EOL;
echo str_repeat('═', 78), PHP_EOL;

// ── 1. Manifesto local + SHA-256 + lint ─────────────────────────────────────
echo PHP_EOL, '  ── MANIFESTO (allowlist · SHA-256 · lint) ──', PHP_EOL;
$localHashes = [];
$problemas = [];
foreach (MANIFEST as $f) {
    $path = $root . '/' . $f;
    if (!is_file($path)) { $problemas[] = "AUSENTE LOCAL: {$f}"; continue; }
    $localHashes[$f] = hash_file('sha256', $path);
    [$lc] = str_ends_with($f, '.php') ? run([PHP_BINARY, '-l', $path]) : [0];
    if ($lc !== 0) { $problemas[] = "LINT: {$f}"; }
    printf("    %-62s %s · %s%s", $f, substr($localHashes[$f], 0, 12), $lc === 0 ? 'lint ok' : 'LINT FALHOU', PHP_EOL);
}
if ($problemas !== []) {
    fwrite(STDERR, 'ABORTA: ' . implode(' | ', $problemas) . PHP_EOL);
    exit(2);
}

// ── 2. Estado remoto + SHA fixado dos pré-existentes ────────────────────────
echo PHP_EOL, '  ── ESTADO REMOTO ──', PHP_EOL;
$lista = implode(' ', array_map('escapeshellarg', array_keys($localHashes)));
[$c, $o] = ssh('cd ' . REMOTE . ' && for f in ' . $lista . '; do if [ -f "$f" ]; then sha256sum "$f"; else echo "AUSENTE $f"; fi; done');
if ($c !== 0) { fwrite(STDERR, 'ABORTA: não li o estado remoto.' . PHP_EOL . $o . PHP_EOL); exit(2); }
$remote = [];
foreach (explode("\n", $o) as $line) {
    if (preg_match('/^([0-9a-f]{64})\s+\.?\/?(.+)$/', trim($line), $m)) { $remote[$m[2]] = $m[1]; }
}
$novos = $iguais = $atualizar = [];
foreach ($localHashes as $f => $h) {
    if (!isset($remote[$f]))    { $novos[] = $f; }
    elseif ($remote[$f] === $h) { $iguais[] = $f; }
    else                        { $atualizar[] = $f; }
}
printf("    novos: %d · idênticos: %d · a atualizar: %d%s", count($novos), count($iguais), count($atualizar), PHP_EOL);
foreach ($novos as $f)     { echo "      + {$f}" . PHP_EOL; }
foreach ($atualizar as $f) { echo "      ~ {$f}" . PHP_EOL; }

$pinned = [];
foreach ($atualizar as $f) { $pinned[$f] = $remote[$f]; }
$assertPinned = static function () use ($pinned): void {
    if ($pinned === []) { return; }
    $l = implode(' ', array_map('escapeshellarg', array_keys($pinned)));
    [$c, $o] = ssh('cd ' . REMOTE . ' && for f in ' . $l . '; do sha256sum "$f" 2>/dev/null || echo "AUSENTE $f"; done');
    if ($c !== 0) { throw new RuntimeException('não reconferi os SHAs fixados'); }
    $now = [];
    foreach (explode("\n", $o) as $line) {
        if (preg_match('/^([0-9a-f]{64})\s+\.?\/?(.+)$/', trim($line), $m)) { $now[$m[2]] = $m[1]; }
    }
    $mudou = [];
    foreach ($pinned as $f => $h) { if (($now[$f] ?? '') !== $h) { $mudou[] = $f; } }
    if ($mudou !== []) { throw new RuntimeException('ABORTA: arquivo remoto mudou desde a análise: ' . implode(', ', $mudou)); }
    printf("     SHA remoto reconferido: %d inalterado(s) ✔%s", count($pinned), PHP_EOL);
};

// ── 3. Provas pré-autorização ───────────────────────────────────────────────
echo PHP_EOL, '  ── PROVAS PRÉ-AUTORIZAÇÃO ──', PHP_EOL;
$config = new Config();
$logger = new Logger($root . '/storage/logs/biomie.log');
$conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo  = Database::connect($config, $conn);

$provas = [];
// A migração #78 JÁ está aplicada — este deploy NÃO pode reaplicá-la.
$st = $pdo->prepare("SELECT status FROM biomie_schema_migrations WHERE schema_version = :v");
$st->execute(['v' => MIGRATION_VERSION]);
$migStatus = (string) ($st->fetchColumn() ?: 'ausente');
$provas['migration #78 já está APLICADA no banco (não reaplicar)'] = $migStatus === 'applied';
// As 4 constraints já existem no banco.
$chks = $pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'biomie_quote_order_refresh_log'")->fetchAll(PDO::FETCH_COLUMN);
foreach (['chk_qorl_source', 'chk_qorl_decision', 'chk_qorl_execution_state', 'chk_qorl_resolution'] as $ck) {
    $provas["constraint {$ck} presente no banco"] = in_array($ck, $chks, true);
}
// Código: debounce 75, portão centralizado (todas as origens automáticas), flag.
$ref = (string) file_get_contents($root . '/app/QuoteAgenda/QuoteOrderRefresher.php');
$provas['debounce = 75s (margem sobre os 60s da Omie)'] = str_contains($ref, 'DEBOUNCE_SECONDS = 75');
$provas['portão cobre webhook+scanner+manual (centralizado no refresher)'] =
    str_contains($ref, 'AUTO_DEACTIVATION_SOURCES') && str_contains($ref, "'scanner'")
    && str_contains($ref, '$autoDeactivationEnabled');
$jr = (string) file_get_contents($root . '/app/Queue/JobRunner.php');
$provas['worker respeita a flag centralizada + notifica hold'] = str_contains($jr, 'quote_agenda_auto_deactivation_enabled')
    && str_contains($jr, 'notifyDeactivationHold');
$env = (string) file_get_contents($root . '/config/env.php');
$provas['flag auto_deactivation definida (default OFF, fallback ao nome antigo)'] =
    str_contains($env, 'quote_agenda_auto_deactivation_enabled')
    && str_contains($env, 'BIOMIE_QUOTE_AGENDA_WEBHOOK_AUTO_APPLY');
// Suítes-chave locais verdes.
[$rcRef] = run([PHP_BINARY, $root . '/tests/run_quote_order_refresher.php']);
$provas['suíte run_quote_order_refresher (hold)'] = $rcRef === 0;
[$rcGuard] = run([PHP_BINARY, $root . '/tests/run_test_database_guard.php']);
$provas['suíte run_test_database_guard'] = $rcGuard === 0;

foreach ($provas as $nome => $ok) { printf("    %s %s%s", $ok ? '✅' : '❌', $nome, PHP_EOL); }
$provasOk = !in_array(false, $provas, true);

$dbIdent = $pdo->query('SELECT DATABASE() AS db, @@hostname AS host')->fetch(PDO::FETCH_ASSOC) ?: [];
printf("%s    banco=%s · host=%s · migration #78=%s%s", PHP_EOL,
    (string) ($dbIdent['db'] ?? '?'), (string) ($dbIdent['host'] ?? '?'), $migStatus, PHP_EOL);

if (!$apply) {
    echo PHP_EOL, '  ── PLANO (sem DDL, sem migrate-one) ──', PHP_EOL;
    foreach ([
        'lock global das 5 empresas (mesma sessão)',
        'backup remoto do manifesto',
        'upload de todos os arquivos → checksum SHA-256',
        'promoção atômica (mv) — inclui o ARQUIVO da migration #78 (sem executar)',
        'checks remotos: php -l + autoload (refresher/jobrunner/notification) + status #78=applied + CHECKS_END',
        'RELEASE_LOCK=1 verificado',
        'qualquer falha ⇒ restaura o backup (novos são removidos). Nunca roda migrate/down.',
    ] as $i => $l) { printf("    %d. %s%s", $i + 1, $l, PHP_EOL); }
    echo PHP_EOL, '  ', $provasOk ? '✅' : '⚠️ ', ' DRY-RUN concluído — nada enviado. ', $apply ? '' : "Rode com --apply.", PHP_EOL;
    exit($provasOk ? 0 : 1);
}

if (!$provasOk) { fwrite(STDERR, 'ABORTA: provas falhando.' . PHP_EOL); exit(2); }

// ── APPLY ───────────────────────────────────────────────────────────────────
$lockNames = array_map(static fn (int $c): string => 'biomie:realtime_pipeline:company:' . $c, COMPANIES);
$connId = (string) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();

echo PHP_EOL, '  ── LOCK GLOBAL ──', PHP_EOL;
$locks = [];
foreach ($lockNames as $name) {
    $l = CronLock::acquire($pdo, $name, $logger, 90);
    if ($l === null) {
        foreach ($locks as $x) { $x->release(); }
        fwrite(STDERR, "ABORTA: lock indisponível ({$name}). Nada foi tocado." . PHP_EOL);
        exit(1);
    }
    $locks[] = $l;
    printf("     %s ✔%s", $name, PHP_EOL);
}

$liberar = static function () use ($pdo, $lockNames, $connId): void {
    echo PHP_EOL, '  ── LIBERANDO LOCK ──', PHP_EOL;
    $agora = (string) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
    printf("     mesma sessão: %s%s", $agora === $connId ? 'sim ✔' : 'NÃO ✘', PHP_EOL);
    foreach ($lockNames as $name) {
        $r = $pdo->prepare('SELECT RELEASE_LOCK(:n)'); $r->execute(['n' => $name]);
        $v = $r->fetchColumn();
        $chk = $pdo->prepare('SELECT IS_USED_LOCK(:n)'); $chk->execute(['n' => $name]);
        $used = $chk->fetchColumn();
        printf("     %-46s RELEASE=%s IS_USED=%s %s%s", $name,
            $v === null ? 'NULL' : (string) $v, $used === null ? 'NULL' : (string) $used,
            ($v !== null && (int) $v === 1 && $used === null) ? '✔' : '✘', PHP_EOL);
    }
};

$failed = false;
$promoted = false;
$bkp = REMOTE . '/storage/backups/agenda_f2const_' . date('Ymd_His');
try {
    echo PHP_EOL, '  ── BACKUP REMOTO ──', PHP_EOL;
    [$c, $o] = ssh("mkdir -p {$bkp} && cd " . REMOTE . ' && for f in ' . $lista . '; do '
        . "if [ -f \"\$f\" ]; then mkdir -p \"{$bkp}/\$(dirname \$f)\"; cp \"\$f\" \"{$bkp}/\$f\"; fi; done && echo OK");
    if ($c !== 0) { throw new RuntimeException('backup falhou: ' . $o); }
    printf("     backup em %s%s", $bkp, PHP_EOL);

    $assertPinned();

    echo PHP_EOL, '  ── UPLOAD + CHECKSUM ──', PHP_EOL;
    foreach (array_keys($localHashes) as $f) {
        [$c] = ssh('mkdir -p ' . REMOTE . '/' . dirname($f));
        if ($c !== 0) { throw new RuntimeException("mkdir remoto falhou: {$f}"); }
        [$c, $o] = scpUp($root . '/' . $f, REMOTE . '/' . $f . '.deploy_tmp');
        if ($c !== 0) { throw new RuntimeException("upload falhou: {$f} :: {$o}"); }
    }
    $tmp = implode(' ', array_map(static fn ($f) => escapeshellarg($f . '.deploy_tmp'), array_keys($localHashes)));
    [, $o] = ssh('cd ' . REMOTE . ' && sha256sum ' . $tmp);
    $got = [];
    foreach (explode("\n", $o) as $line) {
        if (preg_match('/^([0-9a-f]{64})\s+\.?\/?(.+)\.deploy_tmp$/', trim($line), $m)) { $got[$m[2]] = $m[1]; }
    }
    $bad = [];
    foreach ($localHashes as $f => $h) { if (($got[$f] ?? '') !== $h) { $bad[] = $f; } }
    if ($bad !== []) {
        ssh('cd ' . REMOTE . ' && rm -f ' . $tmp);
        throw new RuntimeException('checksum divergente: ' . implode(', ', $bad));
    }
    printf("     %d/%d checksums conferem ✔%s", count($localHashes), count($localHashes), PHP_EOL);

    echo PHP_EOL, '  ── PROMOÇÃO ATÔMICA ──', PHP_EOL;
    $mv = 'cd ' . REMOTE . ' && ' . implode(' && ', array_map(
        static fn ($f) => 'mv -f ' . escapeshellarg($f . '.deploy_tmp') . ' ' . escapeshellarg($f),
        array_keys($localHashes)));
    [$c, $o] = ssh($mv);
    if ($c !== 0) { throw new RuntimeException('promoção falhou — restaure de ' . $bkp); }
    $promoted = true;
    printf("     %d arquivo(s) promovido(s) ✔%s", count($localHashes), PHP_EOL);

    echo PHP_EOL, '  ── CHECKS REMOTOS ──', PHP_EOL;
    $phpFiles = implode(' ', array_map('escapeshellarg',
        array_filter(array_keys($localHashes), static fn ($f) => str_ends_with($f, '.php'))));
    [$c, $o] = ssh('cd ' . REMOTE . "
for f in {$phpFiles}; do php -l \"\$f\" >/dev/null 2>&1 || echo \"LINT_FAIL \$f\"; done
php -r 'require \"vendor/autoload.php\";
  \$c=[\"BIomie\\\\QuoteAgenda\\\\QuoteOrderRefresher\",\"BIomie\\\\Queue\\\\JobRunner\",\"BIomie\\\\Notifications\\\\NotificationService\"];
  foreach(\$c as \$x){ echo class_exists(\$x)?\"\":\"CLASSE_FALTA \$x\\n\"; } echo \"AUTOLOAD_OK\\n\";'
php -r 'require \"app/Bootstrap.php\"; \BIomie\\Bootstrap::init(); \$c=new \BIomie\\Config();
  echo \"AUTO_DEACT=\".var_export(\$c->get(\"features.quote_agenda_auto_deactivation_enabled\"),true).PHP_EOL;
  echo \"DEBOUNCE=\".\BIomie\\QuoteAgenda\\QuoteOrderRefresher::DEBOUNCE_SECONDS.PHP_EOL;
  echo \"GATE_SOURCES=\".implode(\"|\",\BIomie\\QuoteAgenda\\QuoteOrderRefresher::AUTO_DEACTIVATION_SOURCES).PHP_EOL;'
php bin/schema_migrate.php status 2>/dev/null | grep -A2 " . escapeshellarg(MIGRATION_VERSION) . " | head -3
echo CHECKS_END");
    echo '     ' . str_replace("\n", "\n     ", $o) . PHP_EOL;
    if (!str_contains($o, 'CHECKS_END')) { throw new RuntimeException('checks remotos NÃO terminaram: ' . substr($o, 0, 200)); }
    if (str_contains($o, 'LINT_FAIL') || str_contains($o, 'CLASSE_FALTA') || !str_contains($o, 'AUTOLOAD_OK')) {
        throw new RuntimeException('checks remotos falharam');
    }
    if (!str_contains($o, 'DEBOUNCE=75') || strpos($o, 'AUTO_DEACT=false') === false
        || !str_contains($o, 'GATE_SOURCES=webhook|scanner|manual')) {
        throw new RuntimeException('runtime remoto não reflete o esperado (DEBOUNCE=75, AUTO_DEACT=false, gate webhook|scanner|manual)');
    }

    echo PHP_EOL, '  ✅ DEPLOY OK · backup: ', $bkp, PHP_EOL;
    echo '     Drift eliminado: arquivo #78 no servidor, runtime hold/debounce no ar, auto_apply OFF.', PHP_EOL;
} catch (Throwable $e) {
    $failed = true;
    fwrite(STDERR, PHP_EOL . 'ERRO: ' . $e->getMessage() . PHP_EOL);
    echo PHP_EOL, '  ── RESTAURANDO O MANIFESTO (sem DDL, nada de migrate/down) ──', PHP_EOL;
    $l = implode(' ', array_map('escapeshellarg', array_keys($localHashes)));
    [, $o] = ssh('cd ' . REMOTE . ' && for f in ' . $l . '; do '
        . "if [ -f \"{$bkp}/\$f\" ]; then cp -f \"{$bkp}/\$f\" \"\$f\" && echo \"revertido \$f\"; "
        . "else rm -f \"\$f\" && echo \"removido  \$f\"; fi; rm -f \"\$f.deploy_tmp\"; done");
    echo '     ' . str_replace("\n", "\n     ", trim($o)) . PHP_EOL;
} finally {
    $liberar();
}

exit($failed ? 1 : 0);
