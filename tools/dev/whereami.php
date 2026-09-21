<?php

declare(strict_types=1);

/**
 * Onde esta máquina está conectada?
 *
 * Imprime ambiente, banco, servidor, usuário (e se é somente-leitura) e o
 * código em execução. Sai com código 3 quando o banco não é o esperado
 * (DB_EXPECTED_TARGET na config ou --expect=production|legacy|other) e com 2
 * quando nem conecta. Rode antes de qualquer auditoria ou diagnóstico.
 *
 *   php tools/dev/whereami.php
 *   php tools/dev/whereami.php --expect=production
 */

use BIomie\Config;
use BIomie\Database;
use BIomie\Support\EnvironmentGuard;

require dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$expect = null;
foreach (array_slice(array_map('strval', (array) ($_SERVER['argv'] ?? [])), 1) as $arg) {
    if (str_starts_with($arg, '--expect=')) {
        $expect = substr($arg, strlen('--expect='));
    }
}

try {
    $config = new Config();
    $info = EnvironmentGuard::describe(Database::connect($config, 'db'), $config);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Sem conexão com o banco: ' . $exception->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Na máquina de desenvolvimento, abra o túnel antes: bash tools/dev/db_tunnel.sh' . PHP_EOL);
    exit(2);
}

echo EnvironmentGuard::banner($info), PHP_EOL;

try {
    EnvironmentGuard::assertExpected($info, $expect);
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(3);
}
