<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$worker = (string) file_get_contents($root . '/cron/process_refresh_queue.php');

$checks = [
    'worker exige company_id explicito' =>
        str_contains($worker, 'company_id explicito e positivo e obrigatorio'),
    'worker nao usa fallback para empresa 1' =>
        !preg_match('/payload\[[\'"]company_id[\'"]\]\s*\?\?\s*1/', $worker)
        && !preg_match('/\$companyId\s*=\s*max\s*\(\s*1\s*,/', $worker),
    'worker valida canal explicito' =>
        str_contains($worker, 'canal explicito deve ser varejo ou licitacao'),
    'worker inicializa TenantContext pela empresa do job' =>
        str_contains($worker, 'TenantContext::bootForCompany($pdo, $companyId, $canal)'),
    'worker usa credenciais Omie da empresa do job' =>
        str_contains($worker, '$factory->forCompany($companyId)'),
    'worker isola bronze pela empresa do job' =>
        str_contains($worker, 'new BronzeRepository($pdo, $logger, $companyId)'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
$result = [
    'ok' => $failed === [],
    'total' => count($checks),
    'passed' => count($checks) - count($failed),
    'failed' => $failed,
];

echo json_encode(
    $result,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
), PHP_EOL;

exit($result['ok'] ? 0 : 1);
