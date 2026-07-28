<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — Deploy da Fase 2 sob LOCK GLOBAL do pipeline.
 *
 * As flags protegem UI/scanner/notificações, mas NÃO protegem o cron: mapper,
 * hashing e writer entram no fluxo comercial de TODAS as empresas no instante do
 * deploy. Por isso o deploy roda segurando o lock de TODAS as empresas (uma única
 * sessão MySQL), impedindo qualquer cron de iniciar durante a troca.
 *
 * Runbook: lock → backup remoto → upload p/ nomes temporários → validar checksums
 *          → promover (mv atômico) na ordem de dependência → php -l + checks
 *          → opcache_reset → liberar lock.
 *
 * Uso:
 *   php tools/dev/quote_agenda_deploy_fase2.php --dry-run
 *   php tools/dev/quote_agenda_deploy_fase2.php --apply
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

$opts  = getopt('', ['apply', 'dry-run']);
$apply = isset($opts['apply']);

const SSH_HOST = 'u106688109@82.112.247.199';
const SSH_PORT = '65002';
const REMOTE   = '/home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie';
const COMPANIES = [1, 2, 3, 4, 5];

/** Ordem de promoção: dependências primeiro (inertes → núcleo). */
const FILES = [
    // inertes (não mudam comportamento sozinhos)
    'database/migrations/Version20260715000000_QuoteAgendaOrderDates.php',
    'config/env.php',
    // ordem obrigatória do runbook
    'app/Support/OmieDateParser.php',
    'app/DataPlatform/Silver/Schema/CanonicalSchemaManager.php',
    'app/Canonical/Support/CanonicalHashing.php',
    'app/Canonical/CanonicalWriteRepository.php',
    'app/FiscalSync/FiscalCanonicalMapper.php',
    'app/Sync/CommercialSyncCoordinator.php',
];

$root = dirname(__DIR__, 2);

/** @return array{0:int,1:string} */
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
        '-o', 'ConnectTimeout=15', '-p', SSH_PORT, SSH_HOST, $script]);
}

function scpUp(string $local, string $remote): array
{
    return run(['scp', '-q', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=no',
        '-P', SSH_PORT, $local, SSH_HOST . ':' . $remote]);
}

echo str_repeat('═', 78), PHP_EOL;
echo '  DEPLOY FASE 2 — ' . ($apply ? 'APPLY' : 'DRY-RUN') . ' · sob lock global do pipeline', PHP_EOL;
echo str_repeat('═', 78), PHP_EOL;

// ── Checksums locais ─────────────────────────────────────────────────────────────
$local = [];
foreach (FILES as $f) {
    $path = $root . '/' . $f;
    if (!is_file($path)) { fwrite(STDERR, "ARQUIVO LOCAL AUSENTE: {$f}\n"); exit(2); }
    $local[$f] = md5_file($path);
    printf("  %-68s %s%s", $f, substr($local[$f], 0, 12), PHP_EOL);
}

if (!$apply) {
    echo PHP_EOL . '  (dry-run — nada enviado. Use --apply.)' . PHP_EOL;
    exit(0);
}

// ── 1. LOCK GLOBAL: todas as empresas ────────────────────────────────────────────
$config = new Config();
$logger = new Logger($root . '/storage/logs/biomie.log');
$connName = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $connName);

$locks = [];
echo PHP_EOL . '  ── 1. Lock global (bloqueia o cron de todas as empresas) ──' . PHP_EOL;
foreach (COMPANIES as $cid) {
    $l = CronLock::acquire($pdo, 'biomie:realtime_pipeline:company:' . $cid, $logger, 90);
    if ($l === null) {
        foreach ($locks as $x) { $x->release(); }
        fwrite(STDERR, "ABORTADO: não consegui o lock da empresa {$cid} (cron rodando). Nada foi tocado.\n");
        exit(1);
    }
    $locks[] = $l;
    echo "     lock empresa {$cid} ✔" . PHP_EOL;
}

$release = static function () use ($locks): void {
    foreach ($locks as $x) { $x->release(); }
};

