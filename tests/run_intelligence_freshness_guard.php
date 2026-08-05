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
$check(
    'a própria fonte do cron.d é LF puro',
    !str_contains($cron, "\r"),
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
