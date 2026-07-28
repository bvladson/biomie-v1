<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — prontidão e ativação gradual (Fase 9).
 *
 * SEGURO POR PADRÃO: sem --apply nada é ligado. Avalia, recomenda e explica.
 *
 * ─── POR QUE GRADUAL ────────────────────────────────────────────────────────
 * São TRÊS interruptores independentes de propósito:
 *   1. quote_agenda_enabled ............ a tela existe (leitura)
 *   2. quote_agenda_scanner_enabled .... o snapshot vivo se atualiza sozinho
 *   3. quote_agenda_notifications_enabled  o gestor é avisado (empurra)
 * Ligar os três juntos destrói a capacidade de isolar causa: se algo sair
 * errado, não se sabe se foi leitura, sincronização ou mensagem. E o terceiro
 * é o único que alcança pessoas — é o último, sempre.
 *
 * A política POR EMPRESA (biomie_quote_followup_policy.enabled) é o quarto
 * controle, e o único reversível por este script: as flags 1–3 são de ambiente
 * e vivem no .env do servidor.
 *
 *   php tools/dev/quote_agenda_activation.php                    # prontidão
 *   php tools/dev/quote_agenda_activation.php --enable=SLUG      # dry-run
 *   php tools/dev/quote_agenda_activation.php --enable=SLUG --apply
 *   php tools/dev/quote_agenda_activation.php --disable=SLUG --apply   # ROLLBACK
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Acl\Roles;
use BIomie\Config;
use BIomie\Database;
use BIomie\QuoteAgenda\QuoteAgendaReadService;

$opts    = getopt('', ['enable:', 'disable:', 'apply', 'json']);
$apply   = isset($opts['apply']);
$enable  = isset($opts['enable'])  ? strtoupper(trim((string) $opts['enable']))  : null;
$disable = isset($opts['disable']) ? strtoupper(trim((string) $opts['disable'])) : null;

if ($enable !== null && $disable !== null) {
    fwrite(STDERR, "Escolha --enable OU --disable, nunca os dois.\n");
    exit(2);
}

$config = new Config();
$conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo  = Database::connect($config, $conn);

$flags = [
    'quote_agenda_enabled'               => (bool) $config->get('features.quote_agenda_enabled', false),
    'quote_agenda_scanner_enabled'       => (bool) $config->get('features.quote_agenda_scanner_enabled', false),
    'quote_agenda_notifications_enabled' => (bool) $config->get('features.quote_agenda_notifications_enabled', false),
];

$companies = $pdo->query('SELECT id, slug FROM biomie_companies WHERE ativo = 1 ORDER BY id')
    ->fetchAll(PDO::FETCH_ASSOC);

/**
 * Prontidão de UMA empresa. Nenhum critério aqui é cosmético: cada um já
 * causou (ou quase causou) um problema real neste projeto.
 *
 * @return array<string,mixed>
 */
function prontidao(PDO $pdo, int $cid, string $slug): array
{
    $read = new QuoteAgendaReadService($pdo, $cid);
    $fresh = $read->freshness();
    $items = $read->openItems();

    $policy = $pdo->prepare('SELECT enabled, stale_after_minutes, cadence_days_json FROM biomie_quote_followup_policy WHERE company_id = :c');
    $policy->execute(['c' => $cid]);
    $pol = $policy->fetch(PDO::FETCH_ASSOC) ?: [];

    // Destinatários: sem gestor com a capability, ligar a empresa é ligar para
    // ninguém — a tela existiria sem plateia.
    $rec = $pdo->prepare(
        'SELECT u.role, uc.role_override FROM biomie_users u
           JOIN biomie_user_companies uc ON uc.user_id = u.id
          WHERE u.is_active = 1 AND uc.company_id = :c'
    );
    $rec->execute(['c' => $cid]);
    $recipients = 0;
    foreach ($rec->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $role = trim((string) ($r['role_override'] ?? '')) !== '' ? (string) $r['role_override'] : (string) $r['role'];
        if (in_array('sales.quotes.followup.view', Roles::capabilities($role), true)) { $recipients++; }
    }

    $provisorias = 0;
    $semValor = 0;
    $missing = 0;
    $cobraveis = 0;
    foreach ($items as $it) {
        if (($it['date_is_official'] ?? false) !== true) { $provisorias++; }
        if (($it['value'] ?? null) === null)             { $semValor++; }
        if (($it['missing_since'] ?? null) !== null)     { $missing++; }
        if (in_array((string) ($it['urgency'] ?? ''), ['today', 'overdue', 'critical'], true)
            && ($it['missing_since'] ?? null) === null)  { $cobraveis++; }
    }

    $criterios = [
        'política semeada'            => $pol !== [],
        'tem acompanhamentos'         => count($items) > 0,
        'tem destinatário (gestor)'   => $recipients > 0,
        'fonte já sincronizou'        => ($fresh['never_synced'] ?? true) === false,
        'fonte fresca agora'          => ($fresh['is_stale'] ?? true) === false,
        'sem datas provisórias'       => $provisorias === 0,
        'sem itens não reconciliados' => $missing === 0,
    ];

    return [
        'company_id' => $cid,
        'slug' => $slug,
        'policy_enabled' => (bool) ($pol['enabled'] ?? false),
        'stale_after_minutes' => (int) ($pol['stale_after_minutes'] ?? 45),
        'followups' => count($items),
        'cobraveis' => $cobraveis,
        'recipients' => $recipients,
        'provisorias' => $provisorias,
        'sem_valor' => $semValor,
        'missing' => $missing,
        'freshness' => $fresh,
        'criterios' => $criterios,
        // Bloqueantes = impedem ativar. Provisórias/valor ausente NÃO bloqueiam:
        // são qualificadores que a própria mensagem declara.
        'bloqueios' => array_keys(array_filter([
            'política semeada'          => !$criterios['política semeada'],
            'tem acompanhamentos'       => !$criterios['tem acompanhamentos'],
            'tem destinatário (gestor)' => !$criterios['tem destinatário (gestor)'],
            'fonte já sincronizou'      => !$criterios['fonte já sincronizou'],
        ])),
    ];
}

