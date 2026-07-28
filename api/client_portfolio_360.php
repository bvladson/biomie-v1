<?php

declare(strict_types=1);

/**
 * api/client_portfolio_360.php — COM.R3
 *
 * Carteira 360 de clientes: summary, by_vendor, clients, conflicts, recurring, new, drawer.
 *
 * Modos:
 *   ?mode=summary    — KPIs agregados de toda a carteira
 *   ?mode=by_vendor  — Breakdown por vendedor (carteira + KPIs)
 *   ?mode=clients    — Lista paginada de clientes da carteira
 *   ?mode=conflicts  — NFs fora da carteira (vendedor ≠ dono)
 *   ?mode=recurring  — Clientes recorrentes (2+ meses distintos)
 *   ?mode=new        — Clientes novos/conquistados no período
 *   ?mode=drawer     — Detalhe para drawer de um cliente específico
 *
 * Parâmetros:
 *   start, end         — período (obrigatório)
 *   vendor             — filtrar por nome de vendedor
 *   client             — filtrar por nome de cliente (modo drawer usa codigo_cliente_omie)
 *   conflict_only      — retornar apenas conflitos
 *   recurring_only     — retornar apenas recorrentes
 *   new_only           — retornar apenas novos
 *   active_only        — retornar apenas ativos no período
 *   limit              — máx linhas (default 50, max 200)
 *   offset             — paginação
 *
 * Permission: sales.view
 */

require __DIR__ . '/bootstrap.php';
\BIomie\Auth::requireCapability('sales.view', true);

use BIomie\Commercial\ClientPortfolio360Resolver;
use BIomie\Config;
use BIomie\Database;
use BIomie\DataPlatform\Query\CustomerQuery;
use BIomie\DataPlatform\ReadModel\CustomerReadModel;
use BIomie\DataPlatform\ReadModel\CustomerReadModelFactory;
use BIomie\DataPlatform\Support\CustomerLegacyAdapter;
use BIomie\Http;
use BIomie\Support\SecretMask;

header('Content-Type: application/json; charset=utf-8');

// ── DB ────────────────────────────────────────────────────────────────────────

try {
    $config = new Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);
} catch (\Throwable $e) {
    Http::json([
        'ok' => false,
        'error' => SecretMask::sanitizeErrorMessage($e->getMessage()),
    ], 500);
}

// ── Params ────────────────────────────────────────────────────────────────────

$range      = Http::resolveDateRange();
[$startDate, $endDate] = [$range['start'], $range['end']];
$mode       = Http::query('mode', 'summary');
$vendorName = Http::query('vendor', '');
$clientId   = (int) Http::query('client', '0');
$limit      = min(200, max(1, (int) Http::query('limit', '50')));
$offset     = max(0, (int) Http::query('offset', '0'));

$validModes = ['summary', 'by_vendor', 'clients', 'conflicts', 'recurring', 'new', 'drawer'];
if (!in_array($mode, $validModes, true)) {
    $mode = 'summary';
}

// ── Resolver ──────────────────────────────────────────────────────────────────

$resolver = new ClientPortfolio360Resolver($pdo);
$customerModel = CustomerReadModelFactory::make($config);

$output = [
    'ok' => true,
    'mode' => $mode,
    'period' => ['start' => $startDate, 'end' => $endDate],
    'customer_source_contract' => 'customer.profile.v1',
];

