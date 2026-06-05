<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\ReadModel;

use BIomie\Config;
use BIomie\Database;
use BIomie\DataPlatform\Repository\CustomerReadRepository;
use PDO;

final class CustomerReadModelFactory
{
    public static function make(Config $config, ?PDO $pdo = null): CustomerReadModel
    {
        if (!$pdo instanceof PDO) {
            [$pdo] = Database::getGeoConnection($config);
        }

        return new CustomerReadModel(new CustomerReadRepository($pdo));
    }
}
