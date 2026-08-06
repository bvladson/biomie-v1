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

    /**
     * VEREDITO DO BACKTEST — o score desta camada realmente ordena?
     *
     * Ate aqui a tela dizia "Prioridade territorial: X" para as cinco empresas,
     * sem distincao. O backtest mostrou que na EXPERT o score e
     * ANTI-preditivo (spearman -0,18): priorizar pelo topo la e pior que
     * sortear. Apresentar os dois casos com a mesma palavra e o mesmo destaque
     * transforma medicao em decoracao.
     *
     * A confianca (`confidence`) responde outra pergunta: "esta serie e
     * estavel?". O veredito responde "esta ORDENACAO ja provou valer?". Um
     * territorio pode ter serie estavel num modelo que nao ordena nada.
     */
    $validation = ['verdict' => 'nao_avaliado', 'checked' => false];
    if (Http::tableExists($pdo, 'intelligence_geo_backtests')) {
        $stmt = $pdo->prepare(<<<'SQL'
            SELECT veredito, motivo,
                   COUNT(*)                       AS janelas,
                   AVG(lift_top_n)                AS lift_medio,
                   AVG(spearman)                  AS spearman_medio,
                   AVG(precisao_top_n)            AS precisao_topo,
                   AVG(precisao_base)             AS precisao_base,
                   AVG(cobertura_faixa_pct)       AS cobertura_faixa,
                   MAX(computed_at)               AS avaliado_em
              FROM intelligence_geo_backtests
             WHERE company_id = :company_id AND layer_id = :layer AND is_current = 1
             GROUP BY veredito, motivo
             ORDER BY janelas DESC
             LIMIT 1
        SQL);
        $stmt->execute(['company_id' => TenantContext::companyId(), 'layer' => $storageLayer]);
        $backtest = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($backtest !== null) {
            $validation = [
                'verdict' => (string) $backtest['veredito'],
                'checked' => true,
                'reason' => (string) $backtest['motivo'],
                'windows' => (int) $backtest['janelas'],
                'lift' => $backtest['lift_medio'] !== null ? round((float) $backtest['lift_medio'], 2) : null,
                'spearman' => $backtest['spearman_medio'] !== null ? round((float) $backtest['spearman_medio'], 2) : null,
                // A precisao do topo so significa algo AO LADO da taxa base.
                'top_hit_rate' => $backtest['precisao_topo'] !== null ? round((float) $backtest['precisao_topo'], 3) : null,
                'base_hit_rate' => $backtest['precisao_base'] !== null ? round((float) $backtest['precisao_base'], 3) : null,
                'band_coverage_pct' => $backtest['cobertura_faixa'] !== null ? round((float) $backtest['cobertura_faixa'], 1) : null,
                'checked_at' => $backtest['avaliado_em'],
            ];
        }
    }
    $ordersWell = ($validation['verdict'] ?? '') === 'ordena';

    // "Acionavel" exige as DUAS coisas: serie confiavel E ordenacao provada.
    // Sem o veredito, um territorio de serie estavel num modelo anti-preditivo
    // aparecia como recomendacao de primeira linha.
    $actionableRows = $ordersWell
        ? array_values(array_filter(
            $rows,
            static fn (array $row): bool => in_array((string) ($row['confidence'] ?? ''), ['alta', 'media'], true),
        ))
        : [];

    Http::json([
        'ok' => true,
        'status' => $rows === [] ? 'awaiting_first_run' : 'ready',
        'data' => $rows,
        'summary' => [
            'territories' => count($rows),
            'confidence' => $confidence,
            'actionable_territories' => count($actionableRows),
            'potential_revenue' => array_sum(array_column($actionableRows, 'potential_revenue')),
            'top' => $actionableRows[0] ?? null,
            // O primeiro do ranking continua sendo entregue mesmo sem veredito —
            // a tela precisa poder mostrar "o mais bem pontuado" como
            // EXPLORACAO. O que muda e a palavra e o destaque, nao o dado.
            'top_unvalidated' => $rows[0] ?? null,
            'orders_well' => $ordersWell,
        ],
        'validation' => $validation,
        'meta' => ['request_id' => RequestContext::id(), 'source' => 'python_persisted'],
    ]);
} catch (Throwable $exception) {
    global $logger;
    $logger?->error('geo_intelligence api error', ['message' => $exception->getMessage()]);
    Http::json(['ok' => false, 'error' => 'geo_intelligence_error',
        'message' => 'Nao foi possivel carregar a inteligencia territorial agora.',
        'request_id' => RequestContext::id()], 500);
}