try {
    switch ($mode) {

        // ── Summary ───────────────────────────────────────────────────────────
        case 'summary':
            $vendor = $vendorName !== '' ? $vendorName : null;
            $summary = $resolver->resolveSummary($startDate, $endDate, $vendor);

            // Top vendors by client count
            $byVendor = portfolioByVendorQuery($pdo, $startDate, $endDate, $limit);

            $output['summary']           = $summary;
            $output['top_vendors']       = $byVendor;
            $output['top_conflitos']     = $resolver->resolveConflicts($startDate, $endDate, null, 5);
            $output['top_recorrentes']   = CustomerLegacyAdapter::enrichRows($customerModel, $resolver->resolveRecurring($startDate, $endDate, null, 5));
            break;

        // ── By vendor ─────────────────────────────────────────────────────────
        case 'by_vendor':
            $output['vendors'] = portfolioByVendorQuery($pdo, $startDate, $endDate, $limit, $offset);
            $output['total']   = count($output['vendors']); // paginated count
            break;

        // ── Clients ───────────────────────────────────────────────────────────
        case 'clients':
            $vendor = $vendorName !== '' ? $vendorName : null;
            if ($vendor === null) {
                $output['error'] = 'Parâmetro vendor obrigatório para mode=clients';
                $output['ok'] = false;
                break;
            }
            $output['clients'] = CustomerLegacyAdapter::enrichRows(
                $customerModel,
                $resolver->resolvePortfolioByVendor($vendor, $startDate, $endDate, $limit, $offset)
            );
            $output['summary'] = $resolver->resolveSummary($startDate, $endDate, $vendor);
            break;

        // ── Conflicts ─────────────────────────────────────────────────────────
        case 'conflicts':
            $vendor = $vendorName !== '' ? $vendorName : null;
            $output['conflicts'] = $resolver->resolveConflicts($startDate, $endDate, $vendor, $limit);
            $output['note']      = 'Conflitos são informativos — não bloqueiam faturamento';
            break;

        // ── Recurring ─────────────────────────────────────────────────────────
        case 'recurring':
            $vendor = $vendorName !== '' ? $vendorName : null;
            $output['recurring'] = CustomerLegacyAdapter::enrichRows(
                $customerModel,
                $resolver->resolveRecurring($startDate, $endDate, $vendor, $limit)
            );
            break;

        // ── New customers ─────────────────────────────────────────────────────
        case 'new':
            $output['new_customers'] = CustomerLegacyAdapter::enrichRows(
                $customerModel,
                $resolver->resolveNewCustomers($startDate, $endDate, $limit)
            );
            break;

        // ── Drawer ────────────────────────────────────────────────────────────
        case 'drawer':
            if ($clientId <= 0) {
                $output['error'] = 'Parâmetro client (codigo_cliente_omie) obrigatório para mode=drawer';
                $output['ok'] = false;
                break;
            }
            $output['client'] = clientDrawerData($pdo, $customerModel, $clientId, $startDate, $endDate);
            break;
    }
} catch (\Throwable $e) {
    $output['ok']    = false;
    $output['error'] = SecretMask::sanitizeErrorMessage($e->getMessage());
}

Http::json($output, $output['ok'] === true ? 200 : 422);

// ── Functions ──────────────────────────────────────────────────────────────────

/**
 * Vendors ranked by portfolio clients in the period.
 *
 * @return list<array<string,mixed>>
 */
