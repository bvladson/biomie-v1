<?php

declare(strict_types=1);

/**
 * AGENDA — refresher direcionado (Fase 2): debounce, dry-run e não-desativação.
 *
 * Exercita o caminho que a produção vai percorrer, mas SEM rede: a consulta ao
 * Omie é injetada. Tabelas em TEMPORARY (auto-limpam, invisíveis a outras
 * sessões, não tocam o schema real nem a migração pendente).
 *
 *   php tests/run_quote_order_refresher.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Config;
use BIomie\Database;
use BIomie\QuoteAgenda\QuoteOrderRefresher;

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$config = new Config();
$pdo = test_pdo(); // suíte MUTÁVEL: escreve → só o test_db aceita (TestDatabaseGuard bloqueia produção)

// Espelha a migração 20260722000000 e a tabela de acompanhamento, em TEMPORARY.
$pdo->exec('CREATE TEMPORARY TABLE biomie_quote_order_refresh_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT NOT NULL, omie_order_id BIGINT NOT NULL,
    decision VARCHAR(16) NOT NULL, resolution VARCHAR(16) NULL,
    etapa_seen VARCHAR(8) NULL, reason VARCHAR(255) NOT NULL,
    source VARCHAR(16) NOT NULL, http_status SMALLINT UNSIGNED NULL,
    is_dry_run TINYINT(1) NOT NULL DEFAULT 1,
    applied TINYINT(1) NOT NULL DEFAULT 0,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id), KEY idx (company_id, omie_order_id, checked_at)
) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE biomie_sales_quote_followup_schedule (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT NOT NULL, omie_order_id BIGINT NOT NULL,
    seller_id VARCHAR(32) NULL, quote_created_at DATETIME NOT NULL,
    quote_created_source VARCHAR(32) NOT NULL DEFAULT \'omie_dinc\',
    next_followup_on DATE NULL, followup_step TINYINT UNSIGNED NOT NULL DEFAULT 1,
    charge_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_seen_at DATETIME NULL, missing_since DATETIME NULL,
    reconciliation_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    resolved_at DATETIME NULL, resolution VARCHAR(16) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uq (company_id, omie_order_id)
) ENGINE=InnoDB');

const CID = 777;
$seed = static function (PDO $pdo, string $order, ?string $missing = null): void {
    $st = $pdo->prepare('INSERT INTO biomie_sales_quote_followup_schedule
        (company_id, omie_order_id, quote_created_at, missing_since, reconciliation_attempts, charge_count, followup_step)
        VALUES (:c,:o,NOW(),:m,:a,3,2)');
    $st->execute(['c' => CID, 'o' => $order, 'm' => $missing, 'a' => $missing ? 2 : 0]);
};
$row = static function (PDO $pdo, string $order): array {
    $st = $pdo->prepare('SELECT * FROM biomie_sales_quote_followup_schedule WHERE company_id=:c AND omie_order_id=:o');
    $st->execute(['c' => CID, 'o' => $order]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
};
/** Fonte falsa: devolve o que mandarmos, sem rede. */
$fonte = static fn (array $resp): callable => static fn (string $id): array => $resp;

// ── 1. Etapa 10 → mantém e limpa ausência ────────────────────────────────────
$seed($pdo, '1001', '2026-07-01 10:00:00');
$r = (new QuoteOrderRefresher($pdo, CID, $fonte(['ok' => true, 'etapa' => '10'])))->refresh('1001', 'webhook', true);
$check('etapa 10 → keep, não desativa', $r['action'] === 'keep' && $r['deactivates'] === false);
$a = $row($pdo, '1001');
$check('keep limpa missing_since', $a['missing_since'] === null);
$check('keep zera tentativas de reconciliação', (int) $a['reconciliation_attempts'] === 0);
$check('keep NÃO resolve o acompanhamento', $a['resolved_at'] === null);
$check('keep preserva a cobrança já feita', (int) $a['charge_count'] === 3);

// ── 2. Etapa confirmada fora da 10 → resolve (auto-desativação LIGADA) ────────
$seed($pdo, '1002');
$r = (new QuoteOrderRefresher($pdo, CID, $fonte(['ok' => true, 'etapa' => '50'])))->refresh('1002', 'webhook', true, true);
$b = $row($pdo, '1002');
$check('etapa 50 → resolve/invoiced', $r['resolution'] === 'invoiced' && $r['deactivates'] === true);
$check('resolve grava resolved_at + resolution', $b['resolved_at'] !== null && $b['resolution'] === 'invoiced');
$check('resolve NÃO apaga a linha (trilha preservada)', $b !== []);

