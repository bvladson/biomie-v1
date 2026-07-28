<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — deploy da Fase 10 (vínculo vendedor + mapa Admin).
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
/** Migração aplicada por migrate-one (out-of-order, aditiva). */
const AGENDA_MIGRATION = '20260720000000';
const MIGRATION_FILE = 'database/migrations/Version20260720000000_QuoteSellerBinding.php';

/** Runtime da Fase 10 (promovido SÓ após a migração aplicar). */
const MANIFEST = [
    'app/Acl/Roles.php',                          // capability sales.quotes.followup.self
    'app/QuoteAgenda/SellerBindingResolver.php',  // resolve seller_ids do usuário logado
    'api/quote_agenda_self.php',                  // vendedor logado: só a própria carteira
    'api/quote_seller_binding.php',               // API do mapa (users.manage)
    'views/admin.php',                            // registra a aba
    'views/admin/tabs/seller-binding.php',        // aba "Vínculo Vendedores"
    'tools/dev/biomie_release_guard.php',         // D56 Fase 10
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
echo '  DEPLOY AGENDA — FASE 10 (vínculo vendedor) · ', $apply ? 'APPLY' : 'DRY-RUN (nada será enviado)', PHP_EOL;
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
// RBAC: vendedor ganha .self; NUNCA .view/.manage. Gestor não ganha .self.
$roles = (string) file_get_contents($root . '/app/Acl/Roles.php');
foreach (['viewer', 'analyst'] as $papel) {
    $caps = \BIomie\Acl\Roles::capabilities($papel);
    $provas["$papel tem .self"] = in_array('sales.quotes.followup.self', $caps, true);
    $provas["$papel NÃO tem .view/.manage"] =
        !in_array('sales.quotes.followup.view', $caps, true) && !in_array('sales.quotes.followup.manage', $caps, true);
}
$provas['manager/admin NÃO têm .self'] =
    !in_array('sales.quotes.followup.self', \BIomie\Acl\Roles::capabilities('manager'), true)
    && !in_array('sales.quotes.followup.self', \BIomie\Acl\Roles::capabilities('admin'), true);
// self endpoint: escopo SEMPRE do servidor, nunca do request.
$self = (string) file_get_contents($root . '/api/quote_agenda_self.php');
$provas['self endpoint exige .self'] = str_contains($self, "requireCapability('sales.quotes.followup.self'");
$provas['self endpoint resolve por vínculo (não por request)'] =
    str_contains($self, 'SellerBindingResolver') && !str_contains($self, "Http::query('seller_id'");
// admin mapping: users.manage + degradação se migração ausente.
$bind = (string) file_get_contents($root . '/api/quote_seller_binding.php');
$provas['admin mapping exige users.manage'] = str_contains($bind, "requireCapability('users.manage'");
$provas['admin mapping degrada com 503 se tabela ausente'] = str_contains($bind, 'migration_pending');
// resolver: escopo vazio nunca vira tudo.
$provas['SellerBindingResolver presente'] = is_file($root . '/app/QuoteAgenda/SellerBindingResolver.php');
// migração: aditiva (CREATE TABLE) e reversível.
$mig = (string) file_get_contents($root . '/' . MIGRATION_FILE);
$provas['migração é CREATE TABLE aditiva + reversível'] =
    str_contains($mig, 'CREATE TABLE IF NOT EXISTS') && str_contains($mig, 'function down');
// suítes-chave localmente verdes.
[$rcSeller] = run([PHP_BINARY, $root . '/tests/run_quote_seller_binding.php']);
$provas['suíte run_quote_seller_binding'] = $rcSeller === 0;

$config = new Config();
$logger = new Logger($root . '/storage/logs/biomie.log');
$conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo  = Database::connect($config, $conn);

// pré-voo do migrate-one (execução LOCAL contra o banco produtivo).
$runner = new \BIomie\Migrations\MigrationRunner(
    $config, $logger,
    $root . '/' . trim((string) $config->get('migrations.path', 'database/migrations'), '/'),
    (string) $config->get('migrations.table', 'biomie_schema_migrations'),
    (string) $config->get('app.version', 'dev')
);
$dbIdent = $pdo->query('SELECT DATABASE() AS db, @@hostname AS host')->fetch(PDO::FETCH_ASSOC) ?: [];
$pendentes = [];
$alvoStatus = null;
foreach ($runner->status()['migrations'] as $m) {
    if (($m['status'] ?? '') === 'pending') { $pendentes[] = (string) $m['version']; }
    if ((string) $m['version'] === AGENDA_MIGRATION) { $alvoStatus = (string) ($m['status'] ?? '?'); }
}
sort($pendentes);
$anterioresPendentes = array_values(array_filter($pendentes,
    static fn (string $v): bool => strcmp($v, AGENDA_MIGRATION) < 0));
$provas['migração alvo está PENDENTE'] = $alvoStatus === 'pending';
$provas['migrate-one será out-of-order consciente (há anteriores pendentes)'] = $anterioresPendentes !== [];

foreach ($provas as $nome => $ok) { printf("    %s %s%s", $ok ? '✅' : '❌', $nome, PHP_EOL); }
$provasOk = !in_array(false, $provas, true);

echo PHP_EOL, '  ── PRÉ-VOO MIGRATE-ONE (LOCAL → banco produtivo) ──', PHP_EOL;
printf("    DATABASE()=%s · @@hostname=%s · alvo=%s (status: %s)%s",
    (string) ($dbIdent['db'] ?? '?'), (string) ($dbIdent['host'] ?? '?'), AGENDA_MIGRATION, $alvoStatus ?? '?', PHP_EOL);
printf("    pendentes anteriores (ficam pendentes): %s%s", implode(', ', $anterioresPendentes), PHP_EOL);

if (!$apply) {
    echo PHP_EOL, '  ── PLANO ──', PHP_EOL;
    foreach ([
        'lock global das 5 empresas (mesma sessão)',
        'backup remoto (runtime + migration file)',
        'upload da migration file → checksum',
        'migrate-one --version=' . AGENDA_MIGRATION . ' --allow-out-of-order (LOCAL)',
        'verificar: tabela biomie_quote_seller_binding existe · delta = só o alvo · anteriores seguem pendentes',
        'promover runtime (7 arquivos) → checksum → mv',
        'checks remotos php -l + autoload + CHECKS_END · RELEASE_LOCK=1',
        'falha antes do DDL ⇒ restaura tudo; após ⇒ migração aditiva permanece, restaura só runtime',
    ] as $i => $l) { printf("    %d. %s%s", $i + 1, $l, PHP_EOL); }
    echo PHP_EOL, '  ', $provasOk ? '✅' : '⚠️ ', ' DRY-RUN concluído — nada enviado nem aplicado.', PHP_EOL;
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
$ddlDone = false;
$bkp = REMOTE . '/storage/backups/agenda_f10_' . date('Ymd_His');
try {
    echo PHP_EOL, '  ── BACKUP REMOTO ──', PHP_EOL;
    [$c, $o] = ssh("mkdir -p {$bkp} && cd " . REMOTE . ' && for f in ' . $lista . ' ' . escapeshellarg(MIGRATION_FILE) . '; do '
        . "if [ -f \"\$f\" ]; then mkdir -p \"{$bkp}/\$(dirname \$f)\"; cp \"\$f\" \"{$bkp}/\$f\"; fi; done && echo OK");
    if ($c !== 0) { throw new RuntimeException('backup falhou: ' . $o); }
    printf("     backup em %s%s", $bkp, PHP_EOL);

    $assertPinned();

    // ── MIGRAÇÃO: sobe o arquivo, aplica LOCAL (migrate-one), verifica ────────
    echo PHP_EOL, '  ── 1. MIGRATION FILE ──', PHP_EOL;
    [$c] = ssh('mkdir -p ' . REMOTE . '/' . dirname(MIGRATION_FILE));
    [$c, $o] = scpUp($root . '/' . MIGRATION_FILE, REMOTE . '/' . MIGRATION_FILE . '.deploy_tmp');
    if ($c !== 0) { throw new RuntimeException('upload da migration falhou: ' . $o); }
    $migHash = hash_file('sha256', $root . '/' . MIGRATION_FILE);
    [, $o] = ssh('cd ' . REMOTE . ' && sha256sum ' . escapeshellarg(MIGRATION_FILE . '.deploy_tmp'));
    if (!str_starts_with(trim($o), $migHash)) {
        ssh('cd ' . REMOTE . ' && rm -f ' . escapeshellarg(MIGRATION_FILE . '.deploy_tmp'));
        throw new RuntimeException('checksum da migration divergente');
    }
    [$c] = ssh('cd ' . REMOTE . ' && mv -f ' . escapeshellarg(MIGRATION_FILE . '.deploy_tmp') . ' ' . escapeshellarg(MIGRATION_FILE));
    if ($c !== 0) { throw new RuntimeException('promoção da migration falhou'); }
    echo '     migration file no servidor ✔' . PHP_EOL;

    echo PHP_EOL, '  ── 2. MIGRATE-ONE (LOCAL → banco produtivo) ──', PHP_EOL;
    // Reconfere na última janela: o alvo ainda pendente, anteriores idem.
    $pend2 = [];
    foreach ($runner->status()['migrations'] as $m) {
        if (($m['status'] ?? '') === 'pending') { $pend2[] = (string) $m['version']; }
    }
    if (!in_array(AGENDA_MIGRATION, $pend2, true)) {
        throw new RuntimeException('alvo não está mais pendente — abortar antes do DDL');
    }
    $appliedBefore = array_map('strval', $pdo->query(
        "SELECT schema_version FROM biomie_schema_migrations WHERE status='applied'")->fetchAll(PDO::FETCH_COLUMN));
    $ddlDone = true; // a partir daqui o DDL pode ter tocado o schema (aditivo)
    [$c, $o] = run([PHP_BINARY, $root . '/bin/schema_migrate.php', 'migrate-one',
        '--version=' . AGENDA_MIGRATION, '--allow-out-of-order']);
    echo '     ' . str_replace("\n", "\n     ", $o) . PHP_EOL;

    echo PHP_EOL, '  ── 3. VERIFICAÇÃO PÓS-DDL ──', PHP_EOL;
    $tableExists = (int) $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biomie_quote_seller_binding'")->fetchColumn() === 1;
    $appliedAfter = array_map('strval', $pdo->query(
        "SELECT schema_version FROM biomie_schema_migrations WHERE status='applied'")->fetchAll(PDO::FETCH_COLUMN));
    $novas = array_map('strval', array_values(array_diff($appliedAfter, $appliedBefore)));
    $foreignPend = [];
    foreach ($runner->status()['migrations'] as $m) {
        if (($m['status'] ?? '') === 'pending' && strcmp((string) $m['version'], AGENDA_MIGRATION) < 0) {
            $foreignPend[] = (string) $m['version'];
        }
    }
    printf("     tabela biomie_quote_seller_binding: %s%s", $tableExists ? 'existe ✔' : 'AUSENTE ✘', PHP_EOL);
    printf("     delta de versões aplicadas: %s %s%s", json_encode($novas), $novas === [AGENDA_MIGRATION] ? '✔' : '✘', PHP_EOL);
    printf("     anteriores ainda pendentes: %d %s%s", count($foreignPend), $foreignPend !== [] ? '✔' : '(nenhuma)', PHP_EOL);
    if (!$tableExists || $novas !== [AGENDA_MIGRATION]) {
        throw new RuntimeException('verificação pós-DDL falhou — runtime NÃO promovido. Backup: ' . $bkp);
    }

    echo PHP_EOL, '  ── 4. UPLOAD + CHECKSUM (runtime) ──', PHP_EOL;
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
  \$c=[\"BIomie\\\\QuoteAgenda\\\\SellerBindingResolver\",\"BIomie\\\\QuoteAgenda\\\\QuoteAgendaReadService\",\"BIomie\\\\Acl\\\\Roles\"];
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
    echo '     Fase 10 no ar (dormente até haver contas+vínculos de vendedor).', PHP_EOL;
} catch (Throwable $e) {
    $failed = true;
    fwrite(STDERR, PHP_EOL . 'ERRO: ' . $e->getMessage() . PHP_EOL);
    // BARREIRA: antes do DDL, reverter TUDO (inclui remover a migration file).
    // Depois do DDL, a migração é ADITIVA e PERMANECE (CREATE TABLE) — restaura
    // só o runtime; a migration file fica p/ o schema não ficar órfão do arquivo.
    $l = implode(' ', array_map('escapeshellarg', array_keys($localHashes)));
    if (!$ddlDone) {
        echo PHP_EOL, '  ── RESTAURANDO TUDO (schema intacto) ──', PHP_EOL;
        [, $o] = ssh('cd ' . REMOTE . ' && for f in ' . $l . ' ' . escapeshellarg(MIGRATION_FILE) . '; do '
            . "if [ -f \"{$bkp}/\$f\" ]; then cp -f \"{$bkp}/\$f\" \"\$f\" && echo \"revertido \$f\"; "
            . "else rm -f \"\$f\" && echo \"removido  \$f\"; fi; rm -f \"\$f.deploy_tmp\"; done");
        echo '     ' . str_replace("\n", "\n     ", trim($o)) . PHP_EOL;
    } else {
        echo PHP_EOL, '  ── RESTAURANDO SÓ RUNTIME (migração aditiva permanece) ──', PHP_EOL;
        fwrite(STDERR, 'A tabela é aditiva e permanece; código tolera ambos os estados (503/degrada). NÃO rodar down().' . PHP_EOL);
        [, $o] = ssh('cd ' . REMOTE . ' && for f in ' . $l . '; do '
            . "if [ -f \"{$bkp}/\$f\" ]; then cp -f \"{$bkp}/\$f\" \"\$f\" && echo \"revertido \$f\"; "
            . "else rm -f \"\$f\" && echo \"removido  \$f\"; fi; rm -f \"\$f.deploy_tmp\"; done");
        echo '     ' . str_replace("\n", "\n     ", trim($o)) . PHP_EOL;
    }
} finally {
    $liberar();
}

exit($failed ? 1 : 0);