$estado = [];
foreach ($companies as $c) {
    $estado[strtoupper((string) $c['slug'])] = prontidao($pdo, (int) $c['id'], (string) $c['slug']);
}

// ── MUTAÇÃO: só a política por empresa; reversível e restrita ────────────────
$mutacao = null;
$alvo = $enable ?? $disable;
if ($alvo !== null) {
    if (!isset($estado[$alvo])) {
        fwrite(STDERR, "Empresa {$alvo} não encontrada ou inativa.\n");
        exit(2);
    }
    $e = $estado[$alvo];
    $ligar = $enable !== null;

    if ($ligar && $e['bloqueios'] !== []) {
        fwrite(STDERR, 'ABORTA: ' . $alvo . ' tem bloqueios: ' . implode('; ', $e['bloqueios']) . PHP_EOL);
        exit(1);
    }
    // Desligar NUNCA é bloqueado: rollback tem de funcionar sempre, inclusive
    // (e principalmente) quando o estado está ruim.

    $mutacao = [
        'empresa' => $alvo,
        'de' => $e['policy_enabled'] ? 'enabled' : 'disabled',
        'para' => $ligar ? 'enabled' : 'disabled',
        'aplicado' => false,
    ];

    if ($apply) {
        $st = $pdo->prepare(
            'UPDATE biomie_quote_followup_policy SET enabled = :v, updated_at = NOW() WHERE company_id = :c'
        );
        $st->execute(['v' => $ligar ? 1 : 0, 'c' => $e['company_id']]);
        $conf = $pdo->prepare('SELECT enabled FROM biomie_quote_followup_policy WHERE company_id = :c');
        $conf->execute(['c' => $e['company_id']]);
        $mutacao['aplicado'] = ((int) $conf->fetchColumn() === ($ligar ? 1 : 0));
        $estado[$alvo]['policy_enabled'] = $ligar;
    }
}

