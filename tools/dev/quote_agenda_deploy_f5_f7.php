<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — deploy das Fases 5–7 (dry-run por padrão).
 *
 * SEGURO POR PADRÃO: sem --apply nada é enviado, nada é aplicado.
 *
 * PRINCÍPIOS
 *  · ALLOWLIST explícita com SHA-256 por arquivo. Nunca diretório inteiro,
 *    nunca curinga — cada caminho é aprovado individualmente.
 *  · As 4 migrations de OUTRO fluxo de trabalho (InvoiceProjectLineage,
 *    InvoiceFiscalAuthorizationStatus, CompetenciaSnapshotVisibilityScope,
 *    ChannelProjectClassification) NÃO entram no manifesto e NÃO são aplicadas.
 *    Se já existirem no servidor, permanecem intocadas.
 *  · A migration da Agenda é aplicada por `migrate-one --version=... ` — o
 *    `migrate` comum arrastaria as alheias.
 *  · O migrate-one roda na CÓPIA LOCAL conectada ao BANCO PRODUTIVO. O binário
 *    que executa o DDL é o mesmo que os testes locais exercitaram; o servidor
 *    recebe CLI e migration para ficar consistente, mas não os executa.
 *  · Ordem: CLI+migrations → migrate-one (local) → verificação → runtime → checks.
 *  · Lock global das 5 empresas; RELEASE_LOCK exigido = 1 e ausência confirmada.
 *  · BARREIRA DE ROLLBACK: o que se pode desfazer muda quando o DDL começa.
 *    Ver $barreira — antes do DDL restaura-se tudo; depois, só o runtime.
 *    NUNCA rodar down() em produção.
 *
 * Uso:
 *   php tools/dev/quote_agenda_deploy_f5_f7.php              # dry-run + manifesto
 *   php tools/dev/quote_agenda_deploy_f5_f7.php --apply
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();
set_time_limit(0);

// Barreira de rollback + AGENDA_MIGRATION. Arquivo próprio para que o teste
// (tests/run_deploy_rollback_barrier.php) exercite a MESMA função que roda aqui.
require_once __DIR__ . '/quote_agenda_deploy_barrier_fn.php';

$opts   = getopt('', ['apply', 'resume-after-ddl']);
$apply  = isset($opts['apply']);

/**
 * MODO RESUME — retomada pós-RECOVERY_REQUIRED de 2026-07-20 00:59 UTC.
 *
 * O DDL foi aplicado CORRETAMENTE naquele apply; quem falhou foi o guard do
 * migrate-one (bug de tipagem int/string, corrigido + coberto por
 * tests/run_migrate_one_guard_types.php). Neste modo:
 *   · o migrate-one é PULADO — a versão já está aplicada;
 *   · as pré-condições exigem o estado EXATO deixado pelo DDL;
 *   · o stage1 é REPROMOVIDO para levar o CLI corrigido;
 *   · qualquer falha restaura do backup desta retomada — que já é
 *     compatível com o schema aplicado, então a restauração é total.
 */
$resume = isset($opts['resume-after-ddl']);

const SSH_HOST = 'u106688109@82.112.247.199';
const SSH_PORT = '65002';
const REMOTE   = '/home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie';
const COMPANIES = [1, 2, 3, 4, 5];

/** Migrations de OUTRO fluxo — jamais enviadas nem aplicadas. */
const FOREIGN_MIGRATIONS = [
    '20260718010000_InvoiceProjectLineage',
    '20260718020000_InvoiceFiscalAuthorizationStatus',
    '20260718030000_CompetenciaSnapshotVisibilityScope',
    '20260718040000_ChannelProjectClassification',
];

/**
 * Conjunto EXATO de pendentes esperado no banco produtivo.
 *
 * O migrate-one age sobre um alvo declarado, mas o operador precisa saber
 * exatamente o que MAIS está pendente antes de autorizar um out-of-order.
 * Divergência aqui significa que outro fluxo avançou desde a análise — o
 * deploy aborta e o plano é refeito, não adaptado em tempo de execução.
 */
const EXPECTED_PENDING = [
    '20260718010000',
    '20260718020000',
    '20260718030000',
    '20260718040000',
    AGENDA_MIGRATION,
];

/**
 * MANIFESTO — allowlist explícita, agrupada por etapa de promoção.
 * Etapa 1 vai antes do migrate-one; etapa 2 é o runtime.
 */
const MANIFEST = [
    // ── Etapa 1: ferramenta + migrations (necessárias para migrate-one/verify) ──
    'stage1_tooling' => [
        'bin/schema_migrate.php',                                                  // + migrate-one
        'database/migrations/Version20260715000000_QuoteAgendaOrderDates.php',     // F2 (verify)
        'database/migrations/Version20260716000000_QuoteAgendaFollowupFoundation.php', // F3 (verify)
        'database/migrations/Version20260718000000_QuoteAgendaChargeAudit.php',    // F5 (verify)
        'database/migrations/Version20260718120000_QuoteAgendaChargeAuditSnapshots.php', // F7 (aplicar)
    ],

    // ── Etapa 2: runtime das Fases 5–7 ──────────────────────────────────────────
    'stage2_domain' => [
        'app/QuoteAgenda/FollowupDatePolicy.php',
        'app/QuoteAgenda/QuoteLiveSnapshot.php',
        'app/QuoteAgenda/QuoteLiveSnapshotSource.php',
        'app/QuoteAgenda/QuoteLiveSnapshotService.php',
        'app/QuoteAgenda/QuoteLiveSnapshotScanner.php',
        'app/QuoteAgenda/QuoteAgendaReadService.php',
        'app/QuoteAgenda/QuoteFollowupChargeService.php',
    ],
    'stage2_shared' => [
        'app/Acl/Roles.php',              // capabilities .view/.manage
        'app/PageAssetRegistry.php',      // agenda-orcamentos + -inativa
        'app/PageRenderer.php',           // mapa template → page_key
    ],
    'stage2_api' => [
        'api/quote_agenda_common.php',
        'api/quote_agenda_summary.php',
        'api/quote_agenda_calendar.php',
        'api/quote_agenda_day.php',
        'api/quote_agenda_detail.php',
        'api/quote_agenda_history.php',
        'api/quote_agenda_charge.php',
        'api/quote_agenda_policy.php',
        'api/quote_agenda_freshness.php',
    ],
    'stage2_ui' => [
        'quote_agenda.php',
        'views/quote_agenda.php',
        'assets/css/quote-agenda.css',
        'assets/css/quote-agenda.min.css',
        'assets/js/modules/quote-agenda/index.js',
        'assets/js/modules/quote-agenda/index.min.js',
    ],
    'stage2_ops' => [
        'bin/quote_agenda_scan.php',            // scanner (F4) — sem cron ainda
        'tools/dev/biomie_release_guard.php',   // D55
    ],
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
    // Fonte editada no Windows: scripts multi-linha carregariam \r\n e o bash
    // remoto veria "caminho\r" (inexistente). Foi exatamente o que ENGOLIU os
    // checks remotos no resume de 2026-07-20 — normalizar é obrigatório.
    return run(['ssh', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=no',
        '-o', 'ConnectTimeout=15', '-p', SSH_PORT, SSH_HOST, str_replace("\r\n", "\n", $script)]);
}
function scpUp(string $local, string $remote): array
{
    return run(['scp', '-q', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=no',
        '-P', SSH_PORT, $local, SSH_HOST . ':' . $remote]);
}

/** @return list<string> todos os caminhos do manifesto, em ordem de promoção */
function manifestFiles(?string $stage = null): array
{
    $out = [];
    foreach (MANIFEST as $k => $files) {
        if ($stage !== null && !str_starts_with($k, $stage)) { continue; }
        foreach ($files as $f) { $out[] = $f; }
    }

    return $out;
}

echo str_repeat('═', 80), PHP_EOL;
echo '  DEPLOY AGENDA — FASES 5–7 · ' . ($apply ? 'APPLY' : 'DRY-RUN (nada será enviado)')
    . ($resume ? ' · RESUME-AFTER-DDL' : ''), PHP_EOL;
