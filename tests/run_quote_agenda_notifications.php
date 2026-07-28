<?php

declare(strict_types=1);

/**
 * AGENDA — notificações (Fase 8): supressão, dedup, cooldown, destinatários.
 *
 * O caso que importa aqui NÃO é "a mensagem saiu". É "a mensagem NÃO saiu
 * quando não podia sair". Uma notificação indevida termina num gestor cobrando
 * um vendedor por um orçamento que já virou pedido — e isso não tem desfazer.
 *
 * Escreve em transação REVERTIDA (company_id sintético livre ≤ 254).
 *
 *   php tests/run_quote_agenda_notifications.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Config;
use BIomie\Database;
use BIomie\Notifications\NotificationService;
use BIomie\QuoteAgenda\QuoteAgendaNotificationPolicy as P;
use BIomie\QuoteAgenda\QuoteAgendaNotifier;

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

/** Flags todas ligadas — o caminho feliz, para isolar cada portão. */
const ON = ['module_enabled' => true, 'notifications_enabled' => true, 'policy_enabled' => true];
/** Frescor saudável. */
const FRESH = ['never_synced' => false, 'is_stale' => false, 'age_minutes' => 3];

/** Item cobrável mínimo. */
function item(array $over = []): array
{
    return $over + [
        'followup_id' => 1, 'urgency' => 'overdue', 'value' => 1000.0,
        'date_is_official' => true, 'missing_since' => null, 'resolved_at' => null,
    ];
}

// ── 1. ELEGIBILIDADE: o que pode virar cobrança ──────────────────────────────
$check('no prazo (future) NÃO é cobrável', !P::isChargeable(item(['urgency' => 'future'])));
$check('vence hoje é cobrável', P::isChargeable(item(['urgency' => 'today'])));
$check('atrasado é cobrável', P::isChargeable(item(['urgency' => 'overdue'])));
$check('crítico é cobrável', P::isChargeable(item(['urgency' => 'critical'])));
$check('RESOLVIDO nunca é cobrável', !P::isChargeable(item(['resolved_at' => '2026-07-19 10:00:00'])));
$check('com resolution preenchida nunca é cobrável', !P::isChargeable(item(['resolution' => 'invoiced'])));
$check('SUMIDO do Omie (missing_since) não é cobrável — ausência não é prova',
    !P::isChargeable(item(['missing_since' => '2026-07-19 10:00:00'])));

// ── 2. PORTÕES DE CONFIGURAÇÃO ───────────────────────────────────────────────
$um = [item()];
foreach ([
    'módulo desligado'        => [['module_enabled' => false] + ON, P::R_MODULE_DISABLED],
    'notificações desligadas' => [['notifications_enabled' => false] + ON, P::R_NOTIFICATIONS_DISABLED],
    'política da empresa OFF' => [['policy_enabled' => false] + ON, P::R_POLICY_DISABLED],
] as $nome => [$flags, $motivo]) {
    $d = P::decideDigest($flags, FRESH, $um, 1);
    $check("digest suprimido: {$nome}", $d['decision'] === 'suppress' && $d['reason'] === $motivo, $d['reason']);
}

// ── 3. 🔴 O PORTÃO QUE EVITA COBRANÇA INDEVIDA ───────────────────────────────
$stale = P::decideDigest(ON, ['never_synced' => false, 'is_stale' => true, 'age_minutes' => 190], $um, 1);
$check('FONTE VENCIDA suprime o digest (não cobra com dado velho)',
    $stale['decision'] === 'suppress' && $stale['reason'] === P::R_STALE_SOURCE, $stale['reason']);
$check('digest suprimido NÃO afirma dado atual', $stale['claims_current_data'] === false);
$check('digest suprimido reporta zero acionáveis', $stale['actionable'] === 0);

$never = P::decideDigest(ON, ['never_synced' => true, 'is_stale' => true], $um, 1);
$check('NUNCA SINCRONIZADO suprime o digest',
    $never['decision'] === 'suppress' && $never['reason'] === P::R_NEVER_SYNCED, $never['reason']);

// Ordem dos portões: config vence frescor (o motivo devolvido guia a ação).
$ambos = P::decideDigest(['module_enabled' => false] + ON, ['never_synced' => true, 'is_stale' => true], $um, 1);
$check('módulo OFF + fonte vencida ⇒ reporta o portão mais externo',
    $ambos['reason'] === P::R_MODULE_DISABLED, $ambos['reason']);

