<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(300);

/**
 * Rematerializa mart_vendas_por_cnae_cnpj com alinhamento de início de mês.
 *
 * Uso:
 *   php tools/dev/rematerialize_cnae_mart.php
 *   php tools/dev/rematerialize_cnae_mart.php --start=2026-02-01 --end=2026-05-31
 *
 * O builder já normaliza $start para o primeiro dia do mês internamente.
 * O parâmetro --start aqui define qual competência cobrir, não o dia exato.
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\DataPlatform\Gold\Builders\CnaeSalesMartBuilder;
use BIomie\Logger;
use BIomie\Support\FiscalValueBasis;

function cnaeMartColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

$opts  = getopt('', ['start:', 'end:']);
$start = isset($opts['start']) ? trim((string) $opts['start']) : '2026-02-01';
$end   = isset($opts['end'])   ? trim((string) $opts['end'])   : date('Y-m-d');

$config     = Config::fromEnvironment();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo        = Database::connect($config, $connection);
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

$logger = new Logger('/tmp/rematerialize_cnae_biomie.log');

echo "\n=== Rematerialização mart_vendas_por_cnae_cnpj ===\n";
echo "Start solicitado : $start\n";
echo "Mês efectivo     : " . date('Y-m-01', strtotime($start)) . " (builder ajusta automaticamente)\n";
echo "End              : $end\n\n";

$before = (int) $pdo->query('SELECT COUNT(*) FROM mart_vendas_por_cnae_cnpj')->fetchColumn();
$beforeRevenue = (float) $pdo->query('SELECT COALESCE(SUM(faturamento),0) FROM mart_vendas_por_cnae_cnpj')->fetchColumn();
printf("Antes: %d linhas — R$ %s faturamento\n\n", $before, number_format($beforeRevenue, 2, ',', '.'));

$builder = new CnaeSalesMartBuilder($pdo, $logger);
$inserted = $builder->refresh($start, $end);

$after = (int) $pdo->query('SELECT COUNT(*) FROM mart_vendas_por_cnae_cnpj')->fetchColumn();
$afterRevenue = (float) $pdo->query('SELECT COALESCE(SUM(faturamento),0) FROM mart_vendas_por_cnae_cnpj')->fetchColumn();
printf("Depois: %d linhas — R$ %s faturamento\n", $after, number_format($afterRevenue, 2, ',', '.'));
printf("Rows afetados pelo refresh: %d\n\n", $inserted);

// Compare against the same canonical commercial basis used by CnaeSalesMartBuilder.
$nfBasisExpr = cnaeMartColumnExists($pdo, 'biomie_notas_fiscais_canonicas', 'valor_base_comercial_nf')
    ? FiscalValueBasis::preComputedBaseSql('nf')
    : (cnaeMartColumnExists($pdo, 'biomie_notas_fiscais_canonicas', 'valor_frete')
        ? FiscalValueBasis::commercialBaseSql('nf')
        : 'nf.valor_total');
$scopeFilter = cnaeMartColumnExists($pdo, 'biomie_notas_fiscais_canonicas', 'operation_scope')
    ? " AND nf.operation_scope = 'commercial'"
    : '';
$visibilityFilter = cnaeMartColumnExists($pdo, 'biomie_notas_fiscais_canonicas', 'is_biomie_commercial_visible')
    ? ' AND COALESCE(nf.is_biomie_commercial_visible, 1) = 1'
    : (cnaeMartColumnExists($pdo, 'biomie_notas_fiscais_canonicas', 'nome_vendedor')
        ? " AND NOT UPPER(nf.nome_vendedor) LIKE '%LICITAC%'"
        : '');

$canonStmt = $pdo->prepare(
    "SELECT COALESCE(SUM({$nfBasisExpr}),0)
     FROM biomie_notas_fiscais_canonicas nf
     WHERE nf.status_fiscal = 'autorizada'
       AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :s AND :e{$scopeFilter}{$visibilityFilter}"
);
$canonStmt->execute(['s' => $start, 'e' => $end]);
$canonRevenue = (float) $canonStmt->fetchColumn();

$delta = round($afterRevenue - $canonRevenue, 2);
printf("Canonical commercial  : R$ %s\n", number_format($canonRevenue, 2, ',', '.'));
printf("Mart após rebuild     : R$ %s\n", number_format($afterRevenue, 2, ',', '.'));
printf("Delta                 : R$ %s %s\n\n",
    number_format(abs($delta), 2, ',', '.'),
    $delta >= 0 ? '(overshoot — possível duplicata)' : '(undershoot — cobertura incompleta)'
);

if (abs($delta) <= 0.01) {
    echo "STATUS: OK — mart bate com canonical commercial.\n\n";
    exit(0);
} elseif ($delta > 0.01) {
    echo "STATUS: CRITICO — mart tem mais que canonical (contaminação provável).\n\n";
    exit(1);
} else {
    printf("STATUS: AVISO — deficit de R$ %s. Execute com --start=%s para cobrir todo o período.\n\n",
        number_format(abs($delta), 2, ',', '.'),
        date('Y-m-01', strtotime($start))
    );
    exit(1);
}
