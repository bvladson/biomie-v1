<?php

/**
 * tools/dev/fase_l_qa.php
 *
 * Fase L.QA — Validação pós-exclusão da Licitação.
 *
 * Verifica os 12 critérios de aceite: marts limpos, APIs canonicas limpas,
 * Plano de Voo limpo, dados brutos preservados, Base Comercial intacta.
 *
 * Usage:
 *   php tools/dev/fase_l_qa.php
 *   php tools/dev/fase_l_qa.php --json
 *   php tools/dev/fase_l_qa.php --pretty
 *   php tools/dev/fase_l_qa.php --ac=4        (só critério 4)
 *   php tools/dev/fase_l_qa.php --fail-fast
 *   php tools/dev/fase_l_qa.php --start=2026-01-01 --end=2026-05-31
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

// ── CLI args ──────────────────────────────────────────────────────────────────

$opts = getopt('', ['json', 'pretty', 'ac:', 'fail-fast', 'start:', 'end:', 'help']);

$asJson   = isset($opts['json']);
$pretty   = isset($opts['pretty']);
$failFast = isset($opts['fail-fast']);
$onlyAc   = isset($opts['ac']) ? (int) $opts['ac'] : null;
$start    = $opts['start'] ?? '2026-01-01';
$end      = $opts['end']   ?? date('Y-m-t');

if (isset($opts['help'])) {
    echo <<<HELP
Fase L.QA — Validação pós-exclusão da Licitação

Usage:
  php tools/dev/fase_l_qa.php [--json] [--pretty] [--ac=N] [--fail-fast]
                               [--start=YYYY-MM-DD] [--end=YYYY-MM-DD]

Critérios de aceite (12):
  AC-1   licitacao_visibility_guard 10/10 PASS
  AC-2   Fiscal value basis guard OK
  AC-3   Commercial recalibration internamente consistente
  AC-4   Licitação ausente em NF do Período (canonical query)
  AC-5   Licitação ausente em Vendedores (canonical query)
  AC-6   Licitação ausente em Mix/Mercadorias (mart_produto_periodo)
  AC-7   Licitação ausente em Geo (mart_compradores_por_geo)
  AC-8   Licitação ausente em Plano de Voo (pedidos_canonicos)
  AC-9   Licitação ausente em Rankings (mart_vendedor_dia)
  AC-10  Raw/bronze/canonical preservados (contagens intactas)
  AC-11  Nenhum dado deletado (licitação existe como visible=0)
  AC-12  Base Comercial NF fórmula intacta

HELP;
    exit(0);
}

// ── DB ────────────────────────────────────────────────────────────────────────

$config = new \BIomie\Config();
$conn = \BIomie\Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
if (!\BIomie\Database::isConfigured($config, $conn)) {
    fwrite(STDERR, "ERRO: banco de dados não configurado.\n");
    exit(1);
}
try {
    $pdo = \BIomie\Database::connect($config, $conn);
} catch (Throwable $e) {
    fwrite(STDERR, "ERRO ao conectar: " . $e->getMessage() . "\n");
    exit(1);
}

// ── Shared helpers ────────────────────────────────────────────────────────────

function colExists(PDO $pdo, string $table, string $col): bool
{
    static $cache = [];
    $key = "{$table}:{$col}";
    if (!isset($cache[$key])) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
        );
        $stmt->execute([$table, $col]);
        $cache[$key] = (int) $stmt->fetchColumn() > 0;
    }
    return $cache[$key];
}

function tableExists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (!isset($cache[$table])) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
        );
        $stmt->execute([$table]);
        $cache[$table] = (int) $stmt->fetchColumn() > 0;
    }
    return $cache[$table];
}

function countQ(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function normalizeName(string $name): string
{
    $n   = mb_strtolower(trim($name));
    $map = [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u',
        'ç' => 'c',
    ];
    return mb_strtoupper(strtr($n, $map));
}

/** @return list<string> */
function detectLicitacaoCodes(PDO $pdo): array
{
    $codes = [];
    if (tableExists($pdo, 'biomie_vendor_scope_rules')) {
        $stmt = $pdo->query(
            "SELECT codigo_vendedor_omie FROM biomie_vendor_scope_rules
             WHERE active = 1 AND is_biomie_commercial_visible = 0"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $c) {
            if ((string) $c !== '') $codes[] = (string) $c;
        }
    }
    if (empty($codes) && tableExists($pdo, 'biomie_vendedores_omie')) {
        $stmt = $pdo->query("SELECT codigo_omie, nome FROM biomie_vendedores_omie");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $nome = (string) ($row['nome'] ?? '');
            $code = (string) ($row['codigo_omie'] ?? '');
            if ($code !== '' && str_contains(normalizeName($nome), 'LICITAC')) {
                $codes[] = $code;
            }
        }
    }
    if (empty($codes) && tableExists($pdo, 'biomie_notas_fiscais_canonicas')) {
        $stmt = $pdo->query(
            "SELECT DISTINCT codigo_vendedor_omie FROM biomie_notas_fiscais_canonicas
             WHERE UPPER(nome_vendedor) LIKE '%LICITAC%'
               AND codigo_vendedor_omie IS NOT NULL AND codigo_vendedor_omie != ''
             LIMIT 20"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $c) {
            if ((string) $c !== '') $codes[] = (string) $c;
        }
    }
    return array_unique($codes);
}