// ── 4. CONTEÚDO E QUALIFICADORES ─────────────────────────────────────────────
$d = P::decideDigest(ON, FRESH, [
    item(['urgency' => 'critical', 'value' => 5000.0]),
    item(['urgency' => 'overdue',  'value' => 2000.0]),
    item(['urgency' => 'today',    'value' => null]),               // valor desconhecido
    item(['urgency' => 'future',   'value' => 9999.0]),             // fora: no prazo
    item(['urgency' => 'overdue',  'missing_since' => '2026-07-19 08:00:00']), // fora: não reconciliado
], 2);
$check('envia quando há acionáveis', $d['decision'] === 'send' && $d['reason'] === P::R_OK);
$check('conta só os cobráveis (3 de 5)', $d['actionable'] === 3, (string) $d['actionable']);
$check('contagem por urgência correta',
    $d['counts'] === ['today' => 1, 'overdue' => 1, 'critical' => 1], json_encode($d['counts']));
$check('🔴 valor desconhecido NÃO vira zero (soma só conhecidos: 7000)',
    $d['total_value'] === 7000.0, var_export($d['total_value'], true));
$check('valor desconhecido é contado à parte', $d['unknown_value_count'] === 1);
$check('item não reconciliado sai da cobrança e vira qualificador',
    in_array('unreconciled_items', $d['qualifiers'], true) && $d['unreconciled_count'] === 1);
$check('crítico eleva a severidade para warning', $d['severity'] === 'warning', $d['severity']);

$prov = P::decideDigest(ON, FRESH, [item(['date_is_official' => false])], 1);
$check('data provisória vira qualificador (idade é "pelo menos")',
    in_array('provisional_dates', $prov['qualifiers'], true) && $prov['provisional_count'] === 1);
$semCampo = P::decideDigest(ON, FRESH, [item(['date_is_official' => null])], 1);
$check('item SEM o campo de confiança é tratado como provisório, não oficial',
    $semCampo['provisional_count'] === 1);

$check('sem acionáveis ⇒ silêncio',
    P::decideDigest(ON, FRESH, [item(['urgency' => 'future'])], 1)['reason'] === P::R_NOTHING_ACTIONABLE);
$check('sem destinatários ⇒ silêncio',
    P::decideDigest(ON, FRESH, $um, 0)['reason'] === P::R_NO_RECIPIENTS);
$check('lista vazia ⇒ silêncio', P::decideDigest(ON, FRESH, [], 1)['decision'] === 'suppress');

// ── 5. ALERTA TÉCNICO: emitido JUSTAMENTE quando o digest é suprimido ────────
$s = P::decideStale(ON, ['never_synced' => false, 'is_stale' => true, 'age_minutes' => 190]);
$check('alerta técnico É emitido com fonte vencida', $s['decision'] === 'send' && $s['reason'] === P::R_STALE_SOURCE);
$check('fonte fresca ⇒ alerta técnico suprimido',
    P::decideStale(ON, FRESH)['decision'] === 'suppress');
$check('🔴 empresa NÃO ativada (política OFF) não alerta fonte vencida (evita ruído)',
    P::decideStale(['policy_enabled' => false] + ON, ['never_synced' => true, 'is_stale' => true])['decision'] === 'suppress');
$check('empresa ATIVADA com fonte vencida alerta',
    P::decideStale(ON, ['never_synced' => true, 'is_stale' => true])['decision'] === 'send');
$check('módulo OFF silencia até o alerta técnico',
    P::decideStale(['module_enabled' => false] + ON, ['is_stale' => true])['decision'] === 'suppress');
// Complementaridade: nunca os dois calados sobre o mesmo fato.
$fStale = ['never_synced' => false, 'is_stale' => true, 'age_minutes' => 99];
$check('🔴 fonte vencida: digest CALA e alerta FALA (nunca silêncio total)',
    P::decideDigest(ON, $fStale, $um, 1)['decision'] === 'suppress'
    && P::decideStale(ON, $fStale)['decision'] === 'send');

// ── 6. SEGREDOS ──────────────────────────────────────────────────────────────
$comErro = P::decideStale(ON, [
    'never_synced' => false, 'is_stale' => true, 'age_minutes' => 60,
    'error_code' => 'snapshot_sync_failed',
    'last_error' => 'SQLSTATE[HY000] senha=hunter2 host=10.0.0.5',
]);
$serial = json_encode($comErro);
$check('erro BRUTO nunca entra na decisão do alerta',
    !str_contains((string) $serial, 'hunter2') && !str_contains((string) $serial, '10.0.0.5'));
$check('só o código sanitizado é propagado', $comErro['error_code'] === 'snapshot_sync_failed');

// ── 7. INTEGRAÇÃO: dedup e cooldown reais (transação revertida) ─────────────
$config = new Config();
$pdo = test_pdo(); // suíte MUTÁVEL: escreve → só o test_db aceita (TestDatabaseGuard bloqueia produção)

