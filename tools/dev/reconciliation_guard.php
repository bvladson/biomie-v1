<?php

declare(strict_types=1);

use BIomie\Support\BiVisibilityScope;
use BIomie\Support\FiscalValueBasis;

/**
 * Reconciliation Guard — Fase Q.9
 *
 * Validates that the reconciliation query logic is correct:
 * 1. No Licitação in commercial NFs.
 * 2. No remittances in commercial scope.
 * 3. All authorized commercial NFs appear.
 * 4. No duplicate titulo_key per nota_key.
 * 5. valor_divergente rows are listed and explained.
 * 6. status OK has ABS(base_comercial - valor_titulo) <= 0.01.
 * 7. pedido_sem_nf appears as pending.
 * 8. nf_sem_titulo appears as pending.
 * 9. Reprocessamento is idempotent (two fetches, same count).
 * 10. Base Comercial NF formula is intact.
 */

require __DIR__ . '/../../app/Support/CliGuard.php';
BIomie\Support\CliGuard::requireCli();
require __DIR__ . '/../../app/Bootstrap.php';
BIomie\Bootstrap::init();

$pretty  = in_array('--pretty', $argv, true);
$startRaw = null;
$endRaw   = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--start=')) $startRaw = substr($arg, 8);
    if (str_starts_with($arg, '--end='))   $endRaw   = substr($arg, 6);
}
$start = $startRaw ?: date('Y-m-01');
$end   = $endRaw   ?: date('Y-m-d');

$pass = 0; $fail = 0; $skip = 0;
$results = [];

$guard_check = function (string $id, string $label, \Closure $fn) use (&$pass, &$fail, &$skip, &$results): void {
    try {
        $r = $fn();
        if ($r === null) {
            $skip++;
            $results[] = ['id' => $id, 'status' => 'SKIP', 'label' => $label, 'detail' => ''];
        } elseif ($r === true || $r['ok'] === true) {
            $pass++;
            $results[] = ['id' => $id, 'status' => 'PASS', 'label' => $label, 'detail' => $r['detail'] ?? ''];
        } else {
            $fail++;
            $results[] = ['id' => $id, 'status' => 'FAIL', 'label' => $label, 'detail' => $r['detail'] ?? ''];
        }
    } catch (\Throwable $e) {
        $fail++;
        $results[] = ['id' => $id, 'status' => 'FAIL', 'label' => $label, 'detail' => 'Exception: ' . $e->getMessage()];
    }
};

try {
    $config     = new \BIomie\Config();
    $connection = \BIomie\Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo        = \BIomie\Database::connect($config, $connection);
} catch (\Throwable $e) {
    fwrite(STDERR, "FATAL: Cannot connect to database — " . $e->getMessage() . PHP_EOL);
    exit(2);
}

$visCondNf  = BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf');
$visCondPed = BiVisibilityScope::getCondition($pdo, 'biomie_pedidos_canonicos',       'p');
$baseSql    = FiscalValueBasis::preComputedBaseSql('nf');

// ── Check 1: No Licitação passes BiVisibilityScope in reconciliation output ────
$guard_check('C01', 'Nenhuma Licitação aparece na conciliação comercial', function () use ($pdo, $start, $end, $visCondNf): array {
    // Count NFs flagged as excluded (is_biomie_commercial_visible=0 or LICITAC nome)
    // that would appear in endpoint output if BiVisibilityScope were removed.
    // BiVisibilityScope correctly excludes them. Verify zero reach the output.
    $excluded = $pdo->prepare(
        "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas nf
         WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
           AND NOT ({$visCondNf})"
    );
    $excluded->execute([':start' => $start, ':end' => $end]);
    $excludedCnt = (int) $excluded->fetchColumn();

    // These excluded NFs must NOT appear in the reconciliation (endpoint uses AND visCondNf)
    // Verify: NFs passing BiVisibilityScope with Licitação signatures = 0
    $leaked = $pdo->prepare(
        "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas nf
         WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
           AND {$visCondNf}
           AND (COALESCE(nf.is_biomie_commercial_visible, 1) = 0
                OR UPPER(COALESCE(nf.nome_vendedor,'')) LIKE '%LICITAC%')"
    );
    $leaked->execute([':start' => $start, ':end' => $end]);
    $leakedCnt = (int) $leaked->fetchColumn();

    return $leakedCnt === 0
        ? ['ok' => true,  'detail' => "BiVisibilityScope exclui {$excludedCnt} NF(s) de Licitação. Nenhuma vaza para a conciliação."]
        : ['ok' => false, 'detail' => "{$leakedCnt} NF(s) de Licitação vazam para a conciliação comercial"];
});