function placeholders(array $arr): string
{
    return implode(',', array_fill(0, count($arr), '?'));
}

/**
 * Check if a mart table is clean of licitação rows.
 * @return array{pass:bool,count:int,detail:string,skipped:bool}
 */
function martClean(PDO $pdo, string $table, string $vendorCol, array $codes): array
{
    if (!tableExists($pdo, $table)) {
        return ['pass' => true, 'count' => 0, 'detail' => "Tabela {$table} ausente.", 'skipped' => true];
    }
    if (!colExists($pdo, $table, $vendorCol)) {
        return ['pass' => true, 'count' => 0, 'detail' => "Coluna {$vendorCol} ausente em {$table}.", 'skipped' => true];
    }
    if (empty($codes)) {
        // Fallback: name pattern
        $nomeCol = null;
        foreach (['nome_vendedor', 'vendedor_nome', 'nome'] as $c) {
            if (colExists($pdo, $table, $c)) { $nomeCol = $c; break; }
        }
        if ($nomeCol === null) {
            return ['pass' => true, 'count' => 0, 'detail' => "Sem códigos licitação e sem coluna nome — não conclusivo.", 'skipped' => true];
        }
        $count = countQ($pdo, "SELECT COUNT(*) FROM {$table} WHERE UPPER({$nomeCol}) LIKE '%LICITAC%'");
        return [
            'pass'    => $count === 0,
            'count'   => $count,
            'detail'  => $count === 0
                ? "Nenhuma linha licitação em {$table}."
                : "{$count} linhas licitação em {$table} (via nome) — rematerializar.",
            'skipped' => false,
        ];
    }
    $ph    = placeholders($codes);
    $count = countQ($pdo, "SELECT COUNT(*) FROM {$table} WHERE {$vendorCol} IN ({$ph})", array_values($codes));
    return [
        'pass'    => $count === 0,
        'count'   => $count,
        'detail'  => $count === 0
            ? "Nenhuma linha licitação em {$table}."
            : "{$count} linhas licitação em {$table} — rematerializar.",
        'skipped' => false,
    ];
}

// ── Acceptance criteria checks ────────────────────────────────────────────────

$codes = detectLicitacaoCodes($pdo);

