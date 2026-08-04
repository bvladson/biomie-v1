<?php

declare(strict_types=1);

use BIomie\Support\CommercialNfUniverse;

/**
 * tools/dev/finance_nf_titulo_reconciliation.php
 *
 * RECONCILIAÇÃO NF ↔ TÍTULO — responde "o Financeiro bate com o faturado?"
 * de forma auditável. SOMENTE LEITURA: não escreve, não materializa, não
 * reconstrói bridge.
 *
 * ─── POR QUE COMPARAR TOTAIS DE PERÍODO NUNCA FECHA ─────────────────────────
 * O módulo Financeiro agrega títulos por `data_competencia`; o faturamento
 * conta NFs por `data_emissao`. Uma NF de julho parcelada em 3x gera títulos com
 * competência em julho, agosto e setembro. Somar os dois lados no mesmo mês
 * compara populações diferentes — e a diferença que sobra não significa erro
 * nenhum. Por isso a reconciliação aqui é feita pelo ELO, NF a NF, e não por
 * soma de período.
 *
 * ─── A CHAVE DO ELO (a armadilha) ───────────────────────────────────────────
 * O `nota_key` do TÍTULO guarda a chave da NFe de 44 caracteres. O `nota_key` da
 * NF é `omie:<id>`. Casar `nota_key = nota_key` devolve ZERO — o elo correto é
 *
 *     titulo.nota_key (44 chars)  =  nf.chave_nfe
 *
 * Com a chave certa a cobertura é ~99% e abril–junho/2026 fecham com 0–0,9% de
 * diferença: a reconciliação FUNCIONA. Onde ela não fecha, a causa é NF sem
 * título nenhum no banco — que é informação de negócio, não erro de query.
 *
 * ─── VALOR BRUTO DO TÍTULO ──────────────────────────────────────────────────
 * Não existe coluna de valor bruto, mas ele é reconstruível: `valor_liquido`
 * carrega a parte já paga e `valor_aberto` o que falta, então
 * `valor_liquido + valor_aberto` é o valor do título (liquidado → aberto=0;
 * aberto → liquido=0; cancelado → ambos 0, e cancelado é excluído).
 *
 * Uso:
 *   php tools/dev/finance_nf_titulo_reconciliation.php --from=2026-04-01 --to=2026-08-31
 *   php tools/dev/finance_nf_titulo_reconciliation.php --company=1 --limit=10
 *   php tools/dev/finance_nf_titulo_reconciliation.php --json
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$opts = getopt('', ['from:', 'to:', 'company:', 'limit:', 'json']);
$from = (string) ($opts['from'] ?? date('Y-m-01', strtotime('-4 months')));
$to   = (string) ($opts['to'] ?? date('Y-m-t'));
$limit = max(0, (int) ($opts['limit'] ?? 5));
$asJson = isset($opts['json']);

$config = new \BIomie\Config();
$pdo = \BIomie\Database::connect(
    $config,
    \BIomie\Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db'
);

$companies = $pdo->query('SELECT id, slug FROM biomie_companies ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
if (isset($opts['company'])) {
    $wanted = (string) $opts['company'];
    $companies = array_values(array_filter(
        $companies,
        static fn (array $c): bool => (string) $c['id'] === $wanted || strcasecmp((string) $c['slug'], $wanted) === 0
    ));
}

$universe = CommercialNfUniverse::sql('n');

/**
 * Títulos agregados por NF. O elo é `t.nota_key = n.chave_nfe`; `CHAR_LENGTH=44`
 * garante que só entram os títulos cujo nota_key É a chave da NFe.
 */
$titlesSubquery = <<<'SQL'
    SELECT t.company_id,
           t.nota_key AS elo,
           COUNT(*)                              AS qtd,
           SUM(t.valor_liquido + t.valor_aberto) AS bruto,
           SUM(t.valor_liquido)                  AS recebido,
           SUM(t.valor_aberto)                   AS aberto
      FROM biomie_titulos_financeiros_canonicos t
     WHERE t.company_id = :company_id
       AND t.status_titulo <> 'cancelado'
       AND CHAR_LENGTH(t.nota_key) = 44
     GROUP BY t.company_id, t.nota_key
SQL;

$report = [];