echo str_repeat('═', 80), PHP_EOL;

// ── 1. Manifesto local + SHA-256 ─────────────────────────────────────────────
echo PHP_EOL . '  ── MANIFESTO (allowlist explícita · SHA-256) ──' . PHP_EOL;
$localHashes = [];
$missing = [];
foreach (MANIFEST as $stage => $files) {
    echo PHP_EOL . "  [{$stage}]" . PHP_EOL;
    foreach ($files as $f) {
        $path = $root . '/' . $f;
        if (!is_file($path)) { $missing[] = $f; printf("    %-62s %s%s", $f, 'AUSENTE LOCAL', PHP_EOL); continue; }
        $localHashes[$f] = hash_file('sha256', $path);
        printf("    %-62s %s%s", $f, substr($localHashes[$f], 0, 16), PHP_EOL);
    }
}
$total = count($localHashes);
echo PHP_EOL . "  total: {$total} arquivo(s)" . PHP_EOL;
if ($missing !== []) {
    fwrite(STDERR, 'ABORTA: arquivos ausentes no local: ' . implode(', ', $missing) . PHP_EOL);
    exit(2);
}

// ── 2. Lint local de tudo que é PHP ──────────────────────────────────────────
echo PHP_EOL . '  ── LINT LOCAL ──' . PHP_EOL;
$lintFail = [];
foreach (array_keys($localHashes) as $f) {
    if (!str_ends_with($f, '.php')) { continue; }
    [$c] = run([PHP_BINARY, '-l', $root . '/' . $f]);
    if ($c !== 0) { $lintFail[] = $f; }
}
echo '    ' . ($lintFail === [] ? 'OK — todos os .php passam' : 'FALHA: ' . implode(', ', $lintFail)) . PHP_EOL;
if ($lintFail !== []) { exit(2); }

// ── 3. Estado remoto ─────────────────────────────────────────────────────────
echo PHP_EOL . '  ── ESTADO REMOTO ──' . PHP_EOL;
$lista = implode(' ', array_map('escapeshellarg', array_keys($localHashes)));
[$c, $o] = ssh('cd ' . REMOTE . ' && for f in ' . $lista . '; do if [ -f "$f" ]; then sha256sum "$f"; else echo "AUSENTE $f"; fi; done');
if ($c !== 0) {
    fwrite(STDERR, 'ABORTA: nao consegui ler o estado remoto.' . PHP_EOL . $o . PHP_EOL);
    exit(2);
}
$remoteHashes = [];
foreach (explode("\n", $o) as $line) {
    $line = trim($line);
    if (preg_match('/^([0-9a-f]{64})\s+\.?\/?(.+)$/', $line, $m)) { $remoteHashes[$m[2]] = $m[1]; }
}
$novos = $iguais = $diferentes = [];
foreach ($localHashes as $f => $h) {
    if (!isset($remoteHashes[$f]))      { $novos[] = $f; }
    elseif ($remoteHashes[$f] === $h)   { $iguais[] = $f; }
    else                                { $diferentes[] = $f; }
}
printf("    novos: %d · idênticos: %d · a atualizar: %d%s", count($novos), count($iguais), count($diferentes), PHP_EOL);
foreach ($novos as $f)       { echo "      + {$f}" . PHP_EOL; }
foreach ($diferentes as $f)  { echo "      ~ {$f}" . PHP_EOL; }

// ── 3b. SHA remoto ANTERIOR fixado (anti-TOCTOU) ─────────────────────────────
// Entre a leitura do estado remoto e a promoção existe uma janela. Se outra
// pessoa (ou outro deploy) tocar um desses arquivos nesse intervalo, o backup
// já não descreve o que será sobrescrito. Fixamos o SHA agora e reconferimos
// imediatamente antes de cada promoção; qualquer mudança aborta.
$pinnedRemote = [];
foreach ($diferentes as $f) { $pinnedRemote[$f] = $remoteHashes[$f]; }
echo PHP_EOL . '  ── SHA REMOTO FIXADO (reconferido antes de promover) ──' . PHP_EOL;
if ($pinnedRemote === []) {
    echo '    nenhum arquivo pré-existente será sobrescrito' . PHP_EOL;
}
foreach ($pinnedRemote as $f => $h) {
    printf("    %-62s %s%s", $f, substr($h, 0, 16), PHP_EOL);
}

/**
 * Reconfere os SHAs fixados. Aborta se qualquer arquivo mudou desde a análise.
 *
 * @param array<string,string> $pinned
 * @param list<string>         $scope  subconjunto a conferir (etapa em promoção)
 */
$assertPinned = static function (array $pinned, array $scope) : void {
    $alvo = array_intersect_key($pinned, array_flip($scope));
    if ($alvo === []) { return; }
    $l = implode(' ', array_map('escapeshellarg', array_keys($alvo)));
    [$c, $o] = ssh('cd ' . REMOTE . ' && for f in ' . $l . '; do if [ -f "$f" ]; then sha256sum "$f"; else echo "AUSENTE $f"; fi; done');
    if ($c !== 0) { throw new RuntimeException('não consegui reconferir os SHAs remotos fixados'); }
    $agora = [];
    foreach (explode("\n", $o) as $line) {
        if (preg_match('/^([0-9a-f]{64})\s+\.?\/?(.+)$/', trim($line), $m)) { $agora[$m[2]] = $m[1]; }
    }
    $mudou = [];
    foreach ($alvo as $f => $h) {
        if (($agora[$f] ?? '') !== $h) { $mudou[] = $f; }
    }
    if ($mudou !== []) {
        throw new RuntimeException(
            'ABORTA: arquivo remoto mudou desde a análise (outro deploy em curso?): ' . implode(', ', $mudou)
        );
    }
    printf("     SHA remoto reconferido: %d arquivo(s) inalterado(s) ✔%s", count($alvo), PHP_EOL);
};

// ── 4. Migrations alheias: presentes remotamente? (nunca tocadas) ────────────
echo PHP_EOL . '  ── MIGRATIONS DE OUTRO FLUXO (não enviadas, não aplicadas) ──' . PHP_EOL;
$fl = implode(' ', array_map(static fn ($m) => escapeshellarg('database/migrations/Version' . $m . '.php'), FOREIGN_MIGRATIONS));
[, $of] = ssh('cd ' . REMOTE . ' && for f in ' . $fl . '; do if [ -f "$f" ]; then echo "REMOTA  $f"; else echo "ausente $f"; fi; done');
echo '    ' . str_replace("\n", "\n    ", $of) . PHP_EOL;

// ── 5. Estado do banco (pré) ─────────────────────────────────────────────────
$config = new Config();
$logger = new Logger($root . '/storage/logs/biomie.log');
$conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $conn);

$snapCols = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biomie_quote_followup_charge_audit'
        AND COLUMN_NAME IN ('manager_name_snapshot','seller_id_at_charge','seller_name_at_charge')"
)->fetchColumn();
$auditRows = (int) $pdo->query('SELECT COUNT(*) FROM biomie_quote_followup_charge_audit')->fetchColumn();
$appliedBefore = array_map('strval', $pdo->query(
    "SELECT schema_version FROM biomie_schema_migrations WHERE status = 'applied'"
)->fetchAll(PDO::FETCH_COLUMN));

echo PHP_EOL . '  ── BANCO (pré) ──' . PHP_EOL;
printf("    colunas snapshot: %d/3 · auditoria: %d linha(s) · migrations aplicadas: %d%s",
    $snapCols, $auditRows, count($appliedBefore), PHP_EOL);

// ── 5b. PRÉ-VOO DO MIGRATE-ONE (executa LOCAL, contra o banco PRODUTIVO) ─────
// O DDL é disparado pela cópia local — o mesmo binário que os testes locais
// exercitaram. Antes disso, provamos CONTRA QUE BANCO estamos falando e QUE
// conjunto de pendentes existe lá. Sem segredos: nada de host real, usuário
// ou senha; apenas o nome lógico da conexão e o que o próprio servidor diz.
echo PHP_EOL . '  ── PRÉ-VOO DO MIGRATE-ONE (execução LOCAL · banco PRODUTIVO) ──' . PHP_EOL;

