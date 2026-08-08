<?php

declare(strict_types=1);

/**
 * GUARD — o SLA de frescor dos jobs Python existe em dois idiomas.
 *
 * ─── O QUE ISTO IMPEDE ──────────────────────────────────────────────────────
 * O mesmo limiar precisa ser conhecido por três partes:
 *   · o Python, para se autoavaliar em `biomie_intel health`;
 *   · o OpsSentinel, para disparar o alerta no Telegram e no sininho;
 *   · o painel administrativo, para pintar o job como parado.
 *
 * As duas do lado PHP já compartilham `IntelligenceJobSla::MAX_IDLE_HOURS`. A
 * terceira mora em `services/python/biomie_intel/health.py` e não tem como
 * importar a constante PHP. Duas declarações da mesma verdade divergem em
 * silêncio, e a divergência tem sintoma cruel: o painel jura "ok" enquanto o
 * Telegram grita — ou, pior, ninguém alerta e a tela garante que está tudo bem.
 *
 * É a mesma disciplina de `WRITABLE_TABLES` × GRANTs do provisionamento, que já
 * pegou uma permissão viva só no banco.
 *
 * ─── E O PIOR CASO: SLA SEM AGENDAMENTO ─────────────────────────────────────
 * Um job com limiar declarado e sem linha no cron alerta para sempre, e o
 * alerta que sempre toca é o alerta que ninguém lê. O caminho inverso — cron
 * sem limiar — foi o que deixou o `insights` sem dead-man switch enquanto o
 * `quote_score` tinha um. Os dois sentidos são verificados aqui.
 *
 *   php tests/run_intelligence_freshness_guard.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Intelligence\IntelligenceJobSla;

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$root = dirname(__DIR__);

/**
 * job_name gravado em `intelligence_job_runs` → script de cron que o executa.
 * O nome do job vem do código Python (`track(conn, "insights", ...)`) e o do
 * script vem do sistema de arquivos; não dá para derivar um do outro.
 */
$jobParaScript = [
    'quote_score' => 'intelligence_score.sh',
    'insights' => 'intelligence_insights.sh',
    'forecast' => 'intelligence_forecast.sh',
    'geo_intelligence' => 'intelligence_geo.sh',
    'fiscal_mart' => 'fiscal_mart.sh',
    'geo_backtest' => 'geo_backtest.sh',
    'receivable_risk' => 'intelligence_receivables.sh',
    'ops_anomaly' => 'intelligence_ops_anomaly.sh',
];

// ─────────────────────────────────────── 1. os dois idiomas concordam?
$healthPy = (string) @file_get_contents($root . '/services/python/biomie_intel/health.py');
$check('health.py foi lido', $healthPy !== '', strlen($healthPy) . ' bytes');

$python = [];
if (preg_match('/MAX_IDLE_HORAS\s*=\s*\{([^}]*)\}/', $healthPy, $m) === 1) {
    preg_match_all('/["\'](\w+)["\']\s*:\s*(\d+)/', $m[1], $pares, PREG_SET_ORDER);
    foreach ($pares as $par) {
        $python[$par[1]] = (int) $par[2];
    }
}
$php = IntelligenceJobSla::MAX_IDLE_HOURS;

$check('MAX_IDLE_HORAS foi encontrado no Python', $python !== [], json_encode($python));
$check(
    '🔴 os limiares de PHP e Python são idênticos',
    $python === $php,
    'php=' . json_encode($php) . ' python=' . json_encode($python),
);

// ─────────────────────────────────── 2. todo job com SLA tem agendamento
$cron = (string) @file_get_contents($root . '/tools/deploy/vps/biomie.cron');
$check('biomie.cron foi lido', $cron !== '', strlen($cron) . ' bytes');

/** Converte a expressão de cron em "de quantas em quantas horas roda". */
$cadenciaHoras = static function (string $expressao): ?float {
    $campos = preg_split('/\s+/', trim($expressao)) ?: [];
    if (count($campos) < 5) {
        return null;
    }
    $hora = $campos[1];
    if ($hora === '*') {
        return 1.0;
    }
    if (preg_match('#^\*/(\d+)$#', $hora, $mm) === 1) {
        return (float) $mm[1];
    }
    // Hora fixa (ex.: `10 5 * * *`) = uma vez por dia.
    return 24.0;
};

