<?php

declare(strict_types=1);

/**
 * FIN.R1 — Diagnóstico de Enriquecimento de Títulos Financeiros
 *
 * Usage:
 *   php tools/dev/finance_enrichment_diagnosis.php [--start=YYYY-MM-DD] [--end=YYYY-MM-DD] [--pretty]
 *
 * Sem --start/--end analisa todos os títulos.
 */

require __DIR__ . '/../../app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require __DIR__ . '/../../app/Bootstrap.php';
\BIomie\Bootstrap::init();

$opts   = getopt('', ['start:', 'end:', 'pretty']);
$start  = trim((string) ($opts['start'] ?? ''));
$end    = trim((string) ($opts['end']   ?? ''));
$pretty = isset($opts['pretty']);

$pdo = \BIomie\Database::connect(new \BIomie\Config(), 'db');

// ANSI helpers
$bold = static fn(string $s): string => $pretty ? "\033[1m{$s}\033[0m" : $s;
$dim  = static fn(string $s): string => $pretty ? "\033[2m{$s}\033[0m" : $s;
$ok   = static fn(string $s): string => $pretty ? "\033[32m{$s}\033[0m" : $s;
$warn = static fn(string $s): string => $pretty ? "\033[33m{$s}\033[0m" : $s;

$periodLabel = ($start !== '' && $end !== '') ? "{$start} → {$end}" : 'todos os períodos';

echo $bold("=== FIN.R1 — Diagnóstico de Enriquecimento de Títulos Financeiros ===") . "\n";
echo $dim("    Período  : {$periodLabel}") . "\n";
echo $dim("    Executado: " . date('Y-m-d H:i:s')) . "\n\n";

// ── Monta filtro de período ────────────────────────────────────────────────────
$params  = [];
$period  = '';
if ($start !== '' && $end !== '') {
    $period = "AND COALESCE(t.data_competencia, t.data_vencimento) BETWEEN :start AND :end";
    $params = ['start' => $start, 'end' => $end];
}

$exec = static function (string $sql, array $bind = []) use ($pdo): mixed {
    $s = $pdo->prepare($sql);
    $s->execute($bind);
    return $s->fetchColumn();
};
$execAll = static function (string $sql, array $bind = []) use ($pdo): array {
    $s = $pdo->prepare($sql);
    $s->execute($bind);
    return $s->fetchAll(PDO::FETCH_ASSOC);
};

// ── Estado atual ──────────────────────────────────────────────────────────────
$total        = (int) $exec("SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos t WHERE 1=1 {$period}", $params);
$sem_vendedor = (int) $exec("SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos t WHERE (t.codigo_vendedor_omie IS NULL OR t.codigo_vendedor_omie = '') {$period}", $params);
$sem_nom_cli  = (int) $exec("SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos t WHERE (t.nome_cliente IS NULL OR t.nome_cliente = '' OR t.nome_cliente = 'Cliente nao informado') {$period}", $params);
$sem_cod_cli  = (int) $exec("SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos t WHERE (t.codigo_cliente_omie IS NULL OR t.codigo_cliente_omie = 0) {$period}", $params);

echo $bold("[estado_atual]") . "\n";
printf("  total_titulos     : %d\n", $total);
printf("  sem_vendedor      : %s  (%.1f%%)\n",
    $sem_vendedor > 0 ? $warn((string) $sem_vendedor) : $ok((string) $sem_vendedor),
    $sem_vendedor / max(1, $total) * 100);
printf("  sem_nome_cliente  : %s  (%.1f%%)\n",
    $sem_nom_cli > 0 ? $warn((string) $sem_nom_cli) : $ok((string) $sem_nom_cli),
    $sem_nom_cli / max(1, $total) * 100);
printf("  sem_codigo_cliente: %d  (%.1f%%)\n", $sem_cod_cli, $sem_cod_cli / max(1, $total) * 100);
echo "\n";

