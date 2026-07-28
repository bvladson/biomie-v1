<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\ReadModel;

use BIomie\Config;
use BIomie\Database;
use BIomie\DataPlatform\Repository\CnaeReadRepository;
use PDO;

final class CnaeReadModelFactory
{
    public static function make(Config $config, ?PDO $pdo = null): CnaeReadModel
    {
        if (!$pdo instanceof PDO) {
            [$pdo] = Database::getGeoConnection($config);
        }

        return new CnaeReadModel(new CnaeReadRepository($pdo));
    }
}