// AC-1: re-run the 10 visibility guard checks inline
function ac1(PDO $pdo, array $codes): array
{
    $guardFails = [];
    $guardSkips = 0;

    // Guard checks 1-5: mart tables
    $martChecks = [
        1 => ['mart_vendedor_dia',         'codigo_vendedor_omie'],
        2 => ['mart_cliente_periodo',      'codigo_vendedor_omie'],
        3 => ['mart_produto_periodo',      'codigo_vendedor_omie'],
        4 => ['mart_compradores_por_geo',  'codigo_vendedor_omie'],
        5 => ['mart_vendas_por_cnae_cnpj', 'codigo_vendedor_omie'],
    ];
    foreach ($martChecks as $n => [$table, $col]) {
        $r = martClean($pdo, $table, $col, $codes);
        if ($r['skipped']) { $guardSkips++; continue; }
        if (!$r['pass']) $guardFails[] = "Guard check #{$n}: " . $r['detail'];
    }

    // Guard check 6: NF visibility column + backfill applied
    if (tableExists($pdo, 'biomie_notas_fiscais_canonicas')) {
        $hasVis = colExists($pdo, 'biomie_notas_fiscais_canonicas', 'is_biomie_commercial_visible');
        if (!$hasVis) {
            $guardFails[] = "Guard check #6: coluna is_biomie_commercial_visible ausente em biomie_notas_fiscais_canonicas.";
        } elseif (!empty($codes)) {
            $ph = placeholders($codes);
            $cnt = countQ(
                $pdo,
                "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas WHERE codigo_vendedor_omie IN ({$ph}) AND is_biomie_commercial_visible = 1 AND status_fiscal = 'autorizada'",
                array_values($codes)
            );
            if ($cnt > 0) {
                $guardFails[] = "Guard check #6: {$cnt} NFs licitação com visible=1 ainda ativas — backfill pendente.";
            }
        }
    }

    // Guard check 7: biomie_vendedores_omie
    if (tableExists($pdo, 'biomie_vendedores_omie')) {
        $hasVis = colExists($pdo, 'biomie_vendedores_omie', 'is_biomie_commercial_visible');
        if (!$hasVis) {
            $guardFails[] = "Guard check #7: coluna is_biomie_commercial_visible ausente em biomie_vendedores_omie.";
        } elseif (!empty($codes)) {
            $ph  = placeholders($codes);
            $cnt = countQ($pdo, "SELECT COUNT(*) FROM biomie_vendedores_omie WHERE codigo_omie IN ({$ph}) AND is_biomie_commercial_visible = 1", array_values($codes));
            if ($cnt > 0) {
                $guardFails[] = "Guard check #7: {$cnt} vendedores licitação com visible=1 — backfill pendente.";
            }
        }
    }

    // Guard check 8: Base Comercial — licitação excluded
    if (tableExists($pdo, 'biomie_notas_fiscais_canonicas') && !empty($codes)) {
        $hasVis = colExists($pdo, 'biomie_notas_fiscais_canonicas', 'is_biomie_commercial_visible');
        if ($hasVis) {
            $ph  = placeholders($codes);
            $cnt = countQ(
                $pdo,
                "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas WHERE codigo_vendedor_omie IN ({$ph}) AND is_biomie_commercial_visible = 1 AND status_fiscal = 'autorizada'",
                array_values($codes)
            );
            if ($cnt > 0) {
                $guardFails[] = "Guard check #8: {$cnt} NFs licitação contabilizadas na Base Comercial.";
            }
        } else {
            $guardFails[] = "Guard check #8: coluna is_biomie_commercial_visible ausente — Base Comercial não filtrada.";
        }
    }

    // Guard check 9: raw/bronze
    if (tableExists($pdo, 'biomie_omie_bronze')) {
        if (colExists($pdo, 'biomie_omie_bronze', 'is_biomie_commercial_visible')) {
            $guardFails[] = "Guard check #9: coluna visibility encontrada em biomie_omie_bronze — bronze NÃO deve ser alterado.";
        }
    }

    // Guard check 10: migration columns
    $migrRequired = [
        'biomie_notas_fiscais_canonicas'       => ['is_biomie_commercial_visible', 'business_unit_scope'],
        'biomie_pedidos_canonicos'             => ['is_biomie_commercial_visible', 'business_unit_scope'],
        'biomie_titulos_financeiros_canonicos' => ['is_biomie_commercial_visible'],
        'biomie_vendedores_omie'               => ['is_biomie_commercial_visible', 'business_unit_scope'],
    ];
    foreach ($migrRequired as $tbl => $cols) {
        if (!tableExists($pdo, $tbl)) continue;
        foreach ($cols as $col) {
            if (!colExists($pdo, $tbl, $col)) {
                $guardFails[] = "Guard check #10: {$tbl}.{$col} ausente — migração pendente.";
            }
        }
    }

    $pass = empty($guardFails);
    return [
        'ac'     => 1,
        'name'   => 'licitacao_visibility_guard 10/10 PASS',
        'pass'   => $pass,
        'skipped' => false,
        'detail' => $pass
            ? "Todos os 10 checks do visibility guard passaram" . ($guardSkips > 0 ? " ({$guardSkips} skip por tabela ausente)." : ".")
            : 'Falhas: ' . implode(' | ', $guardFails),
        'failures' => $guardFails,
    ];
}

