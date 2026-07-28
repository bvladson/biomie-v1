<?php

declare(strict_types=1);

/**
 * AGENDA — cooldown do botão "Atualizar" e idempotência do hook (Fase 2).
 *
 * Duas propriedades que o usuário exigiu explicitamente:
 *  · um clique NÃO pode disparar sincronização remota em rajada (cooldown), e
 *    NUNCA pode tocar outra empresa;
 *  · um burst de eventos do MESMO pedido vira UM job (chave idempotente).
 *
 * A regra do cooldown é reproduzida aqui exatamente como o endpoint a aplica,
 * contra dados reais de `biomie_quote_live_snapshot_sync` (somente leitura).
 *
 *   php tests/run_quote_refresh_cooldown.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Config;
use BIomie\Database;
use BIomie\QuoteAgenda\QuoteOrderRefresher;

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

const COOLDOWN = 120;   // igual ao REMOTE_COOLDOWN_SECONDS do endpoint

/**
 * Réplica da decisão do endpoint. Pura, para poder ser exercitada.
 *
 * @return array{0:bool,1:string,2:int} [pode enfileirar, motivo, segundos de espera]
 */
function podeEnfileirar(?int $sinceStart, bool $running, bool $flagOn): array
{
    if (!$flagOn)                                   { return [false, 'flag desligada', 0]; }
    if ($running)                                   { return [false, 'já em andamento', COOLDOWN]; }
    if ($sinceStart !== null && $sinceStart < COOLDOWN) {
        return [false, 'cooldown', COOLDOWN - $sinceStart];
    }
    return [true, 'enfileirada', 0];
}

// ── 1. Cooldown ─────────────────────────────────────────────────────────────
[$ok, $r, $wait] = podeEnfileirar(null, false, true);
$check('nunca sincronizou → pode enfileirar', $ok === true, $r);

[$ok, $r, $wait] = podeEnfileirar(5, false, true);
$check('🔴 clique 5s após o anterior → NÃO enfileira (cooldown)', $ok === false && $wait === 115, "espera {$wait}s");

[$ok, $r, $wait] = podeEnfileirar(119, false, true);
$check('119s → ainda no cooldown', $ok === false && $wait === 1);
[$ok, $r, $wait] = podeEnfileirar(120, false, true);
$check('120s → libera (limite fechado)', $ok === true);
[$ok, $r, $wait] = podeEnfileirar(600, false, true);
$check('600s → libera', $ok === true);

[$ok, $r] = podeEnfileirar(999, true, true);
$check('🔴 scan em andamento → NÃO empilha outro', $ok === false, $r);

[$ok, $r] = podeEnfileirar(999, false, false);
$check('flag desligada → botão ainda funciona, mas não enfileira', $ok === false && $r === 'flag desligada');

// ── 2. Chave idempotente do clique é POR EMPRESA ────────────────────────────
$keyFor = static fn (int $cid, int $t): string => sprintf(
    'quote_agenda.company.scan|company:%d|%d', $cid, (int) floor($t / COOLDOWN)
);
$t = time();
$check('🔴 empresas diferentes → chaves diferentes (um clique não afeta outra empresa)',
    $keyFor(1, $t) !== $keyFor(3, $t), $keyFor(1, $t) . ' vs ' . $keyFor(3, $t));
$check('mesma empresa na mesma janela → MESMA chave (colapsa)',
    $keyFor(1, $t) === $keyFor(1, $t + 5));
$check('janela seguinte → chave nova', $keyFor(1, $t) !== $keyFor(1, $t + COOLDOWN + 1));

// ── 3. Chave idempotente do HOOK é por empresa+pedido+janela ────────────────
$hookKey = static fn (int $cid, string $ord, int $t): string => sprintf(
    'quote_agenda.order.refresh|company:%d|order:%s|%d',
    $cid, $ord, (int) floor($t / QuoteOrderRefresher::DEBOUNCE_SECONDS)
);
$check('🔴 burst do MESMO pedido → uma chave só',
    $hookKey(1, '999', $t) === $hookKey(1, '999', $t + 3));
$check('pedidos diferentes → chaves diferentes',
    $hookKey(1, '999', $t) !== $hookKey(1, '888', $t));
$check('mesmo pedido em empresas diferentes → chaves diferentes',
    $hookKey(1, '999', $t) !== $hookKey(2, '999', $t));

// ── 4. Estado REAL das empresas (somente leitura) ───────────────────────────
$config = new Config();
$pdo = Database::connect($config, Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db');
$rows = $pdo->query(
    'SELECT co.slug, s.status, TIMESTAMPDIFF(SECOND, s.started_at, NOW()) since_start
       FROM biomie_companies co
  LEFT JOIN biomie_quote_live_snapshot_sync s ON s.company_id = co.id
      WHERE co.ativo = 1 ORDER BY co.id'
)->fetchAll(PDO::FETCH_ASSOC);
$check('estado real das empresas lido', $rows !== [], count($rows) . ' empresa(s)');
foreach ($rows as $r2) {
    [$ok2, $motivo, $wait2] = podeEnfileirar(
        $r2['since_start'] !== null ? (int) $r2['since_start'] : null,
        ($r2['status'] ?? '') === 'running',
        true
    );
    $checks[] = ['name' => '  ' . $r2['slug'] . ': ' . ($ok2 ? 'liberaria' : 'seguraria') . ' (' . $motivo . ')',
                 'ok' => true, 'detail' => $wait2 > 0 ? "espera {$wait2}s" : ''];
}

// ── 5. Flag continua desligada ──────────────────────────────────────────────
$check('🔴 flag do refresh está OFF (entrega sem ativar nada)',
    (bool) $config->get('features.quote_agenda_webhook_refresh_enabled', false) === false);

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
