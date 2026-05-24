<?php
declare(strict_types=1);
$root = dirname(dirname(__DIR__));
echo "ROOT: {$root}\n";
echo "CliGuard: " . (is_file($root . '/app/Support/CliGuard.php') ? 'OK' : 'MISSING') . "\n";
echo "Bootstrap: " . (is_file($root . '/app/Bootstrap.php') ? 'OK' : 'MISSING') . "\n";
echo "PHP_SAPI: " . PHP_SAPI . "\n";
echo "exec_ok: " . (function_exists('exec') ? 'yes' : 'NO') . "\n";
echo "proc_open_ok: " . (function_exists('proc_open') ? 'yes' : 'NO') . "\n";

require $root . '/app/Support/CliGuard.php';
echo "CliGuard loaded\n";
\BIomie\Support\CliGuard::requireCli();
echo "requireCli passed\n";

require $root . '/app/Bootstrap.php';
echo "Bootstrap loaded\n";
\BIomie\Bootstrap::init();
echo "Bootstrap::init() done\n";

$config = new \BIomie\Config();
echo "Config OK\n";

$pdo = \BIomie\Database::connect($config, 'db');
echo "PDO OK\n";
echo "ALL GOOD\n";
