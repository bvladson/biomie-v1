<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Support\SecretMask;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';

try {
    $pdo = Database::connect($config, $connection);
    $sql = <<<'SQL'
        SELECT
            c.id AS company_id,
            c.slug,
            c.cnpj,
            c.ativo,
            i.active AS integration_active,
            i.last_used_at,
            i.last_success_at,
            i.last_error_at,
            (SELECT COUNT(*) FROM biomie_vendedores_omie v WHERE v.company_id = c.id) AS vendors,
            (SELECT MAX(v.sincronizado_em) FROM biomie_vendedores_omie v WHERE v.company_id = c.id) AS vendors_last_sync,
            (SELECT COUNT(*) FROM biomie_pedidos_canonicos p WHERE p.company_id = c.id) AS orders,
            (SELECT MIN(p.data_emissao) FROM biomie_pedidos_canonicos p WHERE p.company_id = c.id) AS orders_first_date,
            (SELECT MAX(p.data_emissao) FROM biomie_pedidos_canonicos p WHERE p.company_id = c.id) AS orders_last_date,
            (SELECT MAX(p.sincronizado_em) FROM biomie_pedidos_canonicos p WHERE p.company_id = c.id) AS orders_last_sync,
            (SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas n WHERE n.company_id = c.id) AS invoices,
            (SELECT MIN(n.data_emissao) FROM biomie_notas_fiscais_canonicas n WHERE n.company_id = c.id) AS invoices_first_date,
            (SELECT MAX(n.data_emissao) FROM biomie_notas_fiscais_canonicas n WHERE n.company_id = c.id) AS invoices_last_date,
            (SELECT MAX(n.sincronizado_em) FROM biomie_notas_fiscais_canonicas n WHERE n.company_id = c.id) AS invoices_last_sync,
            (SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos t WHERE t.company_id = c.id) AS financial_titles,
            (SELECT MIN(t.data_competencia) FROM biomie_titulos_financeiros_canonicos t WHERE t.company_id = c.id) AS titles_first_date,
            (SELECT MAX(t.data_competencia) FROM biomie_titulos_financeiros_canonicos t WHERE t.company_id = c.id) AS titles_last_date,
            (SELECT MAX(t.sincronizado_em) FROM biomie_titulos_financeiros_canonicos t WHERE t.company_id = c.id) AS titles_last_sync,
            (SELECT COUNT(*) FROM biomie_eventos_canonicos e WHERE e.company_id = c.id) AS events,
            (SELECT MAX(e.ingested_at) FROM biomie_eventos_canonicos e WHERE e.company_id = c.id) AS events_last_sync,
            (SELECT COUNT(*) FROM biomie_omie_bronze b WHERE b.company_id = c.id) AS bronze_records,
            (SELECT MAX(b.ingested_at) FROM biomie_omie_bronze b WHERE b.company_id = c.id) AS bronze_last_sync
        FROM biomie_companies c
        LEFT JOIN biomie_company_integrations i
          ON i.company_id = c.id
         AND i.provider = 'omie'
        WHERE c.ativo = 1
        ORDER BY c.id
    SQL;

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $integrationSummary = $pdo->query(<<<'SQL'
        SELECT
            COUNT(*) AS total,
            COUNT(DISTINCT app_key) AS distinct_app_keys,
            SUM(CASE WHEN app_secret_encrypted IS NOT NULL AND app_secret_encrypted <> '' THEN 1 ELSE 0 END) AS encrypted_secrets,
            SUM(CASE WHEN webhook_token_hash IS NOT NULL AND webhook_token_hash <> '' THEN 1 ELSE 0 END) AS webhook_tokens,
            SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) AS active_integrations
        FROM biomie_company_integrations
        WHERE provider = 'omie'
    SQL)->fetch(PDO::FETCH_ASSOC);

    fwrite(STDOUT, json_encode([
        'ok' => true,
        'connection' => $connection,
        'generated_at' => date(DATE_ATOM),
        'integration_summary' => $integrationSummary,
        'companies' => $rows,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, SecretMask::sanitizeErrorMessage($exception->getMessage(), 500) . PHP_EOL);
    exit(1);
}