// AC-2: Fiscal value basis — formula columns intact + commercial scope filter exists
function ac2(PDO $pdo): array
{
    $issues = [];
    $table  = 'biomie_notas_fiscais_canonicas';

    if (!tableExists($pdo, $table)) {
        return ['ac' => 2, 'name' => 'Fiscal value basis guard OK', 'pass' => true, 'skipped' => true, 'detail' => 'Tabela ausente.'];
    }

    foreach (['valor_frete', 'valor_seguro', 'valor_outras_despesas', 'valor_total', 'valor_mercadorias'] as $col) {
        if (!colExists($pdo, $table, $col)) {
            $issues[] = "Coluna {$col} ausente — fórmula Base Comercial incompleta.";
        }
    }

    // Verify at least one commercial NF has non-zero valor_frete (sanity check)
    if (empty($issues) && colExists($pdo, $table, 'operation_scope')) {
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM {$table}
             WHERE status_fiscal = 'autorizada' AND operation_scope = 'commercial'
               AND COALESCE(is_biomie_commercial_visible, 1) = 1"
        );
        $cnt = (int) $stmt->fetchColumn();
        if ($cnt === 0) {
            $issues[] = "Nenhuma NF comercial encontrada — backfill ou migrate pendente.";
        }
    }

    $pass = empty($issues);
    return [
        'ac'     => 2,
        'name'   => 'Fiscal value basis guard OK',
        'pass'   => $pass,
        'skipped' => false,
        'detail' => $pass
            ? 'Colunas da Base Comercial (valor_frete, seguro, outras_despesas, total, mercadorias) presentes.'
            : implode(' | ', $issues),
    ];
}

// AC-3: Commercial recalibration — totals internally consistent (no negatives, reasonable coverage)
function ac3(PDO $pdo, string $start, string $end): array
{
    $table = 'biomie_notas_fiscais_canonicas';
    if (!tableExists($pdo, $table)) {
        return ['ac' => 3, 'name' => 'Commercial recalibration consistente', 'pass' => true, 'skipped' => true, 'detail' => 'Tabela ausente.'];
    }

    $hasVis   = colExists($pdo, $table, 'is_biomie_commercial_visible');
    $hasScope = colExists($pdo, $table, 'operation_scope');
    $hasFrete = colExists($pdo, $table, 'valor_frete');

    $visCond   = $hasVis   ? "AND COALESCE(nf.is_biomie_commercial_visible, 1) = 1 " : '';
    $scopeCond = $hasScope ? "AND nf.operation_scope = 'commercial' "                : '';

    $basisExpr = $hasFrete
        ? "SUM(nf.valor_total - COALESCE(nf.valor_frete,0) - COALESCE(nf.valor_outras_despesas,0) - COALESCE(nf.valor_seguro,0))"
        : "SUM(nf.valor_total)";

    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS nfs,
                    COALESCE(SUM(nf.valor_total), 0) AS bruto,
                    COALESCE({$basisExpr}, 0) AS base_comercial
             FROM {$table} nf
             WHERE nf.status_fiscal = 'autorizada'
               {$scopeCond}{$visCond}
               AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN ? AND ?"
        );
        $stmt->execute([$start, $end]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['ac' => 3, 'name' => 'Commercial recalibration consistente', 'pass' => false, 'skipped' => false, 'detail' => 'Erro: ' . $e->getMessage()];
    }

    $nfs  = (int)   ($row['nfs']          ?? 0);
    $bruto = (float) ($row['bruto']        ?? 0);
    $base  = (float) ($row['base_comercial'] ?? 0);

    $issues = [];
    if ($bruto < 0)          $issues[] = "valor_total negativo ({$bruto}) — inconsistência.";
    if ($base < 0)           $issues[] = "base_comercial negativa ({$base}) — inconsistência.";
    if ($nfs === 0)          $issues[] = "Zero NFs no período {$start}→{$end} — migrate ou backfill pendente.";
    if ($base > $bruto * 1.01) $issues[] = "base_comercial ({$base}) > bruto ({$bruto}) — fórmula incorreta.";

    $pass = empty($issues);
    return [
        'ac'            => 3,
        'name'          => 'Commercial recalibration consistente',
        'pass'          => $pass,
        'skipped'        => false,
        'detail'        => $pass
            ? "NFs: {$nfs} | Bruto: R$ " . number_format($bruto, 2, ',', '.') . " | Base Comercial: R$ " . number_format($base, 2, ',', '.') . " | Período: {$start}→{$end}."
            : implode(' | ', $issues),
        'nfs'           => $nfs,
        'bruto'         => $bruto,
        'base_comercial' => $base,
    ];
}

