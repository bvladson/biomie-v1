<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — Fase 2 (metade 1): DRY-RUN de impacto das datas.
 *
 * Aplica o OmieDateParser sobre os payloads bronze já armazenados dos orçamentos
 * VIVOS (etapa 10) e mede a cobertura de inclusão real, SEM escrever nada.
 *
 * Responde à pergunta do checkpoint: dos N orçamentos vivos, quantos têm dInc
 * direto no payload, quantos precisariam do fallback via PedidoEtapas, e quantos
 * cairiam em first_seen / legacy.
 *
 * READ-ONLY. Apenas SELECT + parsing em memória. Nenhuma escrita em produção.
 *
 * Uso:
 *   php tools/dev/quote_order_dates_dryrun.php
 *   php tools/dev/quote_order_dates_dryrun.php --etapa=10 --json
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Support\OmieDateParser;

$opts     = getopt('', ['etapa:', 'json']);
$etapa    = (string) ($opts['etapa'] ?? '10');
$jsonMode = isset($opts['json']);

$config = new Config();
$conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $conn);

// ── 1. Orçamentos vivos (etapa alvo) ────────────────────────────────────────────
$quotes = $pdo->prepare(
    "SELECT company_id, pedido_id_omie, data_emissao
     FROM biomie_pedidos_canonicos
     WHERE etapa_omie = :etapa
     ORDER BY company_id, pedido_id_omie"
);
$quotes->execute(['etapa' => $etapa]);
$rows = $quotes->fetchAll(PDO::FETCH_ASSOC);

$total = count($rows);
if ($total === 0) {
    fwrite(STDOUT, "Nenhum orçamento na etapa {$etapa}.\n");
    exit(0);
}

// ── 2. Payload bronze mais recente por pedido (só os ids em jogo) ────────────────
$ids = array_values(array_unique(array_map(static fn (array $r): string => (string) $r['pedido_id_omie'], $rows)));
$ph  = implode(',', array_fill(0, count($ids), '?'));

$bronzeSql = "
    SELECT x.natural_key,
           JSON_UNQUOTE(JSON_EXTRACT(x.payload_json,'$.infoCadastro.dInc')) AS dinc,
           JSON_UNQUOTE(JSON_EXTRACT(x.payload_json,'$.infoCadastro.hInc')) AS hinc,
           JSON_UNQUOTE(JSON_EXTRACT(x.payload_json,'$.infoCadastro.dAlt')) AS dalt,
           JSON_UNQUOTE(JSON_EXTRACT(x.payload_json,'$.infoCadastro.hAlt')) AS halt,
           JSON_UNQUOTE(JSON_EXTRACT(x.payload_json,'$.cabecalho.data_previsao')) AS prev
    FROM biomie_omie_bronze x
    INNER JOIN (
        SELECT natural_key, MAX(ingested_at) AS mx
        FROM biomie_omie_bronze
        WHERE entity_name = 'order' AND natural_key IN ($ph)
        GROUP BY natural_key
    ) latest ON latest.natural_key = x.natural_key AND latest.mx = x.ingested_at
    WHERE x.entity_name = 'order' AND x.natural_key IN ($ph)
";
$bronzeStmt = $pdo->prepare($bronzeSql);
$bronzeStmt->execute([...$ids, ...$ids]);

$bronze = [];
foreach ($bronzeStmt->fetchAll(PDO::FETCH_ASSOC) as $b) {
    $bronze[(string) $b['natural_key']] ??= $b; // primeiro (dedup de MAX empatado)
}

// ── 3. Parse + tally ─────────────────────────────────────────────────────────────
$stat = [
    'total_analisado'                => $total,
    'com_bronze'                     => 0,
    'sem_bronze'                     => 0,
    'com_dinc_direto'                => 0,
    'sem_dinc_direto'                => 0,
    'com_data_alteracao'             => 0,
    'com_previsao'                   => 0,
    'dinc_diferente_previsao'        => 0,
    'datas_invalidas'                => 0,
    'necessitam_fallback_pedidoetapas' => 0,
];

