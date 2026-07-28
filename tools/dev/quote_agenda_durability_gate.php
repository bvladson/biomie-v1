<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — Gate de DURABILIDADE da Fase 2.
 *
 * Prova que as datas canônicas resistem ao fluxo normal de DELETE+reinsert do
 * cron. NÃO basta poll espaçado: só conta como ciclo quando o sync efetivamente
 * AVANÇA (completed_at do cron-delta e/ou sincronizado_em dos pedidos).
 *
 * Aprova só com 2 ciclos reais confirmados, e em cada um revalida tudo.
 *
 * READ-ONLY.
 *
 * Uso: php tools/dev/quote_agenda_durability_gate.php [--cycles=2] [--max-min=30]
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;

$opts    = getopt('', ['cycles:', 'max-min:']);
$want    = max(1, (int) ($opts['cycles'] ?? 2));
$maxMin  = max(5, (int) ($opts['max-min'] ?? 30));

$config = new Config();
$conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $conn);

const SENTINELA = '8897507832';
const SENT_ESPERADO = '2026-07-16 09:52:09';
const LICITACAO = '8896509853';
/** Empresas e a contagem de orçamentos VIVOS esperada. */
const ALVOS = [1 => ['MAG_FILIAL', 39], 2 => ['EXPERT', 12], 3 => ['UNI_DESIGN', 11]];

/**
 * Sinal de ciclo — SÓ a sentinela conta.
 *
 * MAX(sincronizado_em) global pode avançar porque OUTRO pedido foi escrito, e
 * order_stage_events_sync prova que o sync de eventos rodou, não que o
 * replacePendingOrdersWindow passou por ESTA linha. Como a sentinela pertence à
 * janela atingida pelo cron, o avanço do sincronizado_em DELA prova que foi
 * removida/reinserida pelo caminho corrigido.
 *
 * IMPORTANTE: comparar sempre dentro da MESMA coluna. sincronizado_em vem de
 * NOW() do banco (UTC); completed_at é wall-clock do app (Fortaleza). Nunca
 * cruzar os dois sem normalização — cron_completed_at aqui é só corroboração.
 */