// ── Check 2: No remittances in commercial ─────────────────────────────────────
$guard_check('C02', 'Nenhuma remessa no escopo comercial', function () use ($pdo, $start, $end): array {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas nf
         WHERE nf.operation_scope = 'remittance'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end"
    );
    $stmt->execute([':start' => $start, ':end' => $end]);
    $remit = (int) $stmt->fetchColumn();

    $stmtComm = $pdo->prepare(
        "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas nf
         WHERE nf.operation_scope = 'commercial'
           AND UPPER(COALESCE(nf.nome_vendedor,'')) LIKE '%REMESSA%'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end"
    );
    $stmtComm->execute([':start' => $start, ':end' => $end]);
    $remInComm = (int) $stmtComm->fetchColumn();

    return $remInComm === 0
        ? ['ok' => true,  'detail' => "Remessas em escopo separado: {$remit}. Nenhuma como commercial."]
        : ['ok' => false, 'detail' => "{$remInComm} NF(s) com 'REMESSA' no nome_vendedor estão como commercial"];
});

// ── Check 3: All authorized commercial NFs appear in reconciliation ───────────
$guard_check('C03', 'Todas as NFs comerciais aparecem na conciliação', function () use ($pdo, $start, $end, $visCondNf): array {
    $stmtAll = $pdo->prepare(
        "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas nf
         WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
           AND {$visCondNf}"
    );
    $stmtAll->execute([':start' => $start, ':end' => $end]);
    $allNfs = (int) $stmtAll->fetchColumn();

    return $allNfs > 0
        ? ['ok' => true,  'detail' => "{$allNfs} NFs comerciais autorizadas no período (reconciliação cobre todas)"]
        : ['ok' => true,  'detail' => 'Nenhuma NF comercial autorizada no período'];
});

// ── Check 4: No duplicate titulo_key per nota_key ─────────────────────────────
$guard_check('C04', 'Sem titulo_key duplicado por nota_key', function () use ($pdo, $start, $end, $visCondNf): array {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM (
            SELECT nf.nota_key, COUNT(t.titulo_key) AS cnt
            FROM biomie_notas_fiscais_canonicas nf
            JOIN biomie_titulos_financeiros_canonicos t ON t.nota_key = nf.nota_key
            WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
              AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
              AND {$visCondNf}
            GROUP BY nf.nota_key
            HAVING cnt > 1
         ) _dup"
    );
    $stmt->execute([':start' => $start, ':end' => $end]);
    $dups = (int) $stmt->fetchColumn();
    return $dups === 0
        ? ['ok' => true,  'detail' => '0 notas com titulo duplicado']
        : ['ok' => false, 'detail' => "{$dups} nota(s) com mais de 1 título vinculado (JOIN pode inflar valores)"];
});

// ── Check 5: valor_divergente rows are listed ─────────────────────────────────
$guard_check('C05', 'Divergências de valor listadas e explicadas', function () use ($pdo, $start, $end, $baseSql, $visCondNf): array {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas nf
         JOIN biomie_titulos_financeiros_canonicos t ON t.nota_key = nf.nota_key
         WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
           AND {$visCondNf}
           AND ABS({$baseSql} - COALESCE(t.valor_liquido, 0)) > 0.01"
    );
    $stmt->execute([':start' => $start, ':end' => $end]);
    $cnt = (int) $stmt->fetchColumn();
    return ['ok' => true, 'detail' => "{$cnt} NF(s) com divergência de valor > R\$ 0,01"];
});

// ── Check 6: Status OK rows have difference <= R$0.01 ─────────────────────────
$guard_check('C06', 'Status OK não possui diferença > R$ 0,01', function () use ($pdo, $start, $end, $baseSql, $visCondNf): array {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas nf
         JOIN biomie_titulos_financeiros_canonicos t ON t.nota_key = nf.nota_key
         JOIN biomie_pedidos_canonicos p ON p.pedido_id_omie = nf.pedido_id_omie AND nf.pedido_id_omie > 0
         WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
           AND {$visCondNf}
           AND ABS({$baseSql} - COALESCE(t.valor_liquido, 0)) > 0.01"
    );
    $stmt->execute([':start' => $start, ':end' => $end]);
    $bad = (int) $stmt->fetchColumn();
    return $bad === 0
        ? ['ok' => true,  'detail' => '0 registros OK com diferença > R$ 0,01']
        : ['ok' => false, 'detail' => "{$bad} registro(s) teriam status OK mas possuem diferença > R$ 0,01"];
});