$dbIdent = $pdo->query('SELECT DATABASE() AS db, @@hostname AS host, VERSION() AS ver')
    ->fetch(PDO::FETCH_ASSOC) ?: [];

// CONNECTION_ID da sessão que segurará os locks. GET_LOCK pertence à CONEXÃO:
// registrar o id aqui permite provar, na liberação, que foi a MESMA sessão —
// e não outra que apenas encontrou o lock livre por acaso.
$connId = (string) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();

/** Nome do host que executa o migrate-one (não é o host do banco). */
$execHost = php_uname('n');

$runner = new \BIomie\Migrations\MigrationRunner(
    $config,
    $logger,
    $root . '/' . trim((string) $config->get('migrations.path', 'database/migrations'), '/'),
    (string) $config->get('migrations.table', 'biomie_schema_migrations'),
    (string) $config->get('app.version', 'dev')
);
$mStatus = $runner->status();
$pendentesDescobertas = [];
foreach ($mStatus['migrations'] as $m) {
    if (($m['status'] ?? '') === 'pending') { $pendentesDescobertas[] = (string) $m['version']; }
}
sort($pendentesDescobertas);
// Em resume o DDL já aconteceu: o esperado são SOMENTE as 4 alheias.
$esperadas = $resume
    ? array_map(static fn ($m) => explode('_', $m)[0], FOREIGN_MIGRATIONS)
    : EXPECTED_PENDING;
sort($esperadas);

echo '    onde o migrate-one roda ... LOCAL (esta máquina), via ' . PHP_BINARY . PHP_EOL;
echo '    conexão lógica ........... ' . $conn . PHP_EOL;
echo '    DATABASE() ............... ' . (string) ($dbIdent['db'] ?? '?') . PHP_EOL;
echo '    @@hostname ............... ' . (string) ($dbIdent['host'] ?? '?') . PHP_EOL;
echo '    servidor ................. ' . (string) ($dbIdent['ver'] ?? '?') . PHP_EOL;
echo '    versão-alvo .............. ' . AGENDA_MIGRATION
    . ($resume ? ' (JÁ APLICADA — migrate-one será PULADO)' : '') . PHP_EOL;
echo '    pendentes descobertas .... ' . count($pendentesDescobertas) . PHP_EOL;
foreach ($pendentesDescobertas as $v) {
    $alvo = $v === AGENDA_MIGRATION;
    printf("      %s %s%s%s", $alvo ? '→' : ' ', $v, $alvo ? '   (ALVO deste deploy)' : '   (outro fluxo — permanece pendente)', PHP_EOL);
}

$pendingBate = $pendentesDescobertas === $esperadas;
printf("    conjunto == esperado ..... %s%s", $pendingBate ? 'SIM ✔' : 'NÃO ✘', PHP_EOL);
if (!$pendingBate) {
    echo '      esperado: ' . implode(', ', $esperadas) . PHP_EOL;
    echo '      obtido:   ' . implode(', ', $pendentesDescobertas) . PHP_EOL;
}
printf("    flags: enabled=%s scanner=%s notif=%s%s",
    var_export((bool) $config->get('features.quote_agenda_enabled', false), true),
    var_export((bool) $config->get('features.quote_agenda_scanner_enabled', false), true),
    var_export((bool) $config->get('features.quote_agenda_notifications_enabled', false), true), PHP_EOL);

// ── 5c. LOCKS: aquisição e liberação com prova de MESMA SESSÃO ───────────────
// GET_LOCK pertence à CONEXÃO — nem COMMIT nem fim de transação o soltam, e
// só a sessão proprietária consegue liberá-lo. Por isso registramos o
// CONNECTION_ID() na aquisição e reconferimos na liberação: sem isso, um
// RELEASE_LOCK bem sucedido poderia estar apenas encontrando o lock livre.
/** Nomes dos locks reais do pipeline (uma chave por empresa). */
$lockNames = array_map(static fn (int $c): string => 'biomie:realtime_pipeline:company:' . $c, COMPANIES);

/**
 * Adquire as chaves e registra o CONNECTION_ID de quem passou a possuí-las.
 *
 * @param list<string> $names
 * @return array{ok:bool,conn_id:string,evidencias:list<array<string,mixed>>}
 */
$locksAdquirir = static function (PDO $pdo, array $names, int $timeout, ?Logger $logger): array {
    $connId = (string) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
    $ev = [];
    $obtidas = [];
    foreach ($names as $name) {
        $g = $pdo->prepare('SELECT GET_LOCK(:n, :t) AS a');
        $g->execute(['n' => $name, 't' => $timeout]);
        $ok = ((int) $g->fetchColumn()) === 1;

        // IS_USED_LOCK devolve o CONNECTION_ID do PROPRIETÁRIO: se for o nosso,
        // a posse está provada — não é inferência por ausência.
        $u = $pdo->prepare('SELECT IS_USED_LOCK(:n) AS used');
        $u->execute(['n' => $name]);
        $dono = $u->fetchColumn();

        $ev[] = [
            'lock' => $name,
            'get_lock' => $ok ? 1 : 0,
            'owner_connection_id' => $dono === null ? null : (string) $dono,
            'mesma_sessao' => $dono !== null && (string) $dono === $connId,
        ];
        if (!$ok) {
            // Devolve o que já pegou: nada foi tocado ainda.
            foreach ($obtidas as $j) {
                $r = $pdo->prepare('SELECT RELEASE_LOCK(:n)');
                $r->execute(['n' => $j]);
            }
            $logger?->warning('Lock indisponível no deploy.', ['lock' => $name]);
            return ['ok' => false, 'conn_id' => $connId, 'evidencias' => $ev];
        }
        $obtidas[] = $name;
    }

    return ['ok' => true, 'conn_id' => $connId, 'evidencias' => $ev];
};

/**
 * Libera exigindo RELEASE_LOCK = 1 e confirmando ausência do lock.
 *
 * RELEASE_LOCK: 1 = liberado por ESTA sessão · 0 = existe mas é de outra ·
 * NULL = não existe. Exigir 1 é o que distingue liberação real de no-op.
 *
 * @param list<string> $names
 * @return array{ok:bool,conn_id:string,evidencias:list<array<string,mixed>>}
 */
$locksLiberar = static function (PDO $pdo, array $names, string $connIdEsperado, string $rotulo = 'LIBERANDO LOCK'): array {
    echo PHP_EOL . "  ── {$rotulo} ──" . PHP_EOL;
    $connIdAgora = (string) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
    $mesmaSessao = $connIdAgora === $connIdEsperado;
    printf("     CONNECTION_ID na aquisição=%s · na liberação=%s %s%s",
        $connIdEsperado, $connIdAgora, $mesmaSessao ? '✔ MESMA SESSÃO' : '✘ SESSÃO DIFERENTE', PHP_EOL);

    $tudoOk = $mesmaSessao;
    $ev = [];
    foreach ($names as $name) {
        $rl = $pdo->prepare('SELECT RELEASE_LOCK(:n) AS r');
        $rl->execute(['n' => $name]);
        $r = $rl->fetchColumn();
        $liberado = $r !== null && (int) $r === 1;

        $chk = $pdo->prepare('SELECT IS_USED_LOCK(:n) AS used, IS_FREE_LOCK(:n2) AS free');
        $chk->execute(['n' => $name, 'n2' => $name]);
        $st = $chk->fetch(PDO::FETCH_ASSOC) ?: [];
        $used = $st['used'] ?? null;
        $free = $st['free'] ?? null;
        $ausente = $used === null || (int) $free === 1;

        $ok = $liberado && $ausente;
        $tudoOk = $tudoOk && $ok;
        $ev[] = [
            'lock' => $name,
            'release_lock' => $r === null ? null : (int) $r,
            'is_used_lock_after' => $used === null ? null : (string) $used,
            'is_free_lock_after' => $free === null ? null : (int) $free,
            'ok' => $ok,
        ];
        printf("     %-46s RELEASE_LOCK=%s IS_USED_LOCK=%s IS_FREE_LOCK=%s %s%s",
            $name,
            $r === null ? 'NULL' : (string) $r,
            $used === null ? 'NULL' : (string) $used,
            $free === null ? 'NULL' : (string) $free,
            $ok ? '✔' : '✘ NÃO LIBERADO', PHP_EOL);
    }
    if (!$tudoOk) {
        fwrite(STDERR, '  ⚠️  ATENÇÃO: liberação não comprovada em alguma chave. ' .
            'O lock cai quando a conexão encerrar, mas verifique o pipeline.' . PHP_EOL);
    }

    return ['ok' => $tudoOk, 'conn_id' => $connIdAgora, 'evidencias' => $ev];
};