// ── Path 1: mart_bridge_pedido_nfe_titulo ─────────────────────────────────────
$p1_match = (int) $exec("SELECT COUNT(DISTINCT t.id)
    FROM biomie_titulos_financeiros_canonicos t
    JOIN mart_bridge_pedido_nfe_titulo b ON b.titulo_key = t.titulo_key
    WHERE 1=1 {$period}", $params);
$p1_vendor = (int) $exec("SELECT COUNT(DISTINCT t.id)
    FROM biomie_titulos_financeiros_canonicos t
    JOIN mart_bridge_pedido_nfe_titulo b ON b.titulo_key = t.titulo_key
    WHERE (t.codigo_vendedor_omie IS NULL OR t.codigo_vendedor_omie = '')
      AND b.codigo_vendedor_omie IS NOT NULL AND b.codigo_vendedor_omie <> ''
    {$period}", $params);
$p1_client = (int) $exec("SELECT COUNT(DISTINCT t.id)
    FROM biomie_titulos_financeiros_canonicos t
    JOIN mart_bridge_pedido_nfe_titulo b ON b.titulo_key = t.titulo_key
    WHERE (t.nome_cliente IS NULL OR t.nome_cliente = '' OR t.nome_cliente = 'Cliente nao informado')
      AND b.nome_cliente IS NOT NULL AND b.nome_cliente <> '' AND b.nome_cliente <> 'Cliente nao informado'
    {$period}", $params);

echo $bold("[path1 — mart_bridge_pedido_nfe_titulo via titulo_key]") . "\n";
printf("  titulos_com_match       : %d\n", $p1_match);
printf("  can_enrich_vendedor     : %s\n", $p1_vendor > 0 ? $ok((string) $p1_vendor) : '0');
printf("  can_enrich_nome_cliente : %s\n", $p1_client > 0 ? $ok((string) $p1_client) : '0');
echo "\n";

// ── Path 2: bridge_nf_titulo_items → notas_fiscais_canonicas ─────────────────
$p2_match = (int) $exec("SELECT COUNT(DISTINCT t.id)
    FROM biomie_titulos_financeiros_canonicos t
    JOIN biomie_bridge_nf_titulo_items bi ON bi.titulo_key = t.titulo_key
    JOIN biomie_notas_fiscais_canonicas n  ON n.nota_key   = bi.nota_key
    WHERE 1=1 {$period}", $params);
$p2_vendor = (int) $exec("SELECT COUNT(DISTINCT t.id)
    FROM biomie_titulos_financeiros_canonicos t
    JOIN biomie_bridge_nf_titulo_items bi ON bi.titulo_key = t.titulo_key
    JOIN biomie_notas_fiscais_canonicas n  ON n.nota_key   = bi.nota_key
    WHERE (t.codigo_vendedor_omie IS NULL OR t.codigo_vendedor_omie = '')
      AND n.codigo_vendedor_omie IS NOT NULL AND n.codigo_vendedor_omie <> ''
    {$period}", $params);
$p2_new = (int) $exec("SELECT COUNT(DISTINCT t.id)
    FROM biomie_titulos_financeiros_canonicos t
    JOIN biomie_bridge_nf_titulo_items bi ON bi.titulo_key = t.titulo_key
    JOIN biomie_notas_fiscais_canonicas n  ON n.nota_key   = bi.nota_key
    LEFT JOIN mart_bridge_pedido_nfe_titulo b ON b.titulo_key = t.titulo_key
    WHERE (t.codigo_vendedor_omie IS NULL OR t.codigo_vendedor_omie = '')
      AND n.codigo_vendedor_omie IS NOT NULL AND n.codigo_vendedor_omie <> ''
      AND (b.id IS NULL OR b.codigo_vendedor_omie IS NULL OR b.codigo_vendedor_omie = '')
    {$period}", $params);

echo $bold("[path2 — bridge_nf_titulo_items → notas_fiscais_canonicas]") . "\n";
printf("  titulos_com_match         : %d\n", $p2_match);
printf("  can_enrich_vendedor       : %s\n", $p2_vendor > 0 ? $ok((string) $p2_vendor) : '0');
printf("  novos_alem_path1          : %d  %s\n", $p2_new, $dim("(títulos que path1 não cobre)"));
echo "\n";

// ── Path 3: biomie_clientes_dim ───────────────────────────────────────────────
$p3_client = (int) $exec("SELECT COUNT(DISTINCT t.id)
    FROM biomie_titulos_financeiros_canonicos t
    JOIN biomie_clientes_dim c ON c.codigo_cliente_omie = t.codigo_cliente_omie
    WHERE (t.nome_cliente IS NULL OR t.nome_cliente = '' OR t.nome_cliente = 'Cliente nao informado')
      AND c.nome_cliente IS NOT NULL AND c.nome_cliente <> ''
      AND c.nome_cliente NOT IN ('Cliente nao informado', 'Cliente sem nome')
      AND t.codigo_cliente_omie IS NOT NULL AND t.codigo_cliente_omie > 0
    {$period}", $params);

echo $bold("[path3 — biomie_clientes_dim via codigo_cliente_omie (nome_cliente only)]") . "\n";
printf("  can_enrich_nome_cliente   : %s\n", $p3_client > 0 ? $ok((string) $p3_client) : '0');
echo "\n";

// ── Resumo projetado ──────────────────────────────────────────────────────────
$total_enrichable = (int) $exec("SELECT COUNT(DISTINCT t.id)
    FROM biomie_titulos_financeiros_canonicos t
    WHERE (t.codigo_vendedor_omie IS NULL OR t.codigo_vendedor_omie = '') AND (
        EXISTS (SELECT 1 FROM mart_bridge_pedido_nfe_titulo b
                WHERE b.titulo_key = t.titulo_key
                  AND b.codigo_vendedor_omie IS NOT NULL AND b.codigo_vendedor_omie <> '')
        OR EXISTS (SELECT 1 FROM biomie_bridge_nf_titulo_items bi
                   JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = bi.nota_key
                   WHERE bi.titulo_key = t.titulo_key
                     AND n.codigo_vendedor_omie IS NOT NULL AND n.codigo_vendedor_omie <> '')
    ) {$period}", $params);

$sem_vinculo = max(0, $sem_vendedor - $total_enrichable);

echo $bold("[resumo_projetado_pos_enriquecimento]") . "\n";
printf("  sem_vendedor_atual        : %d\n", $sem_vendedor);
printf("  vendor_enrichable_total   : %s  (%.1f%% dos sem vendedor)\n",
    $ok((string) $total_enrichable), $total_enrichable / max(1, $sem_vendedor) * 100);
printf("  sem_vinculo_nos_bridges   : %s  %s\n",
    $sem_vinculo > 0 ? $warn((string) $sem_vinculo) : $ok((string) $sem_vinculo),
    $dim("(sem evidência — permanece Sem vendedor)"));
printf("  cliente_nome_enrichable   : %s  %s\n",
    $ok((string) max($p1_client, $p2_vendor, $p3_client)),
    $dim("(via path1+path2+path3)"));
echo "\n";

// ── Amostras ──────────────────────────────────────────────────────────────────
$samples = $execAll("SELECT t.titulo_key, t.titulo_id_omie, t.nome_cliente,
                            b.nome_vendedor AS b_vendedor, b.nome_cliente AS b_cliente
                     FROM biomie_titulos_financeiros_canonicos t
                     JOIN mart_bridge_pedido_nfe_titulo b ON b.titulo_key = t.titulo_key
                     WHERE (t.codigo_vendedor_omie IS NULL OR t.codigo_vendedor_omie = '')
                       AND b.codigo_vendedor_omie IS NOT NULL AND b.codigo_vendedor_omie <> ''
                     LIMIT 3", []);
if ($samples) {
    echo $bold("[amostras_path1 — títulos que seriam enriquecidos]") . "\n";
    foreach ($samples as $r) echo '  ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    echo "\n";
}

echo $ok("OK") . " — rode " . $bold("enrich_finance_titles.php --dry-run") . " para simular, ou sem a flag para aplicar.\n";
