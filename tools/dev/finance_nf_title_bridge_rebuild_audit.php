<?php

declare(strict_types=1);

/**
 * FASE 2 — FIN.R4.4.1: Audit de reconstrução da bridge NF×título.
 *
 * Read-only em modo --dry-run (padrão). Simula a reconstrução da bridge
 * mostrando quais títulos ganhariam resolução de NF, pedido, vendedor e cliente.
 *
 * Não executa INSERT/UPDATE sem o flag --apply e sem o mecanismo seguro existente.
 * Para aplicar a bridge, use tools/dev/bridge_nf_titulo_build.php (builder existente).
 *
 * Uso:
 *   php tools/dev/finance_nf_title_bridge_rebuild_audit.php \
 *       --start=2026-05-01 --end=2026-05-31 --dry-run --pretty --sample=30
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;

$opts    = getopt('', ['start:', 'end:', 'dry-run', 'apply', 'pretty', 'json', 'sample:', 'help']);
if (isset($opts['help'])) {
    echo "Usage: php finance_nf_title_bridge_rebuild_audit.php --start=YYYY-MM-DD --end=YYYY-MM-DD [--dry-run|--apply] [--pretty] [--sample=30]\n";
    echo "  (sem flag)  Apenas simula (padrão seguro)\n";
    echo "  --dry-run   Apenas simula, não aplica nada\n";
    echo "  --apply     Executa simulação e depois delega ao bridge_nf_titulo_build.php\n";
    exit(0);
}

$start   = (string) ($opts['start']   ?? date('Y-m-01'));
$end     = (string) ($opts['end']     ?? date('Y-m-d'));
$pretty  = isset($opts['pretty']);
$asJson  = isset($opts['json']);
$sample  = max(1, min(100, (int) ($opts['sample'] ?? 30)));
$applyMode = isset($opts['apply']) && !isset($opts['dry-run']);
$dryRun  = true; // audit always simulates; apply delegates to bridge_nf_titulo_build.php

// ── Connect ───────────────────────────────────────────────────────────────────
try {
    $config = new Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $conn)) {
        fwrite(STDERR, "ERROR: Banco de dados não configurado.\n");
        exit(1);
    }
    $pdo = Database::connect($config, $conn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    fwrite(STDERR, "ERROR connecting: " . $e->getMessage() . "\n");
    exit(1);
}

function bldExists(PDO $pdo, string $tbl): bool
{
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t LIMIT 1");
        $s->execute(['t' => $tbl]);
        return (int) $s->fetchColumn() > 0;
    } catch (\Throwable) { return false; }
}

function bldQ(PDO $pdo, string $sql, array $p = []): array
{
    try {
        $s = $pdo->prepare($sql);
        $s->execute($p);
        return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable) { return []; }
}

function bldScalar(PDO $pdo, string $sql, array $p = []): int
{
    try {
        $s = $pdo->prepare($sql);
        $s->execute($p);
        return (int) ($s->fetchColumn() ?: 0);
    } catch (\Throwable) { return 0; }
}

$hasNf      = bldExists($pdo, 'biomie_notas_fiscais_canonicas');
$hasBridgeI = bldExists($pdo, 'biomie_bridge_nf_titulo_items');
$hasBridgeS = bldExists($pdo, 'biomie_bridge_nf_titulo_summary');
$hasPedido  = bldExists($pdo, 'biomie_pedidos_canonicos');
$hasCliente = bldExists($pdo, 'biomie_clientes_dim');
$hasBuilder = file_exists(dirname(__DIR__, 2) . '/tools/dev/bridge_nf_titulo_build.php');

// ── Current bridge state ──────────────────────────────────────────────────────
$currentSummaryRows = $hasBridgeS ? bldScalar($pdo,
    "SELECT COUNT(*) FROM biomie_bridge_nf_titulo_summary bs
     JOIN biomie_titulos_financeiros_canonicos t ON t.titulo_key = bs.titulo_key
     WHERE t.data_competencia BETWEEN :s AND :e",
    ['s' => $start, 'e' => $end]
) : 0;

$currentItemRows = $hasBridgeI ? bldScalar($pdo,
    "SELECT COUNT(*) FROM biomie_bridge_nf_titulo_items bi
     JOIN biomie_titulos_financeiros_canonicos t ON t.titulo_key = bi.titulo_key
     WHERE t.data_competencia BETWEEN :s AND :e",
    ['s' => $start, 'e' => $end]
) : 0;

$totalTitulos = bldScalar($pdo,
    "SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos WHERE data_competencia BETWEEN :s AND :e",
    ['s' => $start, 'e' => $end]
);

// ── Simulate bridge reconstruction ────────────────────────────────────────────
// Strategy: chave_nfe_exato (confidence 100) — t.chave_nfe = nf.chave_nfe
$potentialChaveNfe = $hasNf ? bldScalar($pdo,
    "SELECT COUNT(DISTINCT t.titulo_key)
     FROM biomie_titulos_financeiros_canonicos t
     JOIN biomie_notas_fiscais_canonicas nf ON t.chave_nfe = nf.chave_nfe AND t.chave_nfe IS NOT NULL AND t.chave_nfe != ''
     WHERE t.data_competencia BETWEEN :s AND :e",
    ['s' => $start, 'e' => $end]
) : 0;

// Strategy: nota_key_as_chave_nfe (confidence 100) — t.nota_key = nf.chave_nfe
$potentialNotaKeyAsChave = $hasNf ? bldScalar($pdo,
    "SELECT COUNT(DISTINCT t.titulo_key)
     FROM biomie_titulos_financeiros_canonicos t
     JOIN biomie_notas_fiscais_canonicas nf ON t.nota_key = nf.chave_nfe AND CHAR_LENGTH(t.nota_key) = 44
     WHERE t.data_competencia BETWEEN :s AND :e",
    ['s' => $start, 'e' => $end]
) : 0;

// Union of both strategies (total potential)
$potentialTotal = $hasNf ? bldScalar($pdo,
    "SELECT COUNT(DISTINCT t.titulo_key)
     FROM biomie_titulos_financeiros_canonicos t
     JOIN biomie_notas_fiscais_canonicas nf ON
       (t.chave_nfe = nf.chave_nfe AND t.chave_nfe IS NOT NULL AND t.chave_nfe != '')
       OR (t.nota_key = nf.chave_nfe AND CHAR_LENGTH(t.nota_key) = 44)
     WHERE t.data_competencia BETWEEN :s AND :e",
    ['s' => $start, 'e' => $end]
) : 0;

// New bridge rows that would be added (not in bridge_items yet)
$newBridgeRows = ($hasNf && $hasBridgeI) ? bldScalar($pdo,
    "SELECT COUNT(DISTINCT t.titulo_key)
     FROM biomie_titulos_financeiros_canonicos t
     JOIN biomie_notas_fiscais_canonicas nf ON
       (t.chave_nfe = nf.chave_nfe AND t.chave_nfe IS NOT NULL AND t.chave_nfe != '')
       OR (t.nota_key = nf.chave_nfe AND CHAR_LENGTH(t.nota_key) = 44)
     WHERE t.data_competencia BETWEEN :s AND :e
       AND NOT EXISTS (SELECT 1 FROM biomie_bridge_nf_titulo_items bi WHERE bi.titulo_key = t.titulo_key)",
    ['s' => $start, 'e' => $end]
) : $potentialTotal;

// What would resolve — NF
$wouldResolveNf = $potentialTotal;

// What would resolve — Pedido (NF with pedido_id_omie)
$wouldResolvePedido = $hasNf ? bldScalar($pdo,
    "SELECT COUNT(DISTINCT t.titulo_key)
     FROM biomie_titulos_financeiros_canonicos t
     JOIN biomie_notas_fiscais_canonicas nf ON
       (t.chave_nfe = nf.chave_nfe AND t.chave_nfe IS NOT NULL AND t.chave_nfe != '')
       OR (t.nota_key = nf.chave_nfe AND CHAR_LENGTH(t.nota_key) = 44)
     WHERE t.data_competencia BETWEEN :s AND :e
       AND nf.pedido_id_omie IS NOT NULL AND nf.pedido_id_omie > 0",
    ['s' => $start, 'e' => $end]
) : 0;

// What would resolve — Vendedor
$wouldResolveVendedor = $hasNf ? bldScalar($pdo,
    "SELECT COUNT(DISTINCT t.titulo_key)
     FROM biomie_titulos_financeiros_canonicos t
     JOIN biomie_notas_fiscais_canonicas nf ON
       (t.chave_nfe = nf.chave_nfe AND t.chave_nfe IS NOT NULL AND t.chave_nfe != '')
       OR (t.nota_key = nf.chave_nfe AND CHAR_LENGTH(t.nota_key) = 44)
     WHERE t.data_competencia BETWEEN :s AND :e
       AND nf.codigo_vendedor_omie IS NOT NULL AND nf.codigo_vendedor_omie != '' AND nf.codigo_vendedor_omie != '0'",
    ['s' => $start, 'e' => $end]
) : 0;

// What would resolve — Cliente (via NF ou clientes_dim)
$wouldResolveCliente = $hasNf ? bldScalar($pdo,
    "SELECT COUNT(DISTINCT t.titulo_key)
     FROM biomie_titulos_financeiros_canonicos t
     JOIN biomie_notas_fiscais_canonicas nf ON
       (t.chave_nfe = nf.chave_nfe AND t.chave_nfe IS NOT NULL AND t.chave_nfe != '')
       OR (t.nota_key = nf.chave_nfe AND CHAR_LENGTH(t.nota_key) = 44)
     WHERE t.data_competencia BETWEEN :s AND :e
       AND nf.codigo_cliente_omie IS NOT NULL AND nf.codigo_cliente_omie != ''",
    ['s' => $start, 'e' => $end]
) : 0;

// Potential duplicates (same titulo_key matches multiple NFs)
$potentialDuplicates = $hasNf ? bldScalar($pdo,
    "SELECT COUNT(*)
     FROM (
       SELECT t.titulo_key, COUNT(DISTINCT nf.nota_key) AS cnt
       FROM biomie_titulos_financeiros_canonicos t
       JOIN biomie_notas_fiscais_canonicas nf ON
         (t.chave_nfe = nf.chave_nfe AND t.chave_nfe IS NOT NULL AND t.chave_nfe != '')
         OR (t.nota_key = nf.chave_nfe AND CHAR_LENGTH(t.nota_key) = 44)
       WHERE t.data_competencia BETWEEN :s AND :e
       GROUP BY t.titulo_key
       HAVING cnt > 1
     ) dups",
    ['s' => $start, 'e' => $end]
) : 0;

// ── Examples ──────────────────────────────────────────────────────────────────
$examples = $hasNf ? bldQ($pdo,
    "SELECT t.titulo_key, t.chave_nfe, t.nota_key,
            nf.nota_key AS nf_nota_key, nf.numero_nf,
            nf.codigo_vendedor_omie, nf.nome_vendedor,
            nf.codigo_cliente_omie, nf.nome_cliente,
            nf.pedido_id_omie,
            CASE
              WHEN t.chave_nfe = nf.chave_nfe THEN 'chave_nfe_exato'
              WHEN t.nota_key = nf.chave_nfe THEN 'nota_key_as_chave_nfe'
              ELSE 'outro'
            END AS match_strategy,
            CASE WHEN bi.titulo_key IS NOT NULL THEN 1 ELSE 0 END AS ja_na_bridge
     FROM biomie_titulos_financeiros_canonicos t
     JOIN biomie_notas_fiscais_canonicas nf ON
       (t.chave_nfe = nf.chave_nfe AND t.chave_nfe IS NOT NULL AND t.chave_nfe != '')
       OR (t.nota_key = nf.chave_nfe AND CHAR_LENGTH(t.nota_key) = 44)
     LEFT JOIN biomie_bridge_nf_titulo_items bi ON bi.titulo_key = t.titulo_key
     WHERE t.data_competencia BETWEEN :s AND :e
     LIMIT :lim",
    ['s' => $start, 'e' => $end, 'lim' => $sample]
) : [];

// ── Build result ──────────────────────────────────────────────────────────────
$pct = static fn(int $n, int $tot): float => $tot > 0 ? round($n / $tot * 100, 1) : 0.0;

$result = [
    'ok'       => true,
    'mode'     => 'dry_run',
    'period'   => ['start' => $start, 'end' => $end, 'generated_at' => date(DATE_ATOM)],
    'tables'   => [
        'biomie_notas_fiscais_canonicas'  => $hasNf,
        'biomie_bridge_nf_titulo_items'   => $hasBridgeI,
        'biomie_bridge_nf_titulo_summary' => $hasBridgeS,
        'biomie_pedidos_canonicos'        => $hasPedido,
        'biomie_clientes_dim'             => $hasCliente,
        'bridge_builder_tool'             => $hasBuilder,
    ],
    'current_state' => [
        'total_titulos'            => $totalTitulos,
        'bridge_summary_rows'      => $currentSummaryRows,
        'bridge_items_rows'        => $currentItemRows,
        'pct_titulos_em_bridge'    => $pct($currentItemRows > 0 ? $currentSummaryRows : 0, $totalTitulos),
    ],
    'simulation' => [
        'potential_chave_nfe_exato'        => $potentialChaveNfe,
        'potential_nota_key_as_chave_nfe'  => $potentialNotaKeyAsChave,
        'potential_total'                  => $potentialTotal,
        'new_bridge_rows_to_add'           => $newBridgeRows,
        'would_resolve_nf'                 => ['count' => $wouldResolveNf,     'pct' => $pct($wouldResolveNf, $totalTitulos)],
        'would_resolve_pedido'             => ['count' => $wouldResolvePedido,  'pct' => $pct($wouldResolvePedido, $totalTitulos)],
        'would_resolve_vendedor'           => ['count' => $wouldResolveVendedor,'pct' => $pct($wouldResolveVendedor, $totalTitulos)],
        'would_resolve_cliente'            => ['count' => $wouldResolveCliente, 'pct' => $pct($wouldResolveCliente, $totalTitulos)],
        'potential_duplicates'             => $potentialDuplicates,
    ],
    'examples'    => $examples,
    'next_action' => $applyMode
        ? 'Modo --apply: delegando ao bridge_nf_titulo_build.php'
        : ($hasBuilder
            ? 'Para aplicar: php tools/dev/finance_nf_title_bridge_rebuild_audit.php --apply --start=... --end=...'
            : 'Builder não encontrado — criar bridge_nf_titulo_build.php antes de aplicar.'),
    'diagnostics' => ['source' => 'finance_nf_title_bridge_rebuild_audit', 'apply_mode' => $applyMode],
];

if ($asJson || !$pretty) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    if (!$applyMode) {
        exit(0);
    }
}

// ── Pretty output ─────────────────────────────────────────────────────────────
$G = "\033[32m"; $R = "\033[31m"; $Y = "\033[33m"; $D = "\033[0m"; $bold = "\033[1m";
$ok   = fn(string $s) => "{$G}✔{$D} {$s}";
$bad  = fn(string $s) => "{$R}✖{$D} {$s}";
$warn = fn(string $s) => "{$Y}⚠{$D} {$s}";

echo "\n{$bold}┌──────────────────────────────────────────────────────────────────┐{$D}\n";
echo "{$bold}│  FIN.R4.4.1 — Bridge NF×Título Rebuild Audit (DRY-RUN)            │{$D}\n";
echo "{$bold}│  Período: {$start} → {$end}                                         │{$D}\n";
echo "{$bold}└──────────────────────────────────────────────────────────────────┘{$D}\n\n";

echo "{$bold}ESTADO ATUAL{$D}\n";
echo "  Títulos no período   : {$totalTitulos}\n";
echo "  Bridge summary rows  : {$currentSummaryRows}\n";
echo "  Bridge items rows    : {$currentItemRows}\n";
echo "  Builder disponível   : " . ($hasBuilder ? $ok("sim") : $warn("não encontrado")) . "\n";

echo "\n{$bold}SIMULAÇÃO DE RECONSTRUÇÃO{$D}\n";
$s = $result['simulation'];
echo "  Via chave_nfe_exato          : {$s['potential_chave_nfe_exato']} títulos\n";
echo "  Via nota_key_as_chave_nfe    : {$s['potential_nota_key_as_chave_nfe']} títulos\n";
echo "  Total potencial              : {$s['potential_total']} títulos\n";
echo "  Novos rows na bridge         : {$s['new_bridge_rows_to_add']} títulos sem bridge\n";
echo "  Duplicatas potenciais        : " . ($s['potential_duplicates'] > 0 ? $warn($s['potential_duplicates']) : $ok("0")) . "\n";

echo "\n{$bold}IMPACTO SE BRIDGE RECONSTRUÍDA{$D}\n";
echo "  Resolução NF       : " . $ok("{$s['would_resolve_nf']['count']} ({$s['would_resolve_nf']['pct']}%)") . "\n";
echo "  Resolução Pedido   : " . ($s['would_resolve_pedido']['count'] > 0 ? $ok("{$s['would_resolve_pedido']['count']} ({$s['would_resolve_pedido']['pct']}%)") : $warn("0% — NFs sem pedido_id_omie")) . "\n";
echo "  Resolução Vendedor : " . ($s['would_resolve_vendedor']['count'] > 0 ? $ok("{$s['would_resolve_vendedor']['count']} ({$s['would_resolve_vendedor']['pct']}%)") : $warn("0% — NFs sem vendedor")) . "\n";
echo "  Resolução Cliente  : " . ($s['would_resolve_cliente']['count'] > 0 ? $ok("{$s['would_resolve_cliente']['count']} ({$s['would_resolve_cliente']['pct']}%)") : $warn("0%")) . "\n";

echo "\n{$bold}PRÓXIMA AÇÃO{$D}\n";
if ($applyMode) {
    echo "  {$G}→ Modo --apply: delegando para bridge_nf_titulo_build.php{$D}\n";
} else {
    echo "  {$Y}→{$D} {$result['next_action']}\n";
}

if ($examples !== []) {
    echo "\n{$bold}EXEMPLOS{$D}\n";
    foreach (array_slice($examples, 0, 5) as $ex) {
        $inBridge = $ex['ja_na_bridge'] ? $G . "✔bridge" . $D : $Y . "⚠sem bridge" . $D;
        echo "  {$ex['titulo_key']} | NF={$ex['numero_nf']} | strategy={$ex['match_strategy']} | {$inBridge}\n";
        echo "    vendedor={$ex['codigo_vendedor_omie']} | cliente={$ex['codigo_cliente_omie']} | pedido={$ex['pedido_id_omie']}\n";
    }
}

// ── Delegate to builder when --apply ─────────────────────────────────────────
if ($applyMode) {
    $builderPath = dirname(__DIR__, 2) . '/tools/dev/bridge_nf_titulo_build.php';

    if (!file_exists($builderPath)) {
        echo "\n{$R}✖ ERRO: bridge_nf_titulo_build.php não encontrado — não é possível aplicar.{$D}\n";
        exit(1);
    }

    if ($potentialTotal === 0 && $currentItemRows > 0) {
        echo "\n{$Y}⚠ Nenhuma nova oportunidade de match detectada. Bridge já está atualizada para o período.{$D}\n";
        echo "  Sem ação necessária via builder.\n\n";
        exit(0);
    }

    echo "\n{$bold}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━{$D}\n";
    echo "{$bold}  Executando bridge_nf_titulo_build.php --start={$start} --end={$end}{$D}\n";
    echo "{$bold}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━{$D}\n\n";

    $php = PHP_BINARY;
    $cmd = escapeshellarg($php)
        . ' ' . escapeshellarg($builderPath)
        . ' --start=' . escapeshellarg($start)
        . ' --end='   . escapeshellarg($end)
        . ($pretty ? ' --pretty' : '');

    $ds = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $ds, $pipes);
    if (!is_resource($process)) {
        echo "{$R}✖ Falha ao iniciar bridge_nf_titulo_build.php via proc_open.{$D}\n";
        exit(1);
    }
    fclose($pipes[0]);
    $builderOut = stream_get_contents($pipes[1]);
    $builderErr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $builderExit = proc_close($process);

    echo $builderOut;
    if ($builderErr !== '') {
        fwrite(STDERR, $builderErr);
    }

    if ($builderExit !== 0) {
        echo "\n{$R}✖ bridge_nf_titulo_build.php saiu com código {$builderExit}.{$D}\n";
    } else {
        echo "\n{$G}✔ bridge_nf_titulo_build.php concluído. Execute materialize_marts.php para atualizar summary.{$D}\n";
    }
    exit($builderExit);
}

echo "\n";
exit(0);