// ── 6. Provas exigidas antes da autorização ─────────────────────────────────
echo PHP_EOL . '  ── PROVAS PRÉ-AUTORIZAÇÃO ──' . PHP_EOL;
$provas = [];

// 6a. Flag OFF ⇒ sem raiz, sem JS, sem CSS
$htmlOff = \BIomie\PageRenderer::renderPhp('quote_agenda.php',
    ['page_key' => 'agenda-orcamentos-inativa', 'module_enabled' => false, 'can_manage' => false]);
$provas['flag OFF: sem raiz no DOM']  = !str_contains($htmlOff, 'quoteAgendaRoot');
$provas['flag OFF: sem JS no HTML']   = preg_match('#quote-agenda[^"]*\.js#', $htmlOff) !== 1;
$provas['flag OFF: sem CSS no HTML']  = preg_match('#quote-agenda[^"]*\.css#', $htmlOff) !== 1;

// 6b. TODOS os endpoints recusam quando a feature está desativada
$semGuarda = [];
foreach (MANIFEST['stage2_api'] as $f) {
    if (str_ends_with($f, 'common.php')) { continue; }
    $src = (string) file_get_contents($root . '/' . $f);
    if (!str_contains($src, 'quoteAgendaRequireEnabled();')) { $semGuarda[] = basename($f); }
}
$provas['8 endpoints recusam com feature OFF'] = $semGuarda === [];

// 6c. Nada é ativado por este deploy
$provas['nenhuma política habilitada'] =
    (int) $pdo->query('SELECT COUNT(*) FROM biomie_quote_followup_policy WHERE enabled = 1')->fetchColumn() === 0;
$provas['nenhuma cobrança registrada'] =
    (int) $pdo->query('SELECT COALESCE(SUM(charge_count),0) FROM biomie_sales_quote_followup_schedule')->fetchColumn() === 0;
[, $cronOut] = ssh('crontab -l 2>/dev/null | grep -c quote_agenda || true');
$provas['nenhum cron da agenda no crontab'] = trim($cronOut) === '0' || trim($cronOut) === '';

// 6d. Pré-voo do migrate-one
$provas['pendentes descobertas == as ' . count($esperadas) . ' esperadas'] = $pendingBate;
$provas['checksums íntegros (pré)'] = (int) ($mStatus['summary']['checksum_mismatch'] ?? -1) === 0;

// 6d-bis. RESUME: o estado que o DDL deixou precisa estar EXATAMENTE lá.
if ($resume) {
    $aplicadaJa = in_array(AGENDA_MIGRATION, $appliedBefore, true);
    $provas['RESUME: migration ' . AGENDA_MIGRATION . ' já aplicada'] = $aplicadaJa;
    $provas['RESUME: 3/3 colunas snapshot presentes'] = $snapCols === 3;
    $provas['RESUME: auditoria continua vazia'] = $auditRows === 0;
    [, $marcaOut] = ssh('ls ' . REMOTE . '/storage/RECOVERY_REQUIRED_agenda_*.txt 2>/dev/null | wc -l');
    $provas['RESUME: marca RECOVERY_REQUIRED presente (será resolvida)'] = (int) trim($marcaOut) >= 1;
}

// 6d-ter. Guard do migrate-one sem o bug de tipagem que causou o falso
// RECOVERY_REQUIRED de 2026-07-20 (array_keys → int vs string).
[$cTyp] = run([PHP_BINARY, $root . '/tests/run_migrate_one_guard_types.php']);
$provas['guard do migrate-one com tipagem provada (run_migrate_one_guard_types)'] = $cTyp === 0;

// 6e. `pending` é DESCOBERTAS − APLICADAS, não marca-d'água.
// Sem isso, aplicar a Agenda fora de ordem poderia deixar as 4 alheias
// permanentemente "ultrapassadas" e nunca mais migráveis.
[$cSem] = run([PHP_BINARY, $root . '/tests/run_migration_pending_semantics.php']);
$provas['semântica de pending provada (run_migration_pending_semantics)'] = $cSem === 0;

// 6f. Barreira de rollback + liberação de lock — os caminhos que um deploy
// bem sucedido nunca percorre são justamente os que precisam de prova.
[$cBar] = run([PHP_BINARY, $root . '/tests/run_deploy_rollback_barrier.php']);
$provas['barreira de rollback e RELEASE_LOCK provados (run_deploy_rollback_barrier)'] = $cBar === 0;

foreach ($provas as $nome => $ok) {
    printf("    %s %s%s", $ok ? '✅' : '❌', $nome, PHP_EOL);
}
$provasOk = !in_array(false, $provas, true);

// ── 7. Plano ─────────────────────────────────────────────────────────────────
echo PHP_EOL . '  ── PLANO DE EXECUÇÃO ──' . PHP_EOL;
echo '    1. lock global das ' . count(COMPANIES) . ' empresas (GET_LOCK por empresa, mesma sessão)' . PHP_EOL;
echo '    2. backup remoto dos destinos exatos do manifesto' . PHP_EOL;
echo '    3. promover ETAPA 1 (CLI migrate-one + migration da Agenda) — SHA remoto reconferido' . PHP_EOL;
echo '    4. migrate-one --version=' . AGENDA_MIGRATION . ' --allow-out-of-order' . PHP_EOL;
echo '       executado LOCALMENTE contra o banco produtivo (não por SSH)' . PHP_EOL;
echo '       (out-of-order é consciente: as 4 alheias são ANTERIORES e ficam pendentes)' . PHP_EOL;
echo '    5. classificar a BARREIRA: 1 registro novo · 3 colunas snapshot · versão' . PHP_EOL;
echo '       registrada · auditoria vazia · 4 alheias pendentes · checksums íntegros' . PHP_EOL;
echo '    6. promover ETAPA 2 (runtime das Fases 5–7) — SHA remoto reconferido' . PHP_EOL;
echo '    7. php -l remoto · autoload · opcache_reset' . PHP_EOL;
echo '    8. RELEASE_LOCK = 1 exigido + IS_USED_LOCK NULL / IS_FREE_LOCK = 1 confirmados' . PHP_EOL;

echo PHP_EOL . '  ── BARREIRA DE ROLLBACK (o que é reversível muda com o DDL) ──' . PHP_EOL;
foreach ([
    'PRE_DDL           falha antes do primeiro DDL → restaura TODO o stage1 (arquivo novo é removido). Estado idêntico ao anterior.',
    'DDL_DONE          migration registrada + 3 colunas → CLI e migration PERMANECEM no servidor (o schema mudou; o código precisa acompanhar).',
    'STAGE2_FAIL       migration OK, runtime falhou → restaura SOMENTE os arquivos de runtime. stage1 fica.',
    'RECOVERY_REQUIRED DDL parcial sem versão registrada → NÃO restaura nada, PRESERVA CLI+migration, marca e PARA para inspeção humana.',
] as $linha) {
    echo '    · ' . $linha . PHP_EOL;
}