// ── 3. 🔴 FALHA DE REDE NUNCA DESATIVA ───────────────────────────────────────
foreach ([
    'timeout'   => ['ok' => false, 'error' => 'Operation timed out', 'http' => null],
    'HTTP 429'  => ['ok' => false, 'error' => 'rate', 'http' => 429],
    'HTTP 500'  => ['ok' => false, 'error' => 'server', 'http' => 500],
    'HTTP 425'  => ['ok' => false, 'error' => 'early', 'http' => 425],
    'bloqueio'  => ['ok' => false, 'error' => 'API bloqueada por consumo indevido. Tente novamente em 900 seg', 'http' => null],
] as $nome => $resp) {
    $ord = '20' . substr(md5($nome), 0, 4);
    $ord = (string) (2000 + crc32($nome) % 900);
    $seed($pdo, $ord);
    $r = (new QuoteOrderRefresher($pdo, CID, $fonte($resp)))->refresh($ord, 'webhook', true);
    $st = $row($pdo, $ord);
    $check("🔴 {$nome} → retry e o orçamento FICA na Agenda",
        $r['action'] === 'retry' && $r['deactivates'] === false && $st['resolved_at'] === null, $r['reason']);
}

// Exceção de transporte (o callable lança) também não pode desativar.
$seed($pdo, '3001');
$explode = static function (string $id): array { throw new RuntimeException('conexão perdida'); };
$r = (new QuoteOrderRefresher($pdo, CID, $explode))->refresh('3001', 'webhook', true);
$check('🔴 exceção na consulta → retry, orçamento fica',
    $r['action'] === 'retry' && $row($pdo, '3001')['resolved_at'] === null);

// ── 4. Exclusão CONFIRMADA → vanished (auto-desativação LIGADA) ───────────────
$seed($pdo, '4001');
$r = (new QuoteOrderRefresher($pdo, CID, $fonte(['ok' => false, 'error' => 'Pedido não cadastrado', 'http' => null])))
    ->refresh('4001', 'webhook', true, true);
$check('exclusão confirmada → vanished', $r['action'] === 'vanished' && $r['deactivates'] === true);
$check('vanished grava resolution=vanished', $row($pdo, '4001')['resolution'] === 'vanished');

// ── 5. DEBOUNCE por pedido ───────────────────────────────────────────────────
$seed($pdo, '5001');
$chamadas = 0;
$conta = static function (string $id) use (&$chamadas): array { $chamadas++; return ['ok' => true, 'etapa' => '10']; };
$ref = new QuoteOrderRefresher($pdo, CID, $conta);
$ref->refresh('5001', 'webhook', true);
$r2 = $ref->refresh('5001', 'webhook', true);   // imediatamente depois
$check('🔴 2ª chamada no mesmo pedido é DEBOUNCED', ($r2['debounced'] ?? false) === true, $r2['reason'] ?? '');
$check('debounce evitou a chamada ao Omie (1 só)', $chamadas === 1, "chamadas={$chamadas}");
$check('debounce reporta os segundos', isset($r2['seconds_since_last_check']));
// Outro pedido não é afetado pelo debounce do primeiro.
$seed($pdo, '5002');
$ref->refresh('5002', 'webhook', true);
$check('debounce é POR PEDIDO (outro pedido passa)', $chamadas === 2, "chamadas={$chamadas}");

// ── 6. DRY-RUN: decide e loga, mas não muta ──────────────────────────────────
$seed($pdo, '6001');
$r = (new QuoteOrderRefresher($pdo, CID, $fonte(['ok' => true, 'etapa' => '70'])))->refresh('6001', 'webhook', false);
$check('dry-run decide resolve/cancelled', $r['resolution'] === 'cancelled' && $r['deactivates'] === true);
$check('🔴 dry-run NÃO muta o acompanhamento', $row($pdo, '6001')['resolved_at'] === null);
$check('dry-run reporta applied=false', $r['applied'] === false && (int) $r['rows_changed'] === 0);

// ── 7. LOG sempre — inclusive retry e dry-run ───────────────────────────────
$tot = (int) $pdo->query('SELECT COUNT(*) FROM biomie_quote_order_refresh_log WHERE company_id=' . CID)->fetchColumn();
$check('toda decisão gerou log', $tot >= 10, "{$tot} linhas");
$retries = (int) $pdo->query("SELECT COUNT(*) FROM biomie_quote_order_refresh_log WHERE company_id=" . CID . " AND decision='retry'")->fetchColumn();
$check('retries também foram logados (prova de que a rede não apagou)', $retries >= 6, "{$retries} retries");
// ── 7b. 🔴 MODO × MUTAÇÃO são colunas distintas ─────────────────────────────
// Sem separar, "simulei", "rodei e nada mudou" e "rodei e mudei" ficariam
// indistinguíveis na auditoria.
$q = static function (PDO $pdo, string $where): int {
    return (int) $pdo->query('SELECT COUNT(*) FROM biomie_quote_order_refresh_log WHERE company_id=' . CID . ' AND ' . $where)->fetchColumn();
};
$check('simulação → is_dry_run=1 e applied=0', $q($pdo, 'is_dry_run=1 AND applied=0') >= 1);
$check('execução real SEM mutação (keep/retry) → is_dry_run=0 e applied=0',
    $q($pdo, "is_dry_run=0 AND applied=0 AND decision IN ('retry','keep')") >= 1);
