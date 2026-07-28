<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — Diagnóstico de Gate (pré-implementação)
 *
 * Responde às 7 perguntas acordadas antes de iniciar o MVP da Agenda de Orçamentos:
 *
 *   1. Quantidade e valor por company_id + etapa_omie
 *   2. Presença da etapa 10 (orçamentos chegando ao canônico?)
 *   3. Distribuição confirmed / provisional / excluded (operation_scope dos pedidos)
 *   4. Pedidos sem vendedor
 *   5. Pedidos sem pedido_id_omie
 *   6. Amostra comparando infoCadastro.dInc, cabecalho.data_previsao e o atual data_emissao
 *      (somente com --with-bronze-sample — leitura de biomie_omie_bronze)
 *   7. Catálogo real de etapas contendo 10/20/50/60/70/80
 *
 * Também reporta o estado do schema (company_id, operation_scope e as três colunas
 * de data ainda inexistentes) para saber o que a migração terá de fazer.
 *
 * Uso:
 *   php tools/dev/quote_agenda_gate_diagnosis.php
 *   php tools/dev/quote_agenda_gate_diagnosis.php --start=2026-01-01 --end=2026-07-15
 *   php tools/dev/quote_agenda_gate_diagnosis.php --with-bronze-sample --sample=25
 *
 * READ-ONLY. Apenas SELECT/DESCRIBE. Não altera schema, dados ou configuração.
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;

// ── CLI args ────────────────────────────────────────────────────────────────────
$opts        = getopt('', ['start:', 'end:', 'sample:', 'with-bronze-sample', 'json']);
$start       = $opts['start'] ?? date('Y-01-01');
$end         = $opts['end']   ?? date('Y-m-d');
$sampleN     = max(1, min(100, (int) ($opts['sample'] ?? 25)));
$withBronze  = isset($opts['with-bronze-sample']);
$jsonMode    = isset($opts['json']);

$ORDERS = 'biomie_pedidos_canonicos';

// Baseline versionado (gate aprovado em 2026-07-15). Registro de referência —
// diferenças posteriores devem ser explicáveis por movimentação real do Omie,
// NÃO tratadas como falha (novos orçamentos entram/saem todo dia).
const BASELINE_2026_07_15 = [
    'etapa10_pedidos'  => 163,
    'etapa10_valor'    => 1856053.0,
    'provisional'      => 162,
    'confirmed'        => 1,
    'sem_vendedor'     => 7,
    'sem_pedido_id'    => 0,
    'catalogo'         => '10 / 20 / 60',
];

// ── Helpers ─────────────────────────────────────────────────────────────────────
function sep(string $title = ''): void {
    echo PHP_EOL . str_repeat('─', 78) . PHP_EOL;
    if ($title !== '') { echo "  {$title}" . PHP_EOL . str_repeat('─', 78) . PHP_EOL; }
}
function fmtN(int $n): string   { return number_format($n, 0, ',', '.'); }
function fmtR(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }
function pad(string $s, int $w): string { return str_pad(mb_substr($s, 0, $w), $w); }

/** @param array<int,array<string,mixed>> $rows */
function table(array $rows, array $cols): void {
    if ($rows === []) { echo "    (sem linhas)" . PHP_EOL; return; }
    $widths = [];
    foreach ($cols as $key => $label) {
        $widths[$key] = mb_strlen((string) $label);
        foreach ($rows as $r) {
            $widths[$key] = max($widths[$key], mb_strlen((string) ($r[$key] ?? '')));
        }
    }
    $line = '    ';
    foreach ($cols as $key => $label) { $line .= pad((string) $label, $widths[$key] + 2); }
    echo $line . PHP_EOL;
    echo '    ' . str_repeat('·', max(0, mb_strlen($line) - 4)) . PHP_EOL;
    foreach ($rows as $r) {
        $l = '    ';
        foreach ($cols as $key => $label) { $l .= pad((string) ($r[$key] ?? ''), $widths[$key] + 2); }
        echo $l . PHP_EOL;
    }
}