echo PHP_EOL . '  ── CONDIÇÕES DE ABORTAGEM ──' . PHP_EOL;
foreach ([
    'arquivo do manifesto ausente no local',
    'lint local falhando',
    'lock de qualquer empresa indisponível',
    'checksum divergente após upload (temporários removidos, nada promovido)',
    'migrate-one recusado (versão inválida/aplicada/checksum) ou mais de 1 registro novo',
    'alguma das 4 migrations alheias mudar de estado',
    'php -l remoto falhando após promoção → restaurar do backup',
    'SHA remoto fixado divergente na reconferência (outro deploy em curso)',
    'conjunto de pendentes diferente do esperado para o modo (5 no apply · 4 no resume)',
    'qualquer prova pré-autorização falhando',
] as $cond) {
    echo '    · ' . $cond . PHP_EOL;
}

echo PHP_EOL . '  ── PÓS-FALHA ──' . PHP_EOL;
echo '    A restauração segue a barreira acima. A migration é ADITIVA e, uma vez' . PHP_EOL;
echo '    aplicada, PERMANECE — o código tolera ambos os estados (hasSnapshotColumns()).' . PHP_EOL;
echo '    NUNCA rodar down() em produção.' . PHP_EOL;

if (!$apply) {
    // ── ENSAIO DE LOCK (chaves-sombra) ───────────────────────────────────────
    // As chaves REAIS não são adquiridas aqui: pegá-las bloquearia o pipeline
    // das 5 empresas por um deploy que não vai acontecer. Ensaiamos a MESMA
    // sequência SQL em chaves-sombra e, sobre as reais, fazemos apenas a
    // consulta IS_FREE_LOCK — leitura pura, sem posse.
    echo PHP_EOL . '  ── ENSAIO DE LOCK (chaves-sombra · reais só consultadas) ──' . PHP_EOL;
    $sombra = array_map(static fn (string $n): string => $n . ':dryrun' . getmypid(), $lockNames);
    $ensaioAq = $locksAdquirir($pdo, $sombra, 5, $logger);
    foreach ($ensaioAq['evidencias'] as $e) {
        printf("     %-56s GET_LOCK=%d owner=%s %s%s",
            $e['lock'], $e['get_lock'], $e['owner_connection_id'] ?? 'NULL',
            $e['mesma_sessao'] ? '✔' : '✘', PHP_EOL);
    }
    $ensaioLib = $locksLiberar($pdo, $sombra, $ensaioAq['conn_id'], 'ENSAIO — LIBERAÇÃO');

    echo PHP_EOL . '     disponibilidade das chaves REAIS (somente leitura):' . PHP_EOL;
    $reaisLivres = true;
    foreach ($lockNames as $n) {
        $q = $pdo->prepare('SELECT IS_FREE_LOCK(:n) AS free');
        $q->execute(['n' => $n]);
        $free = (int) $q->fetchColumn();
        $reaisLivres = $reaisLivres && $free === 1;
        printf("       %-46s IS_FREE_LOCK=%d %s%s", $n, $free, $free === 1 ? '✔ livre' : '✘ em uso', PHP_EOL);
    }

    // ── RECONFERÊNCIA AO VIVO DA PRÉ-IMAGEM REMOTA ───────────────────────────
    echo PHP_EOL . '  ── PRÉ-IMAGEM REMOTA (reconferência ao vivo) ──' . PHP_EOL;
    $preimagemOk = true;
    try {
        $assertPinned($pinnedRemote, array_keys($pinnedRemote));
    } catch (Throwable $e) {
        $preimagemOk = false;
        echo '     ✘ ' . $e->getMessage() . PHP_EOL;
    }

    // ── EVIDÊNCIAS ───────────────────────────────────────────────────────────
    // OBSERVADO = medido agora. PREVISTO = só existirá após o migrate-one; é
    // apresentado com a asserção que o exigirá, jamais como se já fosse fato.
    echo PHP_EOL . str_repeat('─', 80) . PHP_EOL;
    echo '  EVIDÊNCIAS DO DRY-RUN' . PHP_EOL;
    echo str_repeat('─', 80) . PHP_EOL;

    echo PHP_EOL . '  [OBSERVADO — execução e identidade]' . PHP_EOL;
    printf("    migration_execution_host ...... %s (LOCAL; não é o host do banco)%s", $execHost, PHP_EOL);
    printf("    database ...................... %s%s", (string) ($dbIdent['db'] ?? '?'), PHP_EOL);
    printf("    hostname ...................... %s%s", (string) ($dbIdent['host'] ?? '?'), PHP_EOL);
    printf("    server_version ................ %s%s", (string) ($dbIdent['ver'] ?? '?'), PHP_EOL);
    printf("    connection_id ................. %s%s", $connId, PHP_EOL);

    echo PHP_EOL . '  [OBSERVADO — estado das migrations]' . PHP_EOL;
    printf("    pending_before ................ %d versões%s", count($pendentesDescobertas), PHP_EOL);
    foreach ($pendentesDescobertas as $v) {
        printf("      · %s%s%s", $v, $v === AGENDA_MIGRATION ? '   ← migration_target' : '', PHP_EOL);
    }
    printf("    pending_before == esperado .... %s%s", $pendingBate ? 'SIM ✔' : 'NÃO ✘', PHP_EOL);
    printf("    migration_target .............. %s%s", AGENDA_MIGRATION, PHP_EOL);
    printf("    out_of_order .................. PERMITIDO EXPLICITAMENTE (--allow-out-of-order)%s", PHP_EOL);
    printf("      motivo: %d migrations ANTERIORES de outro fluxo permanecem pendentes%s",
        count($pendentesDescobertas) - 1, PHP_EOL);

    echo PHP_EOL . '  [OBSERVADO — pré-imagem remota]' . PHP_EOL;
    printf("    remote_preimage_sha ........... %d/%d intactos %s%s",
        $preimagemOk ? count($pinnedRemote) : 0, count($pinnedRemote),
        $preimagemOk ? '✔' : '✘', PHP_EOL);
    foreach ($pinnedRemote as $f => $h) {
        printf("      · %-56s %s%s", $f, substr($h, 0, 16), PHP_EOL);
    }

    echo PHP_EOL . '  [OBSERVADO — locks, ensaio em chaves-sombra]' . PHP_EOL;
    printf("    lock_connection_id ............ %s (aquisição) → %s (liberação) %s%s",
        $ensaioAq['conn_id'], $ensaioLib['conn_id'],
        $ensaioAq['conn_id'] === $ensaioLib['conn_id'] ? '✔ MESMA SESSÃO' : '✘', PHP_EOL);
    foreach ($ensaioLib['evidencias'] as $e) {
        printf("    release_lock = %s · is_used_lock_after = %s   %s%s",
            $e['release_lock'] === null ? 'NULL' : (string) $e['release_lock'],
            $e['is_used_lock_after'] ?? 'NULL',
            $e['lock'], PHP_EOL);
    }
    printf("    chaves reais livres agora ..... %s%s", $reaisLivres ? 'SIM ✔' : 'NÃO ✘', PHP_EOL);

    echo PHP_EOL . '  [PREVISTO — só verificável durante o --apply]' . PHP_EOL;
    echo '    Estes três campos NÃO existem em dry-run: nenhum DDL foi executado.' . PHP_EOL;
    echo '    Cada um tem asserção que ABORTA o deploy se não se confirmar.' . PHP_EOL;
    printf("    migration_table_delta ......... previsto [%s]%s", AGENDA_MIGRATION, PHP_EOL);
    echo '      asserção: $novasVersoes === [AGENDA_MIGRATION]; qualquer outra' . PHP_EOL;
    echo '      versão → RECOVERY_REQUIRED, runtime NÃO promovido' . PHP_EOL;
    printf("    pending_after ................. previsto as %d alheias:%s", count(FOREIGN_MIGRATIONS), PHP_EOL);
    foreach (FOREIGN_MIGRATIONS as $m) {
        printf("      · %s%s", explode('_', $m)[0], PHP_EOL);
    }
    echo '      asserção: pending_after MEDIDO === as 4 alheias, além de' . PHP_EOL;
    echo '      $alheiasAplicadas === []. Uma alheia que suma da descoberta sem' . PHP_EOL;
    echo '      ter sido aplicada também aborta — ausência não é aprovação.' . PHP_EOL;
    echo '    stage1_persisted_after_ddl .... previsto SIM' . PHP_EOL;
    echo '      asserção: SHA-256 dos arquivos de stage1 relidos no servidor após' . PHP_EOL;
    echo '      o DDL. Schema novo sem o código correspondente aborta a promoção.' . PHP_EOL;
    echo '      garantia adicional: só PRE_DDL restaura stage1, e exige schema' . PHP_EOL;
    echo '      comprovadamente intacto (0 colunas, versão não registrada).' . PHP_EOL;

    $dryOk = $provasOk && $preimagemOk && $ensaioLib['ok'] && $reaisLivres;
    echo PHP_EOL . '  ' . ($dryOk ? '✅' : '⚠️ ') . ' DRY-RUN concluído — nada foi enviado nem aplicado.' . PHP_EOL;
    if (!$dryOk) { echo '     Há evidências falhando: resolva antes de autorizar.' . PHP_EOL; }
    exit($dryOk ? 0 : 1);
}