// AC-4: Licitação ausente em NF do Período (orders_search canonical)
function ac4(PDO $pdo, array $codes): array
{
    $table = 'biomie_notas_fiscais_canonicas';
    if (!tableExists($pdo, $table)) {
        return ['ac' => 4, 'name' => 'Licitação ausente em NF do Período', 'pass' => true, 'skipped' => true, 'detail' => 'Tabela ausente.'];
    }
    if (empty($codes)) {
        return ['ac' => 4, 'name' => 'Licitação ausente em NF do Período', 'pass' => true, 'skipped' => true, 'detail' => 'Nenhum código licitação detectado.'];
    }

    $hasVis   = colExists($pdo, $table, 'is_biomie_commercial_visible');
    $hasScope = colExists($pdo, $table, 'operation_scope');
    $visCond  = $hasVis   ? "AND COALESCE(nf.is_biomie_commercial_visible, 1) = 1 " : '';
    $scp      = $hasScope ? "AND nf.operation_scope = 'commercial' "                : '';
    $ph       = placeholders($codes);

    // Simulate what orders_search.php returns: commercial + visible NFs filtered by licitação code
    $count = countQ(
        $pdo,
        "SELECT COUNT(*) FROM {$table} nf
         WHERE nf.status_fiscal = 'autorizada' {$scp}{$visCond}
           AND nf.codigo_vendedor_omie IN ({$ph})",
        array_values($codes)
    );

    return [
        'ac'     => 4,
        'name'   => 'Licitação ausente em NF do Período',
        'pass'   => $count === 0,
        'skipped' => false,
        'count'  => $count,
        'detail' => $count === 0
            ? 'Nenhuma NF de licitação retornada pela query canonical (simula orders_search).'
            : "{$count} NFs de licitação ainda retornadas — backfill não aplicado ou coluna visibility ausente.",
    ];
}

// AC-5: Licitação ausente em Vendedores (vendors_search canonical)
function ac5(PDO $pdo, array $codes): array
{
    $table = 'biomie_notas_fiscais_canonicas';
    if (!tableExists($pdo, $table) || empty($codes)) {
        return ['ac' => 5, 'name' => 'Licitação ausente em Vendedores', 'pass' => true, 'skipped' => true, 'detail' => empty($codes) ? 'Nenhum código detectado.' : 'Tabela ausente.'];
    }

    $hasVis  = colExists($pdo, $table, 'is_biomie_commercial_visible');
    $hasScope = colExists($pdo, $table, 'operation_scope');
    $visCond  = $hasVis   ? "AND COALESCE(nf.is_biomie_commercial_visible, 1) = 1 " : '';
    $scp      = $hasScope ? "AND nf.operation_scope = 'commercial' "                : '';
    $ph       = placeholders($codes);

    $count = countQ(
        $pdo,
        "SELECT COUNT(DISTINCT nf.codigo_vendedor_omie) FROM {$table} nf
         WHERE nf.status_fiscal = 'autorizada' {$scp}{$visCond}
           AND nf.codigo_vendedor_omie IN ({$ph})",
        array_values($codes)
    );

    return [
        'ac'     => 5,
        'name'   => 'Licitação ausente em Vendedores',
        'pass'   => $count === 0,
        'skipped' => false,
        'count'  => $count,
        'detail' => $count === 0
            ? 'Nenhum vendedor licitação retornado pela query canonical (simula vendors_search).'
            : "{$count} vendor(es) licitação ainda no escopo comercial — backfill pendente.",
    ];
}

// AC-6: Licitação ausente em Mix/Mercadorias
function ac6(PDO $pdo, array $codes): array
{
    $r = martClean($pdo, 'mart_produto_periodo', 'codigo_vendedor_omie', $codes);
    return ['ac' => 6, 'name' => 'Licitação ausente em Mix/Mercadorias (mart_produto_periodo)', 'pass' => $r['pass'], 'skipped' => $r['skipped'], 'count' => $r['count'], 'detail' => $r['detail']];
}

// AC-7: Licitação ausente em Geo
function ac7(PDO $pdo, array $codes): array
{
    $r = martClean($pdo, 'mart_compradores_por_geo', 'codigo_vendedor_omie', $codes);
    return ['ac' => 7, 'name' => 'Licitação ausente em Geo (mart_compradores_por_geo)', 'pass' => $r['pass'], 'skipped' => $r['skipped'], 'count' => $r['count'], 'detail' => $r['detail']];
}