// ── Connect ─────────────────────────────────────────────────────────────────────
$config = new Config();
$conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
if (!Database::isConfigured($config, $conn)) {
    fwrite(STDERR, "Banco não configurado.\n");
    exit(2);
}

$t0 = microtime(true);
try {
    $pdo = Database::connect($config, $conn);
} catch (Throwable $e) {
    fwrite(STDERR, "Falha ao conectar ao banco ({$conn}): " . $e->getMessage() . "\n");
    fwrite(STDERR, "Se o host recusou a conexão, rode este script no servidor de produção via SSH.\n");
    exit(3);
}

echo str_repeat('═', 78) . PHP_EOL;
echo "  AGENDA DE ORÇAMENTOS — DIAGNÓSTICO DE GATE" . PHP_EOL;
echo "  conexão: {$conn}   janela snapshot: {$start} → {$end}" . PHP_EOL;
echo str_repeat('═', 78) . PHP_EOL;

// ── Column presence helper ───────────────────────────────────────────────────────
function columnExists(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
    );
    $st->execute(['t' => $table, 'c' => $col]);
    return (int) $st->fetchColumn() > 0;
}
function tableExists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t"
    );
    $st->execute(['t' => $table]);
    return (int) $st->fetchColumn() > 0;
}

$hasCompanyId  = columnExists($pdo, $ORDERS, 'company_id');
$hasScope      = columnExists($pdo, $ORDERS, 'operation_scope');
$hasDInc       = columnExists($pdo, $ORDERS, 'data_inclusao_omie');
$hasDAlt       = columnExists($pdo, $ORDERS, 'data_alteracao_omie');
$hasPrevCol    = columnExists($pdo, $ORDERS, 'data_previsao_faturamento');
$companyCol    = $hasCompanyId ? 'company_id' : "'(single)'";

// ── SECTION A — Estado do schema ─────────────────────────────────────────────────
sep('A. ESTADO DO SCHEMA (o que a migração terá de fazer)');
$flag = static fn (bool $b): string => $b ? '[x] existe' : '[ ] AUSENTE (criar)';
echo '    company_id                     : ' . $flag($hasCompanyId) . PHP_EOL;
echo '    operation_scope                : ' . $flag($hasScope) . PHP_EOL;
echo '    data_inclusao_omie             : ' . $flag($hasDInc) . '   ← esperado AUSENTE no MVP' . PHP_EOL;
echo '    data_alteracao_omie            : ' . $flag($hasDAlt) . '   ← esperado AUSENTE no MVP' . PHP_EOL;
echo '    data_previsao_faturamento      : ' . $flag($hasPrevCol) . '   ← esperado AUSENTE no MVP' . PHP_EOL;

// ── SECTION B — Distribuição por etapa (+ por empresa) — gate 1,2,7 ───────────────
sep('B. DISTRIBUIÇÃO POR ETAPA (todo o histórico) — gate 1, 2, 7');
try {
    $sql = "SELECT etapa_omie, status_comercial,
                   COUNT(*) AS pedidos,
                   COALESCE(SUM(valor_total),0) AS total
            FROM {$ORDERS}
            GROUP BY etapa_omie, status_comercial
            ORDER BY etapa_omie IS NULL, etapa_omie, status_comercial";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $view = array_map(static fn (array $r): array => [
        'etapa'  => ($r['etapa_omie'] ?? '') === '' || $r['etapa_omie'] === null ? '(nula)' : $r['etapa_omie'],
        'status' => (string) $r['status_comercial'],
        'pedidos' => fmtN((int) $r['pedidos']),
        'valor'  => fmtR((float) $r['total']),
    ], $rows);
    table($view, ['etapa' => 'ETAPA', 'status' => 'status_comercial', 'pedidos' => 'PEDIDOS', 'valor' => 'VALOR TOTAL']);

    $etapas = array_column($rows, 'etapa_omie');
    $has10  = in_array('10', array_map('strval', $etapas), true);
    echo PHP_EOL . '    → Etapa 10 (Orçamento) presente no canônico? ' . ($has10 ? 'SIM ✔' : 'NÃO ✗  (BLOQUEANTE)') . PHP_EOL;
    $catalog = array_values(array_unique(array_filter(array_map('strval', $etapas), static fn ($v) => $v !== '')));
    sort($catalog);
    echo '    → Catálogo real de etapas: ' . (implode(' / ', $catalog) ?: '(vazio)') . PHP_EOL;
} catch (Throwable $e) {
    echo '    ERRO seção B: ' . $e->getMessage() . PHP_EOL;
}