function portfolioByVendorQuery(\PDO $pdo, string $start, string $end, int $limit = 50, int $offset = 0): array
{
    try {
        $visCond = \BIomie\Support\BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf');
        $tenantFilter = \BIomie\Support\TenantContext::sqlFilter('nf');
        $stmt = $pdo->prepare(
            "SELECT
                nf.nome_vendedor,
                COUNT(DISTINCT nf.codigo_cliente_omie) AS clientes_ativos,
                COUNT(*) AS nfs,
                COALESCE(SUM(COALESCE(nf.valor_base_comercial_nf, nf.valor_mercadorias)), 0) AS receita,
                MAX(COALESCE(nf.data_competencia, nf.data_emissao)) AS ultima_venda
             FROM biomie_notas_fiscais_canonicas nf
             WHERE nf.status_fiscal = 'autorizada'
               AND nf.operation_scope = 'commercial'
               AND {$tenantFilter}
               AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :s AND :e
               AND {$visCond}
             GROUP BY nf.nome_vendedor
             ORDER BY clientes_ativos DESC, receita DESC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute([':s' => $start, ':e' => $end]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable) {
        return [];
    }
}

/**
 * Drawer data for a single client.
 *
 * @return array<string,mixed>
 */
function clientDrawerData(\PDO $pdo, CustomerReadModel $customerModel, int $clientId, string $start, string $end): array
{
    $visCond = \BIomie\Support\BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf');
    $tenantFilter = \BIomie\Support\TenantContext::sqlFilter('nf');
    $customerQuery = CustomerQuery::fromRequest([
        'date_from' => $start,
        'date_to' => $end,
        'customer' => (string) $clientId,
        'limit' => '1',
    ]);
    $customerPayload = $customerModel->profile($customerQuery, false)->toArray();
    $customer = (array) (($customerPayload['data']['customer'] ?? []) ?: []);

    // Client info
    $clientInfo = [];
    try {
        $stmt = $pdo->prepare(
            "SELECT nf.codigo_cliente_omie, MAX(nf.nome_cliente) AS nome_cliente,
                    MIN(COALESCE(nf.data_competencia, nf.data_emissao)) AS primeira_nf,
                    MAX(COALESCE(nf.data_competencia, nf.data_emissao)) AS ultima_nf,
                    COUNT(DISTINCT DATE_FORMAT(COALESCE(nf.data_competencia, nf.data_emissao), '%Y-%m')) AS meses_distintos,
                    COUNT(DISTINCT nf.nota_key) AS total_nfs,
                    COALESCE(SUM(COALESCE(nf.valor_base_comercial_nf, nf.valor_mercadorias)), 0) AS total_receita
             FROM biomie_notas_fiscais_canonicas nf
             WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
               AND {$tenantFilter}
               AND nf.codigo_cliente_omie = :cli AND {$visCond}"
        );
        $stmt->execute([':cli' => $clientId]);
        $clientInfo = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable) {}

    $document = (array) ($customer['document'] ?? []);
    $geo = (array) ($customer['geo'] ?? []);
    $cnae = (array) ($customer['cnae'] ?? []);
    $ownershipProfile = (array) ($customer['ownership'] ?? []);
    $quality = (array) ($customer['quality'] ?? []);
    $clientInfo = $clientInfo + [
        'codigo_cliente_omie' => $clientId,
        'nome_cliente' => (string) ($customer['nome'] ?? 'Cliente'),
    ];
    $clientInfo['nome_cliente'] = (string) (($customer['nome'] ?? '') ?: ($clientInfo['nome_cliente'] ?? 'Cliente'));
    $clientInfo['documento_cliente'] = (string) (($document['masked'] ?? '') ?: 'Nao informado');
    $clientInfo['documento_mascarado'] = $clientInfo['documento_cliente'];
    $clientInfo['document_type'] = (string) ($document['type'] ?? 'unknown');
    $clientInfo['cnae_status'] = (string) ($cnae['status'] ?? 'unknown');
    $clientInfo['cnae_codigo'] = $cnae['codigo'] ?? null;
    $clientInfo['cnae_descricao'] = $cnae['descricao'] ?? null;
    $clientInfo['cidade_key'] = (string) ($geo['cidade_key'] ?? '');
    $clientInfo['cidade_label'] = (string) ($geo['cidade_label'] ?? '');
    $clientInfo['bairro_key'] = (string) ($geo['bairro_key'] ?? '');
    $clientInfo['bairro_label'] = (string) ($geo['bairro_label'] ?? '');
    $clientInfo['geo_quality'] = (string) ($geo['quality'] ?? 'geo_desconhecido');
    $clientInfo['customer_quality_status'] = (string) ($quality['status'] ?? 'unknown');
    $clientInfo['customer_quality_missing'] = (array) ($quality['missing'] ?? []);

    $ownership = [
        'owner' => (string) ($ownershipProfile['seller_name'] ?? ''),
        'source' => (string) ($ownershipProfile['owner_source'] ?? 'missing'),
        'confidence' => (int) ($ownershipProfile['confidence'] ?? 0),
    ];

    // NFs in period
    $nfs = [];
    try {
        $stmt = $pdo->prepare(
            "SELECT nf.nota_key, nf.numero_nf,
                    COALESCE(nf.data_competencia, nf.data_emissao) AS data_ref,
                    nf.nome_vendedor, nf.pedido_id_omie,
                    COALESCE(nf.valor_base_comercial_nf, nf.valor_mercadorias) AS base_comercial
             FROM biomie_notas_fiscais_canonicas nf
             WHERE nf.status_fiscal = 'autorizada' AND nf.operation_scope = 'commercial'
               AND {$tenantFilter}
               AND nf.codigo_cliente_omie = :cli
               AND COALESCE(nf.data_competencia, nf.data_emissao) BETWEEN :s AND :e
               AND {$visCond}
             ORDER BY data_ref DESC
             LIMIT 50"
        );
        $stmt->execute([':cli' => $clientId, ':s' => $start, ':e' => $end]);
        $nfs = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable) {}

    return [
        'client_info' => $clientInfo,
        'ownership'   => $ownership,
        'nfs_periodo' => $nfs,
        'customer_profile' => $customer,
        'customer_source_contract' => 'customer.profile.v1',
    ];
}