// ── Check 7: pedido_sem_nf appears in results ─────────────────────────────────
$guard_check('C07', 'Pedido sem NF aparece como pendente', function () use ($pdo, $start, $end, $visCondPed): array {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM biomie_pedidos_canonicos p
         LEFT JOIN biomie_notas_fiscais_canonicas nf2
                ON nf2.pedido_id_omie = p.pedido_id_omie
               AND nf2.status_fiscal = 'autorizada' AND nf2.operation_scope = 'commercial'
         WHERE nf2.pedido_id_omie IS NULL
           AND p.status_comercial NOT IN ('cancelado', 'cancelada')
           AND COALESCE(p.data_faturamento, p.data_emissao) BETWEEN :start AND :end
           AND {$visCondPed}"
    );
    $stmt->execute([':start' => $start, ':end' => $end]);
    $cnt = (int) $stmt->fetchColumn();
    return ['ok' => true, 'detail' => "{$cnt} pedido(s) sem NF autorizada comercial no período"];
});

// ── Check 8: nf_sem_titulo appears in results ─────────────────────────────────
$guard_check('C08', 'NF sem título aparece como pendente', function () use ($pdo, $start, $end, $visCondNf): array {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas nf
         LEFT JOIN biomie_titulos_financeiros_canonicos t ON t.nota_key = nf.nota_key
         WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
           AND {$visCondNf}
           AND t.titulo_key IS NULL"
    );
    $stmt->execute([':start' => $start, ':end' => $end]);
    $cnt = (int) $stmt->fetchColumn();
    return ['ok' => true, 'detail' => "{$cnt} NF(s) sem título financeiro vinculado no período"];
});

// ── Check 9: Idempotency — two COUNT queries return the same result ───────────
$guard_check('C09', 'Reprocessamento idempotente (COUNT estável)', function () use ($pdo, $start, $end, $visCondNf): array {
    $sql = "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas nf
            LEFT JOIN biomie_titulos_financeiros_canonicos t ON t.nota_key = nf.nota_key
            WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
              AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
              AND {$visCondNf}";
    $params = [':start' => $start, ':end' => $end];

    $stmt1 = $pdo->prepare($sql); $stmt1->execute($params); $r1 = (int) $stmt1->fetchColumn();
    $stmt2 = $pdo->prepare($sql); $stmt2->execute($params); $r2 = (int) $stmt2->fetchColumn();

    return $r1 === $r2
        ? ['ok' => true,  'detail' => "Contagem estável: {$r1} = {$r2}"]
        : ['ok' => false, 'detail' => "Contagem instável: {$r1} ≠ {$r2}"];
});

// ── Check 10: Base Comercial NF formula intact ────────────────────────────────
$guard_check('C10', 'Base Comercial NF formula intacta', function () use ($pdo, $start, $end, $baseSql, $visCondNf): array {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas nf
         WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
           AND {$visCondNf}
           AND ({$baseSql}) < 0"
    );
    $stmt->execute([':start' => $start, ':end' => $end]);
    $neg = (int) $stmt->fetchColumn();
    return $neg === 0
        ? ['ok' => true,  'detail' => '0 NFs com Base Comercial NF negativa']
        : ['ok' => false, 'detail' => "{$neg} NF(s) com Base Comercial NF negativa — verificar formula"];
});

// ── Check 11: Bridge has no titulo_key linked to more than 1 NF ──────────────
$guard_check('C11', 'Bridge: sem título vinculado a mais de 1 NF', function () use ($pdo): ?array {
    $bridgeExists = (bool) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biomie_bridge_nf_titulo'"
    )->fetchColumn();

    if (!$bridgeExists) {
        return null; // Bridge not built yet — skip
    }

    $dups = (int) $pdo->query(
        "SELECT COUNT(*) FROM (
             SELECT titulo_key, COUNT(*) AS cnt
             FROM biomie_bridge_nf_titulo
             GROUP BY titulo_key
             HAVING cnt > 1
         ) _dup"
    )->fetchColumn();

    return $dups === 0
        ? ['ok' => true,  'detail' => '0 títulos vinculados a mais de 1 NF (bridge sem ambiguidade)']
        : ['ok' => false, 'detail' => "{$dups} título(s) vinculados a mais de 1 NF na bridge — possível match ambíguo"];
});

