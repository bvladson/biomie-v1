<?php

declare(strict_types=1);

use BIomie\Acl\AuditLogger;
use BIomie\Acl\DataMasker;
use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('dossier', true);

try {
    $clientId = (int) Http::query('cliente_id', '0');

    if ($clientId <= 0) {
        Http::json(['ok' => false, 'message' => 'ID do cliente invalido.'], 400);
    }

    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $connection);

    $companyId = \BIomie\Support\TenantContext::companyId();
    $tenantFilter = \BIomie\Support\TenantContext::sqlFilter('d');
    $stmt = $pdo->prepare("
        SELECT d.*, g.*,
            (SELECT SUM(f.valor_total)
               FROM biomie_vendas_geo_cliente_fact f
              WHERE f.codigo_cliente_omie = d.codigo_cliente_omie
                AND f.company_id = d.company_id) AS lifetime_value,
            (SELECT COUNT(DISTINCT f.nf_id)
               FROM biomie_vendas_geo_cliente_fact f
              WHERE f.codigo_cliente_omie = d.codigo_cliente_omie
                AND f.company_id = d.company_id
                AND f.nf_id IS NOT NULL) AS total_nfs
        FROM biomie_clientes_dim d
        LEFT JOIN biomie_clientes_geo_dim g
          ON g.codigo_cliente_omie = d.codigo_cliente_omie
         AND g.company_id = d.company_id
        WHERE d.codigo_cliente_omie = :id
          AND {$tenantFilter}
        LIMIT 1
    ");
    $stmt->execute(['id' => $clientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $showFullDoc = Auth::canViewFullDocuments();
    AuditLogger::log($pdo, 'data_accessed', 'dossier', [
        'client_id' => $clientId,
        'show_full_doc' => $showFullDoc,
    ]);

    if (!$row) {
        if ($companyId > 0) {
            $pdo->prepare(
                'INSERT IGNORE INTO biomie_clientes_para_enriquecer
                    (company_id, codigo_cliente_omie, source_origin)
                 VALUES (?, ?, "client_profile_api")'
            )->execute([$companyId, $clientId]);
        }

        $mart = fetchClientProfileMartFallback($pdo, $clientId);
        $nf = fetchClientProfileNfFallback($pdo, $clientId);
        $docRaw = (string) (($mart['documento'] ?? '') ?: ($nf['documento'] ?? ''));
        $docDigits = preg_replace('/\D+/', '', $docRaw) ?? '';
        $docDisplay = $showFullDoc ? $docRaw : DataMasker::maskDocument($docRaw);
        $revenue = (float) (($mart['faturamento'] ?? 0) ?: ($nf['faturamento'] ?? 0));
        $notes = (int) (($mart['notas'] ?? 0) ?: ($nf['notas'] ?? 0));

        Http::json([
            'ok' => true,
            'data' => [
                'id' => $clientId,
                'doc' => $docDisplay,
                'type' => strlen($docDigits) === 14 ? 'CNPJ' : 'N/D',
                'name' => mb_strtoupper((string) (($mart['nome'] ?? '') ?: ($nf['nome'] ?? 'Cliente pendente de enriquecimento'))),
                'fantasy' => '',
                'nature' => 'Pendente de enriquecimento Omie',
                'cnae' => $mart['cnae_codigo'] ?? null,
                'segment' => mb_strtoupper((string) (($mart['cnae_descricao'] ?? '') ?: 'PENDENTE_OMIE')),
                'geography' => [
                    'city' => mb_strtoupper((string) ($mart['cidade'] ?? '')),
                    'neighborhood' => mb_strtoupper((string) ($mart['bairro'] ?? '')),
                    'uf' => mb_strtoupper((string) ($mart['uf'] ?? '')),
                    'cep' => null,
                ],
                'stats' => [
                    'ltv' => $revenue,
                    'nfs' => $notes,
                ],
                'source' => $mart !== [] && (int) ($mart['notas'] ?? 0) > 0 ? 'mart' : 'nf_canonica',
                'pending_omie' => true,
            ],
            'meta' => [
                'request_id' => RequestContext::id(),
                'generated_at' => date(DATE_ATOM),
                'queue_status' => 'enqueued',
                'pending_omie' => true,
            ],
        ]);
    }

    $docRaw = (string) ($row['cpf_cnpj'] ?? '');
    $docDisplay = $showFullDoc ? $docRaw : DataMasker::maskDocument($docRaw);

    Http::json([
        'ok' => true,
        'data' => [
            'id' => $row['codigo_cliente_omie'],
            'doc' => $docDisplay,
            'type' => $row['tipo_documento'],
            'name' => mb_strtoupper($row['nome_cliente']),
            'fantasy' => mb_strtoupper((string) $row['nome_fantasia']),
            'nature' => $row['natureza_cliente'],
            'cnae' => $row['cnae_codigo'],
            'segment' => mb_strtoupper((string) $row['segmento_macro']),
            'geography' => [
                'city' => mb_strtoupper((string) $row['cidade_norm']),
                'neighborhood' => mb_strtoupper((string) $row['bairro_norm']),
                'uf' => mb_strtoupper((string) $row['uf']),
                'cep' => $row['cep'],
            ],
            'stats' => [
                'ltv' => (float) ($row['lifetime_value'] ?? 0),
                'nfs' => (int) ($row['total_nfs'] ?? 0),
            ],
            'source' => 'clientes_dim',
            'pending_omie' => false,
        ],
        'meta' => [
            'request_id' => RequestContext::id(),
            'generated_at' => date(DATE_ATOM),
        ],
    ]);
} catch (\Throwable $exception) {
    Http::json([
        'ok' => false,
        'message' => 'Erro interno ao carregar perfil do cliente.',
        'request_id' => RequestContext::id(),
    ], 500);
}

/** @return array<string,mixed> */
function fetchClientProfileMartFallback(PDO $pdo, int $clientId): array
{
    if (!Http::tableExists($pdo, 'mart_compradores_por_geo')) {
        return [];
    }

    $tenantFilter = \BIomie\Support\CommercialMartScope::condition(
        $pdo,
        'mart_compradores_por_geo'
    );
    $stmt = $pdo->prepare("
        SELECT MAX(cliente_nome) AS nome, MAX(documento) AS documento,
               MAX(cidade_norm) AS cidade, MAX(bairro_norm) AS bairro, MAX(uf) AS uf,
               MAX(cnae_codigo) AS cnae_codigo, MAX(cnae_descricao) AS cnae_descricao,
               COALESCE(SUM(faturamento), 0) AS faturamento,
               COALESCE(SUM(qtd_notas), 0) AS notas,
               MAX(ultima_compra) AS ultima_compra
        FROM mart_compradores_por_geo
        WHERE codigo_cliente_omie = :id
          AND {$tenantFilter}
    ");
    $stmt->execute(['id' => $clientId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/** @return array<string,mixed> */
function fetchClientProfileNfFallback(PDO $pdo, int $clientId): array
{
    if (!Http::tableExists($pdo, 'biomie_notas_fiscais_canonicas')) {
        return [];
    }

    $valorCol = Http::columnExists($pdo, 'biomie_notas_fiscais_canonicas', 'valor_total')
        ? 'valor_total'
        : (Http::columnExists($pdo, 'biomie_notas_fiscais_canonicas', 'valor_nfe') ? 'valor_nfe' : null);
    if ($valorCol === null) {
        return [];
    }

    $tenantFilter = \BIomie\Support\TenantContext::sqlFilter();
    $stmt = $pdo->prepare("
        SELECT MAX(nome_cliente) AS nome, MAX(documento_cliente) AS documento,
               COUNT(DISTINCT id) AS notas, COALESCE(SUM({$valorCol}), 0) AS faturamento,
               MAX(DATE(COALESCE(data_competencia, data_emissao))) AS ultima_compra
        FROM biomie_notas_fiscais_canonicas
        WHERE codigo_cliente_omie = :id
          AND {$tenantFilter}
          AND (status_fiscal IS NULL OR status_fiscal = 'autorizada')
    ");
    $stmt->execute(['id' => $clientId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}
