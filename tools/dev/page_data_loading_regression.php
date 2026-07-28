<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\DataPlatform\Query\GeoQuery;
use BIomie\DataPlatform\ReadModel\GeoReadModelFactory;
use BIomie\Modules\Fiscal\Infrastructure\FiscalIntelligenceService;
use BIomie\PageRenderer;
use BIomie\Support\FinanceVisualAnalyticsBuilder;
use BIomie\Support\TenantContext;

require dirname(__DIR__, 2) . '/app/Bootstrap.php';

Bootstrap::init();

$root = dirname(__DIR__, 2);
$checks = [];

$record = static function (string $id, bool $ok, string $message, mixed $actual = null) use (&$checks): void {
    $checks[] = [
        'id' => $id,
        'ok' => $ok,
        'message' => $message,
        'actual' => $actual,
    ];
};

$renderer = new ReflectionMethod(PageRenderer::class, 'withMagDesignSystem');

foreach (['finance', 'geo'] as $domain) {
    $attribute = $domain === 'finance' ? 'data-finance-assets' : 'data-geo-assets';
    $manifest = [
        'core' => [
            'assets/js/cache-refresh.js?v=old',
            'assets/js/commercial-language.js?v=old',
        ],
    ];
    $encoded = htmlspecialchars(
        (string) json_encode($manifest, JSON_UNESCAPED_SLASHES),
        ENT_QUOTES,
        'UTF-8'
    );
    $html = '<html><head></head><body><script src="assets/js/geo-feature-loader.js" '
        . $attribute . '="' . $encoded . '"></script></body></html>';
    $rendered = (string) $renderer->invoke(null, $html);

    preg_match('/' . preg_quote($attribute, '/') . '="([^"]+)"/', $rendered, $match);
    $decoded = isset($match[1])
        ? json_decode(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'), true)
        : null;
    $paths = is_array($decoded) ? ($decoded['core'] ?? null) : null;

    $record(
        $domain . '_lazy_manifest_valid_json',
        is_array($paths) && count($paths) === 2,
        'O versionamento deve preservar o JSON completo do manifesto lazy.',
        $paths
    );
}

$financeJs = (string) file_get_contents($root . '/assets/js/modules/finance/visual-analytics.js');
$record(
    'finance_late_init',
    str_contains($financeJs, "document.readyState === 'loading'")
        && str_contains($financeJs, 'initVisualSummary();'),
    'O módulo financeiro carregado sob demanda deve iniciar após DOMContentLoaded.'
);

$fiscalJs = (string) file_get_contents($root . '/assets/js/fiscal.js');
$contextSliceStart = strpos($fiscalJs, 'function loadFiscalContext()');
$contextSlice = $contextSliceStart === false ? '' : substr($fiscalJs, $contextSliceStart, 900);
$record(
    'fiscal_context_rejects_api_error',
    str_contains($contextSlice, "throw new Error(data.message || 'Falha ao carregar contexto fiscal')")
        && str_contains($contextSlice, 'Tributos por esfera indisponiveis.'),
    'Contexto Fiscal deve sair do skeleton quando a API retornar ok=false.'
);

$fiscalService = (string) file_get_contents(
    $root . '/app/Modules/Fiscal/Infrastructure/FiscalIntelligenceService.php'
);
$record(
    'fiscal_snapshot_columns_qualified',
    str_contains($fiscalService, 'SUM(s.valor_iss)')
        && str_contains($fiscalService, 'SUM(s.valor_ibs_municipio)')
        && !str_contains($fiscalService, 'SUM(CASE WHEN document_model='),
    'Colunas compartilhadas no join fiscal devem estar qualificadas pelo alias.'
);

try {
    $config = new Config();
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $connection);
    bootRegressionTenant($pdo, null);
    $start = date('Y-m-01');
    $end = date('Y-m-t');

    $fiscal = new FiscalIntelligenceService(static fn () => $pdo);
    $spheres = $fiscal->sphereSummary($start, $end);
    $record(
        'fiscal_runtime',
        isset($spheres['federal'], $spheres['state'], $spheres['municipal'], $spheres['reform']),
        'O serviço fiscal deve executar no banco real sem SQL ambíguo.',
        array_keys($spheres)
    );

    $finance = (new FinanceVisualAnalyticsBuilder($pdo))->build($start, $end, false);
    $record(
        'finance_runtime',
        ($finance['ok'] ?? false) === true
            && is_array($finance['composition'] ?? null)
            && is_array($finance['evolution'] ?? null),
        'O analytics financeiro deve retornar o contrato visual no banco real.',
        [
            'composition' => count($finance['composition'] ?? []),
            'evolution' => count($finance['evolution'] ?? []),
        ]
    );

    $query = GeoQuery::fromRequest([
        'date_from' => $start,
        'date_to' => $end,
        'layer' => 'ceara_regioes',
        'scope' => 'orc_pd',
        'metric' => 'receita',
    ]);
    $geoPayload = GeoReadModelFactory::make($config)->mapPayload($query)->toArray();
    $record(
        'geo_runtime',
        ($geoPayload['ok'] ?? false) === true
            && is_array($geoPayload['data'] ?? null)
            && isset($geoPayload['data']['summary'], $geoPayload['data']['ranking']),
        'O payload geográfico deve retornar o contrato consolidado no banco real.',
        array_keys($geoPayload['data'] ?? [])
    );
} catch (Throwable $exception) {
    $record(
        'runtime_services',
        false,
        'Os serviços reais de Fiscal, Financeiro e Geografia devem executar sem exceção.',
        $exception->getMessage()
    );
}

$failed = array_values(array_filter($checks, static fn (array $check): bool => !$check['ok']));
$result = [
    'ok' => $failed === [],
    'summary' => [
        'checks' => count($checks),
        'passed' => count($checks) - count($failed),
        'failed' => count($failed),
    ],
    'checks' => $checks,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($result['ok'] ? 0 : 1);

/**
 * @return array{id:int,slug:string,canal:string}
 */
function bootRegressionTenant(PDO $pdo, mixed $selector): array
{
    $selector = strtoupper(trim((string) $selector));
    if ($selector !== '') {
        $stmt = $pdo->prepare(
            'SELECT id, slug, canal_padrao
               FROM biomie_companies
              WHERE ativo = 1
                AND (id = :id OR slug = :slug)
              LIMIT 1'
        );
        $stmt->execute([
            'id' => ctype_digit($selector) ? (int) $selector : 0,
            'slug' => $selector,
        ]);
    } else {
        $stmt = $pdo->query(
            'SELECT id, slug, canal_padrao
               FROM biomie_companies
              WHERE ativo = 1
              ORDER BY id
              LIMIT 1'
        );
    }

    $company = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($company)) {
        throw new RuntimeException('Nenhuma empresa ativa encontrada para inicializar o TenantContext.');
    }

    $canal = (string) ($company['canal_padrao'] ?: 'varejo');
    TenantContext::bootForCompany($pdo, (int) $company['id'], $canal);

    return [
        'id' => (int) $company['id'],
        'slug' => (string) $company['slug'],
        'canal' => $canal,
    ];
}