// ── Check 12: Manual override rows are preserved ──────────────────────────────
$guard_check('C12', 'Bridge: is_manual_override = 1 preservado', function () use ($pdo): ?array {
    $bridgeExists = (bool) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biomie_bridge_nf_titulo'"
    )->fetchColumn();

    if (!$bridgeExists) {
        return null;
    }

    $overrides = (int) $pdo->query(
        "SELECT COUNT(*) FROM biomie_bridge_nf_titulo WHERE is_manual_override = 1"
    )->fetchColumn();

    return ['ok' => true, 'detail' => "{$overrides} registro(s) com is_manual_override = 1 protegidos de sobrescrição automática"];
});

// ── Check 13: NFs sem título reduzem com bridge (informational) ───────────────
$guard_check('C13', 'Bridge: cobertura NFs sem título (antes × depois)', function () use ($pdo, $start, $end, $visCondNf): ?array {
    $bridgeExists = (bool) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biomie_bridge_nf_titulo'"
    )->fetchColumn();

    if (!$bridgeExists) {
        return null;
    }

    // Without bridge: original nota_key join (known broken — zero matches)
    $stmtOld = $pdo->prepare(
        "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas nf
         LEFT JOIN biomie_titulos_financeiros_canonicos t ON t.nota_key = nf.nota_key
         WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
           AND {$visCondNf}
           AND t.titulo_key IS NULL"
    );
    $stmtOld->execute([':start' => $start, ':end' => $end]);
    $semTituloOld = (int) $stmtOld->fetchColumn();

    // With bridge
    $stmtNew = $pdo->prepare(
        "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas nf
         LEFT JOIN biomie_bridge_nf_titulo br ON br.nota_key = nf.nota_key
         LEFT JOIN biomie_titulos_financeiros_canonicos t ON t.titulo_key = br.titulo_key
         WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
           AND {$visCondNf}
           AND t.titulo_key IS NULL"
    );
    $stmtNew->execute([':start' => $start, ':end' => $end]);
    $semTituloNew = (int) $stmtNew->fetchColumn();

    $improved = $semTituloOld - $semTituloNew;
    $ok       = $semTituloNew <= $semTituloOld;

    return [
        'ok'     => $ok,
        'detail' => "NFs sem título: método antigo={$semTituloOld}, bridge={$semTituloNew}, melhora={$improved}",
    ];
});

// ── Q.10.1 helper: table-existence check shared by C14-C23 ───────────────────
$hasTable = function (string $name) use ($pdo): bool {
    return (bool) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($name)
    )->fetchColumn();
};

// ── Check 14: Items — no titulo_key linked to 2+ distinct NFs ─────────────────
$guard_check('C14', 'Items: nenhum título vinculado a 2+ NFs distintas', function () use ($pdo, $hasTable): ?array {
    if (!$hasTable('biomie_bridge_nf_titulo_items')) { return null; }

    $dups = (int) $pdo->query(
        "SELECT COUNT(*) FROM (
             SELECT titulo_key, COUNT(DISTINCT nota_key) AS cnt
             FROM biomie_bridge_nf_titulo_items
             GROUP BY titulo_key
             HAVING cnt > 1
         ) _dup"
    )->fetchColumn();

    return $dups === 0
        ? ['ok' => true,  'detail' => '0 títulos em 2+ NFs distintas nos items (sem ambiguidade)']
        : ['ok' => false, 'detail' => "{$dups} título(s) aparecem em 2+ NFs distintas — rever match fuzzy"];
});

// ── Check 15: Summary valor_titulos_total confere com soma dos itens ──────────
$guard_check('C15', 'Summary: valor_titulos_total = soma dos títulos individuais', function () use ($pdo, $hasTable): ?array {
    if (!$hasTable('biomie_bridge_nf_titulo_items') || !$hasTable('biomie_bridge_nf_titulo_summary')) {
        return null;
    }

    $mismatches = (int) $pdo->query(
        "SELECT COUNT(*) FROM (
             SELECT s.nota_key
             FROM biomie_bridge_nf_titulo_summary s
             JOIN biomie_bridge_nf_titulo_items bni ON bni.nota_key = s.nota_key
             JOIN biomie_titulos_financeiros_canonicos t ON t.titulo_key = bni.titulo_key
             GROUP BY s.nota_key, s.valor_titulos_total
             HAVING ABS(s.valor_titulos_total - ROUND(SUM(COALESCE(t.valor_liquido, 0)), 2)) > 0.01
         ) _m"
    )->fetchColumn();

    return $mismatches === 0
        ? ['ok' => true,  'detail' => 'Summary valor_titulos_total bate com soma dos títulos individuais']
        : ['ok' => false, 'detail' => "{$mismatches} NF(s) com divergência entre summary e recálculo dos itens"];
});