foreach ($companies as $company) {
    $companyId = (int) $company['id'];

    $stmt = $pdo->prepare(<<<SQL
        SELECT DATE_FORMAT(n.data_emissao, '%Y-%m')            AS mes,
               COUNT(*)                                        AS nfs,
               SUM(CASE WHEN b.elo IS NULL THEN 1 ELSE 0 END)  AS nfs_sem_titulo,
               ROUND(SUM(n.valor_total), 2)                    AS faturado,
               ROUND(SUM(CASE WHEN b.elo IS NULL THEN n.valor_total ELSE 0 END), 2) AS faturado_sem_titulo,
               ROUND(SUM(COALESCE(b.bruto, 0)), 2)             AS titulos,
               ROUND(SUM(COALESCE(b.recebido, 0)), 2)          AS recebido,
               ROUND(SUM(COALESCE(b.aberto, 0)), 2)            AS aberto
          FROM biomie_notas_fiscais_canonicas n
          LEFT JOIN ({$titlesSubquery}) b
                 ON b.company_id = n.company_id AND b.elo = n.chave_nfe
         WHERE n.company_id = :company_filter
           AND n.data_emissao BETWEEN :from AND :to
           AND {$universe}
         GROUP BY mes
         ORDER BY mes
    SQL);
    $stmt->execute([
        'company_id' => $companyId,
        'company_filter' => $companyId,
        'from' => $from,
        'to' => $to,
    ]);
    $months = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($months === []) {
        continue;
    }

    foreach ($months as &$month) {
        $faturado = (float) $month['faturado'];
        $month['gap'] = round($faturado - (float) $month['titulos'], 2);
        $month['gap_pct'] = $faturado > 0 ? round(100 * $month['gap'] / $faturado, 1) : 0.0;
    }
    unset($month);

    // As maiores NFs sem título: é aqui que a diferença costuma se concentrar,
    // e é o que dá para conferir na Omie uma a uma.
    $offenders = [];
    if ($limit > 0) {
        $stmt = $pdo->prepare(<<<SQL
            SELECT n.chave_nfe, n.data_emissao, n.valor_total, n.nome_cliente,
                   COALESCE(n.business_channel, '') AS business_channel
              FROM biomie_notas_fiscais_canonicas n
             WHERE n.company_id = :company_filter
               AND n.data_emissao BETWEEN :from AND :to
               AND {$universe}
               AND NOT EXISTS (
                     SELECT 1
                       FROM biomie_titulos_financeiros_canonicos t
                      WHERE t.company_id = n.company_id
                        AND t.status_titulo <> 'cancelado'
                        AND t.nota_key = n.chave_nfe)
             ORDER BY n.valor_total DESC
             LIMIT {$limit}
        SQL);
        $stmt->execute(['company_filter' => $companyId, 'from' => $from, 'to' => $to]);
        $offenders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $report[] = [
        'company_id' => $companyId,
        'company_slug' => (string) $company['slug'],
        'months' => $months,
        'top_nfs_sem_titulo' => $offenders,
    ];
}

if ($asJson) {
    echo json_encode(
        ['ok' => true, 'from' => $from, 'to' => $to, 'companies' => $report],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    ), PHP_EOL;
    exit(0);
}

$money = static fn (float|string $v): string => number_format((float) $v, 2, ',', '.');

echo "RECONCILIAÇÃO NF ↔ TÍTULO — {$from} a {$to}\n";
echo "elo: titulo.nota_key(44) = nf.chave_nfe · universo comercial · cancelados fora\n";

foreach ($report as $companyReport) {
    echo "\n", str_repeat('─', 108), "\n";
    echo "{$companyReport['company_slug']} (empresa {$companyReport['company_id']})\n";
    printf("  %-9s %6s %14s %14s %14s %14s %14s %8s\n",
        'mês', 'NFs', 'faturado', 'títulos', 'recebido', 'aberto', 'gap', 'gap%');

    foreach ($companyReport['months'] as $m) {
        printf("  %-9s %6s %14s %14s %14s %14s %14s %7s%%\n",
            $m['mes'], $m['nfs'], $money($m['faturado']), $money($m['titulos']),
            $money($m['recebido']), $money($m['aberto']), $money($m['gap']), $m['gap_pct']);
    }

    if ($companyReport['top_nfs_sem_titulo'] !== []) {
        echo "\n  Maiores NFs SEM nenhum título (conferir na Omie):\n";
        foreach ($companyReport['top_nfs_sem_titulo'] as $nf) {
            printf("    %s  %s  R$ %14s  %s\n",
                substr((string) $nf['chave_nfe'], -8),
                (string) $nf['data_emissao'],
                $money((float) $nf['valor_total']),
                mb_substr(html_entity_decode((string) $nf['nome_cliente']), 0, 40));
        }
    }
}

echo "\n";
echo "Leitura: gap ≈ 0% significa reconciliado. Gap alto = NF faturada sem título\n";
echo "no banco — ou a Omie ainda não gerou o financeiro, ou o sync não trouxe.\n";