// ── SECTION B2 — Orçamentos abertos por empresa (matéria-prima da agenda) ─────────
sep('B2. ORÇAMENTOS ABERTOS (etapa 10) POR EMPRESA — matéria-prima da agenda');
try {
    $sql = "SELECT {$companyCol} AS company, COUNT(*) AS pedidos,
                   COALESCE(SUM(valor_total),0) AS total,
                   MIN(data_emissao) AS mais_antigo, MAX(data_emissao) AS mais_recente
            FROM {$ORDERS}
            WHERE etapa_omie = '10'
            GROUP BY {$companyCol}
            ORDER BY total DESC";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $view = array_map(static fn (array $r): array => [
        'company' => (string) $r['company'],
        'pedidos' => fmtN((int) $r['pedidos']),
        'valor'   => fmtR((float) $r['total']),
        'janela'  => (string) ($r['mais_antigo'] ?? '?') . ' → ' . (string) ($r['mais_recente'] ?? '?'),
    ], $rows);
    table($view, ['company' => 'EMPRESA', 'pedidos' => 'ORÇAMENTOS', 'valor' => 'VALOR', 'janela' => 'JANELA data_emissao']);
    echo PHP_EOL . '    ⚠ Lembrete: data_emissao aqui é PREVISÃO (com fallback dInc), não a criação real.' . PHP_EOL;
} catch (Throwable $e) {
    echo '    ERRO seção B2: ' . $e->getMessage() . PHP_EOL;
}

// ── SECTION C — Contaminação do 'outro' (10 vs 60) ───────────────────────────────
sep("C. POR QUE NÃO FILTRAR POR status='outro' (etapa 10 vs 60 colapsam em 'outro')");
try {
    $sql = "SELECT status_comercial, etapa_omie, COUNT(*) AS pedidos
            FROM {$ORDERS}
            WHERE status_comercial = 'outro'
            GROUP BY status_comercial, etapa_omie
            ORDER BY pedidos DESC";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $view = array_map(static fn (array $r): array => [
        'etapa'   => ($r['etapa_omie'] ?? '') === '' ? '(nula)' : (string) $r['etapa_omie'],
        'pedidos' => fmtN((int) $r['pedidos']),
    ], $rows);
    table($view, ['etapa' => "ETAPA dentro de 'outro'", 'pedidos' => 'PEDIDOS']);
} catch (Throwable $e) {
    echo '    ERRO seção C: ' . $e->getMessage() . PHP_EOL;
}

