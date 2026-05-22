<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\SystemHealth\SystemHealthService;

require __DIR__ . '/bootstrap.php';

Auth::requireLogin(true);

/** Maps internal check IDs to user-friendly domain labels. */
$domainLabels = [
    'database'         => 'Banco de dados',
    'jobs'             => 'Processamento de dados',
    'freshness'        => 'Atualidade dos dados',
    'semantic'         => 'Metricas e KPIs',
    'shadow'           => 'Consistencia dos dados',
    'customer_quality' => 'Cadastro de clientes',
    'geo_quality'      => 'Mapa de vendas',
    'webhook_inbox'    => 'Integracao Omie',
];

$userMessages = [
    'ok'       => 'Operando normalmente.',
    'warning'  => 'Ha uma situacao que precisa de atencao.',
    'critical' => 'Ha um problema que requer acao.',
    'unknown'  => 'Estado indeterminado.',
];

$overallMessages = [
    'ok'       => 'Todos os sistemas estao operando normalmente.',
    'warning'  => 'Alguns sistemas precisam de atencao. Seus dados podem estar incompletos.',
    'critical' => 'Um ou mais sistemas apresentam problemas. Entre em contato com o suporte.',
    'unknown'  => 'Nao foi possivel verificar todos os sistemas agora.',
];

$pdo = null;
try {
    [$pdo] = Database::getGeoConnection($config);
} catch (\Throwable) {}

try {
    $service = new SystemHealthService($pdo);
    $report  = $service->run();
    $data    = $report->toArray();
    $overall = $data['status'] ?? 'unknown';

    $domains = array_map(static function (array $check) use ($domainLabels, $userMessages): array {
        $status = $check['status'] ?? 'unknown';
        return [
            'id'      => $check['id'],
            'label'   => $domainLabels[$check['id']] ?? $check['label'],
            'status'  => $status,
            'message' => $userMessages[$status] ?? 'Estado desconhecido.',
        ];
    }, $data['checks'] ?? []);

    Http::json([
        'ok'           => true,
        'status'       => $overall,
        'message'      => $overallMessages[$overall] ?? 'Estado indeterminado.',
        'domains'      => $domains,
        'generated_at' => date(DATE_ATOM),
        'request_id'   => RequestContext::id(),
    ]);
} catch (\Throwable) {
    Http::json([
        'ok'           => false,
        'status'       => 'unknown',
        'message'      => 'Nao foi possivel verificar a saude do sistema agora. Tente novamente em instantes.',
        'domains'      => [],
        'generated_at' => date(DATE_ATOM),
        'request_id'   => RequestContext::id(),
    ]);
}