$semCron = [];
$limiteCurto = [];
foreach ($php as $job => $limite) {
    $script = $jobParaScript[$job] ?? null;
    if ($script === null) {
        $semCron[] = $job . ' (sem mapeamento para script)';
        continue;
    }

    $linha = null;
    foreach (explode("\n", $cron) as $l) {
        if (str_contains($l, $script) && !str_starts_with(ltrim($l), '#')) {
            $linha = trim($l);
            break;
        }
    }
    if ($linha === null) {
        $semCron[] = $job . ' → ' . $script;
        continue;
    }

    $cadencia = $cadenciaHoras($linha);
    if ($cadencia !== null && $limite <= $cadencia) {
        $limiteCurto[] = sprintf('%s: limite %dh <= cadência %sh', $job, $limite, $cadencia);
    }
}

$check(
    '🔴 todo job com SLA declarado tem linha no cron',
    $semCron === [],
    implode(' | ', $semCron),
);
$check(
    'nenhum limite é menor ou igual à cadência (alarme falso garantido)',
    $limiteCurto === [],
    implode(' | ', $limiteCurto),
);

// ────────────────────────────── 3. o inverso: cron de inteligência sem SLA
$semSla = [];
foreach (explode("\n", $cron) as $linha) {
    if (!preg_match('#cron/hostinger/(intelligence_\w+\.sh)#', $linha, $mm) || str_starts_with(ltrim($linha), '#')) {
        continue;
    }
    $script = $mm[1];
    if (!in_array($script, $jobParaScript, true)) {
        $semSla[] = $script;
    }
}
$check(
    '🔴 nenhum cron de inteligência roda sem dead-man switch',
    $semSla === [],
    implode(' | ', $semSla),
);

// ───────────────────────────────────── 4. os scripts existem e são LF puro
$faltando = [];
$comCrlf = [];
foreach ($jobParaScript as $job => $script) {
    $caminho = $root . '/cron/hostinger/' . $script;
    if (!is_file($caminho)) {
        $faltando[] = $script;
        continue;
    }
    // CRLF em fonte de cron já derrubou os 15 jobs da VPS por 14h30 em silêncio.
    if (str_contains((string) file_get_contents($caminho), "\r")) {
        $comCrlf[] = $script;
    }
}
$check('os scripts de cron existem', $faltando === [], implode(' | ', $faltando));
$check('🔴 nenhum script de cron tem CRLF', $comCrlf === [], implode(' | ', $comCrlf));