try {
    $stamp = date('Ymd_His');
    $bkp = REMOTE . '/storage/backups/fase2_' . $stamp;

    // ── 2. Backup remoto ─────────────────────────────────────────────────────────
    echo PHP_EOL . '  ── 2. Backup dos arquivos remotos existentes ──' . PHP_EOL;
    $mk = "mkdir -p {$bkp} && cd " . REMOTE . " && for f in " . implode(' ', FILES) . "; do "
        . "if [ -f \"\$f\" ]; then mkdir -p \"{$bkp}/\$(dirname \$f)\"; cp \"\$f\" \"{$bkp}/\$f\"; echo \"bkp: \$f\"; "
        . "else echo \"novo (sem backup): \$f\"; fi; done";
    [$c, $o] = ssh($mk);
    echo '     ' . str_replace("\n", "\n     ", $o) . PHP_EOL;
    if ($c !== 0) { throw new RuntimeException('backup remoto falhou'); }
    echo "     → backup em: {$bkp}" . PHP_EOL;

    // ── 3/4. Upload para nomes temporários ───────────────────────────────────────
    echo PHP_EOL . '  ── 3/4. Upload para nomes temporários ──' . PHP_EOL;
    foreach (FILES as $f) {
        [$c, $o] = ssh('mkdir -p ' . REMOTE . '/' . dirname($f));
        if ($c !== 0) { throw new RuntimeException("mkdir remoto falhou: {$f}"); }
        [$c, $o] = scpUp($root . '/' . $f, REMOTE . '/' . $f . '.deploy_tmp');
        if ($c !== 0) { throw new RuntimeException("upload falhou: {$f} :: {$o}"); }
        echo "     enviado: {$f}.deploy_tmp" . PHP_EOL;
    }

    // ── 5. Validar checksums de TODOS antes de promover ──────────────────────────
    echo PHP_EOL . '  ── 5. Validação de checksums ──' . PHP_EOL;
    // Um único md5sum com a lista — sem $(...) aninhado (que quebrou na v1).
    $tmpList = implode(' ', array_map(static fn (string $f): string => $f . '.deploy_tmp', FILES));
    [$c, $o] = ssh('cd ' . REMOTE . ' && md5sum ' . $tmpList);
    $remoteSums = [];
    foreach (explode("\n", $o) as $line) {
        if (preg_match('/^([0-9a-f]{32})\s+\.?\/?(.+?)\.deploy_tmp$/', trim($line), $m)) {
            $remoteSums[$m[2]] = $m[1];
        }
    }
    $bad = [];
    foreach (FILES as $f) {
        $okSum = ($remoteSums[$f] ?? '') === $local[$f];
        printf("     %s %-64s %s%s", $okSum ? '✔' : '✘', $f, substr($remoteSums[$f] ?? 'AUSENTE', 0, 12), PHP_EOL);
        if (!$okSum) { $bad[] = $f; }
    }
    if ($bad !== []) {
        ssh('cd ' . REMOTE . ' && for f in ' . implode(' ', FILES) . '; do rm -f $f.deploy_tmp; done');
        throw new RuntimeException('checksum divergente: ' . implode(', ', $bad) . ' — temporários removidos, nada promovido');
    }

    // ── 6. Promoção atômica (mv) na ordem de dependência ─────────────────────────
    echo PHP_EOL . '  ── 6. Promoção (mv) na ordem de dependência ──' . PHP_EOL;
    $mv = 'cd ' . REMOTE . ' && ' . implode(' && ', array_map(
        static fn (string $f): string => "mv -f {$f}.deploy_tmp {$f} && echo 'promovido: {$f}'",
        FILES
    ));
    [$c, $o] = ssh($mv);
    echo '     ' . str_replace("\n", "\n     ", $o) . PHP_EOL;
    if ($c !== 0) { throw new RuntimeException('promoção falhou — restaure de ' . $bkp); }

    // ── 7. Checks pós-promoção ───────────────────────────────────────────────────
    echo PHP_EOL . '  ── 7. Checks no servidor ──' . PHP_EOL;
    $checks = 'cd ' . REMOTE . '
echo "-- php -l --"
for f in ' . implode(' ', FILES) . '; do php -l "$f" >/dev/null 2>&1 && echo "  lint OK: $f" || echo "  LINT_FAIL: $f"; done
echo "-- autoload encontra OmieDateParser? --"
php -r \'require "vendor/autoload.php"; echo class_exists("BIomie\\\\Support\\\\OmieDateParser") ? "  CLASSE_OK" : "  CLASSE_FALTA"; echo PHP_EOL;\'
echo "-- writer: ocorrencias de data_inclusao_omie (esperado >=3) --"
grep -c data_inclusao_omie app/Canonical/CanonicalWriteRepository.php
echo "-- mapper: data_emissao segue previsao-primeiro (regra legada) --"
grep -c "cabecalho.data_previsao" app/FiscalSync/FiscalCanonicalMapper.php
echo "-- migracao fisicamente presente --"
ls database/migrations/Version20260715000000_QuoteAgendaOrderDates.php >/dev/null 2>&1 && echo "  MIGRACAO_OK" || echo "  MIGRACAO_FALTA"
echo "-- flags fisicamente presentes (esperado 4: 1 comentario + 3 flags) --"
grep -c quote_agenda config/env.php
echo "-- opcache --"
php -r \'echo function_exists("opcache_reset") ? (opcache_reset() ? "  OPCACHE_RESET" : "  OPCACHE_NOOP") : "  OPCACHE_NA"; echo PHP_EOL;\'';
    [$c, $o] = ssh($checks);
    echo '     ' . str_replace("\n", "\n     ", $o) . PHP_EOL;

    if (str_contains($o, 'LINT_FAIL') || str_contains($o, 'CLASSE_FALTA') || str_contains($o, 'MIGRACAO_FALTA')) {
        throw new RuntimeException('checks falharam — cron SEGUE BLOQUEADO. Backup: ' . $bkp);
    }

    echo PHP_EOL . '  ✅ DEPLOY OK · backup: ' . $bkp . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, PHP_EOL . 'ERRO NO DEPLOY: ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Lock será liberado; verifique o estado antes de reativar o cron.' . PHP_EOL);
    $release();
    exit(1);
}

// ── 8. Liberar lock só depois dos checks ─────────────────────────────────────────
$release();
echo '  ── 8. Lock global liberado (cron pode voltar a rodar) ──' . PHP_EOL;
exit(0);