$check('mutação efetivada → is_dry_run=0 e applied=1',
    $q($pdo, "is_dry_run=0 AND applied=1 AND decision IN ('resolve','vanished')") >= 1);
$check('🔴 combinação impossível is_dry_run=1 + applied=1 nunca ocorre',
    $q($pdo, 'is_dry_run=1 AND applied=1') === 0);
$check('🔴 retry NUNCA aparece com applied=1',
    $q($pdo, "decision='retry' AND applied=1") === 0);
// `source` guarda a ORIGEM real, não o modo — dry-run não pode apagá-la.
$check('dry-run preserva a origem em source (não vira "dryrun")',
    $q($pdo, "is_dry_run=1 AND source='webhook'") >= 1);
$check('nenhuma linha com source vazio', $q($pdo, "source=''") === 0);

// ── 7c. 🔴 ORIGEM inválida é recusada NO CÓDIGO ─────────────────────────────
// O sql_mode do servidor NÃO é estrito: `source NOT NULL` sem default deixaria
// o MariaDB gravar '' em silêncio. A recusa tem de vir daqui.
$antes = $q($pdo, '1=1');
$chamouOrigem = 0;
$cOrig = static function (string $id) use (&$chamouOrigem): array { $chamouOrigem++; return ['ok' => true, 'etapa' => '10']; };
$seed($pdo, '7101');
$rOrig = (new QuoteOrderRefresher($pdo, CID, $cOrig))->refresh('7101', 'origem_inventada', true);
$check('🔴 origem desconhecida → recusa', ($rOrig['skipped'] ?? false) === true, $rOrig['reason'] ?? '');
$check('🔴 origem inválida NÃO consulta o Omie', $chamouOrigem === 0);
$check('🔴 origem inválida NÃO grava linha no log', $q($pdo, '1=1') === $antes);
foreach (QuoteOrderRefresher::SOURCES as $src) {
    $seed($pdo, (string) (7200 + crc32($src) % 90));
    $ok = (new QuoteOrderRefresher($pdo, CID, $cOrig))->refresh((string) (7200 + crc32($src) % 90), $src, false);
    $check("origem válida '{$src}' é aceita", ($ok['skipped'] ?? false) !== true, $ok['reason'] ?? '');
}

// ── 9. Entrada inválida não vira chamada ────────────────────────────────────
$chamadas2 = 0;
$c2 = static function (string $id) use (&$chamadas2): array { $chamadas2++; return ['ok' => true, 'etapa' => '10']; };
$r = (new QuoteOrderRefresher($pdo, CID, $c2))->refresh('abc', 'webhook', true);
$check('id inválido é recusado sem chamar o Omie', ($r['skipped'] ?? false) === true && $chamadas2 === 0);

// ── 8. Guard "sem tabela de log" ────────────────────────────────────────────
// O guard existe para o caso de a migração não estar aplicada (ambiente novo ou
// rollback): sem log não há debounce nem auditoria, então o refresher RECUSA em
// vez de agir às cegas — antes dele, o serviço estourava PDOException crua.
//
// O caminho NEGATIVO não é exercitável aqui depois que a migração foi aplicada:
// esta suíte trabalha sobre uma TEMPORARY que SOMBREIA a tabela real, e
// derrubá-la apenas revelaria a real (o que, numa versão anterior deste teste,
// chegou a gravar uma linha sintética em produção). Foi verificado ANTES de
// aplicar a migração, pelo CLI:
//     12345 → skipped (log/debounce indisponível (migração da Fase 2 pendente)
//                      — nada foi consultado nem alterado) [dry-run]
// Aqui garantimos o lado positivo e que o método existe e é público.
$refOk = new QuoteOrderRefresher($pdo, CID, $fonte(['ok' => true, 'etapa' => '10']));
$check('logTableReady() disponível quando a tabela existe', $refOk->logTableReady() === true);
$check('guard logTableReady() é público e checável',
    method_exists(QuoteOrderRefresher::class, 'logTableReady'));

