<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260417190000';
    }

    public function getDescription(): string
    {
        return 'Fase 8: mart_forecasts — camada preditiva transparente (projeção linear, confiança declarada).';
    }

    /**
     * @return list<string>
     */
    public function getChecksumSources(): array
    {
        return [
            __DIR__ . '/sql/Version20260417190000_ForecastMart.sql',
        ];
    }

    public function up(\PDO $pdo): void
    {
        $this->executeSqlFile($pdo, __DIR__ . '/sql/Version20260417190000_ForecastMart.sql');
    }

    public function down(\PDO $pdo): void
    {
        $this->dropTables($pdo, ['mart_forecasts']);
    }
};