// ── SECTION D — Escopo dos orçamentos — gate 3 ───────────────────────────────────
sep('D. ESCOPO DOS ORÇAMENTOS (etapa 10) — gate 3 (confirmed/provisional/excluded)');
if (!$hasScope) {
    echo "    operation_scope AUSENTE na tabela — todos entrariam como 'provisional'." . PHP_EOL;
} else {
    try {
        $sql = "SELECT COALESCE(NULLIF(operation_scope,''),'(null)') AS scope, COUNT(*) AS pedidos,
                       COALESCE(SUM(valor_total),0) AS total
                FROM {$ORDERS}
                WHERE etapa_omie = '10'
                GROUP BY scope
                ORDER BY pedidos DESC";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $bucket = ['commercial_confirmed' => 0, 'commercial_provisional' => 0, 'excluded_remittance' => 0];
        $view = [];
        foreach ($rows as $r) {
            $scope = (string) $r['scope'];
            $n = (int) $r['pedidos'];
            $tag = match ($scope) {
                'commercial' => 'commercial_confirmed',
                'remittance' => 'excluded_remittance',
                default      => 'commercial_provisional',
            };
            $bucket[$tag] += $n;
            $view[] = ['scope' => $scope, 'tag' => $tag, 'pedidos' => fmtN($n), 'valor' => fmtR((float) $r['total'])];
        }
        table($view, ['scope' => 'operation_scope', 'tag' => 'TRI-STATE', 'pedidos' => 'PEDIDOS', 'valor' => 'VALOR']);
        echo PHP_EOL;
        echo '    confirmed  : ' . fmtN($bucket['commercial_confirmed']) . PHP_EOL;
        echo '    provisional: ' . fmtN($bucket['commercial_provisional']) . '   ← KPI técnico admin' . PHP_EOL;
        echo '    excluded   : ' . fmtN($bucket['excluded_remittance']) . PHP_EOL;
    } catch (Throwable $e) {
        echo '    ERRO seção D: ' . $e->getMessage() . PHP_EOL;
    }
}

// ── SECTION E — Qualidade: sem vendedor / sem pedido_id — gate 4,5 ────────────────
sep('E. QUALIDADE DOS ORÇAMENTOS ABERTOS — gate 4, 5');
try {
    $sql = "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN codigo_vendedor_omie IN ('','0') OR codigo_vendedor_omie IS NULL
                          OR nome_vendedor = 'Sem vendedor' THEN 1 ELSE 0 END) AS sem_vendedor,
                SUM(CASE WHEN pedido_id_omie IS NULL OR pedido_id_omie = 0 THEN 1 ELSE 0 END) AS sem_pedido_id,
                SUM(CASE WHEN codigo_cliente_omie IS NULL OR codigo_cliente_omie = 0 THEN 1 ELSE 0 END) AS sem_cliente
            FROM {$ORDERS}
            WHERE etapa_omie = '10'";
    $r = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];
    echo '    orçamentos etapa 10          : ' . fmtN((int) ($r['total'] ?? 0)) . PHP_EOL;
    echo '    sem vendedor (crítico)       : ' . fmtN((int) ($r['sem_vendedor'] ?? 0)) . PHP_EOL;
    echo '    sem pedido_id_omie (excluir) : ' . fmtN((int) ($r['sem_pedido_id'] ?? 0)) . PHP_EOL;
    echo '    sem cliente                  : ' . fmtN((int) ($r['sem_cliente'] ?? 0)) . PHP_EOL;
} catch (Throwable $e) {
    echo '    ERRO seção E: ' . $e->getMessage() . PHP_EOL;
}