// ── 10. PORTÃO DA DESATIVAÇÃO — TODAS as origens automáticas são retidas ──────
// Fecha a corrida webhook×scanner: enquanto autoDeactivationEnabled=false, um
// resolve/vanished vindo de webhook, scanner OU manual é RETIDO (decisão real,
// applied=0), não importa quem confirmou primeiro. `cli` é o único que aplica.
$resolveSrc = $fonte(['ok' => true, 'etapa' => '50']);                                   // → resolve/invoiced
$vanishSrc  = $fonte(['ok' => false, 'error' => 'Pedido não cadastrado', 'http' => null]); // → vanished
$id = 8000;

// ── flag OFF: resolve/vanished de origem AUTOMÁTICA fica retido ──────────────
foreach (['webhook', 'scanner', 'manual'] as $src) {
    $o = (string) (++$id);
    $seed($pdo, $o);
    $r = (new QuoteOrderRefresher($pdo, CID, $resolveSrc))->refresh($o, $src, true, false);
    $st = $row($pdo, $o);
    $check("hold: {$src} + resolve + flag OFF → held, applied=0, followup ABERTO",
        ($r['held'] ?? false) === true && $r['applied'] === false
        && (int) $r['rows_changed'] === 0 && $st['resolved_at'] === null && $st['resolution'] === null,
        json_encode(['held' => $r['held'] ?? null, 'resolved_at' => $st['resolved_at'] ?? null]));
    $check("hold: {$src} + resolve → log é decisão REAL (is_dry_run=0, applied=0)",
        $q($pdo, "decision='resolve' AND is_dry_run=0 AND applied=0 AND omie_order_id={$o}") >= 1);
}
// vanished por scanner também é retido
$o = (string) (++$id);
$seed($pdo, $o);
$r = (new QuoteOrderRefresher($pdo, CID, $vanishSrc))->refresh($o, 'scanner', true, false);
$check('hold: scanner + vanished + flag OFF → held, followup ABERTO',
    ($r['held'] ?? false) === true && $row($pdo, $o)['resolved_at'] === null);

// ── cli NUNCA é retido: --apply explícito desativa (caminho deliberado) ──────
$o = (string) (++$id);
$seed($pdo, $o);
$r = (new QuoteOrderRefresher($pdo, CID, $resolveSrc))->refresh($o, 'cli', true, false);
$check('cli + resolve + --apply → NÃO retido, applied=1, só este resolvido',
    ($r['held'] ?? false) === false && $r['applied'] === true
    && $row($pdo, $o)['resolved_at'] !== null);

// ── flag ON: desativação automática liberada (webhook e scanner aplicam) ─────
foreach (['webhook', 'scanner'] as $src) {
    $o = (string) (++$id);
    $seed($pdo, $o);
    $r = (new QuoteOrderRefresher($pdo, CID, $resolveSrc))->refresh($o, $src, true, true); // enabled=true
    $check("flag ON: {$src} + resolve → aplica automaticamente (held=false, applied=1)",
        ($r['held'] ?? false) === false && $r['applied'] === true && $row($pdo, $o)['resolved_at'] !== null);
}

// ── keep/retry seguem automáticos em QUALQUER origem, mesmo flag OFF ─────────
$o = (string) (++$id);
$seed($pdo, $o, '2026-07-01 10:00:00');
$r = (new QuoteOrderRefresher($pdo, CID, $fonte(['ok' => true, 'etapa' => '10'])))->refresh($o, 'scanner', true, false);
$check('keep (scanner, flag OFF) → automático, limpa ausência, não retido',
    ($r['held'] ?? false) === false && $r['action'] === 'keep' && $row($pdo, $o)['missing_since'] === null);
$o = (string) (++$id);
$seed($pdo, $o);
$r = (new QuoteOrderRefresher($pdo, CID, $fonte(['ok' => false, 'error' => 'server', 'http' => 500])))->refresh($o, 'webhook', true, false);
$check('retry (webhook, flag OFF) → automático, não retido, orçamento fica',
    ($r['held'] ?? false) === false && $r['action'] === 'retry' && $row($pdo, $o)['resolved_at'] === null);

// ── 🔴 HOLD NÃO VIRA LOOP: dentro do debounce a reavaliação é ignorada ───────
$o = (string) (++$id);
$seed($pdo, $o);
$ref = new QuoteOrderRefresher($pdo, CID, $resolveSrc);
$r1 = $ref->refresh($o, 'webhook', true, false);                 // held
$r2 = $ref->refresh($o, 'webhook', true, false);                 // logo depois → debounced
$check('hold não faz loop: 2ª execução no debounce é ignorada',
    ($r1['held'] ?? false) === true && ($r2['debounced'] ?? false) === true);
$check('hold não faz loop: followup permanece intacto e ABERTO',
    $row($pdo, $o)['resolved_at'] === null);
$check('hold não faz loop: NÃO duplica linha de decisão no debounce',
    $q($pdo, "omie_order_id={$o} AND is_dry_run=0") === 1);

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