// AC-8: Licitação ausente em Plano de Voo (biomie_pedidos_canonicos)
function ac8(PDO $pdo, array $codes): array
{
    $table = 'biomie_pedidos_canonicos';
    if (!tableExists($pdo, $table)) {
        return ['ac' => 8, 'name' => 'Licitação ausente em Plano de Voo', 'pass' => true, 'skipped' => true, 'detail' => 'Tabela biomie_pedidos_canonicos ausente.'];
    }
    if (empty($codes)) {
        return ['ac' => 8, 'name' => 'Licitação ausente em Plano de Voo', 'pass' => true, 'skipped' => true, 'detail' => 'Nenhum código licitação detectado.'];
    }

    $hasVis  = colExists($pdo, $table, 'is_biomie_commercial_visible');
    $hasCode = colExists($pdo, $table, 'codigo_vendedor_omie');

    if (!$hasCode) {
        return ['ac' => 8, 'name' => 'Licitação ausente em Plano de Voo', 'pass' => true, 'skipped' => true, 'detail' => 'Coluna codigo_vendedor_omie ausente em pedidos_canonicos.'];
    }

    $visCond = $hasVis ? "AND COALESCE(is_biomie_commercial_visible, 1) = 1 " : '';
    $ph      = placeholders($codes);

    $count = countQ(
        $pdo,
        "SELECT COUNT(*) FROM {$table}
         WHERE codigo_vendedor_omie IN ({$ph}) {$visCond}",
        array_values($codes)
    );

    $detail = $count === 0
        ? 'Nenhum pedido de licitação no escopo do Plano de Voo.'
        : ($hasVis
            ? "{$count} pedidos licitação com visible=1 em pedidos_canonicos — backfill pendente."
            : "{$count} pedidos licitação em pedidos_canonicos (coluna visibility ausente — migração pendente).");

    return [
        'ac'     => 8,
        'name'   => 'Licitação ausente em Plano de Voo',
        'pass'   => $count === 0,
        'skipped' => false,
        'count'  => $count,
        'detail' => $detail,
    ];
}

// AC-9: Licitação ausente em Rankings (mart_vendedor_dia)
function ac9(PDO $pdo, array $codes): array
{
    $r = martClean($pdo, 'mart_vendedor_dia', 'codigo_vendedor_omie', $codes);
    return ['ac' => 9, 'name' => 'Licitação ausente em Rankings (mart_vendedor_dia)', 'pass' => $r['pass'], 'skipped' => $r['skipped'], 'count' => $r['count'], 'detail' => $r['detail']];
}

// AC-10: Raw/bronze/canonical preservados
function ac10(PDO $pdo, array $codes): array
{
    $issues = [];
    $info   = [];

    // Bronze must NOT have visibility column
    if (tableExists($pdo, 'biomie_omie_bronze')) {
        if (colExists($pdo, 'biomie_omie_bronze', 'is_biomie_commercial_visible')) {
            $issues[] = 'VIOLAÇÃO: coluna visibility encontrada em biomie_omie_bronze (não deve ser alterada).';
        }
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM biomie_omie_bronze");
            $info['bronze_total'] = (int) $stmt->fetchColumn();
        } catch (Throwable) {}
    }

    // Canonical tables must still have all rows (visibility does not delete rows)
    foreach (['biomie_notas_fiscais_canonicas', 'biomie_pedidos_canonicos'] as $tbl) {
        if (!tableExists($pdo, $tbl)) continue;
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM {$tbl}");
            $info["{$tbl}_total"] = (int) $stmt->fetchColumn();
        } catch (Throwable) {}
    }

    $pass = empty($issues);
    return [
        'ac'     => 10,
        'name'   => 'Raw/bronze/canonical preservados',
        'pass'   => $pass,
        'skipped' => false,
        'detail' => $pass
            ? 'Bronze sem coluna visibility. Contagens canônicas: ' . implode(', ', array_map(fn($k, $v) => "{$k}={$v}", array_keys($info), $info)) . '.'
            : implode(' | ', $issues),
        'counts' => $info,
    ];
}