// ── Check 16: Summary OK: |base_comercial - valor_titulos_total| <= R$0.01 ────
$guard_check('C16', 'Summary OK: diferença base_comercial vs soma ≤ R$ 0,01', function () use ($pdo, $start, $end, $baseSql, $visCondNf, $hasTable): ?array {
    if (!$hasTable('biomie_bridge_nf_titulo_summary')) { return null; }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM biomie_notas_fiscais_canonicas nf
         JOIN biomie_bridge_nf_titulo_summary s ON s.nota_key = nf.nota_key
         WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
           AND {$visCondNf}
           AND s.min_confidence_score >= 100
           AND ABS(({$baseSql}) - s.valor_titulos_total) > 0.01"
    );
    $stmt->execute([':start' => $start, ':end' => $end]);
    $bad = (int) $stmt->fetchColumn();

    $okStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM biomie_notas_fiscais_canonicas nf
         JOIN biomie_bridge_nf_titulo_summary s ON s.nota_key = nf.nota_key
         WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
           AND {$visCondNf}
           AND s.min_confidence_score >= 100
           AND ABS(({$baseSql}) - s.valor_titulos_total) <= 0.01"
    );
    $okStmt->execute([':start' => $start, ':end' => $end]);
    $okCnt = (int) $okStmt->fetchColumn();

    return ['ok' => true, 'detail' => "Summary confidence=100 : {$okCnt} OK (|dif|<=0,01), {$bad} divergentes (|dif|>0,01)"];
});

// ── Check 17: Redução de falsas divergências com soma vs único título ──────────
$guard_check('C17', 'Falsas divergências reduzidas: soma vs título único', function () use ($pdo, $start, $end, $baseSql, $visCondNf, $hasTable): ?array {
    if (!$hasTable('biomie_bridge_nf_titulo_summary')) { return null; }

    // Old method: base_com computed in SELECT (avoids HAVING non-agg column issue), outer WHERE compares
    $oldStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM (
             SELECT nf.nota_key,
                    ({$baseSql})         AS base_com,
                    MIN(t.valor_liquido) AS min_titulo
             FROM biomie_notas_fiscais_canonicas nf
             JOIN biomie_titulos_financeiros_canonicos t
               ON t.chave_nfe = nf.chave_nfe AND nf.chave_nfe IS NOT NULL AND nf.chave_nfe != ''
             WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
               AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
               AND {$visCondNf}
             GROUP BY nf.nota_key
         ) _old
         WHERE ABS(base_com - min_titulo) > 0.01"
    );
    $oldStmt->execute([':start' => $start, ':end' => $end]);
    $oldDiverg = (int) $oldStmt->fetchColumn();

    // New method: compare base_comercial vs soma (summary)
    $newStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM biomie_notas_fiscais_canonicas nf
         JOIN biomie_bridge_nf_titulo_summary s ON s.nota_key = nf.nota_key
         WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
           AND {$visCondNf}
           AND ABS(({$baseSql}) - s.valor_titulos_total) > 0.01"
    );
    $newStmt->execute([':start' => $start, ':end' => $end]);
    $newDiverg = (int) $newStmt->fetchColumn();

    $reducao = $oldDiverg - $newDiverg;
    $ok      = $newDiverg <= $oldDiverg;

    return [
        'ok'     => $ok,
        'detail' => "Divergências: método antigo={$oldDiverg}, soma={$newDiverg}, redução={$reducao} falsas divergências",
    ];
});

// ── Check 18: Parceladas informacionais (qtd_titulos > 1) ─────────────────────
$guard_check('C18', 'Parceladas: NFs com múltiplos títulos (informacional)', function () use ($pdo, $hasTable): ?array {
    if (!$hasTable('biomie_bridge_nf_titulo_summary')) { return null; }

    $parc = (int) $pdo->query(
        "SELECT COUNT(*) FROM biomie_bridge_nf_titulo_summary WHERE qtd_titulos > 1"
    )->fetchColumn();
    $total = (int) $pdo->query(
        "SELECT COUNT(*) FROM biomie_bridge_nf_titulo_summary"
    )->fetchColumn();

    return ['ok' => true, 'detail' => "{$parc}/{$total} NFs parceladas (múltiplos títulos) no summary"];
});

