<?php

declare(strict_types=1);

use BIomie\Bootstrap;

require_once __DIR__ . '/app/Bootstrap.php';

if (!class_exists(Bootstrap::class, false)) {
    throw new RuntimeException('Bootstrap do BIomie nao encontrado para analise estatica.');
}

Bootstrap::init();

$_SERVER['REQUEST_METHOD'] ??= 'GET';
$_SERVER['REQUEST_URI'] ??= '/phpstan';
$_SERVER['SCRIPT_FILENAME'] ??= __FILE__;
$_GET ??= [];
$_POST ??= [];
$_REQUEST ??= [];
$_SESSION ??= [];
$argv ??= ['phpstan'];
