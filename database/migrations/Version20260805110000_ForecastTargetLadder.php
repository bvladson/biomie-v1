<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string { return '20260805110000'; }
    public function getDescription(): string { return 'Forecast distingue compromisso-base, desafio ativo e meta máxima.'; }

    public function up(PDO $pdo): void
    {
        foreach ([
            'base_target DECIMAL(15,2) NULL AFTER meta_valor',
            'active_target DECIMAL(15,2) NULL AFTER base_target',
            'maximum_target DECIMAL(15,2) NULL AFTER active_target',
            'achieved_level TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER maximum_target',
            'active_level TINYINT UNSIGNED NULL AFTER achieved_level',
            'levels_total TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER active_level',
            'level_probabilities_json TEXT NULL AFTER levels_total',
        ] as $definition) {
            [$column] = explode(' ', $definition, 2);
            if (!$this->columnExists($pdo, 'intelligence_forecasts', $column)) {
                $pdo->exec('ALTER TABLE intelligence_forecasts ADD COLUMN ' . $definition);
            }
        }
    }

    public function down(PDO $pdo): void
    {
        foreach (['level_probabilities_json','levels_total','active_level','achieved_level','maximum_target','active_target','base_target'] as $column) {
            if ($this->columnExists($pdo, 'intelligence_forecasts', $column)) {
                $pdo->exec('ALTER TABLE intelligence_forecasts DROP COLUMN ' . $column);
            }
        }
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=:t AND column_name=:c');
        $stmt->execute(['t' => $table, 'c' => $column]);
        return (int) $stmt->fetchColumn() > 0;
    }
};
