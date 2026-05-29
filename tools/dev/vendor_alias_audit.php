<?php

declare(strict_types=1);

/**
 * COM.R1.5 — Auditoria de Aliases de Vendedores com Consciência de Canal.
 *
 * Verifica que CAIO - REV e CAIO - VAR nunca são resolvidos como o mesmo vendedor.
 * Detecta nomes ambíguos, cross-channel e variantes de formatação.
 *
 * Uso:
 *   php tools/dev/vendor_alias_audit.php --start=YYYY-MM-DD --end=YYYY-MM-DD
 *       [--pretty] [--json]
 *
 * Read-only.
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Support\VendorAliasResolver;

$opts     = getopt('', ['start:', 'end:', 'pretty', 'json']);
$start    = (string) ($opts['start'] ?? date('Y-01-01'));
$end      = (string) ($opts['end']   ?? date('Y-m-d'));
$pretty   = isset($opts['pretty']);
$jsonMode = isset($opts['json']);

$t0  = microtime(true);
$res = new VendorAliasResolver();

// ── DB ────────────────────────────────────────────────────────────────────────
try {
    $config = new Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    fwrite(STDERR, 'DB: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

function tblAA(PDO $pdo, string $t): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t LIMIT 1");
    $s->execute([':t' => $t]);
    return (int)$s->fetchColumn() > 0;
}

$hasNfs     = tblAA($pdo, 'biomie_notas_fiscais_canonicas');
$hasPedidos = tblAA($pdo, 'biomie_pedidos_canonicos');
$hasVendors = tblAA($pdo, 'biomie_vendedores_omie');

// ── Load vendor names from NFs + pedidos ─────────────────────────────────────
$vendorNamesInPeriod = [];
$nfDateE = "COALESCE(data_competencia, data_emissao)";

if ($hasNfs) {
    $sNf = $pdo->prepare(
        "SELECT DISTINCT nome_vendedor FROM biomie_notas_fiscais_canonicas
         WHERE {$nfDateE} BETWEEN :start AND :end AND status_fiscal = 'autorizada'"
    );
    $sNf->execute([':start' => $start, ':end' => $end]);
    foreach ($sNf->fetchAll(PDO::FETCH_COLUMN) as $n) {
        $vendorNamesInPeriod[(string)$n] = true;
    }
}

if ($hasPedidos) {
    $sP = $pdo->prepare(
        "SELECT DISTINCT nome_vendedor FROM biomie_pedidos_canonicos
         WHERE data_emissao BETWEEN :start AND :end"
    );
    $sP->execute([':start' => $start, ':end' => $end]);
    foreach ($sP->fetchAll(PDO::FETCH_COLUMN) as $n) {
        $vendorNamesInPeriod[(string)$n] = true;
    }
}

// ── Load canonical vendor roster ──────────────────────────────────────────────
$canonicalVendors = [];
if ($hasVendors) {
    $sV = $pdo->query("SELECT codigo_omie, nome, ativo FROM biomie_vendedores_omie ORDER BY nome");
    foreach ($sV->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $canonicalVendors[] = $row;
    }
}

// ── Resolve all names seen in the period ──────────────────────────────────────
$allNames   = array_keys($vendorNamesInPeriod);
$resolved   = [];
$caioRecords = ['rev' => [], 'var' => [], 'ambiguous' => []];
$crossChannelPairs = [];
$aliasCandidates = [];

foreach ($allNames as $name) {
    if (trim($name) === '') continue;
    $r = $res->resolve($name);
    $resolved[$name] = $r;

    if (!empty($r['alias_candidate'])) {
        $aliasCandidates[] = [
            'name' => $name,
            'suggested_alias' => $r['suggested_alias'] ?? null,
            'requires_manual_approval' => (bool) ($r['requires_manual_approval'] ?? true),
            'channel_match' => (bool) ($r['channel_match'] ?? false),
            'warnings' => $r['warnings'] ?? [],
        ];
    }

    $channel  = $r['vendor_channel'];
    $baseName = mb_strtolower(trim($r['base_name']));

    // Track CAIO-specific records
    if (str_contains($baseName, 'caio')) {
        if ($channel === VendorAliasResolver::CHANNEL_REV)  $caioRecords['rev'][]  = $name;
        elseif ($channel === VendorAliasResolver::CHANNEL_VAR) $caioRecords['var'][]  = $name;
        else $caioRecords['ambiguous'][] = $name;
    }
}

// ── Detect cross-channel pairs ────────────────────────────────────────────────
$byBase = [];
foreach ($resolved as $name => $r) {
    $base    = trim($r['base_name']);
    $channel = $r['vendor_channel'];
    if ($base === '' || $channel === VendorAliasResolver::CHANNEL_OUTRO) continue;
    $byBase[$base][] = ['name' => $name, 'channel' => $channel];
}

foreach ($byBase as $base => $entries) {
    $channels = array_unique(array_column($entries, 'channel'));
    if (count($channels) > 1) {
        // Multiple distinct channels for same base — cross-channel detected
        $crossChannelPairs[] = ['base' => $base, 'entries' => $entries];
    }
}

// ── Verify CAIO-REV != CAIO-VAR ───────────────────────────────────────────────
$caioRevVarSeparate = !$res->areEquivalent('CAIO - REV', 'CAIO - VAR');
$caioIsCrossChannel = $res->isCrossChannelConflict('CAIO - REV', 'CAIO - VAR');

// ── Aggregates ────────────────────────────────────────────────────────────────
$byChannel = [];
foreach ($resolved as $r) {
    $ch = $r['vendor_channel'];
    $byChannel[$ch] = ($byChannel[$ch] ?? 0) + 1;
}
arsort($byChannel);

$ambiguousNames = array_filter($resolved, fn($r) => in_array(VendorAliasResolver::WARN_AMBIGUOUS_CHANNEL, $r['warnings'], true));
$noChannelNames = array_filter($resolved, fn($r) => in_array(VendorAliasResolver::WARN_NO_CHANNEL, $r['warnings'], true));

$elapsed = round(microtime(true) - $t0, 2);

// ── Output ────────────────────────────────────────────────────────────────────
$report = [
    'vendor_alias_audit' => [
        'version'                    => 'COM.R1.5',
        'period'                     => ['start' => $start, 'end' => $end],
        'generated_at'               => date('Y-m-d H:i:s'),
        'elapsed_s'                  => $elapsed,
        'total_vendors_seen'         => count($allNames),
        'canonical_roster_count'     => count($canonicalVendors),
        'by_channel'                 => $byChannel,
        'caio_rev_records'           => $caioRecords['rev'],
        'caio_var_records'           => $caioRecords['var'],
        'caio_ambiguous_records'     => $caioRecords['ambiguous'],
        'caio_cross_channel_pairs'   => count($crossChannelPairs),
        'caio_rev_var_are_separate'  => $caioRevVarSeparate,
        'caio_is_cross_channel'      => $caioIsCrossChannel,
        'ambiguous_channel_names'    => array_keys($ambiguousNames),
        'alias_candidates'           => $aliasCandidates,
        'caio_bruno_rev_detected'    => count(array_filter($aliasCandidates, fn($r) => strcasecmp((string) ($r['name'] ?? ''), 'CAIO BRUNO - REV') === 0)) > 0,
        'no_channel_names_count'     => count($noChannelNames),
        'cross_channel_pairs'        => $crossChannelPairs,
        'sources'                    => [
            'hasNfs'     => $hasNfs,
            'hasPedidos' => $hasPedidos,
            'hasVendors' => $hasVendors,
        ],
    ],
];

if ($jsonMode) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

function fmtCB(bool $v, string $yes = '✔', string $no = '✗'): string { return $v ? "\033[32m{$yes}\033[0m" : "\033[31m{$no}\033[0m"; }

$d = $report['vendor_alias_audit'];
$hr = str_repeat('═', 72);
echo PHP_EOL . $hr . PHP_EOL;
echo '  COM.R1.5 — Auditoria de Aliases de Vendedor' . PHP_EOL;
echo '  Período: ' . $start . ' → ' . $end . PHP_EOL;
echo $hr . PHP_EOL . PHP_EOL;

echo '  Vendedores distintos no período : ' . $d['total_vendors_seen'] . PHP_EOL;
echo '  Cadastro canônico (roster)      : ' . $d['canonical_roster_count'] . PHP_EOL . PHP_EOL;

echo str_repeat('─', 72) . PHP_EOL . '  Distribuição por canal' . PHP_EOL . str_repeat('─', 72) . PHP_EOL . PHP_EOL;
foreach ($d['by_channel'] as $ch => $count) {
    echo '  ' . str_pad($ch, 20) . ': ' . $count . PHP_EOL;
}

echo PHP_EOL . str_repeat('─', 72) . PHP_EOL . '  Verificação CAIO (Regra Canal)' . PHP_EOL . str_repeat('─', 72) . PHP_EOL . PHP_EOL;
echo '  ' . fmtCB($d['caio_rev_var_are_separate']) . ' CAIO - REV e CAIO - VAR são canais SEPARADOS' . PHP_EOL;
echo '  ' . fmtCB($d['caio_is_cross_channel']) . ' CAIO - REV vs CAIO - VAR detectado como cross-channel' . PHP_EOL;
if ($d['caio_rev_records']) echo '  CAIO-REV registros   : ' . implode(', ', $d['caio_rev_records']) . PHP_EOL;
if ($d['caio_var_records']) echo '  CAIO-VAR registros   : ' . implode(', ', $d['caio_var_records']) . PHP_EOL;
if ($d['caio_ambiguous_records']) echo '  CAIO-ambíguo         : ' . implode(', ', $d['caio_ambiguous_records']) . PHP_EOL;

if ($d['cross_channel_pairs']) {
    echo PHP_EOL . str_repeat('─', 72) . PHP_EOL . '  Pares Cross-Channel detectados' . PHP_EOL . str_repeat('─', 72) . PHP_EOL . PHP_EOL;
    foreach ($d['cross_channel_pairs'] as $pair) {
        echo '  Base: ' . $pair['base'] . PHP_EOL;
        foreach ($pair['entries'] as $e) {
            echo '    [' . $e['channel'] . '] ' . $e['name'] . PHP_EOL;
        }
    }
}

if ($d['ambiguous_channel_names']) {
    echo PHP_EOL . str_repeat('─', 72) . PHP_EOL . '  Nomes Ambíguos (sem canal, potencialmente multi-canal)' . PHP_EOL . str_repeat('─', 72) . PHP_EOL . PHP_EOL;
    foreach ($d['ambiguous_channel_names'] as $n) echo '  ' . $n . PHP_EOL;
}

if ($d['alias_candidates']) {
    echo PHP_EOL . str_repeat('-', 72) . PHP_EOL . '  Candidatos a Alias Manual Seguro' . PHP_EOL . str_repeat('-', 72) . PHP_EOL . PHP_EOL;
    if (false) {
        // Legacy separator kept unreachable to avoid touching unrelated encoded output.
    echo PHP_EOL . str_repeat('â”€', 72) . PHP_EOL . '  Candidatos a Alias Manual Seguro' . PHP_EOL . str_repeat('â”€', 72) . PHP_EOL . PHP_EOL;
    }
    foreach ($d['alias_candidates'] as $candidate) {
        echo '  ' . $candidate['name'] . ' -> ' . ($candidate['suggested_alias'] ?? '-')
            . '  manual=' . (!empty($candidate['requires_manual_approval']) ? 'sim' : 'nao')
            . '  channel_match=' . (!empty($candidate['channel_match']) ? 'sim' : 'nao') . PHP_EOL;
    }
}

echo PHP_EOL . $hr . PHP_EOL;
echo '  Concluído em ' . $elapsed . 's' . PHP_EOL;
echo $hr . PHP_EOL . PHP_EOL;