foreach ($rows as $r) {
    $id = (string) $r['pedido_id_omie'];
    $b  = $bronze[$id] ?? null;

    if ($b === null) {
        $stat['sem_bronze']++;
        $stat['sem_dinc_direto']++;
        continue;
    }
    $stat['com_bronze']++;

    $rawDinc = trim((string) ($b['dinc'] ?? ''));
    $dincParsed = OmieDateParser::parseDateTime($rawDinc, (string) ($b['hinc'] ?? ''));
    $prevParsed = OmieDateParser::parseDate((string) ($b['prev'] ?? ''));
    $altParsed  = OmieDateParser::parseDateTime((string) ($b['dalt'] ?? ''), (string) ($b['halt'] ?? ''));

    if ($dincParsed !== null) {
        $stat['com_dinc_direto']++;
        if ($rawDinc !== '' && $prevParsed !== null && substr($dincParsed, 0, 10) !== $prevParsed) {
            $stat['dinc_diferente_previsao']++;
        }
    } else {
        $stat['sem_dinc_direto']++;
        $stat['necessitam_fallback_pedidoetapas']++;
        if ($rawDinc !== '') {
            $stat['datas_invalidas']++; // tinha dInc mas o parser estrito rejeitou
        }
    }

    if ($altParsed !== null)  { $stat['com_data_alteracao']++; }
    if ($prevParsed !== null) { $stat['com_previsao']++; }
}

// Cobertura em camadas de confiança (do checkpoint)
$tier1 = $stat['com_dinc_direto'];                       // Pedido.infoCadastro.dInc
$tier2 = $stat['necessitam_fallback_pedidoetapas'] - $stat['sem_bronze']; // tem bronze mas sem dInc → PedidoEtapas
$tier2 = max(0, $tier2);
$tier34 = $stat['sem_bronze'];                           // sem payload → first_seen / legacy

// ── 4. Saída ─────────────────────────────────────────────────────────────────────
if ($jsonMode) {
    echo json_encode([
        'etapa' => $etapa,
        'stats' => $stat,
        'coverage_tiers' => [
            'tier1_dinc_direto'        => $tier1,
            'tier2_precisa_pedidoetapas'=> $tier2,
            'tier34_first_seen_legacy' => $tier34,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}

$pct = static fn (int $n): string => $total > 0 ? number_format($n / $total * 100, 1, ',', '.') . '%' : '0%';

echo str_repeat('═', 72), PHP_EOL;
echo "  DRY-RUN — DATAS DOS ORÇAMENTOS (etapa {$etapa}) · sem escrita", PHP_EOL;
echo str_repeat('═', 72), PHP_EOL;
foreach ([
    'total_analisado'                  => 'Orçamentos vivos analisados',
    'com_bronze'                       => 'Com payload bronze',
    'sem_bronze'                       => 'Sem payload bronze',
    'com_dinc_direto'                  => 'dInc direto e VÁLIDO',
    'sem_dinc_direto'                  => 'Sem dInc direto utilizável',
    'com_previsao'                     => 'Com data_previsao válida',
    'com_data_alteracao'               => 'Com dAlt/hAlt válido',
    'dinc_diferente_previsao'          => 'dInc ≠ previsão (data_emissao erraria)',
    'datas_invalidas'                  => 'dInc presente mas rejeitado (inválido)',
    'necessitam_fallback_pedidoetapas' => 'Precisariam de fallback',
] as $k => $label) {
    printf("  %-40s %6s   (%s)%s", $label, number_format($stat[$k], 0, ',', '.'), $pct($stat[$k]), PHP_EOL);
}

echo PHP_EOL, "  Cobertura por camada de confiança:", PHP_EOL;
printf("    1. dInc direto (Pedido.infoCadastro)      : %d  (%s)%s", $tier1, $pct($tier1), PHP_EOL);
printf("    2. precisam de PedidoEtapas               : %d  (%s)%s", $tier2, $pct($tier2), PHP_EOL);
printf("    3/4. first_seen / fallback legado         : %d  (%s)%s", $tier34, $pct($tier34), PHP_EOL);

echo PHP_EOL, "  → Decisão: se camada 1 cobre ~tudo, ressincronizar direto (sem construir o resolver).", PHP_EOL;
echo         "            Se camadas 2/3 forem relevantes, implementar CanonicalOrderDateResolver antes.", PHP_EOL;