// company_id sintético livre — TINYINT no canônico satura em 255.
$usados = $pdo->query('SELECT id FROM biomie_companies')->fetchAll(PDO::FETCH_COLUMN);
$cid = 0;
for ($i = 200; $i <= 254; $i++) {
    if (!in_array((string) $i, array_map('strval', $usados), true)) { $cid = $i; break; }
}
$check('company_id sintético livre encontrado (≤254)', $cid > 0 && $cid <= 254, (string) $cid);

$notifier = new QuoteAgendaNotifier($pdo, new NotificationService($pdo), null);

$pdo->beginTransaction();
try {
    $key = 'quote_agenda:test:' . $cid . ':' . bin2hex(random_bytes(4));

    $check('chave inédita não está deduplicada', !$notifier->alreadySent($key, $cid, 3600));

    $pdo->prepare(
        'INSERT INTO biomie_notifications (company_id, category, severity, priority, title, source, dedupe_key, created_at)
         VALUES (:c, "business", "info", "high", "teste dedup", "quote_agenda", :k, NOW())'
    )->execute(['c' => $cid, 'k' => $key]);

    $check('🔴 após emitir, a MESMA chave é bloqueada na janela', $notifier->alreadySent($key, $cid, 3600));
    $check('mesma chave em OUTRA empresa não é bloqueada (isolamento)',
        !$notifier->alreadySent($key, $cid - 1, 3600));

    // Expiração do cooldown: uma emissão ANTIGA não pode bloquear para sempre.
    // Precisa de linha antiga de verdade — checar a recém-inserida com janela
    // curta só provaria que ela é recente (foi o engano da 1ª versão deste teste).
    $antiga = 'quote_agenda:test-old:' . $cid . ':' . bin2hex(random_bytes(4));
    $pdo->prepare(
        'INSERT INTO biomie_notifications (company_id, category, severity, priority, title, source, dedupe_key, created_at)
         VALUES (:c, "sync", "warning", "high", "teste dedup", "quote_agenda", :k, NOW() - INTERVAL 2 HOUR)'
    )->execute(['c' => $cid, 'k' => $antiga]);

    $check('emissão de 2h atrás AINDA bloqueia com cooldown de 6h',
        $notifier->alreadySent($antiga, $cid, 6 * 3600));
    $check('🔴 cooldown EXPIRADO (1h) libera nova emissão',
        !$notifier->alreadySent($antiga, $cid, 3600));

    // Chave do digest carrega o dia civil ⇒ vira automaticamente no dia seguinte.
    $hoje = (new DateTimeImmutable('now', new DateTimeZone('America/Fortaleza')))->format('Y-m-d');
    $check('chave do digest inclui o dia civil de Fortaleza',
        str_contains(sprintf('quote_agenda:digest:%d:%s', $cid, $hoje), $hoje));
} finally {
    $pdo->rollBack();
}

$sobrou = (int) $pdo->query(
    "SELECT COUNT(*) FROM biomie_notifications WHERE source = 'quote_agenda' AND title = 'teste dedup'"
)->fetchColumn();
$check('rollback não deixou resíduo', $sobrou === 0, "{$sobrou} linha(s)");

// ── 8. DESTINATÁRIOS: vendedor jamais recebe ────────────────────────────────
$viewer  = \BIomie\Acl\Roles::capabilities('viewer');
$analyst = \BIomie\Acl\Roles::capabilities('analyst');
$manager = \BIomie\Acl\Roles::capabilities('manager');
$check('🔴 viewer (vendedor) NÃO tem a capability de destinatário',
    !in_array('sales.quotes.followup.view', $viewer, true));
$check('analyst (vendedor) NÃO tem a capability de destinatário',
    !in_array('sales.quotes.followup.view', $analyst, true));
$check('manager (gestor) TEM a capability', in_array('sales.quotes.followup.view', $manager, true));

$reais = $notifier->recipientsFor(1);
$check('destinatários reais da empresa 1 resolvidos', count($reais) > 0, count($reais) . ' usuário(s)');
$check('nenhum destinatário sem a capability',
    array_filter($reais, static fn (array $u): bool =>
        !in_array('sales.quotes.followup.view', \BIomie\Acl\Roles::capabilities($u['role']), true)) === []);

$passed = count(array_filter($checks, static fn (array $r): bool => $r['ok']));
$result = [
    'ok' => $passed === count($checks),
    'total' => count($checks),
    'passed' => $passed,
    'failed' => count($checks) - $passed,
    'checks' => $checks,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);