// ── SECTION F — Amostra dInc × previsão × data_emissao — gate 6 (opcional) ────────
sep('F. AMOSTRA: dInc × data_previsao × data_emissao — gate 6');
if (!$withBronze) {
    echo '    (pulado — rode com --with-bronze-sample para ler biomie_omie_bronze)' . PHP_EOL;
} elseif (!tableExists($pdo, 'biomie_omie_bronze')) {
    echo '    biomie_omie_bronze não existe neste banco.' . PHP_EOL;
} else {
    try {
        // Descobre quais entity_name do bronze guardam payloads de pedido.
        $ent = $pdo->query(
            "SELECT entity_name, COUNT(*) c FROM biomie_omie_bronze
             WHERE entity_name LIKE '%order%' OR entity_name LIKE '%pedido%'
             GROUP BY entity_name ORDER BY c DESC LIMIT 10"
        )->fetchAll(PDO::FETCH_ASSOC);
        echo '    entity_name candidatos (pedido): ' .
            (implode(', ', array_map(static fn ($e) => $e['entity_name'] . '(' . $e['c'] . ')', $ent)) ?: '(nenhum)') . PHP_EOL . PHP_EOL;

        $sql = "SELECT
                    JSON_UNQUOTE(JSON_EXTRACT(b.payload_json,'$.cabecalho.codigo_pedido'))  AS cod,
                    JSON_UNQUOTE(JSON_EXTRACT(b.payload_json,'$.cabecalho.etapa'))          AS etapa,
                    JSON_UNQUOTE(JSON_EXTRACT(b.payload_json,'$.infoCadastro.dInc'))        AS dinc,
                    JSON_UNQUOTE(JSON_EXTRACT(b.payload_json,'$.cabecalho.data_previsao'))  AS prev,
                    p.data_emissao                                                          AS data_emissao_canon
                FROM biomie_omie_bronze b
                LEFT JOIN {$ORDERS} p
                    ON p.pedido_id_omie = JSON_EXTRACT(b.payload_json,'$.cabecalho.codigo_pedido')
                WHERE (b.entity_name LIKE '%order%' OR b.entity_name LIKE '%pedido%')
                  AND JSON_EXTRACT(b.payload_json,'$.infoCadastro.dInc') IS NOT NULL
                ORDER BY b.ingested_at DESC
                LIMIT {$sampleN}";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $view = array_map(static fn (array $r): array => [
            'cod'   => (string) ($r['cod'] ?? ''),
            'etapa' => (string) ($r['etapa'] ?? ''),
            'dinc'  => (string) ($r['dinc'] ?? ''),
            'prev'  => (string) ($r['prev'] ?? ''),
            'canon' => (string) ($r['data_emissao_canon'] ?? '(sem match)'),
            'match' => (string) ($r['dinc'] ?? '') !== '' && (string) ($r['prev'] ?? '') !== '' &&
                       $r['dinc'] !== $r['prev'] ? 'dInc≠prev ⚠' : '',
        ], $rows);
        table($view, [
            'cod' => 'PEDIDO', 'etapa' => 'ETAPA', 'dinc' => 'dInc (inclusão)',
            'prev' => 'data_previsao', 'canon' => 'data_emissao(canon)', 'match' => 'OBS',
        ]);
        echo PHP_EOL . '    → Se data_emissao(canon) bate com data_previsao e ≠ dInc, confirma o achado do mapper.' . PHP_EOL;
    } catch (Throwable $e) {
        echo '    ERRO seção F: ' . $e->getMessage() . PHP_EOL;
    }
}

// ── SECTION G — Drift vs baseline versionado (2026-07-15) ────────────────────────
sep('G. DRIFT VS BASELINE (2026-07-15) — informativo, não é gate de falha');
try {
    $cur = $pdo->query(
        "SELECT COUNT(*) AS pedidos, COALESCE(SUM(valor_total),0) AS valor
         FROM {$ORDERS} WHERE etapa_omie = '10'"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $curN = (int) ($cur['pedidos'] ?? 0);
    $curV = (float) ($cur['valor'] ?? 0);
    $dN = $curN - BASELINE_2026_07_15['etapa10_pedidos'];
    $dV = $curV - BASELINE_2026_07_15['etapa10_valor'];
    echo '    orçamentos etapa 10 : baseline ' . fmtN(BASELINE_2026_07_15['etapa10_pedidos'])
        . '  →  agora ' . fmtN($curN) . '  (' . ($dN >= 0 ? '+' : '') . fmtN($dN) . ')' . PHP_EOL;
    echo '    valor total         : baseline ' . fmtR(BASELINE_2026_07_15['etapa10_valor'])
        . '  →  agora ' . fmtR($curV) . '  (' . ($dV >= 0 ? '+' : '') . fmtR($dV) . ')' . PHP_EOL;
    echo '    → diferenças são normais (movimentação Omie); investigar só se houver queda abrupta e inexplicada.' . PHP_EOL;
} catch (Throwable $e) {
    echo '    ERRO seção G: ' . $e->getMessage() . PHP_EOL;
}

sep();
printf("  concluído em %.2fs%s", microtime(true) - $t0, PHP_EOL);
