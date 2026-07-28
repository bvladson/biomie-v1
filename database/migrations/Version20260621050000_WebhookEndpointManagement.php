<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260621050000';
    }

    public function getDescription(): string
    {
        return 'Gerencia endpoints webhook Omie por empresa com token criptografado e rotacao auditavel.';
    }

    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            ALTER TABLE biomie_company_integrations
                ADD COLUMN IF NOT EXISTS webhook_token_encrypted TEXT NULL AFTER webhook_token_hash,
                ADD COLUMN IF NOT EXISTS webhook_rotated_at DATETIME NULL AFTER secret_rotated_at,
                ADD COLUMN IF NOT EXISTS webhook_last_received_at DATETIME NULL AFTER last_used_at
        SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            ALTER TABLE biomie_company_integrations
                DROP COLUMN IF EXISTS webhook_last_received_at,
                DROP COLUMN IF EXISTS webhook_rotated_at,
                DROP COLUMN IF EXISTS webhook_token_encrypted
        SQL);
    }
};
