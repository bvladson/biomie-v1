<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — deploy das Fases 8–9 (dry-run por padrão).
 *
 * SEGURO POR PADRÃO: sem --apply nada é enviado.
 *
 * Diferente do deploy F5–F7: aqui NÃO há migração. É código puro (4 novos + 2
 * atualizados), então não há migrate-one nem barreira de DDL. O que permanece:
 * allowlist com SHA-256, lock global das 5 empresas, backup, verificação de
 * checksum pós-upload, promoção atômica (mv), checks remotos com marcador de
 * fim, e restauração do backup em qualquer falha.
 *
 * Um dos arquivos (QuoteAgendaReadService.php) corrige um defeito que dá 500 em
 * produção quando a tela é aberta (WITH CONSISTENT SNAPSHOT sob READ-COMMITTED).
 *
 *   php tools/dev/quote_agenda_deploy_f8_f9.php
 *   php tools/dev/quote_agenda_deploy_f8_f9.php --apply
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

/** Allowlist explícita — 4 novos + 2 atualizados. Nenhum toca schema. */
const MANIFEST = [
    'app/QuoteAgenda/QuoteAgendaNotificationPolicy.php',  // F8 — decisão pura
    'app/QuoteAgenda/QuoteAgendaNotifier.php',            // F8 — emissão/dedup
    'app/QuoteAgenda/QuoteAgendaReadService.php',         // FIX — REPEATABLE READ
    'bin/quote_agenda_notify.php',                        // F8 — CLI (cron)
    'bin/quote_agenda_scan.php',                          // exit code por empresa ativada
    'cron/hostinger/quote_agenda_scan.sh',                // F9 — wrapper cron scanner
    'cron/hostinger/quote_agenda_notify.sh',             // F9 — wrapper cron notify
    'tools/dev/quote_agenda_activation.php',              // F9 — prontidão/ativação
    'tools/dev/biomie_release_guard.php',                 // D56 + D7/D55
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
    // CRLF do Windows quebra o bash remoto (incidente de 2026-07-20): normaliza.
    return run(['ssh', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=no',
        '-o', 'ConnectTimeout=15', '-p', SSH_PORT, SSH_HOST, str_replace("\r\n", "\n", $script)]);
}
function scpUp(string $local, string $remote): array
{
    return run(['scp', '-q', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=no',
        '-P', SSH_PORT, $local, SSH_HOST . ':' . $remote]);
}

echo str_repeat('═', 78), PHP_EOL;
echo '  DEPLOY AGENDA — FASES 8–9 · ', $apply ? 'APPLY' : 'DRY-RUN (nada será enviado)', PHP_EOL;
echo str_repeat('═', 78), PHP_EOL;

// ── 1. Manifesto local + SHA-256 + lint ─────────────────────────────────────
echo PHP_EOL, '  ── MANIFESTO (allowlist · SHA-256 · lint) ──', PHP_EOL;
$localHashes = [];
$problemas = [];
foreach (MANIFEST as $f) {
    $path = $root . '/' . $f;
    if (!is_file($path)) { $problemas[] = "AUSENTE LOCAL: {$f}"; continue; }
    $localHashes[$f] = hash_file('sha256', $path);
    // Só arquivos .php passam por php -l; wrappers .sh são validados por sh -n.
    if (str_ends_with($f, '.php')) {
        [$lc] = run([PHP_BINARY, '-l', $path]);
    } elseif (str_ends_with($f, '.sh')) {
        [$lc] = run(['sh', '-n', $path]);
    } else {
        $lc = 0;
    }
    $lint = $lc === 0 ? 'lint ok' : 'LINT FALHOU';
    if ($lc !== 0) { $problemas[] = "LINT: {$f}"; }
    printf("    %-56s %s · %s%s", $f, substr($localHashes[$f], 0, 16), $lint, PHP_EOL);
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
    if (!isset($remote[$f]))       { $novos[] = $f; }
    elseif ($remote[$f] === $h)    { $iguais[] = $f; }
    else                           { $atualizar[] = $f; }
}
printf("    novos: %d · idênticos: %d · a atualizar: %d%s", count($novos), count($iguais), count($atualizar), PHP_EOL);
foreach ($novos as $f)     { echo "      + {$f}" . PHP_EOL; }
foreach ($atualizar as $f) { echo "      ~ {$f}" . PHP_EOL; }

// SHA remoto ANTERIOR dos arquivos que serão sobrescritos — reconferido antes
// de promover (anti-TOCTOU: outro deploy não pode ter tocado nesse intervalo).
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
    if ($mudou !== []) {
        throw new RuntimeException('ABORTA: arquivo remoto mudou desde a análise: ' . implode(', ', $mudou));
    }
    printf("     SHA remoto reconferido: %d inalterado(s) ✔%s", count($pinned), PHP_EOL);
};

