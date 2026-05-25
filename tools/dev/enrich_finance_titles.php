<?php

declare(strict_types=1);

/**
 * FIN.R1 — Enriquecimento de Títulos Financeiros Canônicos
 *
 * Enriquece biomie_titulos_financeiros_canonicos com dados de vendedor/cliente
 * vindos dos bridges existentes, em 3 passes:
 *
 *   Pass 1 — mart_bridge_pedido_nfe_titulo via titulo_key
 *   Pass 2 — biomie_bridge_nf_titulo_items → biomie_notas_fiscais_canonicas
 *   Pass 3 — biomie_clientes_dim via codigo_cliente_omie (nome_cliente only)
 *
 * Regras:
 *   - Não altera Base Comercial NF (notas_fiscais_canonicas é read-only aqui)
 *   - Não altera raw/bronze
 *   - Não força vínculo sem evidência nos bridges
 *   - COALESCE: só preenche campos ainda vazios/padrão
 *
 * Usage:
 *   php tools/dev/enrich_finance_titles.php [--start=YYYY-MM-DD] [--end=YYYY-MM-DD] [--dry-run] [--pretty]
 *
 * Sem --start/--end processa todos os títulos.
 */

require __DIR__ . '/../../app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require __DIR__ . '/../../app/Bootstrap.php';
\BIomie\Bootstrap::init();

$opts   = getopt('', ['start:', 'end:', 'dry-run', 'pretty']);
$start  = trim((string) ($opts['start']  ?? ''));
$end    = trim((string) ($opts['end']    ?? ''));
$dryRun = isset($opts['dry-run']);
$pretty = isset($opts['pretty']);

$pdo = \BIomie\Database::connect(new \BIomie\Config(), 'db');

// ANSI helpers
$bold = static fn(string $s): string => $pretty ? "\033[1m{$s}\033[0m" : $s;
$dim  = static fn(string $s): string => $pretty ? "\033[2m{$s}\033[0m" : $s;
$ok   = static fn(string $s): string => $pretty ? "\033[32m{$s}\033[0m" : $s;
$warn = static fn(string $s): string => $pretty ? "\033[33m{$s}\033[0m" : $s;

$periodLabel = ($start !== '' && $end !== '') ? "{$start} → {$end}" : 'todos os períodos';
$label       = $dryRun ? '[DRY-RUN] ' : '';

echo $bold("=== FIN.R1 — Enriquecimento de Títulos Financeiros ===") . "\n";
echo $dim("    Modo     : ") . ($dryRun ? $warn('DRY-RUN (nenhuma alteração será salva)') : $ok('REAL (alterações serão aplicadas)')) . "\n";
echo $dim("    Período  : {$periodLabel}") . "\n";
echo $dim("    Executado: " . date('Y-m-d H:i:s')) . "\n\n";

// ── Monta filtro de período (aplicado na tabela de títulos) ───────────────────
$params  = [];
$period  = '';
if ($start !== '' && $end !== '') {
    $period = "AND COALESCE(t.data_competencia, t.data_vencimento) BETWEEN :start AND :end";
    $params = ['start' => $start, 'end' => $end];
}

$q = static function (string $sql, array $bind = []) use ($pdo): mixed {
    $s = $pdo->prepare($sql);
    $s->execute($bind);
    return $s->fetchColumn();
};

// ── Snapshot antes ────────────────────────────────────────────────────────────
$before_vend = (int) $q("SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos t WHERE (t.codigo_vendedor_omie IS NULL OR t.codigo_vendedor_omie = '') {$period}", $params);
$before_cli  = (int) $q("SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos t WHERE (t.nome_cliente IS NULL OR t.nome_cliente = '' OR t.nome_cliente = 'Cliente nao informado') {$period}", $params);
$before_doc  = (int) $q("SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos t WHERE (t.numero_documento IS NULL OR t.numero_documento = '') {$period}", $params);

echo $bold("[antes]") . "\n";
printf("  sem_vendedor    : %d\n", $before_vend);
printf("  sem_nome_cliente: %d\n", $before_cli);
printf("  sem_numero_doc  : %d\n\n", $before_doc);

// ── Pass 1: mart_bridge_pedido_nfe_titulo via titulo_key ──────────────────────
echo "{$label}" . $bold("[pass1]") . " mart_bridge_pedido_nfe_titulo → titulo_key ...\n";