if (!$provasOk) {
    fwrite(STDERR, 'ABORTA: provas pré-autorização falhando.' . PHP_EOL);
    exit(2);
}

// ── APPLY ────────────────────────────────────────────────────────────────────

/**
 * BARREIRA DE ROLLBACK.
 *
 * Antes do primeiro DDL tudo é reversível: os arquivos voltam e o banco nunca
 * foi tocado. Depois do DDL o schema mudou de forma ADITIVA e IRREVERSÍVEL por
 * política (não rodamos down() em produção) — devolver o CLI e a migration
 * antigos deixaria o servidor com código que desconhece o schema vigente.
 * Por isso, a partir de DDL_DONE, o stage1 PERMANECE.
 */
$barreira = 'PRE_DDL';

echo PHP_EOL . '  ── 1. LOCK GLOBAL ──' . PHP_EOL;
$aq = $locksAdquirir($pdo, $lockNames, 90, $logger);
foreach ($aq['evidencias'] as $e) {
    printf("     %-46s GET_LOCK=%d owner_connection_id=%s %s%s",
        $e['lock'], $e['get_lock'],
        $e['owner_connection_id'] ?? 'NULL',
        $e['mesma_sessao'] ? '✔ desta sessão' : '✘', PHP_EOL);
}
if (!$aq['ok']) {
    fwrite(STDERR, 'ABORTA: lock indisponível. Nada foi tocado.' . PHP_EOL);
    exit(1);
}
$lockConnId = $aq['conn_id'];
echo '     CONNECTION_ID proprietário: ' . $lockConnId . PHP_EOL;

