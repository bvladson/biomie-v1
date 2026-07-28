<?php

declare(strict_types=1);

/**
 * Constrói biomie_clientes_grupo a partir das tabelas canônicas.
 *
 * Itera sobre todas as empresas ativas, agrega pedidos e NFs por CNPJ de
 * cliente, popula biomie_clientes_grupo_membros e depois consolida no grupo.
 * CNPJs que aparecem em 2+ empresas ficam com total_companies >= 2.
 *
 * Uso:
 *   php bin/build_clientes_grupo.php
 *   php bin/build_clientes_grupo.php --dry-run
 *   php bin/build_clientes_grupo.php --company=mag_filial  (só uma empresa)
 *   php bin/build_clientes_grupo.php --min-receita=100     (filtra CNPJs sem receita)
 *   php bin/build_clientes_grupo.php --audit               (mostra top compartilhados)
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Support\ClientGroupResolver;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo    = Database::connect($config, $conn);

set_time_limit(0);
ini_set('memory_limit', '512M');

$rawArgs    = array_slice($argv ?? [], 1);
$dryRun     = in_array('--dry-run', $rawArgs, true);
$auditMode  = in_array('--audit', $rawArgs, true);
$companyArg = '';
$minReceita = 0.0;
foreach ($rawArgs as $arg) {
    if (str_starts_with($arg, '--company='))    $companyArg = substr($arg, 10);
    if (str_starts_with($arg, '--min-receita=')) $minReceita = (float) substr($arg, 14);
}

// ── Modo Audit ────────────────────────────────────────────────────────────────
if ($auditMode) {
    $resolver = new ClientGroupResolver($pdo);
    $shared   = $resolver->getSharedClients(2);
    echo "CNPJs compartilhados (2+ empresas): " . count($shared) . "\n\n";
    foreach (array_slice($shared, 0, 20) as $g) {
        echo sprintf(
            "CNPJ %-14s | %-40s | %d empresas | R$ %s\n",
            $g['cnpj'],
            mb_substr((string) $g['nome_canonical'], 0, 40),
            $g['total_companies'],
            number_format((float) $g['total_receita'], 2, ',', '.')
        );
    }
    exit(0);
}

// ── Resolve empresas ──────────────────────────────────────────────────────────
if ($companyArg !== '') {
    $stmt = $pdo->prepare(
        "SELECT id, slug, canal_padrao FROM biomie_companies
          WHERE ativo = 1 AND (id = :sel_id OR slug = :sel_slug) LIMIT 1"
    );
    $stmt->execute([
        'sel_id'   => ctype_digit($companyArg) ? (int) $companyArg : 0,
        'sel_slug' => strtoupper($companyArg),
    ]);
    $companies = $stmt->fetchAll(\PDO::FETCH_ASSOC);
} else {
    $companies = $pdo->query(
        "SELECT id, slug, canal_padrao FROM biomie_companies WHERE ativo = 1 ORDER BY id"
    )->fetchAll(\PDO::FETCH_ASSOC);
}

if (empty($companies)) {
    fwrite(STDERR, "Nenhuma empresa encontrada.\n");
    exit(2);
}

$t0      = microtime(true);
$now     = date('Y-m-d H:i:s');
$nowDate = date('Y-m-d');
$totalMembros = 0;

echo ($dryRun ? '[DRY-RUN] ' : '') . "Construindo biomie_clientes_grupo...\n";
echo 'Empresas: ' . implode(', ', array_column($companies, 'slug')) . "\n\n";

// ── Por empresa: agrega e upsert membros ──────────────────────────────────────
foreach ($companies as $company) {
    $cid  = (int) $company['id'];
    $slug = (string) $company['slug'];
    $defaultChannel = (string) ($company['canal_padrao'] ?: 'varejo');

    echo "  → {$slug} (id={$cid}): ";

    // Fonte: pedidos (canal predominante calculado por contagem)
    $pedStmt = $pdo->prepare("
        SELECT
            REGEXP_REPLACE(documento_cliente, '[^0-9]', '')       AS cnpj,
            MAX(nome_cliente)                                      AS nome_local,
            MAX(codigo_cliente_omie)                               AS codigo_omie,
            COUNT(*)                                               AS total_pedidos,
            SUM(valor_total)                                       AS receita_ped,
            SUM(business_channel = 'licitacao')                   AS cnt_licit,
            SUM(business_channel = 'varejo')                      AS cnt_varejo,
            MIN(DATE(COALESCE(data_faturamento, data_emissao)))   AS first_seen,
            MAX(DATE(COALESCE(data_faturamento, data_emissao)))   AS last_seen
        FROM biomie_pedidos_canonicos
        WHERE company_id = :company_id
          AND documento_cliente IS NOT NULL
          AND CHAR_LENGTH(REGEXP_REPLACE(documento_cliente, '[^0-9]', '')) = 14
        GROUP BY cnpj
    ");
    $pedStmt->execute(['company_id' => $cid]);
    $rowsPed = $pedStmt->fetchAll(\PDO::FETCH_ASSOC);

    // Fonte: NFs (contagem e receita)
    $nfStmt = $pdo->prepare("
        SELECT
            REGEXP_REPLACE(documento_cliente, '[^0-9]', '')       AS cnpj,
            MAX(nome_cliente)                                      AS nome_local,
            MAX(codigo_cliente_omie)                               AS codigo_omie,
            COUNT(*)                                               AS total_nfs,
            SUM(valor_total)                                       AS receita_nf,
            MIN(DATE(data_emissao))                               AS first_nf,
            MAX(DATE(data_emissao))                               AS last_nf
        FROM biomie_notas_fiscais_canonicas
        WHERE company_id = :company_id
          AND documento_cliente IS NOT NULL
          AND CHAR_LENGTH(REGEXP_REPLACE(documento_cliente, '[^0-9]', '')) = 14
          AND status_fiscal = 'autorizada'
        GROUP BY cnpj
    ");
    $nfStmt->execute(['company_id' => $cid]);
    $rowsNF = $nfStmt->fetchAll(\PDO::FETCH_ASSOC);

    // Indexa ambas as fontes para incluir clientes presentes apenas em pedidos ou NFs.
    $pedByKey = [];
    foreach ($rowsPed as $r) {
        $pedByKey[(string) $r['cnpj']] = $r;
    }
    $nfByKey = [];
    foreach ($rowsNF as $r) {
        $nfByKey[(string) $r['cnpj']] = $r;
    }
    $customerKeys = array_values(array_unique(array_merge(array_keys($pedByKey), array_keys($nfByKey))));

    $cnt = 0;
    if (!$dryRun) {
        $upsert = $pdo->prepare("
            INSERT INTO biomie_clientes_grupo_membros
                (cnpj, company_id, codigo_cliente_omie, nome_local,
                 canal_predominante, total_pedidos, total_nfs, total_receita,
                 first_seen_at, last_seen_at, built_at)
            VALUES
                (:cnpj, :cid, :omie, :nome, :canal, :ped, :nfs, :rec, :first, :last, NOW())
            ON DUPLICATE KEY UPDATE
                codigo_cliente_omie = VALUES(codigo_cliente_omie),
                nome_local          = VALUES(nome_local),
                canal_predominante  = VALUES(canal_predominante),
                total_pedidos       = VALUES(total_pedidos),
                total_nfs           = VALUES(total_nfs),
                total_receita       = VALUES(total_receita),
                first_seen_at       = LEAST(COALESCE(first_seen_at, VALUES(first_seen_at)), VALUES(first_seen_at)),
                last_seen_at        = GREATEST(COALESCE(last_seen_at, VALUES(last_seen_at)), VALUES(last_seen_at)),
                built_at            = NOW()
        ");

        $pdo->beginTransaction();
        try {
            $deleteMembers = $pdo->prepare(
                'DELETE FROM biomie_clientes_grupo_membros WHERE company_id = :company_id'
            );
            $deleteMembers->execute(['company_id' => $cid]);

            foreach ($customerKeys as $cnpj) {
            $row    = $pedByKey[$cnpj] ?? [];
            $nf     = $nfByKey[$cnpj] ?? [];
            $licit  = (int) ($row['cnt_licit'] ?? 0);
            $var    = (int) ($row['cnt_varejo'] ?? 0);
            $canal  = ($licit > $var)
                ? 'licitacao'
                : (($var > $licit) ? 'varejo' : (($licit > 0) ? 'misto' : $defaultChannel));
            $recNF  = (float) ($nf['receita_nf'] ?? 0);
            $recPed = (float) ($row['receita_ped'] ?? 0);
            $receita = max($recPed, $recNF);
            if ($receita < $minReceita) {
                continue;
            }

            $firstDates = array_values(array_filter([
                $row['first_seen'] ?? null,
                $nf['first_nf'] ?? null,
            ]));
            $lastDates = array_values(array_filter([
                $row['last_seen'] ?? null,
                $nf['last_nf'] ?? null,
            ]));

                $upsert->execute([
                'cnpj'  => $cnpj,
                'cid'   => $cid,
                'omie'  => ($row['codigo_omie'] ?? $nf['codigo_omie'] ?? null) ?: null,
                'nome'  => (string) (($row['nome_local'] ?? $nf['nome_local'] ?? '') ?: ''),
                'canal' => $canal,
                'ped'   => (int) ($row['total_pedidos'] ?? 0),
                'nfs'   => (int) ($nf['total_nfs'] ?? 0),
                'rec'   => $receita,
                'first' => $firstDates !== [] ? min($firstDates) : null,
                'last'  => $lastDates !== [] ? max($lastDates) : null,
                ]);
                $cnt++;
            }
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    } else {
        foreach ($customerKeys as $cnpj) {
            $recPed = (float) ($pedByKey[$cnpj]['receita_ped'] ?? 0);
            $recNF = (float) ($nfByKey[$cnpj]['receita_nf'] ?? 0);
            if (max($recPed, $recNF) >= $minReceita) {
                $cnt++;
            }
        }
    }

    $totalMembros += $cnt;
    echo "{$cnt} CNPJs\n";
}

// ── Consolida grupo (agregação cross-empresa) ─────────────────────────────────
if (!$dryRun) {
    echo "\n  Consolidando biomie_clientes_grupo...\n";

    $pdo->exec("
        INSERT INTO biomie_clientes_grupo
            (cnpj, nome_canonical, primary_company_id, total_companies,
             total_pedidos, total_nfs, total_receita,
             first_seen_at, last_seen_at, built_at)
        SELECT
            m.cnpj,
            MAX(m.nome_local)                        AS nome_canonical,
            MIN(m.company_id)                        AS primary_company_id,
            COUNT(DISTINCT m.company_id)             AS total_companies,
            SUM(m.total_pedidos)                     AS total_pedidos,
            SUM(m.total_nfs)                         AS total_nfs,
            SUM(m.total_receita)                     AS total_receita,
            MIN(m.first_seen_at)                     AS first_seen_at,
            MAX(m.last_seen_at)                      AS last_seen_at,
            NOW()                                    AS built_at
        FROM biomie_clientes_grupo_membros m
        GROUP BY m.cnpj
        ON DUPLICATE KEY UPDATE
            nome_canonical     = VALUES(nome_canonical),
            total_companies    = VALUES(total_companies),
            total_pedidos      = VALUES(total_pedidos),
            total_nfs          = VALUES(total_nfs),
            total_receita      = VALUES(total_receita),
            first_seen_at      = VALUES(first_seen_at),
            last_seen_at       = VALUES(last_seen_at),
            built_at           = NOW()
    ");
    $pdo->exec("
        DELETE g
          FROM biomie_clientes_grupo g
          LEFT JOIN biomie_clientes_grupo_membros m ON m.cnpj = g.cnpj
         WHERE m.cnpj IS NULL
    ");

    // Contagem de resultado
    $totalGrupos  = (int) $pdo->query("SELECT COUNT(*) FROM biomie_clientes_grupo")->fetchColumn();
    $totalShared  = (int) $pdo->query("SELECT COUNT(*) FROM biomie_clientes_grupo WHERE total_companies >= 2")->fetchColumn();

    // Audit trail
    $elapsed = (int) ((microtime(true) - $t0) * 1000);
    $pdo->prepare("
        INSERT INTO biomie_clientes_grupo_builds
            (duration_ms, grupos_total, grupos_shared, membros_total, status)
        VALUES (:ms, :gt, :gs, :mt, 'ok')
    ")->execute([
        'ms' => $elapsed,
        'gt' => $totalGrupos,
        'gs' => $totalShared,
        'mt' => $totalMembros,
    ]);
} else {
    $totalGrupos = '(dry-run)';
    $totalShared = '(dry-run)';
}

$elapsed = round(microtime(true) - $t0, 1);
echo "\n";
echo json_encode([
    'ok'            => true,
    'dry_run'       => $dryRun,
    'total_membros' => $totalMembros,
    'total_grupos'  => $totalGrupos,
    'total_shared'  => $totalShared,
    'elapsed_s'     => $elapsed,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