$p1_cand = (int) $q("SELECT COUNT(DISTINCT t.id)
    FROM biomie_titulos_financeiros_canonicos t
    JOIN mart_bridge_pedido_nfe_titulo b ON b.titulo_key = t.titulo_key
    WHERE ((t.codigo_vendedor_omie IS NULL OR t.codigo_vendedor_omie = '')
        OR (t.nome_cliente IS NULL OR t.nome_cliente = '' OR t.nome_cliente = 'Cliente nao informado'))
    {$period}", $params);

printf("  candidatos: %d títulos\n", $p1_cand);

if (!$dryRun && $p1_cand > 0) {
    $p1bind = $params;
    $p1sql  = "
        UPDATE biomie_titulos_financeiros_canonicos t
        JOIN mart_bridge_pedido_nfe_titulo b ON b.titulo_key = t.titulo_key
        SET
            t.codigo_vendedor_omie = CASE
                WHEN (t.codigo_vendedor_omie IS NULL OR t.codigo_vendedor_omie = '')
                     AND b.codigo_vendedor_omie IS NOT NULL AND b.codigo_vendedor_omie <> ''
                THEN b.codigo_vendedor_omie ELSE t.codigo_vendedor_omie END,
            t.nome_vendedor = CASE
                WHEN (t.nome_vendedor IS NULL OR t.nome_vendedor = '' OR t.nome_vendedor = 'Sem vendedor')
                     AND b.nome_vendedor IS NOT NULL AND b.nome_vendedor NOT IN ('', 'Sem vendedor')
                THEN b.nome_vendedor ELSE t.nome_vendedor END,
            t.codigo_cliente_omie = CASE
                WHEN (t.codigo_cliente_omie IS NULL OR t.codigo_cliente_omie = 0)
                     AND b.codigo_cliente_omie IS NOT NULL AND b.codigo_cliente_omie > 0
                THEN b.codigo_cliente_omie ELSE t.codigo_cliente_omie END,
            t.nome_cliente = CASE
                WHEN (t.nome_cliente IS NULL OR t.nome_cliente = '' OR t.nome_cliente = 'Cliente nao informado')
                     AND b.nome_cliente IS NOT NULL AND b.nome_cliente NOT IN ('', 'Cliente nao informado')
                THEN b.nome_cliente ELSE t.nome_cliente END
        WHERE ((t.codigo_vendedor_omie IS NULL OR t.codigo_vendedor_omie = '')
            OR (t.nome_cliente IS NULL OR t.nome_cliente = '' OR t.nome_cliente = 'Cliente nao informado'))
        " . ($period !== '' ? str_replace('BETWEEN :start AND :end', 'BETWEEN :start AND :end', $period) : '');
    $stmt = $pdo->prepare($p1sql);
    $stmt->execute($p1bind);
    printf("  " . $ok("atualizados: %d") . "\n\n", $stmt->rowCount());
} elseif ($dryRun) {
    echo "  atualizados: " . $dim("(dry-run — não aplicado)") . "\n\n";
} else {
    echo "  atualizados: 0\n\n";
}

// ── Pass 2: bridge_nf_titulo_items → notas_fiscais_canonicas ─────────────────
echo "{$label}" . $bold("[pass2]") . " biomie_bridge_nf_titulo_items → biomie_notas_fiscais_canonicas ...\n";

$p2_cand = (int) $q("SELECT COUNT(DISTINCT t.id)
    FROM biomie_titulos_financeiros_canonicos t
    JOIN biomie_bridge_nf_titulo_items bi ON bi.titulo_key = t.titulo_key
    JOIN biomie_notas_fiscais_canonicas n  ON n.nota_key   = bi.nota_key
    WHERE (t.codigo_vendedor_omie IS NULL OR t.codigo_vendedor_omie = '')
      AND n.codigo_vendedor_omie IS NOT NULL AND n.codigo_vendedor_omie <> ''
    {$period}", $params);

printf("  candidatos: %d títulos com vendedor ainda vazio após pass1\n", $p2_cand);

if (!$dryRun && $p2_cand > 0) {
    // Subquery por titulo_key evita multiplicar linhas quando há múltiplos bridge_items
    $p2sql = "
        UPDATE biomie_titulos_financeiros_canonicos t
        JOIN (
            SELECT bi.titulo_key,
                   MAX(n.codigo_vendedor_omie) AS codigo_vendedor_omie,
                   MAX(n.nome_vendedor)        AS nome_vendedor,
                   MAX(n.codigo_cliente_omie)  AS codigo_cliente_omie,
                   MAX(n.nome_cliente)         AS nome_cliente
            FROM biomie_bridge_nf_titulo_items bi
            JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = bi.nota_key
            WHERE n.codigo_vendedor_omie IS NOT NULL AND n.codigo_vendedor_omie <> ''
            GROUP BY bi.titulo_key
        ) src ON src.titulo_key = t.titulo_key
        SET
            t.codigo_vendedor_omie = CASE
                WHEN (t.codigo_vendedor_omie IS NULL OR t.codigo_vendedor_omie = '')
                     AND src.codigo_vendedor_omie IS NOT NULL AND src.codigo_vendedor_omie <> ''
                THEN src.codigo_vendedor_omie ELSE t.codigo_vendedor_omie END,
            t.nome_vendedor = CASE
                WHEN (t.nome_vendedor IS NULL OR t.nome_vendedor = '' OR t.nome_vendedor = 'Sem vendedor')
                     AND src.nome_vendedor IS NOT NULL AND src.nome_vendedor NOT IN ('', 'Sem vendedor')
                THEN src.nome_vendedor ELSE t.nome_vendedor END,
            t.codigo_cliente_omie = CASE
                WHEN (t.codigo_cliente_omie IS NULL OR t.codigo_cliente_omie = 0)
                     AND src.codigo_cliente_omie IS NOT NULL AND src.codigo_cliente_omie > 0
                THEN src.codigo_cliente_omie ELSE t.codigo_cliente_omie END,
            t.nome_cliente = CASE
                WHEN (t.nome_cliente IS NULL OR t.nome_cliente = '' OR t.nome_cliente = 'Cliente nao informado')
                     AND src.nome_cliente IS NOT NULL AND src.nome_cliente NOT IN ('', 'Cliente nao informado')
                THEN src.nome_cliente ELSE t.nome_cliente END
        WHERE (t.codigo_vendedor_omie IS NULL OR t.codigo_vendedor_omie = '')
        " . $period;
    $stmt2 = $pdo->prepare($p2sql);
    $stmt2->execute($params);
    printf("  " . $ok("atualizados: %d") . "\n\n", $stmt2->rowCount());
} elseif ($dryRun) {
    echo "  atualizados: " . $dim("(dry-run — não aplicado)") . "\n\n";
} else {
    echo "  atualizados: 0\n\n";
}

// ── Pass 3: biomie_clientes_dim para nome_cliente ─────────────────────────────
echo "{$label}" . $bold("[pass3]") . " biomie_clientes_dim → codigo_cliente_omie (nome_cliente only) ...\n";

$p3_cand = (int) $q("SELECT COUNT(DISTINCT t.id)
    FROM biomie_titulos_financeiros_canonicos t
    JOIN biomie_clientes_dim c ON c.codigo_cliente_omie = t.codigo_cliente_omie
    WHERE (t.nome_cliente IS NULL OR t.nome_cliente = '' OR t.nome_cliente = 'Cliente nao informado')
      AND c.nome_cliente IS NOT NULL AND c.nome_cliente <> ''
      AND c.nome_cliente NOT IN ('Cliente nao informado', 'Cliente sem nome')
      AND t.codigo_cliente_omie IS NOT NULL AND t.codigo_cliente_omie > 0
    {$period}", $params);

printf("  candidatos: %d títulos com nome_cliente ainda padrão\n", $p3_cand);

if (!$dryRun && $p3_cand > 0) {
    $p3sql = "
        UPDATE biomie_titulos_financeiros_canonicos t
        JOIN biomie_clientes_dim c ON c.codigo_cliente_omie = t.codigo_cliente_omie
        SET t.nome_cliente = c.nome_cliente
        WHERE (t.nome_cliente IS NULL OR t.nome_cliente = '' OR t.nome_cliente = 'Cliente nao informado')
          AND c.nome_cliente IS NOT NULL AND c.nome_cliente <> ''
          AND c.nome_cliente NOT IN ('Cliente nao informado', 'Cliente sem nome')
          AND t.codigo_cliente_omie IS NOT NULL AND t.codigo_cliente_omie > 0
        " . $period;
    $stmt3 = $pdo->prepare($p3sql);
    $stmt3->execute($params);
    printf("  " . $ok("atualizados: %d") . "\n\n", $stmt3->rowCount());
} elseif ($dryRun) {
    echo "  atualizados: " . $dim("(dry-run — não aplicado)") . "\n\n";
} else {
    echo "  atualizados: 0\n\n";
}

// ── Pass 4: bridge_nf_titulo_items → notas_fiscais_canonicas (numero_documento + pedido_id_omie) ──
echo "{$label}" . $bold("[pass4]") . " biomie_bridge_nf_titulo_items → numero_documento (numero_nf) + pedido_id_omie ...\n";

$p4_cand = (int) $q("SELECT COUNT(DISTINCT t.id)
    FROM biomie_titulos_financeiros_canonicos t
    JOIN biomie_bridge_nf_titulo_items bi ON bi.titulo_key = t.titulo_key
    JOIN biomie_notas_fiscais_canonicas n  ON n.nota_key   = bi.nota_key
    WHERE (t.numero_documento IS NULL OR t.numero_documento = '')
      AND n.numero_nf IS NOT NULL AND n.numero_nf <> ''
    {$period}", $params);

printf("  candidatos: %d títulos sem numero_documento com nf via bridge\n", $p4_cand);

if (!$dryRun && $p4_cand > 0) {
    $p4sql = "
        UPDATE biomie_titulos_financeiros_canonicos t
        JOIN (
            SELECT bi.titulo_key,
                   MAX(n.numero_nf)        AS numero_nf,
                   MAX(n.pedido_id_omie)   AS pedido_id_omie
            FROM biomie_bridge_nf_titulo_items bi
            JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = bi.nota_key
            WHERE n.numero_nf IS NOT NULL AND n.numero_nf <> ''
            GROUP BY bi.titulo_key
        ) src ON src.titulo_key = t.titulo_key
        SET
            t.numero_documento = CASE
                WHEN (t.numero_documento IS NULL OR t.numero_documento = '')
                     AND src.numero_nf IS NOT NULL AND src.numero_nf <> ''
                THEN src.numero_nf ELSE t.numero_documento END,
            t.pedido_id_omie = CASE
                WHEN (t.pedido_id_omie IS NULL OR t.pedido_id_omie = 0)
                     AND src.pedido_id_omie IS NOT NULL AND src.pedido_id_omie > 0
                THEN src.pedido_id_omie ELSE t.pedido_id_omie END
        WHERE (t.numero_documento IS NULL OR t.numero_documento = '')
           OR (t.pedido_id_omie IS NULL OR t.pedido_id_omie = 0)
        " . $period;
    $stmt4 = $pdo->prepare($p4sql);
    $stmt4->execute($params);
    printf("  " . $ok("atualizados: %d") . "\n\n", $stmt4->rowCount());
} elseif ($dryRun) {
    echo "  atualizados: " . $dim("(dry-run — não aplicado)") . "\n\n";
} else {
    echo "  atualizados: 0\n\n";
}

// ── Snapshot depois ───────────────────────────────────────────────────────────
$after_vend = (int) $q("SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos t WHERE (t.codigo_vendedor_omie IS NULL OR t.codigo_vendedor_omie = '') {$period}", $params);
$after_cli  = (int) $q("SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos t WHERE (t.nome_cliente IS NULL OR t.nome_cliente = '' OR t.nome_cliente = 'Cliente nao informado') {$period}", $params);
$after_doc  = (int) $q("SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos t WHERE (t.numero_documento IS NULL OR t.numero_documento = '') {$period}", $params);

echo $bold("[depois]") . "\n";
$dv  = $after_vend - $before_vend;
$dc  = $after_cli  - $before_cli;
printf("  sem_vendedor    : %d  %s delta: %+d\n",
    $after_vend, $dim('(antes:' . $before_vend . ')'), $dv);
printf("  sem_nome_cliente: %d  %s delta: %+d\n",
    $after_cli,  $dim('(antes:' . $before_cli . ')'), $dc);
printf("  sem_numero_doc  : %d\n\n", $after_doc);

if ($dryRun) {
    echo $warn("DRY-RUN concluído — nenhuma linha foi alterada.") . "\n";
    echo $dim("Execute sem --dry-run para aplicar.") . "\n";
} else {
    echo $ok("Enriquecimento concluído.") . "\n";
    echo $dim("Próximo passo: php bin/materialize_marts.php --start={$start} --end={$end}") . "\n";
}