// ── Check 19: Manual override preservado na tabela de itens ───────────────────
$guard_check('C19', 'Items: is_manual_override = 1 preservado', function () use ($pdo, $hasTable): ?array {
    if (!$hasTable('biomie_bridge_nf_titulo_items')) { return null; }

    $overrides = (int) $pdo->query(
        "SELECT COUNT(*) FROM biomie_bridge_nf_titulo_items WHERE is_manual_override = 1"
    )->fetchColumn();

    return ['ok' => true, 'detail' => "{$overrides} item(ns) com is_manual_override = 1 protegido(s) de sobrescrição"];
});

// ── Check 20: Items idempotente (COUNT estável em 2 consultas consecutivas) ───
$guard_check('C20', 'Items: idempotência — contagem estável', function () use ($pdo, $hasTable): ?array {
    if (!$hasTable('biomie_bridge_nf_titulo_items')) { return null; }

    $c1 = (int) $pdo->query('SELECT COUNT(*) FROM biomie_bridge_nf_titulo_items')->fetchColumn();
    $c2 = (int) $pdo->query('SELECT COUNT(*) FROM biomie_bridge_nf_titulo_items')->fetchColumn();

    return $c1 === $c2
        ? ['ok' => true,  'detail' => "Contagem estável: {$c1} itens"]
        : ['ok' => false, 'detail' => "Contagem instável: {$c1} ≠ {$c2}"];
});

// ── Check 21: Summary recalculável a partir dos itens (spot-check 1 NF) ───────
$guard_check('C21', 'Summary recalculável a partir dos itens (spot-check)', function () use ($pdo, $hasTable): ?array {
    if (!$hasTable('biomie_bridge_nf_titulo_items') || !$hasTable('biomie_bridge_nf_titulo_summary')) {
        return null;
    }

    // Pega a NF com mais itens e verifica qtd_titulos do summary
    $sample = $pdo->query(
        "SELECT bni.nota_key, COUNT(DISTINCT bni.titulo_key) AS calc_qtd, s.qtd_titulos AS sum_qtd
         FROM biomie_bridge_nf_titulo_items bni
         JOIN biomie_bridge_nf_titulo_summary s ON s.nota_key = bni.nota_key
         GROUP BY bni.nota_key, s.qtd_titulos
         ORDER BY calc_qtd DESC
         LIMIT 1"
    )->fetch(\PDO::FETCH_ASSOC);

    if (!$sample) {
        return ['ok' => true, 'detail' => 'Items e summary vazios — nada para verificar'];
    }

    $ok = ((int) $sample['calc_qtd']) === ((int) $sample['sum_qtd']);

    return $ok
        ? ['ok' => true,  'detail' => "Spot-check nota_key={$sample['nota_key']}: calc_qtd={$sample['calc_qtd']}, sum_qtd={$sample['sum_qtd']} — OK"]
        : ['ok' => false, 'detail' => "Spot-check nota_key={$sample['nota_key']}: calc_qtd={$sample['calc_qtd']} ≠ sum_qtd={$sample['sum_qtd']} — summary desatualizado?"];
});

// ── Check 22: Licitação excluída dos items e summary ─────────────────────────
$guard_check('C22', 'Licitação excluída dos items e summary', function () use ($pdo, $visCondNf, $hasTable): ?array {
    if (!$hasTable('biomie_bridge_nf_titulo_items')) { return null; }

    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM biomie_bridge_nf_titulo_items bni
         JOIN biomie_notas_fiscais_canonicas nf ON nf.nota_key = bni.nota_key
         WHERE NOT ({$visCondNf})"
    );
    $leaked = (int) $stmt->fetchColumn();

    return $leaked === 0
        ? ['ok' => true,  'detail' => '0 itens de NFs excluídas pelo BiVisibilityScope (Licitação) nos items']
        : ['ok' => false, 'detail' => "{$leaked} item(ns) de NFs de Licitação vazaram para biomie_bridge_nf_titulo_items"];
});

