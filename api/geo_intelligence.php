<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Config;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\GlobalHomeDataGuard;
use BIomie\Support\TenantContext;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('geo', true);
GlobalHomeDataGuard::requireUnrestricted();

try {
    $layer = trim((string) ($_GET['layer'] ?? 'fortaleza_bairros'));
    $allowed = ['fortaleza_bairros', 'grande_fortaleza_bairros', 'ceara_municipios',
        'ceara_regioes', 'rmf_municipios', 'metro_sobral_municipios',
        'metro_cariri_municipios', 'metro_todas_municipios'];
    if (!in_array($layer, $allowed, true)) {
        Http::json(['ok' => true, 'data' => [], 'status' => 'layer_not_scored']);
    }

    $pdo = Database::connect(new Config(), 'db');
    if (!Http::tableExists($pdo, 'intelligence_geo_scores')) {
        Http::json(['ok' => true, 'data' => [], 'status' => 'migration_pending']);
    }

    $storageLayer = in_array($layer, ['rmf_municipios', 'metro_sobral_municipios',
        'metro_cariri_municipios', 'metro_todas_municipios'], true) ? 'ceara_municipios' : $layer;
    $stmt = $pdo->prepare(<<<'SQL'
        SELECT competencia, layer_id, territory_key, territory_label,
               opportunity_score, confidence, months_used,
               current_revenue, previous_revenue, growth_pct,
               customers, invoices, average_ticket, potential_revenue,
               forecast_revenue, forecast_low, forecast_high,
               main_reason, recommended_action, model_version, computed_at
          FROM intelligence_geo_scores
         WHERE company_id = :company_id AND layer_id = :layer AND is_current = 1
         ORDER BY opportunity_score DESC, current_revenue DESC
         LIMIT 500
    SQL);
    $stmt->execute(['company_id' => TenantContext::companyId(), 'layer' => $storageLayer]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        foreach (['opportunity_score','current_revenue','previous_revenue','growth_pct','average_ticket',
                  'potential_revenue','forecast_revenue','forecast_low','forecast_high'] as $field) {
            $row[$field] = $row[$field] !== null ? (float) $row[$field] : null;
        }
        foreach (['months_used','customers','invoices'] as $field) {
            $row[$field] = (int) ($row[$field] ?? 0);
        }
    }
    unset($row);

    $confidence = ['alta' => 0, 'media' => 0, 'baixa' => 0];
    foreach ($rows as $row) {
        $key = (string) ($row['confidence'] ?? 'baixa');
        $confidence[$key] = ($confidence[$key] ?? 0) + 1;
    }

    Http::json([
        'ok' => true,
        'status' => $rows === [] ? 'awaiting_first_run' : 'ready',
        'data' => $rows,
        'summary' => [
            'territories' => count($rows),
            'confidence' => $confidence,
            'potential_revenue' => array_sum(array_column($rows, 'potential_revenue')),
            'top' => $rows[0] ?? null,
        ],
        'meta' => ['request_id' => RequestContext::id(), 'source' => 'python_persisted'],
    ]);
} catch (Throwable $exception) {
    global $logger;
    $logger?->error('geo_intelligence api error', ['message' => $exception->getMessage()]);
    Http::json(['ok' => false, 'error' => 'geo_intelligence_error',
        'message' => 'Nao foi possivel carregar a inteligencia territorial agora.',
        'request_id' => RequestContext::id()], 500);
}
