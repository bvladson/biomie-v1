<?php

declare(strict_types=1);

use BIomie\Acl\AuditLogger;
use BIomie\Acl\DataMasker;
use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;

require __DIR__ . '/bootstrap.php';

// Fase 12: dossiê completo requer perfil analyst, manager ou admin
Auth::requireModule('dossier', true);

try {
    $clientId = (int) Http::query('cliente_id', '0');

    if ($clientId <= 0) {
        Http::json(['ok' => false, 'message' => 'ID do cliente invalido.'], 400);
    }

    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $connection);

    $sql = "
        SELECT d.*, g.*,
            (SELECT SUM(valor_total) FROM biomie_vendas_geo_cliente_fact WHERE codigo_cliente_omie = d.codigo_cliente_omie) as lifetime_value,
            (SELECT COUNT(DISTINCT nf_id) FROM biomie_vendas_geo_cliente_fact WHERE codigo_cliente_omie = d.codigo_cliente_omie AND nf_id IS NOT NULL) as total_nfs
        FROM biomie_clientes_dim d
        LEFT JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie = d.codigo_cliente_omie
        WHERE d.codigo_cliente_omie = :id
        LIMIT 1
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $clientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        // Enqueue to be enriched on background
        $pdo->prepare('INSERT IGNORE INTO biomie_clientes_para_enriquecer (codigo_cliente_omie, source_origin) VALUES (?, "client_profile_api")')->execute([$clientId]);

        Http::json([
            'ok' => false,
            'message' => 'Cliente ainda não indexado no perfilamento offline. Ele foi adicionado à fila de processamento geográfico.',
            'queue_status' => 'enqueued',
        ], 404);
    }

    // Fase 12: auditoria de acesso ao dossiê + mascaramento condicional
    $showFullDoc = Auth::canViewFullDocuments();
    AuditLogger::log($pdo, 'data_accessed', 'dossier', [
        'client_id'      => $clientId,
        'show_full_doc'  => $showFullDoc,
    ]);

    $docRaw = (string) ($row['cpf_cnpj'] ?? '');
    $docDisplay = $showFullDoc ? $docRaw : DataMasker::maskDocument($docRaw);

    Http::json([
        'ok' => true,
        'data' => [
            'id' => $row['codigo_cliente_omie'],
            'doc' => $docDisplay,
            'type' => $row['tipo_documento'],
            'name' => mb_strtoupper($row['nome_cliente']),
            'fantasy' => mb_strtoupper((string)$row['nome_fantasia']),
            'nature' => $row['natureza_cliente'],
            'cnae' => $row['cnae_codigo'],
            'segment' => mb_strtoupper($row['segmento_macro']),
            'geography' => [
                'city' => mb_strtoupper((string)$row['cidade_norm']),
                'neighborhood' => mb_strtoupper((string)$row['bairro_norm']),
                'uf' => mb_strtoupper((string)$row['uf']),
                'cep' => $row['cep'],
            ],
            'stats' => [
                'ltv' => (float) ($row['lifetime_value'] ?? 0),
                'nfs' => (int) ($row['total_nfs'] ?? 0),
            ]
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