// ─────────────────────── 4b. os seis seguem o mesmo contrato estrutural
//
// `intelligence_geo.sh` era o unico sem lock e com log em outro diretorio. As
// duas ausencias falham calado:
//
//   · sem lock, duas execucoes sobrepostas intercalam o `UPDATE is_current = 0`
//     de uma com o INSERT da outra, e a tela fica sem linha vigente;
//   · com o log fora de `storage/logs/cron`, quem for investigar ve cinco jobs
//     no diretorio e conclui que o sexto nao roda.
//
// Verificado aqui porque a divergencia so aparece comparando os seis lado a
// lado — lendo um script isolado, nada parece errado.
$semLock = [];
$logForaDoPadrao = [];
foreach ($jobParaScript as $job => $script) {
    $caminho = $root . '/cron/hostinger/' . $script;
    if (!is_file($caminho)) {
        continue; // ja reportado acima
    }
    $fonte = (string) file_get_contents($caminho);
    if (!str_contains($fonte, 'flock')) {
        $semLock[] = $script;
    }
    if (!str_contains($fonte, 'storage/logs/cron')) {
        $logForaDoPadrao[] = $script;
    }
}
$check(
    '🔴 todo cron de inteligência toma lock (execuções sobrepostas corrompem o vigente)',
    $semLock === [],
    implode(' | ', $semLock),
);
$check(
    '🔴 todo cron de inteligência loga em storage/logs/cron',
    $logForaDoPadrao === [],
    implode(' | ', $logForaDoPadrao),
);
// O que protege produção é o que o DEPLOY entrega, não o que está na árvore.
//
// O deploy sai de `git archive`, que aplica o `.gitattributes`. Checar a árvore
// de trabalho dá falso positivo em qualquer máquina Windows cujo editor grave
// CRLF — inclusive foi o que aconteceu ao escrever este guard: a árvore tinha
// CRLF, o `git archive` entregava LF puro, e o guard bloqueou uma build
// legítima. Alarme falso treina a ignorar o alarme.
// ATENÇÃO ao que este check protege de verdade. Numa máquina Windows `core.eol`
// vale `native`, então o `git archive` converte para CRLF TODO arquivo marcado
// como texto que não tenha `eol=lf` explícito. O arquivo pode estar em LF no
// disco E no blob e ainda assim chegar em CRLF na VPS.
//
// Foi o que aconteceu com a config do logrotate: por não ter extensão, nem
// `*.sh` nem `*.cron` a alcançavam, e ela subiu com CRLF — que faz o logrotate
// ignorar o arquivo inteiro, o mesmo modo de falha silencioso do cron.d.
//
// Ou seja, a lista abaixo não é decoração: todo arquivo que vá para a VPS e
// seja lido por um parser que quebra com CR precisa de uma linha aqui.
$declaracoes = (string) @file_get_contents($root . '/.gitattributes');
$padroesObrigatorios = [
    '*.cron'                       => '/^\*\.cron\s+text\s+eol=lf/m',
    '*.sh'                         => '/^\*\.sh\s+text\s+eol=lf/m',
    'tools/deploy/vps/logrotate/*' => '#^tools/deploy/vps/logrotate/\*\s+text\s+eol=lf#m',
];
$semDeclaracao = [];
foreach ($padroesObrigatorios as $rotulo => $regex) {
    if (preg_match($regex, $declaracoes) !== 1) {
        $semDeclaracao[] = $rotulo;
    }
}
$check(
    '🔴 .gitattributes força LF em tudo que a VPS lê linha a linha',
    $declaracoes !== '' && $semDeclaracao === [],
    $declaracoes === '' ? '.gitattributes ausente' : 'sem eol=lf: ' . implode(', ', $semDeclaracao),
);

// E o que o artefato ENTREGA, não o que a árvore tem: é o archive que vai para
// produção. Conferir a árvore dá falso positivo em qualquer editor Windows —
// aconteceu ao escrever este guard e bloqueou uma build legítima.
$comCrNoArtefato = [];
$naoInspecionados = [];
foreach (['tools/deploy/vps/biomie.cron', 'tools/deploy/vps/logrotate/biomie'] as $caminho) {
    $saidaArchive = [];
    $rc = 0;
    // Sem `2>/dev/null`: este guard roda na máquina de deploy, onde o `exec()`
    // do PHP passa pelo cmd.exe, que trata `/dev/null` como caminho e falha com
    // "O sistema não pode encontrar o caminho especificado" — o check reprovaria
    // pelo redirecionamento, não pelo conteúdo.
    exec('git -C ' . escapeshellarg($root) . ' archive HEAD ' . escapeshellarg($caminho) . ' | tar -xO', $saidaArchive, $rc);
    if ($rc !== 0) {
        $naoInspecionados[] = $caminho;
    } elseif (str_contains(implode("\n", $saidaArchive), "\r")) {
        $comCrNoArtefato[] = $caminho;
    }
}
$check(
    '🔴 os arquivos de config que o git archive entrega são LF puro',
    $comCrNoArtefato === [] && $naoInspecionados === [],
    $naoInspecionados !== []
        ? 'não consegui inspecionar: ' . implode(', ', $naoInspecionados)
        : 'CR no artefato de deploy: ' . implode(', ', $comCrNoArtefato),
);

// ─────────────────── 5. o forecast persistido tem tabela e destino declarado
$configPy = (string) @file_get_contents($root . '/services/python/biomie_intel/config.py');
$check(
    'intelligence_forecasts está declarada como gravável no Python',
    str_contains($configPy, 'intelligence_forecasts'),
);
$check(
    'existe migration criando intelligence_forecasts',
    glob($root . '/database/migrations/*IntelligenceForecasts.php') !== [],
);

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