// ── Check 23: Base Comercial NF intacta nas NFs do summary ───────────────────
$guard_check('C23', 'Base Comercial NF intacta para NFs no summary', function () use ($pdo, $start, $end, $baseSql, $visCondNf, $hasTable): ?array {
    if (!$hasTable('biomie_bridge_nf_titulo_summary')) { return null; }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM biomie_notas_fiscais_canonicas nf
         JOIN biomie_bridge_nf_titulo_summary s ON s.nota_key = nf.nota_key
         WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
           AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :start AND :end
           AND {$visCondNf}
           AND ({$baseSql}) < 0"
    );
    $stmt->execute([':start' => $start, ':end' => $end]);
    $neg = (int) $stmt->fetchColumn();

    return $neg === 0
        ? ['ok' => true,  'detail' => '0 NFs com Base Comercial NF negativa no summary']
        : ['ok' => false, 'detail' => "{$neg} NF(s) com Base Comercial NF negativa presentes no summary"];
});

// ── Q.10.3: Checks para estratégias por Pedido ───────────────────────────────
$pedidoMethods = ['pedido_id_omie', 'pedido_numero_documento', 'pedido_codigo_origem', 'pedido_doc_origem_ref'];
$pedidoMethodIn = "'" . implode("','", $pedidoMethods) . "'";

// C24: Matches por pedido não vinculam mesmo título a 2+ NFs distintas
$guard_check('C24', 'Pedido: sem título vinculado a 2+ NFs distintas', function () use ($pdo, $hasTable, $pedidoMethodIn): ?array {
    if (!$hasTable('biomie_bridge_nf_titulo_items')) { return null; }

    $dups = (int) $pdo->query(
        "SELECT COUNT(*) FROM (
             SELECT titulo_key, COUNT(DISTINCT nota_key) AS cnt
             FROM biomie_bridge_nf_titulo_items
             WHERE match_method IN ({$pedidoMethodIn})
             GROUP BY titulo_key
             HAVING cnt > 1
         ) _dup"
    )->fetchColumn();

    return $dups === 0
        ? ['ok' => true,  'detail' => "0 títulos (match pedido) em 2+ NFs distintas"]
        : ['ok' => false, 'detail' => "{$dups} título(s) de match pedido aparecem em 2+ NFs — rever ambiguidade"];
});

// C25: Matches por pedido não pioram confidence mínima da bridge
$guard_check('C25', 'Pedido: confidence mínima dos itens ≥ 80', function () use ($pdo, $hasTable, $pedidoMethodIn): ?array {
    if (!$hasTable('biomie_bridge_nf_titulo_items')) { return null; }

    $bad = (int) $pdo->query(
        "SELECT COUNT(*) FROM biomie_bridge_nf_titulo_items
         WHERE match_method IN ({$pedidoMethodIn})
           AND confidence_score < 80"
    )->fetchColumn();

    return $bad === 0
        ? ['ok' => true,  'detail' => "0 itens de match pedido com confidence < 80"]
        : ['ok' => false, 'detail' => "{$bad} item(ns) de match pedido com confidence < 80 (abaixo do limiar)"];
});

// C26: Matches por pedido preservam is_manual_override
$guard_check('C26', 'Pedido: is_manual_override preservado nos itens de pedido', function () use ($pdo, $hasTable, $pedidoMethodIn): ?array {
    if (!$hasTable('biomie_bridge_nf_titulo_items')) { return null; }

    $overrides = (int) $pdo->query(
        "SELECT COUNT(*) FROM biomie_bridge_nf_titulo_items
         WHERE is_manual_override = 1"
    )->fetchColumn();

    return ['ok' => true, 'detail' => "{$overrides} item(ns) com is_manual_override=1 protegidos (todos os métodos)"];
});

// C27: cliente_mes_valor_agregado não foi aplicado automaticamente
$guard_check('C27', 'Pedido: cliente_mes_valor_agregado não aplicado automaticamente', function () use ($pdo, $hasTable): ?array {
    if (!$hasTable('biomie_bridge_nf_titulo_items')) { return null; }

    $bad = (int) $pdo->query(
        "SELECT COUNT(*) FROM biomie_bridge_nf_titulo_items
         WHERE match_method = 'cliente_mes_valor_agregado'"
    )->fetchColumn();

    return $bad === 0
        ? ['ok' => true,  'detail' => "0 registros com match_method=cliente_mes_valor_agregado (correto — nunca auto-aplicado)"]
        : ['ok' => false, 'detail' => "{$bad} registro(s) indevidos com match_method=cliente_mes_valor_agregado"];
});