function sinalDeCiclo(PDO $pdo): array
{
    $s = $pdo->query(
        "SELECT sincronizado_em, data_inclusao_omie, fonte_sync
           FROM biomie_pedidos_canonicos
          WHERE company_id = 1 AND pedido_id_omie = '" . SENTINELA . "'"
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    // Corroboração (coluna própria, fuso próprio — nunca comparada com a de cima).
    $cron = (string) ($pdo->query(
        "SELECT COALESCE(MAX(completed_at),'')
           FROM biomie_sync_state
          WHERE company_id = 1 AND source LIKE 'cron%' AND completed_at IS NOT NULL"
    )->fetchColumn() ?: '');

    return [
        'sentinela_sincronizado_em' => (string) ($s['sincronizado_em'] ?? ''),   // UTC
        'sentinela_data_inclusao'   => (string) ($s['data_inclusao_omie'] ?? ''), // Fortaleza
        'sentinela_fonte'           => (string) ($s['fonte_sync'] ?? ''),
        'cron_completed_at'         => $cron,                                     // Fortaleza (corroboração)
    ];
}

/** @return array{ok:bool,linhas:list<string>} */
function validar(PDO $pdo): array
{
    $linhas = [];
    $ok = true;

    // Sentinela: a linha que o cron antigo apagava.
    $s = $pdo->query(
        "SELECT data_inclusao_omie FROM biomie_pedidos_canonicos
          WHERE company_id = 1 AND pedido_id_omie = '" . SENTINELA . "'"
    )->fetchColumn();
    $sentOk = (string) $s === SENT_ESPERADO;
    $ok = $ok && $sentOk;
    $linhas[] = sprintf('    %s sentinela %s = %s', $sentOk ? '✅' : '❌', SENTINELA, var_export($s, true));

    // Cobertura por empresa: TODOS os vivos (com dInc) devem bater o alvo e nenhum voltar a NULL.
    foreach (ALVOS as $cid => [$slug, $alvo]) {
        $r = $pdo->query(
            "SELECT SUM(data_inclusao_omie IS NOT NULL) di,
                    SUM(data_alteracao_omie IS NOT NULL) da,
                    SUM(data_previsao_faturamento IS NOT NULL) dp,
                    COUNT(*) t, COUNT(DISTINCT pedido_id_omie) uq
               FROM biomie_pedidos_canonicos
              WHERE company_id = {$cid} AND etapa_omie = '10'"
        )->fetch(PDO::FETCH_ASSOC);
        $cobre = (int) $r['di'] === $alvo && (int) $r['da'] === $alvo && (int) $r['dp'] === $alvo;
        $dup   = (int) $r['t'] === (int) $r['uq'];
        $ok = $ok && $cobre && $dup;
        $linhas[] = sprintf(
            '    %s %-11s vivos_com_datas=%s/%s (dAlt=%s prev=%s) · etapa10_total=%s · dup=%s',
            ($cobre && $dup) ? '✅' : '❌', $slug, $r['di'], $alvo, $r['da'], $r['dp'], $r['t'],
            $dup ? 'zero' : 'HÁ DUPLICIDADE'
        );
    }

    // Licitação segue fora.
    $l = (int) $pdo->query(
        "SELECT COUNT(*) FROM biomie_pedidos_canonicos WHERE company_id = 1 AND pedido_id_omie = '" . LICITACAO . "'"
    )->fetchColumn();
    $ok = $ok && $l === 0;
    $linhas[] = sprintf('    %s Licitação %s permanece excluída (linhas=%d)', $l === 0 ? '✅' : '❌', LICITACAO, $l);

    return ['ok' => $ok, 'linhas' => $linhas];
}

$cfg = new Config();
$flagsOff = !$cfg->get('features.quote_agenda_enabled')
    && !$cfg->get('features.quote_agenda_scanner_enabled')
    && !$cfg->get('features.quote_agenda_notifications_enabled');

echo str_repeat('═', 78), PHP_EOL;
echo '  GATE DE DURABILIDADE — exige ' . $want . ' ciclos REAIS do cron (avanço comprovado)', PHP_EOL;
echo str_repeat('═', 78), PHP_EOL;
echo '  flags desligadas: ' . ($flagsOff ? '✅ sim' : '❌ NÃO') . PHP_EOL;

$base = sinalDeCiclo($pdo);
echo '  baseline sentinela.sincronizado_em (UTC) : ' . $base['sentinela_sincronizado_em'] . PHP_EOL;
echo '  baseline sentinela.data_inclusao (BRT)   : ' . $base['sentinela_data_inclusao'] . PHP_EOL;
echo '  baseline cron_completed_at (BRT, corrob.): ' . $base['cron_completed_at'] . PHP_EOL;
$v = validar($pdo);
echo '  estado inicial:' . PHP_EOL . implode(PHP_EOL, $v['linhas']) . PHP_EOL;
echo PHP_EOL . '  aguardando ' . $want . ' reescritas REAIS da sentinela pelo cron...' . PHP_EOL;

$ciclos = [];
$ant = $base;
$fim = time() + ($maxMin * 60);

while (count($ciclos) < $want && time() < $fim) {
    sleep(60);
    $cur = sinalDeCiclo($pdo);

    // ÚNICO critério de ciclo: a sentinela foi reescrita (mesma coluna, mesmo fuso).
    $reescreveu = $cur['sentinela_sincronizado_em'] !== ''
        && $cur['sentinela_sincronizado_em'] > $ant['sentinela_sincronizado_em'];
    if (!$reescreveu) {
        continue; // poll, avanço global ou de outro pedido NÃO conta
    }

    // A sentinela sobreviveu à reescrita com a data intacta?
    $sentIntacta = $cur['sentinela_data_inclusao'] === SENT_ESPERADO;
    $v = validar($pdo);
    $okCiclo = $sentIntacta && $v['ok'];

    $ciclos[] = ['at' => date('H:i:s'), 'sig' => $cur, 'ok' => $okCiclo];
    echo PHP_EOL . '  ── CICLO ' . count($ciclos) . ' detectado às ' . date('H:i:s') . ' ──' . PHP_EOL;
    echo '    sentinela reescrita : ' . $ant['sentinela_sincronizado_em'] . '  →  '
        . $cur['sentinela_sincronizado_em'] . '  (fonte=' . $cur['sentinela_fonte'] . ')' . PHP_EOL;
    echo '    ' . ($sentIntacta ? '✅' : '❌') . ' sobreviveu com data_inclusao_omie = '
        . var_export($cur['sentinela_data_inclusao'], true) . PHP_EOL;
    echo '    cron_completed_at   : ' . $ant['cron_completed_at'] . '  →  ' . $cur['cron_completed_at']
        . '  (corroboração)' . PHP_EOL;
    echo implode(PHP_EOL, $v['linhas']) . PHP_EOL;
    $ant = $cur;
}

echo PHP_EOL . str_repeat('─', 78) . PHP_EOL;
if (count($ciclos) < $want) {
    echo '  ⏳ INCONCLUSIVO: só ' . count($ciclos) . ' de ' . $want
        . ' reescritas REAIS da sentinela em ' . $maxMin . ' min.' . PHP_EOL;
    echo '     Poll, avanço global ou de outro pedido NÃO contam. Rode novamente.' . PHP_EOL;
    exit(2);
}
$todosOk = !in_array(false, array_column($ciclos, 'ok'), true);
echo '  ' . ($todosOk ? '✅ GATE DE DURABILIDADE APROVADO' : '❌ GATE REPROVADO') . ' — '
    . count($ciclos) . ' ciclos REAIS (sentinela reescrita) confirmados' . PHP_EOL;
foreach ($ciclos as $i => $c) {
    echo '     ciclo ' . ($i + 1) . ' às ' . $c['at']
        . ' · sentinela.sincronizado_em=' . $c['sig']['sentinela_sincronizado_em']
        . ' · data_inclusao=' . $c['sig']['sentinela_data_inclusao']
        . ' · ' . ($c['ok'] ? 'tudo íntegro' : 'FALHOU') . PHP_EOL;
}
exit($todosOk ? 0 : 1);
