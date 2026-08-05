<?php

declare(strict_types=1);

/**
 * INSIGHTS — leituras narrativas da empresa corrente (GET).
 *
 * Alimenta o bloco "Leitura da operação" na home: as conclusões que os números
 * já permitem tirar, calculadas fora do request pelo serviço Python.
 *
 * Só devolve leitura VIGENTE e DENTRO DA VALIDADE. Se o job parar, o bloco
 * esvazia e some — apresentar conclusão de três dias atrás como se fosse de
 * hoje é pior que não mostrar nada.
 */

use BIomie\Auth;
use BIomie\Config;
use BIomie\Database;
use BIomie\Http;
use BIomie\Support\TenantContext;

require __DIR__ . '/bootstrap.php';

Auth::requireCapability('sales.view', true);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    Http::methodNotAllowed(['GET']);
}

/** @var Config $config */
/** @var \BIomie\Logger $logger */
$companyId = (int) TenantContext::companyId();
$itens = [];

try {
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $connection);

    $stmt = $pdo->prepare(
        'SELECT kind, severity, title, body, metric_json, link_url, computed_at
           FROM intelligence_insights
          WHERE company_id = :cid
            AND is_current = 1
            AND valid_until > UTC_TIMESTAMP()
          ORDER BY rank_weight DESC, id DESC
          LIMIT 10'
    );
    $stmt->execute(['cid' => $companyId]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $metricas = json_decode((string) ($r['metric_json'] ?? '{}'), true);
        $itens[] = [
            'kind' => (string) $r['kind'],
            'severity' => (string) $r['severity'],
            'title' => (string) $r['title'],
            'body' => (string) $r['body'],
            // Os números que sustentam a frase seguem para a tela: quem discorda
            // precisa poder conferir a conta, senão o painel vira opinião.
            'metrics' => is_array($metricas) ? $metricas : [],
            'link_url' => $r['link_url'] ?: null,
            'computed_at' => (string) $r['computed_at'],
        ];
    }
} catch (Throwable $e) {
    // Tabela ausente (migration pendente) ou falha de leitura não podem derrubar
    // a home: o bloco simplesmente não aparece.
    $logger->warning('insights_summary: leitura indisponivel.', ['message' => $e->getMessage()]);
    $itens = [];
}

Http::json([
    'ok' => true,
    'data' => [
        'company_id' => $companyId,
        'total' => count($itens),
        'insights' => $itens,
    ],
    'generated_at' => gmdate('c'),
]);