if (isset($opts['json'])) {
    echo json_encode(['flags' => $flags, 'empresas' => $estado, 'mutacao' => $mutacao],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
}

// ── RELATÓRIO ───────────────────────────────────────────────────────────────
echo PHP_EOL, str_repeat('═', 78), PHP_EOL;
echo '  AGENDA — PRONTIDÃO E ATIVAÇÃO GRADUAL · ', $apply ? 'APPLY' : 'DRY-RUN', PHP_EOL;
echo str_repeat('═', 78), PHP_EOL;

echo PHP_EOL, '  ── INTERRUPTORES GLOBAIS (.env do servidor) ──', PHP_EOL;
foreach ($flags as $k => $v) {
    printf("    %-38s %s%s", $k, $v ? 'ON' : 'OFF', PHP_EOL);
}

echo PHP_EOL, '  ── PRONTIDÃO POR EMPRESA ──', PHP_EOL;
foreach ($estado as $slug => $e) {
    $apto = $e['bloqueios'] === [];
    printf("%s    %s (id=%d) · politica=%s · %s%s", PHP_EOL, $slug, $e['company_id'],
        $e['policy_enabled'] ? 'ON' : 'OFF', $apto ? 'APTO' : 'BLOQUEADO', PHP_EOL);
    printf("      acompanhamentos=%d · cobraveis=%d · gestores=%d%s",
        $e['followups'], $e['cobraveis'], $e['recipients'], PHP_EOL);
    printf("      qualificadores: provisorias=%d · sem valor=%d · nao reconciliados=%d%s",
        $e['provisorias'], $e['sem_valor'], $e['missing'], PHP_EOL);
    $f = $e['freshness'];
    printf("      frescor: %s%s",
        ($f['never_synced'] ?? true) ? 'NUNCA SINCRONIZOU'
            : sprintf('%s (%d min, limite %d)', ($f['is_stale'] ?? true) ? 'VENCIDO' : 'fresco',
                (int) ($f['age_minutes'] ?? 0), $e['stale_after_minutes']), PHP_EOL);
    foreach ($e['criterios'] as $nome => $ok) {
        printf("        %s %s%s", $ok ? '✅' : '⬜', $nome, PHP_EOL);
    }
    if (!$apto) {
        printf("      BLOQUEIOS: %s%s", implode('; ', $e['bloqueios']), PHP_EOL);
    }
}

// ── RECOMENDAÇÃO DE PILOTO ──────────────────────────────────────────────────
// Menor massa entre as aptas: um erro de julgamento afeta menos gente, e o
// volume ainda é suficiente para observar comportamento real.
$aptas = array_filter($estado, static fn (array $e): bool => $e['bloqueios'] === [] && $e['followups'] > 0);
uasort($aptas, static fn (array $a, array $b): int => $a['followups'] <=> $b['followups']);

echo PHP_EOL, '  ── PILOTO RECOMENDADO ──', PHP_EOL;
if ($aptas === []) {
    echo '    Nenhuma empresa apta. Resolva os bloqueios antes (o mais comum:', PHP_EOL;
    echo '    a fonte nunca sincronizou — rode o scanner manualmente uma vez).', PHP_EOL;
} else {
    $pilotoSlug = (string) array_key_first($aptas);
    $p = $aptas[$pilotoSlug];
    printf("    %s — %d acompanhamentos, %d gestor(es).%s", $pilotoSlug, $p['followups'], $p['recipients'], PHP_EOL);
    echo '    Menor massa entre as aptas: erro de julgamento afeta menos gente,', PHP_EOL;
    echo '    e o volume ainda permite observar comportamento real.', PHP_EOL;
    if (isset($estado['MAG_FILIAL']) && $pilotoSlug !== 'MAG_FILIAL') {
        printf("    (MAG_FILIAL tem %d — deixe por último, é a maior exposição.)%s",
            $estado['MAG_FILIAL']['followups'], PHP_EOL);
    }
}

echo PHP_EOL, '  ── SEQUÊNCIA DE ATIVAÇÃO (cada passo exige aval) ──', PHP_EOL;
foreach ([
    '1. Scanner MANUAL, uma vez, na empresa piloto — antes de qualquer cron:',
    '     php bin/quote_agenda_scan.php --company=SLUG --apply',
    '     Observar: live_total, eligible_total, followups criados/atualizados.',
    '2. Ligar SÓ a tela (leitura), com a política da piloto habilitada:',
    '     .env: BIOMIE_QUOTE_AGENDA_ENABLED=1',
    '     php tools/dev/quote_agenda_activation.php --enable=SLUG --apply',
    '     Observar 1 dia: o gestor confere se os números batem com o Omie.',
    '3. Ligar o scanner automático (cron no PAINEL Hostinger, NÃO crontab):',
    '     .env: BIOMIE_QUOTE_AGENDA_SCANNER_ENABLED=1  ·  cadência */15',
    '     Observar: frescor deixa de vencer; nenhuma ausência falsa.',
    '4. Só então ligar notificações (o único passo que alcança PESSOAS):',
    '     .env: BIOMIE_QUOTE_AGENDA_NOTIFICATIONS_ENABLED=1',
    '     php bin/quote_agenda_notify.php --company=SLUG        (dry-run)',
    '     php bin/quote_agenda_notify.php --company=SLUG --apply',
    '5. Repetir 2–4 para as demais empresas, uma de cada vez.',
] as $l) {
    echo '    ', $l, PHP_EOL;
}

echo PHP_EOL, '  ── ROLLBACK ──', PHP_EOL;
foreach ([
    'Por empresa (imediato, sem deploy):',
    '  php tools/dev/quote_agenda_activation.php --disable=SLUG --apply',
    'Global (desliga para todas):  .env BIOMIE_QUOTE_AGENDA_*=0',
    'Nenhum rollback exige migration: o schema é ADITIVO e o código tolera',
    'ambos os estados. NUNCA rodar down() em produção.',
    'Cobranças já registradas PERMANECEM — auditoria não se apaga por rollback.',
] as $l) {
    echo '    ', $l, PHP_EOL;
}

echo PHP_EOL, '  ── OBSERVABILIDADE (o que olhar depois de cada passo) ──', PHP_EOL;
foreach ([
    'frescor por empresa ..... biomie_quote_live_snapshot_sync.last_successful_at',
    'ausências falsas ........ followups com missing_since != NULL crescendo',
    'cobranças ............... biomie_quote_followup_charge_audit (response_status)',
    'notificações emitidas ... biomie_notifications WHERE source = "quote_agenda"',
    'saúde geral ............. php tools/dev/biomie_release_guard.php --read-only',
] as $l) {
    echo '    ', $l, PHP_EOL;
}

if ($mutacao !== null) {
    echo PHP_EOL, '  ── MUTAÇÃO ──', PHP_EOL;
    printf("    %s: %s → %s · %s%s", $mutacao['empresa'], $mutacao['de'], $mutacao['para'],
        $apply ? ($mutacao['aplicado'] ? 'APLICADO ✔' : 'FALHOU ✘') : 'DRY-RUN (nada gravado)', PHP_EOL);
}

echo PHP_EOL, '  ', $apply ? '⚠️  APPLY executado.' : '✅ DRY-RUN — nada foi ligado nem desligado.', PHP_EOL, PHP_EOL;
exit(0);