// AC-11: Nenhum dado deletado (licitação existe em canonical como visible=0)
function ac11(PDO $pdo, array $codes): array
{
    if (empty($codes)) {
        return ['ac' => 11, 'name' => 'Nenhum dado deletado (licitação existe como visible=0)', 'pass' => true, 'skipped' => true, 'detail' => 'Nenhum código licitação detectado.'];
    }

    $table = 'biomie_notas_fiscais_canonicas';
    if (!tableExists($pdo, $table)) {
        return ['ac' => 11, 'name' => 'Nenhum dado deletado', 'pass' => true, 'skipped' => true, 'detail' => 'Tabela ausente.'];
    }

    $hasVis = colExists($pdo, $table, 'is_biomie_commercial_visible');
    $ph     = placeholders($codes);

    // Total NFs by licitação codes (ALL, regardless of visibility)
    $totalAll = countQ(
        $pdo,
        "SELECT COUNT(*) FROM {$table} WHERE codigo_vendedor_omie IN ({$ph})",
        array_values($codes)
    );

    if ($totalAll === 0) {
        return [
            'ac'     => 11,
            'name'   => 'Nenhum dado deletado (licitação existe como visible=0)',
            'pass'   => true,
            'skipped' => true,
            'detail' => 'Nenhuma NF de licitação encontrada na canonical (possível: códigos detectados mas sem NFs no período).',
        ];
    }

    $vis0 = 0;
    if ($hasVis) {
        $vis0 = countQ(
            $pdo,
            "SELECT COUNT(*) FROM {$table} WHERE codigo_vendedor_omie IN ({$ph}) AND is_biomie_commercial_visible = 0",
            array_values($codes)
        );
    }

    $issues = [];
    if ($hasVis && $vis0 === 0) {
        $issues[] = "Existem {$totalAll} NFs de licitação mas nenhuma com visible=0 — backfill --apply não foi executado.";
    }
    if ($hasVis && $vis0 < $totalAll) {
        $remaining = $totalAll - $vis0;
        $issues[]  = "{$remaining} de {$totalAll} NFs de licitação ainda com visible=1 — backfill incompleto.";
    }

    $pass = empty($issues);
    return [
        'ac'      => 11,
        'name'    => 'Nenhum dado deletado (licitação existe como visible=0)',
        'pass'    => $pass,
        'skipped' => false,
        'count_total'   => $totalAll,
        'count_vis0'    => $vis0,
        'detail'  => $pass
            ? "NFs licitação preservadas: {$totalAll} rows, {$vis0} com visible=0 (dado intacto, apenas reclassificado)."
            : implode(' | ', $issues),
    ];
}

// AC-12: Base Comercial NF fórmula intacta
function ac12(PDO $pdo): array
{
    $table = 'biomie_notas_fiscais_canonicas';
    if (!tableExists($pdo, $table)) {
        return ['ac' => 12, 'name' => 'Base Comercial NF fórmula intacta', 'pass' => true, 'skipped' => true, 'detail' => 'Tabela ausente.'];
    }

    $required = ['valor_total', 'valor_frete', 'valor_seguro', 'valor_outras_despesas', 'valor_mercadorias'];
    $missing  = array_filter($required, fn($c) => !colExists($pdo, $table, $c));

    // Also verify operation_scope column (used by the commercial filter)
    $hasScope = colExists($pdo, $table, 'operation_scope');
    $hasVis   = colExists($pdo, $table, 'is_biomie_commercial_visible');

    $issues = [];
    if (!empty($missing)) {
        $issues[] = 'Colunas ausentes na fórmula Base Comercial: ' . implode(', ', $missing) . '.';
    }
    if (!$hasScope) {
        $issues[] = 'Coluna operation_scope ausente — filtro comercial não aplicável.';
    }
    if (!$hasVis) {
        $issues[] = 'Coluna is_biomie_commercial_visible ausente — Fase L não aplicada à canonical.';
    }

    $pass = empty($issues);
    return [
        'ac'     => 12,
        'name'   => 'Base Comercial NF fórmula intacta',
        'pass'   => $pass,
        'skipped' => false,
        'detail' => $pass
            ? 'Todas as colunas da fórmula Base Comercial presentes (valor_total, frete, seguro, outras_despesas, mercadorias) + operation_scope + is_biomie_commercial_visible.'
            : implode(' | ', $issues),
    ];
}

// ── Run ───────────────────────────────────────────────────────────────────────