// ── 3. Provas pré-autorização ───────────────────────────────────────────────
echo PHP_EOL, '  ── PROVAS PRÉ-AUTORIZAÇÃO ──', PHP_EOL;
$provas = [];
foreach ([
    'run_quote_agenda_notifications',
    'run_quote_agenda_read_transaction',
    'run_quote_agenda_freshness',
    'run_quote_charge_idempotency',
] as $t) {
    [$rc] = run([PHP_BINARY, $root . '/tests/' . $t . '.php']);
    $provas["suíte {$t}"] = $rc === 0;
}
// A tela só funciona porque o fix está presente: exige o marcador da correção.
$rsvc = (string) file_get_contents($root . '/app/QuoteAgenda/QuoteAgendaReadService.php');
$provas['fix REPEATABLE READ presente no ReadService'] =
    str_contains($rsvc, 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
// Nada é ativado por este deploy.
$config = new Config();
$logger = new Logger($root . '/storage/logs/biomie.log');
$conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo  = Database::connect($config, $conn);
// Este deploy é código puro: NÃO escreve em biomie_quote_followup_policy. O
// número de políticas ativas é informativo (muda por ativação, nunca por deploy).
$politicasAtivas = (int) $pdo->query('SELECT COUNT(*) FROM biomie_quote_followup_policy WHERE enabled = 1')->fetchColumn();

foreach ($provas as $nome => $ok) { printf("    %s %s%s", $ok ? '✅' : '❌', $nome, PHP_EOL); }
printf("    ℹ️  políticas ativas: %d (deploy não altera este número)%s", $politicasAtivas, PHP_EOL);
$provasOk = !in_array(false, $provas, true);

if (!$apply) {
    echo PHP_EOL, '  ── PLANO ──', PHP_EOL;
    foreach ([
        'lock global das 5 empresas (mesma sessão, CONNECTION_ID conferido)',
        'backup remoto dos destinos exatos',
        'reconferir SHA fixado dos 2 atualizados',
        'upload → checksum → mv atômico dos 6 arquivos',
        'checks remotos: php -l + autoload + CHECKS_END',
        'falha ⇒ restaura do backup; RELEASE_LOCK = 1 conferido',
    ] as $i => $l) { printf("    %d. %s%s", $i + 1, $l, PHP_EOL); }
    echo PHP_EOL, '  ', $provasOk ? '✅' : '⚠️ ', ' DRY-RUN concluído — nada enviado.', PHP_EOL;
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
$bkp = REMOTE . '/storage/backups/agenda_f8f9_' . date('Ymd_His');
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
    printf("     %d arquivo(s) promovido(s) ✔%s", count($localHashes), PHP_EOL);

    echo PHP_EOL, '  ── CHECKS REMOTOS ──', PHP_EOL;
    // Só .php entram no php -l remoto; .sh quebrariam o parser.
    $phpFiles = implode(' ', array_map('escapeshellarg',
        array_filter(array_keys($localHashes), static fn ($f) => str_ends_with($f, '.php'))));
    [$c, $o] = ssh('cd ' . REMOTE . "
for f in {$phpFiles}; do php -l \"\$f\" >/dev/null 2>&1 || echo \"LINT_FAIL \$f\"; done
php -r 'require \"vendor/autoload.php\";
  \$c=[\"BIomie\\\\QuoteAgenda\\\\QuoteAgendaNotificationPolicy\",\"BIomie\\\\QuoteAgenda\\\\QuoteAgendaNotifier\",\"BIomie\\\\QuoteAgenda\\\\QuoteAgendaReadService\"];
  foreach(\$c as \$x){ echo class_exists(\$x)?\"\":\"CLASSE_FALTA \$x\\n\"; } echo \"AUTOLOAD_OK\\n\";'
php -r 'echo function_exists(\"opcache_reset\") ? (opcache_reset()?\"OPCACHE_RESET\":\"OPCACHE_NOOP\") : \"OPCACHE_NA\"; echo PHP_EOL;'
echo CHECKS_END");
    echo '     ' . str_replace("\n", "\n     ", $o) . PHP_EOL;
    if (!str_contains($o, 'CHECKS_END')) {
        throw new RuntimeException('checks remotos NÃO terminaram — saída: ' . substr($o, 0, 200));
    }
    if (str_contains($o, 'LINT_FAIL') || str_contains($o, 'CLASSE_FALTA') || !str_contains($o, 'AUTOLOAD_OK')) {
        throw new RuntimeException('checks remotos falharam');
    }

    echo PHP_EOL, '  ✅ DEPLOY OK · backup: ', $bkp, PHP_EOL;
    echo '     Flags seguem OFF: nada ativado por este deploy.', PHP_EOL;
} catch (Throwable $e) {
    $failed = true;
    fwrite(STDERR, PHP_EOL . 'ERRO: ' . $e->getMessage() . PHP_EOL);
    // Deploy só de código, sem DDL: restauração é sempre total e segura.
    $l = implode(' ', array_map('escapeshellarg', array_keys($localHashes)));
    echo PHP_EOL, '  ── RESTAURANDO DO BACKUP ──', PHP_EOL;
    [, $o] = ssh('cd ' . REMOTE . ' && for f in ' . $l . '; do '
        . "if [ -f \"{$bkp}/\$f\" ]; then cp -f \"{$bkp}/\$f\" \"\$f\" && echo \"revertido \$f\"; "
        . "else rm -f \"\$f\" && echo \"removido  \$f\"; fi; rm -f \"\$f.deploy_tmp\"; done");
    echo '     ' . str_replace("\n", "\n     ", trim($o)) . PHP_EOL;
} finally {
    $liberar();
}

exit($failed ? 1 : 0);
