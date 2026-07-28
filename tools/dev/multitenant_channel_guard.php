<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$pretty = in_array('--pretty', $argv ?? [], true);
$config = new Config();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';

try {
    $pdo = Database::connect($config, $connection);
    $companies = $pdo->query(<<<'SQL'
        SELECT c.id, c.slug, c.canal_padrao,
               COUNT(DISTINCT i.id) AS active_integrations,
               (SELECT COUNT(*) FROM biomie_pedidos_canonicos p WHERE p.company_id = c.id) AS orders,
               (SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas n WHERE n.company_id = c.id) AS invoices,
               (SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos t WHERE t.company_id = c.id) AS titles,
               (SELECT COUNT(*) FROM biomie_pedidos_canonicos p
                 WHERE p.company_id = c.id AND p.business_channel NOT IN ('varejo','licitacao','outros')) AS invalid_order_channels,
               (SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas n
                 WHERE n.company_id = c.id AND n.business_channel NOT IN ('varejo','licitacao','outros')) AS invalid_invoice_channels,
               (SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos t
                 WHERE t.company_id = c.id AND t.business_channel NOT IN ('varejo','licitacao','outros')) AS invalid_title_channels
          FROM biomie_companies c
          LEFT JOIN biomie_company_integrations i
            ON i.company_id = c.id AND i.provider = 'omie' AND i.active = 1
         WHERE c.ativo = 1
         GROUP BY c.id, c.slug, c.canal_padrao
         ORDER BY c.id
    SQL)->fetchAll(PDO::FETCH_ASSOC);

    $issues = [];
    if (count($companies) !== 5) {
        $issues[] = 'Esperadas 5 empresas ativas; encontrado ' . count($companies) . '.';
    }
    foreach ($companies as $company) {
        $slug = (string) $company['slug'];
        if ((int) $company['active_integrations'] !== 1) {
            $issues[] = "{$slug}: integracao Omie ativa ausente ou duplicada.";
        }
        foreach (['invalid_order_channels', 'invalid_invoice_channels', 'invalid_title_channels'] as $field) {
            if ((int) $company[$field] > 0) {
                $issues[] = "{$slug}: {$company[$field]} registros com business_channel invalido em {$field}.";
            }
        }
    }

    $payload = [
        'ok' => $issues === [],
        'status' => $issues === [] ? 'PASS' : 'FAIL',
        'companies' => $companies,
        'issues' => $issues,
    ];

    if ($pretty) {
        echo "Multiempresa — isolamento de canal\n";
        foreach ($companies as $company) {
            echo sprintf(
                "  %s | canal=%s | pedidos=%d | nfs=%d | titulos=%d | integracao=%d\n",
                $company['slug'],
                $company['canal_padrao'],
                $company['orders'],
                $company['invoices'],
                $company['titles'],
                $company['active_integrations'],
            );
        }
        echo $issues === [] ? "  RESULT: PASS\n" : '  FAIL: ' . implode(' | ', $issues) . PHP_EOL;
    } else {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
    exit($issues === [] ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