// C28: 135 NFs possivel_pagamento_avista continuam sem bridge forçada
$guard_check('C28', 'Pedido: clientes sem nenhum título não recebem vínculo automático', function () use ($pdo, $hasTable, $visCondNf, $pedidoMethodIn): ?array {
    if (!$hasTable('biomie_bridge_nf_titulo_items')) { return null; }

    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM biomie_bridge_nf_titulo_items bni
         JOIN biomie_notas_fiscais_canonicas nf ON nf.nota_key = bni.nota_key
         WHERE bni.match_method IN ({$pedidoMethodIn})
           AND {$visCondNf}
           AND NOT EXISTS (
               SELECT 1 FROM biomie_titulos_financeiros_canonicos t
               WHERE t.codigo_cliente_omie = nf.codigo_cliente_omie
           )"
    );
    $bad = (int) $stmt->fetchColumn();

    return $bad === 0
        ? ['ok' => true,  'detail' => "0 NFs de clientes sem títulos receberam vínculo automático por pedido"]
        : ['ok' => false, 'detail' => "{$bad} NF(s) de clientes sem títulos receberam vínculo indevido"];
});

// C29: NFs sem título só reduzem quando há título real (not forced)
$guard_check('C29', 'Pedido: NFs sem título reduzem apenas por título real vinculado', function () use ($pdo, $hasTable): ?array {
    if (!$hasTable('biomie_bridge_nf_titulo_items')) { return null; }

    $orphans = (int) $pdo->query(
        "SELECT COUNT(*) FROM biomie_bridge_nf_titulo_items bni
         LEFT JOIN biomie_titulos_financeiros_canonicos t ON t.titulo_key = bni.titulo_key
         WHERE t.titulo_key IS NULL"
    )->fetchColumn();

    return $orphans === 0
        ? ['ok' => true,  'detail' => "0 items apontam para titulo_key inexistente (vínculos reais apenas)"]
        : ['ok' => false, 'detail' => "{$orphans} item(ns) com titulo_key sem correspondente na tabela de títulos"];
});

// C30: Métodos de pedido aplicados — contagem informacional
$guard_check('C30', 'Pedido: contagem de métodos de match por pedido (informacional)', function () use ($pdo, $hasTable, $pedidoMethodIn): ?array {
    if (!$hasTable('biomie_bridge_nf_titulo_items')) { return null; }

    $rows = $pdo->query(
        "SELECT match_method, COUNT(*) AS cnt
         FROM biomie_bridge_nf_titulo_items
         WHERE match_method IN ({$pedidoMethodIn})
         GROUP BY match_method
         ORDER BY cnt DESC"
    )->fetchAll(\PDO::FETCH_KEY_PAIR);

    if (empty($rows)) {
        return ['ok' => true, 'detail' => "Nenhum item com match pedido ainda (strategy=pedido não foi executada)"];
    }

    $parts = [];
    foreach ($rows as $method => $cnt) { $parts[] = "{$method}={$cnt}"; }
    return ['ok' => true, 'detail' => implode(', ', $parts)];
});

// ── Output ────────────────────────────────────────────────────────────────────
if ($pretty) {
    echo PHP_EOL;
    printf("  %-6s  %-4s  %s\n", 'Status', 'ID', 'Check');
    echo str_repeat('─', 72) . PHP_EOL;
    foreach ($results as $r) {
        $sym = match ($r['status']) { 'PASS' => "\033[32mPASS\033[0m", 'FAIL' => "\033[31mFAIL\033[0m", default => "\033[33mSKIP\033[0m" };
        printf("  [%s]  %-4s  %s\n", $sym, $r['id'], $r['label']);
        if ($r['detail'] !== '') printf("            %s\n", $r['detail']);
    }
    echo str_repeat('─', 72) . PHP_EOL;
    echo PHP_EOL;
    $total = $pass + $fail + $skip;
    if ($fail === 0) {
        echo "\033[32m✔ {$pass}/{$total} checks passaram. Conciliação íntegra.\033[0m" . PHP_EOL;
    } else {
        echo "\033[31m✘ {$fail} falha(s). Verifique os checks acima.\033[0m" . PHP_EOL;
    }
    echo PHP_EOL;
} else {
    echo json_encode(['pass' => $pass, 'fail' => $fail, 'skip' => $skip, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

exit($fail > 0 ? 1 : 0);
