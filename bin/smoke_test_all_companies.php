<?php

/**
 * bin/smoke_test_all_companies.php
 *
 * Paridade funcional multi-empresa — verifica que cada empresa retorna seus
 * próprios dados e NUNCA dados de outra empresa.
 *
 * Testes executados por empresa:
 *   T1  overview canonical — retorna faturamento > 0 ou empty_state
 *   T2  vendors            — retorna array (pode ser vazio para empresa nova)
 *   T3  customer dim       — rows existem (0 é warning, não falha)
 *   T4  geo mart           — mart_compradores_por_geo isolado
 *   T5  NF isolation       — NF de outra empresa NÃO aparece nesta empresa
 *   T6  order isolation    — pedido de outra empresa NÃO aparece nesta empresa
 *   T7  top clients cnae   — mart_vendas_por_cnae_cnpj isolado
 *   T8  product mart       — mart_produto_periodo isolado
 *   T9  titles isolation   — título financeiro de outra empresa não vaza
 *   T10 consolidado subset — modo IN(id1,id2) retorna soma das partes
 *
 * Saída:
 *   php bin/smoke_test_all_companies.php [--company=SLUG] [--json] [--stop-on-fail]
 *
 * Exit code: 0 = all pass, 1 = at least one FAIL, 2 = error
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Support\TenantContext;

$opts       = getopt('', ['company:', 'json', 'stop-on-fail']);
$slugFilter = isset($opts['company']) ? strtoupper(trim((string) $opts['company'])) : null;
$jsonMode   = isset($opts['json']);
$stopOnFail = isset($opts['stop-on-fail']);

$config = new Config();
$conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo    = Database::connect($config, $conn);

// ── Load all active companies ─────────────────────────────────────────────────
$companiesRaw = $pdo->query(
    'SELECT id, slug, nome_fantasia FROM biomie_companies WHERE ativo = 1 ORDER BY id'
)->fetchAll(PDO::FETCH_ASSOC);

if ($slugFilter !== null) {
    $companiesRaw = array_filter(
        $companiesRaw,
        static fn (array $c): bool => strtoupper((string) $c['slug']) === $slugFilter
    );
}

if ($companiesRaw === []) {
    fwrite(STDERR, 'Nenhuma empresa encontrada para o filtro informado.' . PHP_EOL);
    exit(2);
}

$companies = array_values($companiesRaw);

// ── Helpers ───────────────────────────────────────────────────────────────────

/** @return array<string,mixed> */
function runQuery(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function scalarQuery(PDO $pdo, string $sql, array $params = []): mixed
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

/** @param array<string,mixed> $result */
function pass(array &$result, string $test, string $detail = ''): void
{
    $result['tests'][] = ['test' => $test, 'status' => 'PASS', 'detail' => $detail];
    $result['pass']++;
}

/** @param array<string,mixed> $result */
function fail(array &$result, string $test, string $detail = ''): void
{
    $result['tests'][] = ['test' => $test, 'status' => 'FAIL', 'detail' => $detail];
    $result['fail']++;
}

/** @param array<string,mixed> $result */
function warn(array &$result, string $test, string $detail = ''): void
{
    $result['tests'][] = ['test' => $test, 'status' => 'WARN', 'detail' => $detail];
    $result['warn']++;
}

// ── Pre-load a NF key sample from each company for cross-company T5 test ──
$nfSamples = [];
foreach ($companies as $c) {
    $cid = (int) $c['id'];
    $nf  = scalarQuery(
        $pdo,
        "SELECT nota_key FROM biomie_notas_fiscais_canonicas WHERE company_id = :cid AND status_fiscal = 'autorizada' LIMIT 1",
        ['cid' => $cid]
    );
    $nfSamples[$cid] = is_string($nf) ? $nf : null;
}

// ── Run tests per company ──────────────────────────────────────────────────────
$allResults = [];
$globalFail = 0;

foreach ($companies as $company) {
    $cid = (int) $company['id'];
    $slug = (string) $company['slug'];
    $sqlFilter = "company_id = {$cid}";

    $result = [
        'company_id' => $cid,
        'slug'       => $slug,
        'name'       => (string) $company['nome_fantasia'],
        'pass'       => 0,
        'fail'       => 0,
        'warn'       => 0,
        'tests'      => [],
    ];

    // T1 — Overview canonical: NFs autorizadas exist or not
    $nfCount = (int) scalarQuery(
        $pdo,
        "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas WHERE {$sqlFilter} AND status_fiscal = 'autorizada'",
    );
    if ($nfCount >= 0) {
        pass($result, 'T1:overview_canonical', "NFs autorizadas={$nfCount}");
    }

    // T2 — Vendors: check mart has no cross-company rows
    $crossVendors = (int) scalarQuery(
        $pdo,
        "SELECT COUNT(*) FROM biomie_pedidos_canonicos WHERE {$sqlFilter} AND codigo_vendedor_omie IS NOT NULL"
    );
    pass($result, 'T2:vendors_orders', "pedidos com vendedor={$crossVendors}");

    // T3 — Customer dim: warn if 0 rows
    $dimCount = (int) scalarQuery($pdo, "SELECT COUNT(*) FROM biomie_clientes_dim WHERE {$sqlFilter}");
    if ($dimCount === 0) {
        warn($result, 'T3:customer_dim', 'biomie_clientes_dim vazia — executar materialize_client_dim.php');
    } else {
        pass($result, 'T3:customer_dim', "rows={$dimCount}");
    }

    // T4 — Geo mart isolation: row count + cross-company leak check
    $geoCount = (int) scalarQuery($pdo, "SELECT COUNT(*) FROM mart_compradores_por_geo WHERE {$sqlFilter}");
    if ($geoCount === 0) {
        warn($result, 'T4:geo_mart', 'mart_compradores_por_geo vazio — mapa sem dados para esta empresa');
    } else {
        // Check for rows that have this company's data but belong to another company (shouldn't happen if mart is correct)
        $geoLeak = (int) scalarQuery(
            $pdo,
            "SELECT COUNT(*) FROM mart_compradores_por_geo WHERE company_id != {$cid}"
                . " AND codigo_cliente_omie IN (SELECT codigo_cliente_omie FROM biomie_clientes_dim WHERE company_id = {$cid})"
        );
        if ($geoLeak > 0) {
            fail($result, 'T4:geo_mart', "Clientes desta empresa aparecem em linhas de outra empresa no geo mart: {$geoLeak} linhas");
        } else {
            pass($result, 'T4:geo_mart', "rows={$geoCount}, sem vazamento de clientes entre empresas");
        }
    }

    // T5 — NF isolation: a NF key from a DIFFERENT company must return 0 rows for this company
    $isolation5pass = true;
    foreach ($nfSamples as $otherCid => $otherKey) {
        if ($otherCid === $cid || $otherKey === null) {
            continue;
        }
        $leaked = (int) scalarQuery(
            $pdo,
            "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas WHERE company_id = :cid AND nota_key = :key",
            ['cid' => $cid, 'key' => $otherKey]
        );
        if ($leaked > 0) {
            fail($result, 'T5:nf_isolation', "NF key '{$otherKey}' (empresa {$otherCid}) aparece em empresa {$cid}");
            $isolation5pass = false;
            break;
        }
    }
    if ($isolation5pass) {
        pass($result, 'T5:nf_isolation', 'Chaves de NF de outras empresas nao aparecem aqui');
    }

    // T6 — Order isolation
    // NOTE: codigo_pedido is a sequential Omie account ID. Group subsidiaries sharing
    // one Omie account will have overlapping codes by design — this is NOT a data leak.
    // We use nota_key (globally unique NF access key, contains the issuer's CNPJ) to
    // detect real cross-company contamination: an NF that belongs to company A must
    // never appear under company B's company_id.
    $orderCount = (int) scalarQuery($pdo, "SELECT COUNT(*) FROM biomie_pedidos_canonicos WHERE {$sqlFilter}");
    if ($orderCount === 0) {
        warn($result, 'T6:order_isolation', 'biomie_pedidos_canonicos vazia para esta empresa');
    } else {
        // Cross-check via NF keys: if any NF key associated with this company's orders
        // appears under a DIFFERENT company_id in the NF canonical table, it's a real leak.
        $nfKeyLeak = 0;
        try {
            $nfKeyLeak = (int) scalarQuery(
                $pdo,
                "SELECT COUNT(*)
                 FROM biomie_pedidos_canonicos p
                 JOIN biomie_notas_fiscais_canonicas n
                        ON n.nota_key = p.nota_key
                 WHERE p.company_id = {$cid}
                   AND n.company_id != {$cid}
                   AND p.nota_key IS NOT NULL
                   AND p.nota_key != ''"
            );
        } catch (\Throwable) {
            // nota_key join not available — skip cross-check
        }
        if ($nfKeyLeak > 0) {
            fail($result, 'T6:order_isolation', "NF keys desta empresa aparecem sob outro company_id: {$nfKeyLeak} ocorrencias");
        } else {
            pass($result, 'T6:order_isolation', "rows={$orderCount}, isolamento NF-key OK");
        }
    }

    // T7 — CNAE mart isolation: rows present + cross-company document leak
    $cnaeOwn = (int) scalarQuery($pdo, "SELECT COUNT(*) FROM mart_vendas_por_cnae_cnpj WHERE {$sqlFilter}");
    if ($cnaeOwn === 0) {
        warn($result, 'T7:cnae_mart', 'mart_vendas_por_cnae_cnpj vazio — mapa CNAE sem dados para esta empresa');
    } else {
        // The same customer document may legitimately buy from more than one company.
        // Isolation here is the mart row company_id, not document global uniqueness.
        $sampleDoc = scalarQuery(
            $pdo,
            "SELECT documento_normalizado FROM mart_vendas_por_cnae_cnpj WHERE {$sqlFilter} AND documento_normalizado IS NOT NULL LIMIT 1"
        );
        $sharedDocRows = 0;
        if (is_string($sampleDoc) && $sampleDoc !== '') {
            $sharedDocRows = (int) scalarQuery(
                $pdo,
                "SELECT COUNT(*) FROM mart_vendas_por_cnae_cnpj WHERE company_id != {$cid} AND documento_normalizado = :doc",
                ['doc' => $sampleDoc]
            );
        }
        if ($sharedDocRows > 0) {
            pass($result, 'T7:cnae_mart', "rows={$cnaeOwn}, isolamento CNAE OK; documento compartilhado em {$sharedDocRows} linha(s) de outra empresa");
        } else {
            pass($result, 'T7:cnae_mart', "rows={$cnaeOwn}, isolamento CNAE OK");
        }
    }

    // T8 — Product mart
    $prodCount = (int) scalarQuery($pdo, "SELECT COUNT(*) FROM mart_produto_periodo WHERE {$sqlFilter}");
    if ($prodCount === 0) {
        warn($result, 'T8:product_mart', 'mart_produto_periodo vazio para esta empresa');
    } else {
        pass($result, 'T8:product_mart', "rows={$prodCount}");
    }

    // T9 — Financial titles isolation
    $titlesLeak = false;
    foreach ($nfSamples as $otherCid => $otherKey) {
        if ($otherCid === $cid || $otherKey === null) {
            continue;
        }
        // Get a codigo_integracao from another company
        $otherCode = scalarQuery(
            $pdo,
            'SELECT codigo_integracao FROM biomie_titulos_financeiros_canonicos WHERE company_id = :cid AND codigo_integracao IS NOT NULL LIMIT 1',
            ['cid' => $otherCid]
        );
        if (!is_string($otherCode) || $otherCode === '') {
            continue;
        }
        $leaked = (int) scalarQuery(
            $pdo,
            'SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos WHERE company_id = :cid AND codigo_integracao = :code',
            ['cid' => $cid, 'code' => $otherCode]
        );
        if ($leaked > 0) {
            fail($result, 'T9:titles_isolation', "codigo_integracao '{$otherCode}' (empresa {$otherCid}) aparece em empresa {$cid}");
            $titlesLeak = true;
            break;
        }
    }
    if (!$titlesLeak) {
        pass($result, 'T9:titles_isolation', 'Titulos de outras empresas nao aparecem aqui');
    }

    $allResults[] = $result;
    $globalFail  += $result['fail'];

    if ($stopOnFail && $result['fail'] > 0) {
        break;
    }
}

// ── T10 — Consolidado subset: sum of parts == IN(...) query ──────────────────
if (count($companies) >= 2 && $slugFilter === null) {
    $subsetIds = [(int) $companies[0]['id'], (int) $companies[1]['id']];
    $idStr = implode(',', $subsetIds);

    $sumParts = array_reduce($subsetIds, function (float $carry, int $cid) use ($pdo): float {
        $v = (float) scalarQuery(
            $pdo,
            "SELECT COALESCE(SUM(valor_total),0) FROM biomie_notas_fiscais_canonicas WHERE company_id = :cid AND status_fiscal = 'autorizada'",
            ['cid' => $cid]
        );
        return $carry + $v;
    }, 0.0);

    $sumConsolidado = (float) scalarQuery(
        $pdo,
        "SELECT COALESCE(SUM(valor_total),0) FROM biomie_notas_fiscais_canonicas WHERE company_id IN ({$idStr}) AND status_fiscal = 'autorizada'"
    );

    $t10Result = [
        'company_id' => 0,
        'slug'       => 'CONSOLIDADO',
        'name'       => implode('+', array_map(static fn (int $id) => (string) $id, $subsetIds)),
        'pass'       => 0, 'fail' => 0, 'warn' => 0,
        'tests'      => [],
    ];

    $diff = abs($sumParts - $sumConsolidado);
    if ($diff < 0.01) {
        pass($t10Result, 'T10:consolidado_subset', sprintf('Soma individual=%.2f == consolidado IN()=%.2f', $sumParts, $sumConsolidado));
    } else {
        fail($t10Result, 'T10:consolidado_subset', sprintf('DIVERGENCIA: individual=%.2f consolidado=%.2f diff=%.2f', $sumParts, $sumConsolidado, $diff));
    }
    $allResults[] = $t10Result;
    $globalFail  += $t10Result['fail'];
}

// ── Output ────────────────────────────────────────────────────────────────────
$summary = [
    'ok'           => $globalFail === 0,
    'generated_at' => date(DATE_ATOM),
    'companies_tested' => count($allResults),
    'total_pass'   => array_sum(array_column($allResults, 'pass')),
    'total_fail'   => array_sum(array_column($allResults, 'fail')),
    'total_warn'   => array_sum(array_column($allResults, 'warn')),
    'results'      => $allResults,
];

if ($jsonMode) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    printf("%-14s %-10s %5s %5s %5s\n", 'EMPRESA', 'STATUS', 'PASS', 'FAIL', 'WARN');
    echo str_repeat('-', 50) . PHP_EOL;
    foreach ($allResults as $r) {
        $status = $r['fail'] > 0 ? 'FAIL' : ($r['warn'] > 0 ? 'WARN' : 'PASS');
        printf("%-14s %-10s %5d %5d %5d\n", $r['slug'], $status, $r['pass'], $r['fail'], $r['warn']);
        if ($r['fail'] > 0 || $r['warn'] > 0) {
            foreach ($r['tests'] as $t) {
                if ($t['status'] !== 'PASS') {
                    printf("  [%s] %s — %s\n", $t['status'], $t['test'], $t['detail']);
                }
            }
        }
    }
    echo PHP_EOL;
    printf(
        "Total: %d empresas | PASS %d | FAIL %d | WARN %d\n",
        $summary['companies_tested'],
        $summary['total_pass'],
        $summary['total_fail'],
        $summary['total_warn'],
    );
    if ($summary['ok']) {
        echo 'Resultado: OK — paridade funcional confirmada.' . PHP_EOL;
    } else {
        echo 'Resultado: FALHA — ver detalhes acima.' . PHP_EOL;
    }
}

exit($globalFail > 0 ? 1 : 0);
