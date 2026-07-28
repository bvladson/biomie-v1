<?php

declare(strict_types=1);

/**
 * bin/omie_engine_probe.php — Prova das Fases 6.2 (finance incremental) e
 * 6.3 (coordenador global de quota), de forma ISOLADA e sem tocar a Omie.
 *
 * 6.2: usa reflexão para invocar FinanceSyncCoordinator::buildMonthlyRanges e
 *      confirma que janelas estreitas (delta) NÃO são mais expandidas para o
 *      mês inteiro, preservando chunking mensal apenas em backfills longos.
 *
 * 6.3: aponta o coordenador global para um diretório TEMPORÁRIO (via
 *      BIOMIE_OMIE_GLOBAL_DIR) para não poluir o estado de produção, e verifica:
 *      teto global, registro de slots na janela compartilhada, enforcement do
 *      teto e propagação de cooldown entre empresas.
 *
 * Uso: php bin/omie_engine_probe.php   (exit 0 = tudo PASS)
 */

use BIomie\Cache;
use BIomie\Logger;
use BIomie\OmieRateLimiter;
use BIomie\Sync\FinanceSyncCoordinator;

require __DIR__ . '/../app/Bootstrap.php';
BIomie\Bootstrap::init();

$pass = 0;
$fail = 0;
$lines = [];

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $lines;
    if ($ok) {
        $pass++;
        $lines[] = "  \033[32mPASS\033[0m  {$label}" . ($detail !== '' ? "  — {$detail}" : '');
    } else {
        $fail++;
        $lines[] = "  \033[31mFAIL\033[0m  {$label}" . ($detail !== '' ? "  — {$detail}" : '');
    }
}

/** @return list<array{start:string,end:string}> */
function ranges(string $start, string $end): array
{
    $ref = new ReflectionClass(FinanceSyncCoordinator::class);
    $obj = $ref->newInstanceWithoutConstructor();
    $m = $ref->getMethod('buildMonthlyRanges');
    if (PHP_VERSION_ID < 80100) {
        $m->setAccessible(true);
    }
    /** @var list<array{start:string,end:string}> $r */
    $r = $m->invoke($obj, $start, $end);
    return $r;
}

function days(array $r): int
{
    $total = 0;
    foreach ($r as $w) {
        $total += (int) ((strtotime($w['end']) - strtotime($w['start'])) / 86400) + 1;
    }
    return $total;
}

echo "\n=== Fase 6.2 — Finance incremental (buildMonthlyRanges clampado) ===\n";

// 1) Delta estreito dentro de um mês: NÃO expande para o mês inteiro.
$r = ranges('2026-06-27', '2026-06-28');
check(
    'delta estreito (27→28 jun) gera 1 janela exata, não o mês inteiro',
    count($r) === 1 && $r[0]['start'] === '2026-06-27' && $r[0]['end'] === '2026-06-28',
    json_encode($r)
);
check(
    'janela do delta = 2 dias (antes seriam 30) — economia real de quota',
    days($r) === 2,
    'dias=' . days($r)
);

// 2) Delta cruzando virada de mês: continua chunkado por mês, clampado.
$r = ranges('2026-05-31', '2026-06-01');
check(
    'virada de mês (31 mai → 1 jun) gera 2 chunks clampados',
    count($r) === 2
        && $r[0] === ['start' => '2026-05-31', 'end' => '2026-05-31']
        && $r[1] === ['start' => '2026-06-01', 'end' => '2026-06-01'],
    json_encode($r)
);

// 3) Backfill longo: chunking mensal preservado, bordas clampadas.
$r = ranges('2026-01-15', '2026-03-20');
check(
    'backfill (15 jan → 20 mar) preserva 3 chunks mensais com bordas reais',
    count($r) === 3
        && $r[0] === ['start' => '2026-01-15', 'end' => '2026-01-31']
        && $r[1] === ['start' => '2026-02-01', 'end' => '2026-02-28']
        && $r[2] === ['start' => '2026-03-01', 'end' => '2026-03-20'],
    json_encode($r)
);

// 4) Robustez: datas invertidas não quebram (normaliza).
$r = ranges('2026-06-28', '2026-06-27');
check('datas invertidas são normalizadas (sem erro)', count($r) === 1, json_encode($r));

echo implode("\n", array_splice($lines, 0)) . "\n";

