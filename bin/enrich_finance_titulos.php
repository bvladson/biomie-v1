<?php

/**
 * Enriquecimento de títulos financeiros via dados de NF.
 *
 * Preenche nome_cliente, codigo_cliente_omie, nome_vendedor e
 * codigo_vendedor_omie nos títulos que vieram com "Cliente nao informado"
 * ou "Sem vendedor", usando a NF vinculada via nota_key/chave_nfe.
 *
 * Executado pelo cron após cada sync financeiro.
 */

declare(strict_types=1);
require __DIR__ . '/../app/Bootstrap.php';
\BIomie\Bootstrap::init();
use BIomie\Config;
use BIomie\Database;
use BIomie\Support\TenantCliContext;

$config = new Config();
$conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo    = Database::connect($config, $conn);
$tenant = TenantCliContext::boot($pdo, $argv ?? []);
$companyId = (int) $tenant['company_id'];

$t0 = microtime(true);

// ── 1. Enriquecer via chave_nfe (join direto: nota_key = chave_nfe da NF) ──
$stmt = $pdo->prepare("
    UPDATE biomie_titulos_financeiros_canonicos t
    JOIN biomie_notas_fiscais_canonicas n
      ON n.company_id = t.company_id AND n.chave_nfe = t.nota_key
    SET
        t.nome_cliente        = CASE
            WHEN t.nome_cliente IN ('Cliente nao informado', '') THEN n.nome_cliente
            ELSE t.nome_cliente
        END,
        t.codigo_cliente_omie = CASE
            WHEN t.codigo_cliente_omie IS NULL THEN n.codigo_cliente_omie
            ELSE t.codigo_cliente_omie
        END,
        t.nome_vendedor       = CASE
            WHEN t.nome_vendedor IN ('Sem vendedor', '') THEN n.nome_vendedor
            ELSE t.nome_vendedor
        END,
        t.codigo_vendedor_omie = CASE
            WHEN t.codigo_vendedor_omie IS NULL OR t.codigo_vendedor_omie = '' THEN n.codigo_vendedor_omie
            ELSE t.codigo_vendedor_omie
        END
    WHERE t.company_id = :company_id
      AND t.nota_key IS NOT NULL AND t.nota_key != ''
");
$stmt->execute(['company_id' => $companyId]);
$enrichedByKey = $stmt->rowCount();

// ── 2. Enriquecer via pedido_id_omie (fallback quando nota_key não existe) ──
$stmt2 = $pdo->prepare("
    UPDATE biomie_titulos_financeiros_canonicos t
    JOIN biomie_pedidos_canonicos p
      ON p.company_id = t.company_id AND p.pedido_id_omie = t.pedido_id_omie
    SET
        t.nome_cliente        = CASE
            WHEN t.nome_cliente IN ('Cliente nao informado', '') THEN p.nome_cliente
            ELSE t.nome_cliente
        END,
        t.codigo_cliente_omie = CASE
            WHEN t.codigo_cliente_omie IS NULL THEN p.codigo_cliente_omie
            ELSE t.codigo_cliente_omie
        END,
        t.nome_vendedor       = CASE
            WHEN t.nome_vendedor IN ('Sem vendedor', '') THEN p.nome_vendedor
            ELSE t.nome_vendedor
        END,
        t.codigo_vendedor_omie = CASE
            WHEN t.codigo_vendedor_omie IS NULL OR t.codigo_vendedor_omie = '' THEN p.codigo_vendedor_omie
            ELSE t.codigo_vendedor_omie
        END
    WHERE t.company_id = :company_id
      AND t.pedido_id_omie IS NOT NULL AND t.pedido_id_omie > 0
      AND (t.nome_cliente IN ('Cliente nao informado', '') OR t.nome_vendedor IN ('Sem vendedor', ''))
");
$stmt2->execute(['company_id' => $companyId]);
$enrichedByOrder = $stmt2->rowCount();

$duration = round(microtime(true) - $t0, 2);

echo json_encode([
    'ok'                  => true,
    'company_id'          => $companyId,
    'enriched_by_nf_key'  => $enrichedByKey,
    'enriched_by_order'   => $enrichedByOrder,
    'duration_seconds'    => $duration,
    'timestamp'           => date('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
