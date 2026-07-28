<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\ReadModel;

use BIomie\Config;
use BIomie\Database;
use BIomie\DataPlatform\Repository\GeoReadRepository;
use PDO;

final class GeoReadModelFactory
{
    public static function make(Config $config, ?PDO $pdo = null): GeoReadModel
    {
        if (!$pdo instanceof PDO) {
            [$pdo] = Database::getGeoConnection($config);
        }

        return new GeoReadModel(new GeoReadRepository($pdo));
    }
}