echo "\n=== Fase 6.3 — Coordenador GLOBAL de quota (isolado em tmp) ===\n";

// Isola o estado global num diretório temporário — zero impacto em produção.
$tmpDir = rtrim(sys_get_temp_dir(), '/\\') . '/biomie_global_probe_' . getmypid();
@mkdir($tmpDir, 0775, true);
putenv('BIOMIE_OMIE_GLOBAL_DIR=' . $tmpDir);
putenv('BIOMIE_OMIE_GLOBAL_RPM=5');        // teto baixo só para o teste de enforcement
putenv('BIOMIE_OMIE_GLOBAL_COOLDOWN=1');

$logger = new Logger(rtrim(sys_get_temp_dir(), '/\\') . '/biomie_global_probe_log');
$cacheA = new Cache($tmpDir . '/empresaA', true);
$cacheB = new Cache($tmpDir . '/empresaB', true);
$rlA = new OmieRateLimiter($cacheA, $logger, 'APPKEY-EMPRESA-A', 'cli');
$rlB = new OmieRateLimiter($cacheB, $logger, 'APPKEY-EMPRESA-B', 'cli');

$policy = ['rate_limit_per_minute' => 240, 'runtime' => ['max_sleep_ms' => 50]];

// Status inicial: teto lido, janela zerada.
$st = OmieRateLimiter::globalStatus();
check('teto global lido do ambiente', $st['per_minute_ceiling'] === 5, 'ceiling=' . $st['per_minute_ceiling']);
check('janela global inicia vazia', $st['used_last_minute'] === 0, 'used=' . $st['used_last_minute']);

// Duas empresas distintas consomem a MESMA janela global compartilhada.
$rlA->throttle('geral/empresas/ListarClientes', $policy);
$rlB->throttle('produtos/produto/ListarProdutos', $policy);
$rlA->throttle('financas/contareceber/ListarContasReceber', $policy);
$st = OmieRateLimiter::globalStatus();
check(
    'empresas A e B compartilham a janela global (3 slots somados)',
    $st['used_last_minute'] === 3,
    'used=' . $st['used_last_minute']
);

// Enforcement: com teto=5 e max_sleep curto, a 6ª chamada no mesmo minuto
// deve estourar o teto global e lançar (reagendar), provando que LIMITA.
$rlB->throttle('x/y/z1', $policy);
$rlA->throttle('x/y/z2', $policy); // chega a 5
$enforced = false;
try {
    $rlA->throttle('x/y/z3', $policy); // 6ª — acima do teto, max_sleep insuficiente
} catch (\RuntimeException $e) {
    $enforced = str_contains($e->getMessage(), 'global');
}
check('teto global é ENFORÇADO (6ª chamada acima do teto reagenda)', $enforced);

// Propagação de cooldown: 429 numa empresa esfria TODAS.
$rlA->registerServerCooldown(2, 'probe_429');
$st = OmieRateLimiter::globalStatus();
check(
    'cooldown propaga para o nível global (429 de A esfria todas)',
    $st['cooldown_remaining_ms'] > 0 && $st['cooldown_remaining_ms'] <= 2000,
    'remaining_ms=' . $st['cooldown_remaining_ms']
);
// Empresa B (app_key diferente) deve sentir o cooldown global ao tentar chamar.
$blockedByGlobal = false;
try {
    $rlB->throttle('x/y/after_cooldown', ['rate_limit_per_minute' => 240, 'runtime' => ['max_sleep_ms' => 10]]);
} catch (\RuntimeException $e) {
    $blockedByGlobal = str_contains($e->getMessage(), 'global') || str_contains($e->getMessage(), 'ativo');
}
check('empresa B (app_key distinta) recua pelo cooldown global de A', $blockedByGlobal);

echo implode("\n", $lines) . "\n";

// Limpeza do estado temporário.
@array_map('unlink', glob($tmpDir . '/*') ?: []);
@array_map('unlink', glob($tmpDir . '/*/*') ?: []);

echo "\n";
echo $fail === 0
    ? "\033[32m[OK] TODOS OS CHECKS PASSARAM ({$pass}/" . ($pass + $fail) . ")\033[0m\n"
    : "\033[31m[X] {$fail} FALHA(S) — {$pass}/" . ($pass + $fail) . " PASS\033[0m\n";

exit($fail === 0 ? 0 : 1);