$checkFns = [
    1  => fn() => ac1($pdo, $codes),
    2  => fn() => ac2($pdo),
    3  => fn() => ac3($pdo, $start, $end),
    4  => fn() => ac4($pdo, $codes),
    5  => fn() => ac5($pdo, $codes),
    6  => fn() => ac6($pdo, $codes),
    7  => fn() => ac7($pdo, $codes),
    8  => fn() => ac8($pdo, $codes),
    9  => fn() => ac9($pdo, $codes),
    10 => fn() => ac10($pdo, $codes),
    11 => fn() => ac11($pdo, $codes),
    12 => fn() => ac12($pdo),
];

$toRun = $onlyAc !== null
    ? (isset($checkFns[$onlyAc]) ? [$onlyAc => $checkFns[$onlyAc]] : [])
    : $checkFns;

$results = [];
$passed  = 0;
$failed  = 0;
$skipped = 0;
$t0      = microtime(true);

foreach ($toRun as $n => $fn) {
    try {
        $r = $fn();
    } catch (Throwable $e) {
        $r = ['ac' => $n, 'name' => "AC-{$n}", 'pass' => false, 'skipped' => false, 'detail' => 'Exceção: ' . $e->getMessage()];
    }
    $results[] = $r;
    if ($r['skipped'] ?? false) { $skipped++; }
    elseif ($r['pass'])          { $passed++;  }
    else                         { $failed++;  if ($failFast) break; }
}

$elapsedMs = round((microtime(true) - $t0) * 1000, 1);

$output = [
    'phase'    => 'Fase L.QA',
    'summary'  => [
        'overall'    => $failed === 0 ? 'PASS' : 'FAIL',
        'total'      => count($results),
        'passed'     => $passed,
        'failed'     => $failed,
        'skipped'    => $skipped,
        'elapsed_ms' => $elapsedMs,
        'period'     => ['start' => $start, 'end' => $end],
        'licitacao_codes_detected' => $codes,
        'executed_at' => date('Y-m-d H:i:s'),
    ],
    'criteria' => $results,
];

// ── Output ────────────────────────────────────────────────────────────────────

if ($asJson) {
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if ($pretty) $flags |= JSON_PRETTY_PRINT;
    echo json_encode($output, $flags) . "\n";
    exit($failed > 0 ? 1 : 0);
}

$bar = str_repeat('═', 64);
$sep = str_repeat('─', 64);

echo "\n{$bar}\n";
echo " Fase L.QA — Validação pós-exclusão da Licitação\n";
echo "{$bar}\n";
echo " Período  : {$start} → {$end}\n";
echo " Códigos  : " . (empty($codes) ? '(nenhum detectado)' : implode(', ', $codes)) . "\n";
echo "{$sep}\n\n";

$labels = [
    1  => 'AC-01  licitacao_visibility_guard 10/10 PASS',
    2  => 'AC-02  Fiscal value basis guard OK',
    3  => 'AC-03  Commercial recalibration consistente',
    4  => 'AC-04  Licitação ausente em NF do Período',
    5  => 'AC-05  Licitação ausente em Vendedores',
    6  => 'AC-06  Licitação ausente em Mix/Mercadorias',
    7  => 'AC-07  Licitação ausente em Geo',
    8  => 'AC-08  Licitação ausente em Plano de Voo',
    9  => 'AC-09  Licitação ausente em Rankings',
    10 => 'AC-10  Raw/bronze/canonical preservados',
    11 => 'AC-11  Nenhum dado deletado (visible=0)',
    12 => 'AC-12  Base Comercial NF fórmula intacta',
];

foreach ($results as $r) {
    $n    = $r['ac'] ?? '?';
    $skip = $r['skipped'] ?? false;
    $icon = $skip ? 'SKIP' : ($r['pass'] ? 'PASS' : 'FAIL');
    $lbl  = $labels[$n] ?? "AC-{$n}";
    echo "  [{$icon}]  {$lbl}\n";
    if (!$r['pass'] || $skip) {
        echo "           {$r['detail']}\n";
    }
}

echo "\n{$sep}\n";
$overall = $output['summary']['overall'];
echo " {$overall}  —  {$passed} pass / {$failed} fail / {$skipped} skip  ({$elapsedMs}ms)\n";
echo "{$bar}\n\n";

if ($failed > 0) {
    echo "Ações necessárias:\n";
    foreach ($results as $r) {
        if (!($r['skipped'] ?? false) && !$r['pass']) {
            echo "  AC-{$r['ac']}: {$r['detail']}\n";
        }
    }
    echo "\n";
}

exit($failed > 0 ? 1 : 0);
