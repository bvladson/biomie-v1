<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\Logger;
use BIomie\Modules\Fiscal\Infrastructure\FiscalMartBuilder;

require __DIR__ . '/bootstrap.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    Auth::requireAnyCapability(['fiscal.view', 'finance.view'], true);
}

$out = static function (array $data): void {
    if (PHP_SAPI === 'cli') {
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        Http::json($data);
    }
};

try {
    $config = new \BIomie\Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);

    set_time_limit(300);
    ini_set('memory_limit', '256M');

    $builder = new FiscalMartBuilder($pdo);
    $result  = $builder->build();

    $out(['ok' => true, 'result' => $result]);
} catch (\Throwable $e) {
    $out(['ok' => false, 'message' => $e->getMessage()]);
}