try {
    $stamp = date('Ymd_His');
    $bkp = REMOTE . '/storage/backups/agenda_f5f7_' . $stamp;

    echo PHP_EOL . '  ── 2. BACKUP REMOTO ──' . PHP_EOL;
    [$c, $o] = ssh("mkdir -p {$bkp} && cd " . REMOTE . ' && for f in ' . $lista . '; do '
        . "if [ -f \"\$f\" ]; then mkdir -p \"{$bkp}/\$(dirname \$f)\"; cp \"\$f\" \"{$bkp}/\$f\"; fi; done && echo OK");
    if ($c !== 0) { throw new RuntimeException('backup falhou: ' . $o); }
    echo "     backup em {$bkp}" . PHP_EOL;

    /**
     * Restaura um subconjunto de arquivos a partir do backup.
     *
     * Arquivo que EXISTIA volta ao conteúdo anterior; arquivo que era NOVO
     * (sem cópia no backup) é REMOVIDO — só assim o estado fica idêntico ao
     * anterior, e não "anterior + sobras".
     *
     * @param list<string> $files
     */
    $restaurar = static function (array $files, string $bkp, string $rotulo): void {
        echo PHP_EOL . "  ── RESTAURANDO: {$rotulo} ──" . PHP_EOL;
        $lista = implode(' ', array_map('escapeshellarg', $files));
        [$c, $o] = ssh('cd ' . REMOTE . ' && for f in ' . $lista . '; do '
            . "if [ -f \"{$bkp}/\$f\" ]; then cp -f \"{$bkp}/\$f\" \"\$f\" && echo \"revertido \$f\"; "
            . "else rm -f \"\$f\" && echo \"removido  \$f\"; fi; done");
        echo '     ' . str_replace("\n", "\n     ", trim($o)) . PHP_EOL;
        if ($c !== 0) {
            fwrite(STDERR, "  ⚠️  RESTAURAÇÃO INCOMPLETA ({$rotulo}). Backup preservado em {$bkp}" . PHP_EOL);
        }
    };

    // Promoção por etapa: upload temporário → checksum → mv.
    $promote = static function (array $files, string $rotulo) use ($root, $localHashes, $bkp, $assertPinned, $pinnedRemote): void {
        echo PHP_EOL . "  ── PROMOVENDO: {$rotulo} ──" . PHP_EOL;
        // Última janela antes de sobrescrever: o remoto ainda é o que analisamos?
        $assertPinned($pinnedRemote, $files);
        foreach ($files as $f) {
            [$c] = ssh('mkdir -p ' . REMOTE . '/' . dirname($f));
            if ($c !== 0) { throw new RuntimeException("mkdir remoto falhou: {$f}"); }
            [$c, $o] = scpUp($root . '/' . $f, REMOTE . '/' . $f . '.deploy_tmp');
            if ($c !== 0) { throw new RuntimeException("upload falhou: {$f} :: {$o}"); }
        }
        $tmp = implode(' ', array_map(static fn ($f) => escapeshellarg($f . '.deploy_tmp'), $files));
        [, $o] = ssh('cd ' . REMOTE . ' && sha256sum ' . $tmp);
        $bad = [];
        $got = [];
        foreach (explode("\n", $o) as $line) {
            if (preg_match('/^([0-9a-f]{64})\s+\.?\/?(.+)\.deploy_tmp$/', trim($line), $m)) { $got[$m[2]] = $m[1]; }
        }
        foreach ($files as $f) {
            if (($got[$f] ?? '') !== $localHashes[$f]) { $bad[] = $f; }
        }
        if ($bad !== []) {
            ssh('cd ' . REMOTE . ' && rm -f ' . $tmp);
            throw new RuntimeException('checksum divergente: ' . implode(', ', $bad) . ' — temporários removidos, nada promovido');
        }
        $mv = 'cd ' . REMOTE . ' && ' . implode(' && ', array_map(
            static fn ($f) => 'mv -f ' . escapeshellarg($f . '.deploy_tmp') . ' ' . escapeshellarg($f), $files));
        [$c, $o] = ssh($mv);
        if ($c !== 0) { throw new RuntimeException("promoção falhou ({$rotulo}) — restaure de {$bkp}"); }
        echo '     ' . count($files) . ' arquivo(s) promovido(s) ✔' . PHP_EOL;
    };

    $promote(MANIFEST['stage1_tooling'], 'ETAPA 1 — CLI + migrations da Agenda');

    // ── 4. MIGRATE-ONE — executa AQUI, contra o banco produtivo ──────────────
    // Roda local para que o binário que dispara o DDL seja exatamente o que os
    // testes exercitaram. O servidor já recebeu CLI e migration (acima) para
    // ficar consistente com o schema resultante, mas não os executa.
    echo PHP_EOL . '  ── 4. MIGRATE-ONE (LOCAL → banco produtivo) ──' . PHP_EOL;
    echo '     alvo: ' . AGENDA_MIGRATION . ' · DATABASE()=' . (string) ($dbIdent['db'] ?? '?')
        . ' · @@hostname=' . (string) ($dbIdent['host'] ?? '?') . PHP_EOL;

    // Reconfere na última janela: nenhuma pendente pode ter mudado.
    $pendAgora = [];
    foreach ($runner->status()['migrations'] as $m) {
        if (($m['status'] ?? '') === 'pending') { $pendAgora[] = (string) $m['version']; }
    }
    sort($pendAgora);
    if ($pendAgora !== $esperadas) {
        throw new RuntimeException('conjunto de pendentes mudou desde o pré-voo: ' . implode(', ', $pendAgora));
    }

    if ($resume) {
        // O DDL já aconteceu (2026-07-20 00:59:41 UTC). Reexecutar seria
        // recusado pelo guard ("já aplicada"); pular é a única ação correta.
        // As medições da seção 5 comprovam que o schema está como esperado.
        echo '     PULADO (resume): versão já aplicada em 2026-07-20 00:59:41 UTC.' . PHP_EOL;
        $migrateOk = true;
    } else {
        $ddlIniciado = true;   // a partir daqui o DDL PODE ter tocado o schema
        [$c, $o] = run([PHP_BINARY, $root . '/bin/schema_migrate.php', 'migrate-one',
            '--version=' . AGENDA_MIGRATION, '--allow-out-of-order']);
        echo '     ' . str_replace("\n", "\n     ", $o) . PHP_EOL;
        $migrateOk = $c === 0;
    }

    echo PHP_EOL . '  ── 5. VERIFICAÇÃO PÓS-DDL ──' . PHP_EOL;
    $snapAfter = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biomie_quote_followup_charge_audit'
            AND COLUMN_NAME IN ('manager_name_snapshot','seller_id_at_charge','seller_name_at_charge')"
    )->fetchColumn();
    $appliedAfter = array_map('strval', $pdo->query(
        "SELECT schema_version FROM biomie_schema_migrations WHERE status = 'applied'"
    )->fetchAll(PDO::FETCH_COLUMN));
    $novasVersoes = array_values(array_diff($appliedAfter, $appliedBefore));
    $auditAfter = (int) $pdo->query('SELECT COUNT(*) FROM biomie_quote_followup_charge_audit')->fetchColumn();
    $alheiasAplicadas = array_values(array_intersect(
        $appliedAfter,
        array_map(static fn ($m) => explode('_', $m)[0], FOREIGN_MIGRATIONS)
    ));

    printf("     colunas snapshot: %d/3 %s%s", $snapAfter, $snapAfter === 3 ? '✔' : '✘', PHP_EOL);
    // No apply o delta esperado é [alvo]; no resume o alvo JÁ estava em
    // appliedBefore, então o delta correto é vazio.
    $deltaEsperado = $resume ? [] : [AGENDA_MIGRATION];
    printf("     versões novas: %s (esperado %s) %s%s", json_encode($novasVersoes),
        json_encode($deltaEsperado), $novasVersoes === $deltaEsperado ? '✔' : '✘', PHP_EOL);
    printf("     auditoria: %d linha(s) %s%s", $auditAfter, $auditAfter === $auditRows ? '✔' : '✘', PHP_EOL);
    printf("     alheias aplicadas: %s %s%s", json_encode($alheiasAplicadas),
        $alheiasAplicadas === [] ? '✔' : '✘', PHP_EOL);

    // ── BARREIRA: classificar o que de fato aconteceu com o schema ───────────
    $versaoRegistrada = in_array(AGENDA_MIGRATION, $appliedAfter, true);
    printf("     versão registrada: %s%s", $versaoRegistrada ? 'sim ✔' : 'não', PHP_EOL);

    // pending_after MEDIDO: as 4 alheias devem seguir migráveis. Deduzir isso
    // de "nenhuma alheia aplicada" não bastaria — uma alheia poderia sumir da
    // descoberta (arquivo removido) sem jamais ter sido aplicada.
    $pendAfter = [];
    foreach ($runner->status()['migrations'] as $m) {
        if (($m['status'] ?? '') === 'pending') { $pendAfter[] = (string) $m['version']; }
    }
    sort($pendAfter);
    $alheiasEsperadas = array_map(static fn ($m) => explode('_', $m)[0], FOREIGN_MIGRATIONS);
    sort($alheiasEsperadas);
    $pendingAfterOk = $pendAfter === $alheiasEsperadas;
    printf("     pending_after: %s %s%s", json_encode($pendAfter), $pendingAfterOk ? '✔' : '✘', PHP_EOL);

    // stage1_persisted_after_ddl MEDIDO: o servidor precisa ficar com o CLI e a
    // migration correspondentes ao schema que passou a existir.
    $s1 = implode(' ', array_map('escapeshellarg', MANIFEST['stage1_tooling']));
    [, $s1out] = ssh('cd ' . REMOTE . ' && sha256sum ' . $s1 . ' 2>/dev/null');
    $s1Got = [];
    foreach (explode("\n", $s1out) as $line) {
        if (preg_match('/^([0-9a-f]{64})\s+\.?\/?(.+)$/', trim($line), $m)) { $s1Got[$m[2]] = $m[1]; }
    }
    $s1Faltando = [];
    foreach (MANIFEST['stage1_tooling'] as $f) {
        if (($s1Got[$f] ?? '') !== $localHashes[$f]) { $s1Faltando[] = $f; }
    }
    $stage1Persistido = $s1Faltando === [];
    printf("     stage1_persisted_after_ddl: %d/%d %s%s",
        count(MANIFEST['stage1_tooling']) - count($s1Faltando), count(MANIFEST['stage1_tooling']),
        $stage1Persistido ? '✔' : '✘ ' . implode(', ', $s1Faltando), PHP_EOL);

    if ($resume) {
        // GATE DO RESUME: o estado precisa ser EXATAMENTE o que o DDL de
        // 2026-07-20 deixou. Nenhuma classificação a fazer — ou está tudo lá,
        // ou algo mudou desde a inspeção e é preciso parar de novo.
        $resumeGate = [
            '3/3 colunas snapshot'            => (int) $snapAfter === 3,
            'versão registrada'               => $versaoRegistrada,
            'delta de versões vazio'          => $novasVersoes === [],
            'nenhuma alheia aplicada'         => $alheiasAplicadas === [],
            'pending_after = 4 alheias'       => $pendingAfterOk,
            'auditoria inalterada'            => $auditAfter === $auditRows,
            'stage1 persistido (CLI corrigido)' => $stage1Persistido,
        ];
        $resumeFalhas = array_keys(array_filter($resumeGate, static fn (bool $ok): bool => !$ok));
        foreach ($resumeGate as $nome => $ok) {
            printf("     gate resume: %-36s %s%s", $nome, $ok ? '✔' : '✘', PHP_EOL);
        }
        if ($resumeFalhas !== []) {
            // Nada foi classificado como reversível: o schema já estava
            // aplicado antes desta execução. Parar sem restaurar.
            $barreira = 'RECOVERY_REQUIRED';
            throw new RuntimeException(
                'gate do resume falhou (' . implode('; ', $resumeFalhas)
                . ') — estado divergente do deixado pelo DDL. Runtime NÃO promovido.'
            );
        }
        $barreira = 'DDL_DONE';
        echo '     BARREIRA → DDL_DONE (resume validado; schema íntegro)' . PHP_EOL;
    } else {
    // Mesma função pura coberta por tests/run_deploy_rollback_barrier.php.
    $barreira = agendaBarreiraDecidir(
        $migrateOk, (int) $snapAfter, $versaoRegistrada, $novasVersoes, $alheiasAplicadas
    );

    if ($barreira === 'DDL_DONE') {
        echo '     BARREIRA → DDL_DONE (stage1 permanece no servidor)' . PHP_EOL;

        // O schema está correto, mas o deploy só prossegue se as evidências
        // MEDIDAS confirmarem o previsto. Aqui o stage1 já não pode ser
        // revertido — a falha impede apenas a promoção do runtime.
        if (!$pendingAfterOk) {
            throw new RuntimeException(
                'pending_after diferente das 4 alheias: ' . json_encode($pendAfter)
                . ' — runtime NÃO promovido. Verifique se alguma migration de outro fluxo sumiu da descoberta.'
            );
        }
        if (!$stage1Persistido) {
            throw new RuntimeException(
                'stage1 NÃO persistiu no servidor após o DDL: ' . implode(', ', $s1Faltando)
                . ' — o schema mudou mas o código correspondente não está lá. Runtime NÃO promovido.'
            );
        }
    } elseif ($barreira === 'PRE_DDL') {
        // Falhou ANTES de tocar o schema: reversível por completo.
        echo '     BARREIRA → PRE_DDL (schema intacto; stage1 será revertido)' . PHP_EOL;
        $restaurar(MANIFEST['stage1_tooling'], $bkp, 'ETAPA 1 (schema intacto)');
        throw new RuntimeException('migrate-one falhou sem tocar o schema — estado anterior restaurado.');
    } else {
        // Meio-termo: colunas parciais, ou schema mudado sem versão registrada,
        // ou versão registrada sem as 3 colunas. Nunca adivinhar: parar.
        $marca = REMOTE . '/storage/RECOVERY_REQUIRED_agenda_' . $stamp . '.txt';
        // \\n literal: quem interpreta as quebras é o printf do shell remoto.
        $diag = sprintf(
            "RECOVERY_REQUIRED %s\\nmigrate_one_exit_ok=%s\\ncolunas_snapshot=%d/3\\nversao_registrada=%s\\n"
            . "versoes_novas=%s\\nalheias_aplicadas=%s\\nbackup=%s\\n"
            . "ACAO: inspecao humana. NAO rodar down(). CLI e migration PRESERVADOS no servidor.\\n",
            date('c'), $migrateOk ? 'sim' : 'nao', $snapAfter, $versaoRegistrada ? 'sim' : 'nao',
            json_encode($novasVersoes), json_encode($alheiasAplicadas), $bkp
        );
        ssh('printf ' . escapeshellarg($diag) . ' > ' . escapeshellarg($marca));
        echo PHP_EOL . '  🛑 BARREIRA → RECOVERY_REQUIRED' . PHP_EOL;
        echo '     DDL parcial ou inconsistente. NADA foi restaurado — o CLI e a' . PHP_EOL;
        echo '     migration PERMANECEM no servidor para permitir a inspeção.' . PHP_EOL;
        echo '     Runtime NÃO promovido. Marca: ' . $marca . PHP_EOL;
        throw new RuntimeException('RECOVERY_REQUIRED — intervenção humana necessária. Não rodar down().');
    }
    }   // fim do if(!$resume): classificação da barreira

    // Daqui em diante o schema está íntegro; qualquer falha é do RUNTIME e a
    // reversão é parcial por definição (stage1 acompanha o schema e permanece).
    $barreira = 'STAGE2_FAIL';
    $promote(array_merge(
        MANIFEST['stage2_domain'], MANIFEST['stage2_shared'],
        MANIFEST['stage2_api'], MANIFEST['stage2_ui'], MANIFEST['stage2_ops']
    ), 'ETAPA 2 — runtime Fases 5–7');

    echo PHP_EOL . '  ── 7. CHECKS REMOTOS ──' . PHP_EOL;
    $phpFiles = implode(' ', array_map('escapeshellarg',
        array_filter(array_keys($localHashes), static fn ($f) => str_ends_with($f, '.php'))));
    [$c, $o] = ssh('cd ' . REMOTE . "
for f in {$phpFiles}; do php -l \"\$f\" >/dev/null 2>&1 || echo \"LINT_FAIL \$f\"; done
php -r 'require \"vendor/autoload.php\";
  \$c=[\"BIomie\\\\QuoteAgenda\\\\QuoteAgendaReadService\",\"BIomie\\\\QuoteAgenda\\\\QuoteFollowupChargeService\",\"BIomie\\\\QuoteAgenda\\\\FollowupDatePolicy\",\"BIomie\\\\QuoteAgenda\\\\QuoteLiveSnapshotScanner\"];
  foreach(\$c as \$x){ echo class_exists(\$x)?\"\":\"CLASSE_FALTA \$x\\n\"; } echo \"AUTOLOAD_OK\\n\";'
php -r 'echo function_exists(\"opcache_reset\") ? (opcache_reset()?\"OPCACHE_RESET\":\"OPCACHE_NOOP\") : \"OPCACHE_NA\"; echo PHP_EOL;'
echo CHECKS_END");
    echo '     ' . str_replace("\n", "\n     ", $o) . PHP_EOL;
    // CHECKS_END prova que o script remoto rodou ATÉ O FIM. Sem isso, um
    // erro de shell (como o CRLF de 2026-07-20) pularia os checks em silêncio
    // e o deploy se declararia OK sem nunca ter verificado nada.
    if (!str_contains($o, 'CHECKS_END')) {
        throw new RuntimeException('checks remotos NÃO executaram até o fim — saída: ' . substr($o, 0, 300));
    }
    if (str_contains($o, 'LINT_FAIL') || str_contains($o, 'CLASSE_FALTA') || !str_contains($o, 'AUTOLOAD_OK')) {
        throw new RuntimeException('checks remotos falharam');
    }

    if ($resume) {
        // Incidente encerrado: a marca vira registro RESOLVED dentro do backup
        // desta retomada — some do caminho de alerta, permanece como história.
        echo PHP_EOL . '  ── RESOLVENDO MARCA RECOVERY_REQUIRED ──' . PHP_EOL;
        [, $mv] = ssh('cd ' . REMOTE . ' && for m in storage/RECOVERY_REQUIRED_agenda_*.txt; do '
            . '[ -f "$m" ] || continue; '
            . 'printf "\nRESOLVIDO %s: guard do migrate-one tinha bug de tipagem (int vs string);'
            . ' o DDL estava CORRETO. CLI corrigido + runtime promovidos por --resume-after-ddl.\n" '
            . '"$(date -u +%Y-%m-%dT%H:%M:%SZ)" >> "$m"; '
            . "mv \"\$m\" \"{$bkp}/RESOLVED_\$(basename \$m)\" && echo \"resolvida: \$m\"; done");
        echo '     ' . str_replace("\n", "\n     ", trim($mv) !== '' ? trim($mv) : '(nenhuma marca encontrada)') . PHP_EOL;
    }

    echo PHP_EOL . '  ✅ DEPLOY OK · backup: ' . $bkp . PHP_EOL;
    echo '     Flags seguem OFF: nenhuma tela, scanner, cron ou notificação foi ativado.' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, PHP_EOL . 'ERRO: ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, 'BARREIRA no momento da falha: ' . $barreira . PHP_EOL);
    $failed = true;

    // A barreira decide o que ainda é seguro desfazer.
    if ($resume && isset($bkp, $restaurar) && $barreira !== 'RECOVERY_REQUIRED') {
        // No resume o backup foi tirado DEPOIS do DDL: restaurá-lo por inteiro
        // devolve exatamente o estado pré-retomada, já compatível com o schema.
        fwrite(STDERR, 'RESUME: restaurando o estado pré-retomada (compatível com o schema aplicado).' . PHP_EOL);
        $restaurar(manifestFiles(), $bkp, 'TUDO (estado pré-retomada, pós-DDL)');
    } elseif ($barreira === 'RECOVERY_REQUIRED') {
        fwrite(STDERR, 'Nada restaurado por decisão: DDL parcial exige inspeção humana. NÃO rodar down().' . PHP_EOL);
    } elseif (in_array($barreira, ['DDL_DONE', 'STAGE2_FAIL'], true) && isset($bkp, $restaurar)) {
        // Schema já mudou: CLI e migration TÊM de ficar. Só o runtime volta.
        fwrite(STDERR, 'Migration aplicada e íntegra; revertendo SOMENTE o runtime.' . PHP_EOL);
        $restaurar(array_merge(
            MANIFEST['stage2_domain'], MANIFEST['stage2_shared'],
            MANIFEST['stage2_api'], MANIFEST['stage2_ui'], MANIFEST['stage2_ops']
        ), $bkp, 'ETAPA 2 — runtime (stage1 PERMANECE)');
        fwrite(STDERR, 'A migration é ADITIVA e permanece; o código tolera ambos os estados.' . PHP_EOL);
    } elseif (isset($bkp, $restaurar) && !isset($ddlIniciado)) {
        // Falhou antes mesmo de tentar o DDL: reversão total do que foi tocado.
        $restaurar(manifestFiles(), $bkp, 'TUDO (schema nunca tocado)');
    }
} finally {
    $locksLiberar($pdo, $lockNames, $lockConnId);
}

exit(isset($failed) ? 1 : 0);
