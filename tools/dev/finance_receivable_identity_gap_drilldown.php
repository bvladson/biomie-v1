<?php

declare(strict_types=1);

/**
 * FASE 0 — FIN.R4.4.1: Diagnóstico de chave e vínculo NF×título.
 *
 * Read-only. Identifica exatamente onde o resolver falha e por quê:
 *   A) Compatibilidade de chave (nota_key vs chave_nfe vs nf.chave_nfe)
 *   B) Vínculo NF → Pedido
 *   C) Vínculo NF/Pedido → Vendedor
 *   D) Status da bridge NF×título
 *
 * Uso:
 *   php tools/dev/finance_receivable_identity_gap_drilldown.php \
 *       --start=2026-05-01 --end=2026-05-31 --pretty --sample=30
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;

$opts   = getopt('', ['start:', 'end:', 'pretty', 'json', 'sample:', 'help']);
if (isset($opts['help'])) {
    echo "Usage: php finance_receivable_identity_gap_drilldown.php --start=YYYY-MM-DD --end=YYYY-MM-DD [--pretty] [--json] [--sample=30]\n";
    exit(0);
}

$start  = (string) ($opts['start']  ?? date('Y-m-01'));
$end    = (string) ($opts['end']    ?? date('Y-m-d'));
$pretty = isset($opts['pretty']);
$asJson = isset($opts['json']);
$sample = max(1, min(100, (int) ($opts['sample'] ?? 30)));

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

function tblExists(PDO $pdo, string $tbl): bool
{
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t LIMIT 1");
        $s->execute(['t' => $tbl]);
        return (int) $s->fetchColumn() > 0;
    } catch (\Throwable) { return false; }
}

function safeQ(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $s = $pdo->prepare($sql);
        $s->execute($params);
        return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable) { return []; }
}

function safeScalar(PDO $pdo, string $sql, array $params = []): mixed
{
    try {
        $s = $pdo->prepare($sql);
        $s->execute($params);
        return $s->fetchColumn();
    } catch (\Throwable) { return 0; }
}

$hasNf       = tblExists($pdo, 'biomie_notas_fiscais_canonicas');
$hasBridgeI  = tblExists($pdo, 'biomie_bridge_nf_titulo_items');
$hasBridgeS  = tblExists($pdo, 'biomie_bridge_nf_titulo_summary');
$hasPedido   = tblExists($pdo, 'biomie_pedidos_canonicos');
$hasCliente  = tblExists($pdo, 'biomie_clientes_dim');

$tables = [
    'biomie_notas_fiscais_canonicas'  => $hasNf,
    'biomie_bridge_nf_titulo_items'   => $hasBridgeI,
    'biomie_bridge_nf_titulo_summary' => $hasBridgeS,
    'biomie_pedidos_canonicos'        => $hasPedido,
    'biomie_clientes_dim'             => $hasCliente,
];

// ── Totals ────────────────────────────────────────────────────────────────────
$totalTitulos = (int) safeScalar($pdo,
    "SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos WHERE data_competencia BETWEEN :s AND :e",
    ['s' => $start, 'e' => $end]
);
$totalNfs = $hasNf ? (int) safeScalar($pdo, "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas") : 0;

if ($totalTitulos === 0) {
    $out = ['ok' => true, 'period' => compact('start', 'end'), 'total_titulos' => 0, 'message' => 'Nenhum título no período.'];
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION A — Compatibilidade de chave
// ═══════════════════════════════════════════════════════════════════════════════

$sectA = [];

// A1: titulos com nota_key preenchido
$sectA['a1_com_nota_key'] = (int) safeScalar($pdo,
    "SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos WHERE data_competencia BETWEEN :s AND :e AND nota_key IS NOT NULL AND nota_key != ''",
    ['s' => $start, 'e' => $end]
);

// A2: titulos com nota_key de 44 chars (chave_nfe format)
$sectA['a2_nota_key_44chars'] = (int) safeScalar($pdo,
    "SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos WHERE data_competencia BETWEEN :s AND :e AND CHAR_LENGTH(nota_key) = 44",
    ['s' => $start, 'e' => $end]
);

// A3: titulos onde nota_key bate com nf.nota_key (formato omie:XXXXX)
$sectA['a3_nota_key_eq_nf_nota_key'] = $hasNf ? (int) safeScalar($pdo,
    "SELECT COUNT(DISTINCT t.titulo_key) FROM biomie_titulos_financeiros_canonicos t
     JOIN biomie_notas_fiscais_canonicas nf ON t.nota_key = nf.nota_key
     WHERE t.data_competencia BETWEEN :s AND :e",
    ['s' => $start, 'e' => $end]
) : 0;

// A4: titulos onde nota_key bate com nf.chave_nfe (chave_nfe guardada em nota_key do título)
$sectA['a4_nota_key_eq_nf_chave_nfe'] = $hasNf ? (int) safeScalar($pdo,
    "SELECT COUNT(DISTINCT t.titulo_key) FROM biomie_titulos_financeiros_canonicos t
     JOIN biomie_notas_fiscais_canonicas nf ON t.nota_key = nf.chave_nfe AND nf.chave_nfe IS NOT NULL AND nf.chave_nfe != ''
     WHERE t.data_competencia BETWEEN :s AND :e",
    ['s' => $start, 'e' => $end]
) : 0;

// A5: titulos onde chave_nfe do título bate com nf.chave_nfe (join direto correto)
$sectA['a5_chave_nfe_eq_nf_chave_nfe'] = $hasNf ? (int) safeScalar($pdo,
    "SELECT COUNT(DISTINCT t.titulo_key) FROM biomie_titulos_financeiros_canonicos t
     JOIN biomie_notas_fiscais_canonicas nf ON t.chave_nfe = nf.chave_nfe AND nf.chave_nfe IS NOT NULL AND nf.chave_nfe != ''
     WHERE t.data_competencia BETWEEN :s AND :e",
    ['s' => $start, 'e' => $end]
) : 0;

// A6: titulos onde documento_origem_ref bate com nf.chave_nfe
$sectA['a6_doc_origem_ref_eq_nf_chave_nfe'] = $hasNf ? (int) safeScalar($pdo,
    "SELECT COUNT(DISTINCT t.titulo_key) FROM biomie_titulos_financeiros_canonicos t
     JOIN biomie_notas_fiscais_canonicas nf ON t.documento_origem_ref = nf.chave_nfe AND nf.chave_nfe IS NOT NULL AND nf.chave_nfe != ''
     WHERE t.data_competencia BETWEEN :s AND :e",
    ['s' => $start, 'e' => $end]
) : 0;

// A7: titulos onde numero_documento bate com nf.numero_nf
$sectA['a7_numero_doc_eq_nf_numero'] = $hasNf ? (int) safeScalar($pdo,
    "SELECT COUNT(DISTINCT t.titulo_key) FROM biomie_titulos_financeiros_canonicos t
     JOIN biomie_notas_fiscais_canonicas nf ON t.numero_documento = nf.numero_nf AND nf.numero_nf IS NOT NULL AND nf.numero_nf != ''
     WHERE t.data_competencia BETWEEN :s AND :e",
    ['s' => $start, 'e' => $end]
) : 0;

// A8: melhor cobertura total (union de A4+A5+A6+A7)
$sectA['a8_any_nf_route'] = $hasNf ? (int) safeScalar($pdo,
    "SELECT COUNT(DISTINCT t.titulo_key)
     FROM biomie_titulos_financeiros_canonicos t
     WHERE t.data_competencia BETWEEN :s AND :e
       AND EXISTS (
         SELECT 1 FROM biomie_notas_fiscais_canonicas nf WHERE
           (t.nota_key = nf.chave_nfe AND nf.chave_nfe != '')
           OR (t.chave_nfe = nf.chave_nfe AND t.chave_nfe != '' AND nf.chave_nfe != '')
           OR (t.documento_origem_ref = nf.chave_nfe AND nf.chave_nfe != '')
           OR t.numero_documento = nf.numero_nf
       )",
    ['s' => $start, 'e' => $end]
) : 0;

// A9: titulos sem nenhuma rota de NF
$sectA['a9_sem_rota_nf'] = $totalTitulos - $sectA['a8_any_nf_route'];

// Nota_key format sample
$nfKeyFormatSample = safeQ($pdo,
    "SELECT nota_key, CHAR_LENGTH(nota_key) AS len FROM biomie_notas_fiscais_canonicas LIMIT 3"
);
$sectA['nf_nota_key_format_sample'] = $nfKeyFormatSample;

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION B — Vínculo NF → Pedido
// ═══════════════════════════════════════════════════════════════════════════════

$sectB = [];

if ($hasNf) {
    // B1: NFs encontradas (via chave_nfe join) com pedido_id_omie preenchido
    $sectB['b1_nf_com_pedido_id'] = (int) safeScalar($pdo,
        "SELECT COUNT(DISTINCT nf.nota_key)
         FROM biomie_notas_fiscais_canonicas nf
         JOIN biomie_titulos_financeiros_canonicos t ON t.chave_nfe = nf.chave_nfe
         WHERE t.data_competencia BETWEEN :s AND :e
           AND nf.pedido_id_omie IS NOT NULL AND nf.pedido_id_omie > 0",
        ['s' => $start, 'e' => $end]
    );

    // B2: NFs com codigo_pedido_origem
    $sectB['b2_nf_com_codigo_pedido'] = (int) safeScalar($pdo,
        "SELECT COUNT(DISTINCT nf.nota_key)
         FROM biomie_notas_fiscais_canonicas nf
         JOIN biomie_titulos_financeiros_canonicos t ON t.chave_nfe = nf.chave_nfe
         WHERE t.data_competencia BETWEEN :s AND :e
           AND nf.codigo_pedido_origem IS NOT NULL AND nf.codigo_pedido_origem != ''",
        ['s' => $start, 'e' => $end]
    );

    // B3: NFs sem pedido
    $sectB['b3_nf_sem_pedido'] = (int) safeScalar($pdo,
        "SELECT COUNT(DISTINCT nf.nota_key)
         FROM biomie_notas_fiscais_canonicas nf
         JOIN biomie_titulos_financeiros_canonicos t ON t.chave_nfe = nf.chave_nfe
         WHERE t.data_competencia BETWEEN :s AND :e
           AND (nf.pedido_id_omie IS NULL OR nf.pedido_id_omie = 0)
           AND (nf.codigo_pedido_origem IS NULL OR nf.codigo_pedido_origem = '')",
        ['s' => $start, 'e' => $end]
    );

    // B4: pedidos efetivamente resolvidos em biomie_pedidos_canonicos
    $sectB['b4_pedido_em_canonicos'] = ($hasPedido) ? (int) safeScalar($pdo,
        "SELECT COUNT(DISTINCT t.titulo_key)
         FROM biomie_titulos_financeiros_canonicos t
         JOIN biomie_notas_fiscais_canonicas nf ON t.chave_nfe = nf.chave_nfe
         JOIN biomie_pedidos_canonicos p ON p.pedido_id_omie = nf.pedido_id_omie
         WHERE t.data_competencia BETWEEN :s AND :e",
        ['s' => $start, 'e' => $end]
    ) : 0;

    // B5: exemplos de NF sem pedido
    $sectB['b5_exemplos_nf_sem_pedido'] = safeQ($pdo,
        "SELECT nf.nota_key, nf.numero_nf, nf.chave_nfe, nf.pedido_id_omie, nf.codigo_pedido_origem,
                t.titulo_key, t.nome_cliente, t.codigo_cliente_omie
         FROM biomie_notas_fiscais_canonicas nf
         JOIN biomie_titulos_financeiros_canonicos t ON t.chave_nfe = nf.chave_nfe
         WHERE t.data_competencia BETWEEN :s AND :e
           AND (nf.pedido_id_omie IS NULL OR nf.pedido_id_omie = 0)
           AND (nf.codigo_pedido_origem IS NULL OR nf.codigo_pedido_origem = '')
         LIMIT :lim",
        ['s' => $start, 'e' => $end, 'lim' => min($sample, 10)]
    );
} else {
    $sectB = ['error' => 'biomie_notas_fiscais_canonicas não disponível'];
}

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION C — Vínculo NF/Pedido → Vendedor
// ═══════════════════════════════════════════════════════════════════════════════

$sectC = [];

if ($hasNf) {
    // C1: NFs com codigo_vendedor_omie
    $sectC['c1_nf_com_codigo_vendedor'] = (int) safeScalar($pdo,
        "SELECT COUNT(DISTINCT nf.nota_key)
         FROM biomie_notas_fiscais_canonicas nf
         JOIN biomie_titulos_financeiros_canonicos t ON t.chave_nfe = nf.chave_nfe
         WHERE t.data_competencia BETWEEN :s AND :e
           AND nf.codigo_vendedor_omie IS NOT NULL AND nf.codigo_vendedor_omie != '' AND nf.codigo_vendedor_omie != '0'",
        ['s' => $start, 'e' => $end]
    );

    // C2: NFs com nome_vendedor valido
    $UNKNOWN_V = "('sem vendedor','sem vendedor definido','nao informado','nao identificado','')";
    $sectC['c2_nf_com_nome_vendedor'] = (int) safeScalar($pdo,
        "SELECT COUNT(DISTINCT nf.nota_key)
         FROM biomie_notas_fiscais_canonicas nf
         JOIN biomie_titulos_financeiros_canonicos t ON t.chave_nfe = nf.chave_nfe
         WHERE t.data_competencia BETWEEN :s AND :e
           AND nf.nome_vendedor IS NOT NULL
           AND LOWER(TRIM(nf.nome_vendedor)) NOT IN {$UNKNOWN_V}",
        ['s' => $start, 'e' => $end]
    );

    // C3: pedidos com codigo_vendedor_omie
    $sectC['c3_pedido_com_codigo_vendedor'] = ($hasPedido) ? (int) safeScalar($pdo,
        "SELECT COUNT(DISTINCT p.pedido_id_omie)
         FROM biomie_pedidos_canonicos p
         JOIN biomie_notas_fiscais_canonicas nf ON p.pedido_id_omie = nf.pedido_id_omie
         JOIN biomie_titulos_financeiros_canonicos t ON t.chave_nfe = nf.chave_nfe
         WHERE t.data_competencia BETWEEN :s AND :e
           AND p.codigo_vendedor_omie IS NOT NULL AND p.codigo_vendedor_omie != ''",
        ['s' => $start, 'e' => $end]
    ) : 0;

    // C4: pedidos com nome_vendedor valido
    $sectC['c4_pedido_com_nome_vendedor'] = ($hasPedido) ? (int) safeScalar($pdo,
        "SELECT COUNT(DISTINCT p.pedido_id_omie)
         FROM biomie_pedidos_canonicos p
         JOIN biomie_notas_fiscais_canonicas nf ON p.pedido_id_omie = nf.pedido_id_omie
         JOIN biomie_titulos_financeiros_canonicos t ON t.chave_nfe = nf.chave_nfe
         WHERE t.data_competencia BETWEEN :s AND :e
           AND p.nome_vendedor IS NOT NULL
           AND LOWER(TRIM(p.nome_vendedor)) NOT IN {$UNKNOWN_V}",
        ['s' => $start, 'e' => $end]
    ) : 0;

    // C5: titulos que teriam vendedor resolvido via chave_nfe→NF
    $sectC['c5_titulos_com_vendedor_via_nf'] = (int) safeScalar($pdo,
        "SELECT COUNT(DISTINCT t.titulo_key)
         FROM biomie_titulos_financeiros_canonicos t
         JOIN biomie_notas_fiscais_canonicas nf ON t.chave_nfe = nf.chave_nfe
         WHERE t.data_competencia BETWEEN :s AND :e
           AND nf.codigo_vendedor_omie IS NOT NULL AND nf.codigo_vendedor_omie != '' AND nf.codigo_vendedor_omie != '0'",
        ['s' => $start, 'e' => $end]
    );

    // C6: exemplos de NF com vendedor no comercial mas não retornando
    $sectC['c6_exemplos_nf_com_vendedor'] = safeQ($pdo,
        "SELECT nf.nota_key, nf.numero_nf, nf.codigo_vendedor_omie AS nf_vendedor_codigo,
                nf.nome_vendedor AS nf_nome_vendedor, t.titulo_key,
                t.codigo_vendedor_omie AS titulo_vendedor_codigo, t.nome_vendedor AS titulo_nome_vendedor,
                t.chave_nfe
         FROM biomie_notas_fiscais_canonicas nf
         JOIN biomie_titulos_financeiros_canonicos t ON t.chave_nfe = nf.chave_nfe
         WHERE t.data_competencia BETWEEN :s AND :e
           AND nf.codigo_vendedor_omie IS NOT NULL AND nf.codigo_vendedor_omie != ''
         LIMIT :lim",
        ['s' => $start, 'e' => $end, 'lim' => min($sample, 10)]
    );
} else {
    $sectC = ['error' => 'biomie_notas_fiscais_canonicas não disponível'];
}

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION D — Bridge NF×título
// ═══════════════════════════════════════════════════════════════════════════════

$sectD = [];

// D1: rows em bridge_summary no período (join com títulos por data)
$sectD['d1_bridge_summary_rows_periodo'] = ($hasBridgeS) ? (int) safeScalar($pdo,
    "SELECT COUNT(*) FROM biomie_bridge_nf_titulo_summary bs
     JOIN biomie_titulos_financeiros_canonicos t ON t.titulo_key = bs.titulo_key
     WHERE t.data_competencia BETWEEN :s AND :e",
    ['s' => $start, 'e' => $end]
) : 0;

// D2: rows em bridge_items no período
$sectD['d2_bridge_items_rows_periodo'] = ($hasBridgeI) ? (int) safeScalar($pdo,
    "SELECT COUNT(*) FROM biomie_bridge_nf_titulo_items bi
     JOIN biomie_titulos_financeiros_canonicos t ON t.titulo_key = bi.titulo_key
     WHERE t.data_competencia BETWEEN :s AND :e",
    ['s' => $start, 'e' => $end]
) : 0;

// D3: títulos que resolveriam via bridge_summary
$sectD['d3_titulos_resolvem_via_summary'] = ($hasBridgeS) ? (int) safeScalar($pdo,
    "SELECT COUNT(DISTINCT t.titulo_key)
     FROM biomie_titulos_financeiros_canonicos t
     JOIN biomie_bridge_nf_titulo_summary bs ON bs.titulo_key = t.titulo_key
     WHERE t.data_competencia BETWEEN :s AND :e",
    ['s' => $start, 'e' => $end]
) : 0;

// D4: títulos que resolveriam via bridge_items
$sectD['d4_titulos_resolvem_via_items'] = ($hasBridgeI) ? (int) safeScalar($pdo,
    "SELECT COUNT(DISTINCT t.titulo_key)
     FROM biomie_titulos_financeiros_canonicos t
     JOIN biomie_bridge_nf_titulo_items bi ON bi.titulo_key = t.titulo_key
     WHERE t.data_competencia BETWEEN :s AND :e",
    ['s' => $start, 'e' => $end]
) : 0;

// D5: títulos que poderiam ser adicionados à bridge (chave_nfe match mas sem bridge)
$sectD['d5_oportunidades_bridge'] = ($hasBridgeI && $hasNf) ? (int) safeScalar($pdo,
    "SELECT COUNT(DISTINCT t.titulo_key)
     FROM biomie_titulos_financeiros_canonicos t
     JOIN biomie_notas_fiscais_canonicas nf ON t.chave_nfe = nf.chave_nfe AND t.chave_nfe != ''
     WHERE t.data_competencia BETWEEN :s AND :e
       AND NOT EXISTS (
         SELECT 1 FROM biomie_bridge_nf_titulo_items bi WHERE bi.titulo_key = t.titulo_key
       )",
    ['s' => $start, 'e' => $end]
) : 0;

// D6: exemplos de oportunidades de bridge
$sectD['d6_exemplos_oportunidades_bridge'] = ($hasBridgeI && $hasNf) ? safeQ($pdo,
    "SELECT t.titulo_key, t.chave_nfe, nf.nota_key, nf.numero_nf,
            nf.codigo_vendedor_omie, nf.nome_vendedor, nf.pedido_id_omie
     FROM biomie_titulos_financeiros_canonicos t
     JOIN biomie_notas_fiscais_canonicas nf ON t.chave_nfe = nf.chave_nfe AND t.chave_nfe != ''
     WHERE t.data_competencia BETWEEN :s AND :e
       AND NOT EXISTS (
         SELECT 1 FROM biomie_bridge_nf_titulo_items bi WHERE bi.titulo_key = t.titulo_key
       )
     LIMIT :lim",
    ['s' => $start, 'e' => $end, 'lim' => min($sample, 10)]
) : [];

// ═══════════════════════════════════════════════════════════════════════════════
// SAMPLES — Títulos sem rota de NF e com gap de vendedor
// ═══════════════════════════════════════════════════════════════════════════════

$samplesNoNf = safeQ($pdo,
    "SELECT titulo_key, nota_key, CHAR_LENGTH(nota_key) AS nota_key_len,
            chave_nfe, CHAR_LENGTH(chave_nfe) AS chave_nfe_len,
            documento_origem_ref, numero_documento, nome_cliente, nome_vendedor,
            codigo_vendedor_omie, data_vencimento, valor_aberto
     FROM biomie_titulos_financeiros_canonicos
     WHERE data_competencia BETWEEN :s AND :e
       AND (chave_nfe IS NULL OR chave_nfe = '')
     LIMIT :lim",
    ['s' => $start, 'e' => $end, 'lim' => min($sample, 10)]
);

$samplesNoVendor = ($hasNf) ? safeQ($pdo,
    "SELECT t.titulo_key, t.chave_nfe, nf.nota_key, nf.numero_nf,
            nf.codigo_vendedor_omie AS nf_vendedor_codigo,
            nf.nome_vendedor AS nf_nome_vendedor,
            t.codigo_vendedor_omie AS titulo_vendedor_codigo,
            t.nome_vendedor AS titulo_nome_vendedor,
            t.nome_cliente, t.valor_aberto
     FROM biomie_titulos_financeiros_canonicos t
     JOIN biomie_notas_fiscais_canonicas nf ON t.chave_nfe = nf.chave_nfe
     WHERE t.data_competencia BETWEEN :s AND :e
       AND (nf.codigo_vendedor_omie IS NULL OR nf.codigo_vendedor_omie = '' OR nf.codigo_vendedor_omie = '0')
     LIMIT :lim",
    ['s' => $start, 'e' => $end, 'lim' => min($sample, 10)]
) : [];

// ═══════════════════════════════════════════════════════════════════════════════
// RECOMMENDATION ENGINE
// ═══════════════════════════════════════════════════════════════════════════════

$pct = static fn(int $n, int $tot): float => $tot > 0 ? round($n / $tot * 100, 1) : 0.0;

$resolverBugNote  = ($sectA['a4_nota_key_eq_nf_chave_nfe'] > 0 || $sectA['a5_chave_nfe_eq_nf_chave_nfe'] > 0)
    && ($sectC['c1_nf_com_codigo_vendedor'] ?? 0) > 0
    ? 'resolver_lookup_bug: nota_key guardada como chave_nfe — resolver precisa tentar nfByChaveNfe[nota_key]'
    : null;

$bridgeMissingNote = ($sectD['d5_oportunidades_bridge'] ?? 0) > 0
    ? "bridge_missing: {$sectD['d5_oportunidades_bridge']} títulos poderiam ser adicionados via chave_nfe join — rematerializar bridge"
    : null;

$realGapNote = ($sectA['a9_sem_rota_nf'] ?? 0) > 0
    ? "gap_real: {$sectA['a9_sem_rota_nf']} títulos sem chave_nfe na tabela NF — NF não sincronizada ou título sem NF"
    : null;

$recommendations = array_values(array_filter([$resolverBugNote, $bridgeMissingNote, $realGapNote]));
if ($recommendations === []) {
    $recommendations[] = 'Nenhuma ação identificada automaticamente — verificar logs manualmente.';
}

// ═══════════════════════════════════════════════════════════════════════════════
// OUTPUT
// ═══════════════════════════════════════════════════════════════════════════════

$result = [
    'ok'     => true,
    'period' => ['start' => $start, 'end' => $end, 'generated_at' => date(DATE_ATOM)],
    'tables' => $tables,
    'totals' => ['titulos' => $totalTitulos, 'nfs_canonicas' => $totalNfs],
    'section_a_key_compatibility' => array_merge($sectA, [
        'pct_a4_nota_key_via_chave_nfe' => $pct($sectA['a4_nota_key_eq_nf_chave_nfe'], $totalTitulos),
        'pct_a5_chave_nfe_direta'        => $pct($sectA['a5_chave_nfe_eq_nf_chave_nfe'], $totalTitulos),
        'pct_a8_qualquer_rota'           => $pct($sectA['a8_any_nf_route'], $totalTitulos),
        'pct_a9_sem_rota'                => $pct($sectA['a9_sem_rota_nf'], $totalTitulos),
    ]),
    'section_b_nf_pedido'         => $sectB,
    'section_c_vendedor'          => $sectC,
    'section_d_bridge'            => $sectD,
    'samples_sem_nf'              => $samplesNoNf,
    'samples_nf_sem_vendedor'     => $samplesNoVendor,
    'recommendations'             => $recommendations,
    'diagnostics' => ['source' => 'finance_receivable_identity_gap_drilldown'],
];

if ($asJson || !$pretty) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

// ── Pretty output ─────────────────────────────────────────────────────────────
$G = "\033[32m"; $R = "\033[31m"; $Y = "\033[33m"; $B = "\033[34m"; $D = "\033[0m"; $bold = "\033[1m";
$ok  = fn(string $s) => "{$G}✔{$D} {$s}";
$bad = fn(string $s) => "{$R}✖{$D} {$s}";
$warn = fn(string $s) => "{$Y}⚠{$D} {$s}";

echo "\n{$bold}┌──────────────────────────────────────────────────────────────────┐{$D}\n";
echo "{$bold}│  FIN.R4.4.1 — Finance Identity Gap Drilldown                       │{$D}\n";
echo "{$bold}│  Período: {$start} → {$end}                                         │{$D}\n";
echo "{$bold}└──────────────────────────────────────────────────────────────────┘{$D}\n\n";

echo "{$bold}TABELAS DISPONÍVEIS{$D}\n";
foreach ($tables as $tbl => $available) {
    echo "  " . ($available ? $ok($tbl) : $bad($tbl)) . "\n";
}

echo "\n{$bold}TOTAIS{$D}\n";
echo "  Títulos no período : {$totalTitulos}\n";
echo "  NFs canônicas total: {$totalNfs}\n";

echo "\n{$bold}──── A) COMPATIBILIDADE DE CHAVE ────{$D}\n";
$a = $sectA;
echo "  A1 Títulos com nota_key                : {$a['a1_com_nota_key']}\n";
echo "  A2 Títulos com nota_key de 44 chars    : {$a['a2_nota_key_44chars']} (chave_nfe no campo nota_key)\n";
echo "  A3 nota_key = nf.nota_key              : " . ($a['a3_nota_key_eq_nf_nota_key'] > 0 ? $ok($a['a3_nota_key_eq_nf_nota_key']) : $bad($a['a3_nota_key_eq_nf_nota_key'])) . "\n";
echo "  A4 nota_key = nf.chave_nfe             : " . ($a['a4_nota_key_eq_nf_chave_nfe'] > 0 ? $ok("{$a['a4_nota_key_eq_nf_chave_nfe']} ({$result['section_a_key_compatibility']['pct_a4_nota_key_via_chave_nfe']}%)") : $bad("0")) . "\n";
echo "  A5 chave_nfe = nf.chave_nfe            : " . ($a['a5_chave_nfe_eq_nf_chave_nfe'] > 0 ? $ok("{$a['a5_chave_nfe_eq_nf_chave_nfe']} ({$result['section_a_key_compatibility']['pct_a5_chave_nfe_direta']}%)") : $bad("0")) . "\n";
echo "  A6 doc_origem_ref = nf.chave_nfe       : {$a['a6_doc_origem_ref_eq_nf_chave_nfe']}\n";
echo "  A7 numero_documento = nf.numero_nf     : {$a['a7_numero_doc_eq_nf_numero']}\n";
echo "  A8 Qualquer rota de NF                 : " . ($a['a8_any_nf_route'] > 0 ? $ok("{$a['a8_any_nf_route']} ({$result['section_a_key_compatibility']['pct_a8_qualquer_rota']}%)") : $bad("0")) . "\n";
echo "  A9 Sem nenhuma rota de NF              : " . ($a['a9_sem_rota_nf'] > 0 ? $warn("{$a['a9_sem_rota_nf']} ({$result['section_a_key_compatibility']['pct_a9_sem_rota']}%)") : $ok("0")) . "\n";

if ($nfKeyFormatSample !== []) {
    echo "\n  NF nota_key format: '{$nfKeyFormatSample[0]['nota_key']}' (len={$nfKeyFormatSample[0]['len']})\n";
    echo "  Título nota_key format: 44-char chave_nfe\n";
    echo "  {$Y}→ DIVERGÊNCIA: resolver Path 2 (titulo.nota_key = nf.nota_key) nunca vai bater!{$D}\n";
    echo "  {$G}→ FIX: resolver deve tentar nfByChaveNfe[titulo.nota_key] quando len=44{$D}\n";
}

echo "\n{$bold}──── B) NF → PEDIDO ────{$D}\n";
if (isset($sectB['error'])) {
    echo "  {$R}{$sectB['error']}{$D}\n";
} else {
    echo "  B1 NFs com pedido_id_omie              : {$sectB['b1_nf_com_pedido_id']}\n";
    echo "  B2 NFs com codigo_pedido_origem        : {$sectB['b2_nf_com_codigo_pedido']}\n";
    echo "  B3 NFs sem pedido                      : " . ($sectB['b3_nf_sem_pedido'] > 0 ? $warn($sectB['b3_nf_sem_pedido']) : $ok("0")) . "\n";
    echo "  B4 Pedidos em biomie_pedidos_canonicos : {$sectB['b4_pedido_em_canonicos']}\n";
}

echo "\n{$bold}──── C) NF/PEDIDO → VENDEDOR ────{$D}\n";
if (isset($sectC['error'])) {
    echo "  {$R}{$sectC['error']}{$D}\n";
} else {
    echo "  C1 NFs com codigo_vendedor_omie        : " . ($sectC['c1_nf_com_codigo_vendedor'] > 0 ? $ok($sectC['c1_nf_com_codigo_vendedor']) : $bad("0")) . "\n";
    echo "  C2 NFs com nome_vendedor válido        : {$sectC['c2_nf_com_nome_vendedor']}\n";
    echo "  C3 Pedidos com codigo_vendedor         : {$sectC['c3_pedido_com_codigo_vendedor']}\n";
    echo "  C4 Pedidos com nome_vendedor           : {$sectC['c4_pedido_com_nome_vendedor']}\n";
    echo "  C5 Títulos com vendedor via NF         : " . ($sectC['c5_titulos_com_vendedor_via_nf'] > 0 ? $ok("{$sectC['c5_titulos_com_vendedor_via_nf']} (resolvíveis se lookup corrigido)") : $bad("0")) . "\n";
}

echo "\n{$bold}──── D) BRIDGE NF×TÍTULO ────{$D}\n";
echo "  D1 Bridge summary rows no período     : {$sectD['d1_bridge_summary_rows_periodo']}\n";
echo "  D2 Bridge items rows no período       : {$sectD['d2_bridge_items_rows_periodo']}\n";
echo "  D3 Títulos via bridge_summary         : {$sectD['d3_titulos_resolvem_via_summary']}\n";
echo "  D4 Títulos via bridge_items           : {$sectD['d4_titulos_resolvem_via_items']}\n";
echo "  D5 Oportunidades bridge (sem bridge)  : " . ($sectD['d5_oportunidades_bridge'] > 0 ? $warn("{$sectD['d5_oportunidades_bridge']} títulos poderiam ser adicionados") : $ok("0")) . "\n";

echo "\n{$bold}──── RECOMENDAÇÕES ────{$D}\n";
foreach ($recommendations as $rec) {
    echo "  {$Y}→{$D} {$rec}\n";
}

if ($samplesNoNf !== []) {
    echo "\n{$bold}──── SAMPLE: Títulos sem chave_nfe ────{$D}\n";
    foreach ($samplesNoNf as $row) {
        echo "  {$row['titulo_key']} | nota_key={$row['nota_key']} ({$row['nota_key_len']}c) | chave_nfe={$row['chave_nfe']} ({$row['chave_nfe_len']}c)\n";
    }
}

if ($samplesNoVendor !== []) {
    echo "\n{$bold}──── SAMPLE: Títulos com NF mas sem vendedor ────{$D}\n";
    foreach ($samplesNoVendor as $row) {
        $nfV = $row['nf_vendedor_codigo'] ?: 'N/A';
        echo "  {$row['titulo_key']} | NF={$row['numero_nf']} | nf_vendedor={$nfV} | titulo_vendedor={$row['titulo_vendedor_codigo']}\n";
    }
}

echo "\n";
exit(0);
