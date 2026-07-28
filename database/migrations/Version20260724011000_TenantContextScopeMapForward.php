<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * Migração forward para ambientes que possam ter registrado a versão R1 antes
 * da inclusão de company_scope_map_json no artefato de desenvolvimento.
 *
 * É deliberadamente idempotente: instalações novas já recebem a coluna pela
 * migration base; instalações intermediárias recebem a coluna aqui e têm
 * contextos de grupo antigos revogados, pois não é seguro reconstruir os canais
 * autorizados a partir de heurística.
 */
return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260724011000';
    }

    public function getDescription(): string
    {
        return 'Forward fix do mapa de escopos por empresa em contextos R1.';
    }

    public function getConnectionName(): string
    {
        return 'primary';
    }

    public function up(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'biomie_user_contexts')
            || $this->columnExists($pdo, 'biomie_user_contexts', 'company_scope_map_json')
        ) {
            return;
        }

        $pdo->exec(
            'ALTER TABLE biomie_user_contexts
             ADD COLUMN company_scope_map_json LONGTEXT NULL
             AFTER allowed_company_ids_json'
        );

        $pdo->exec(<<<'SQL'
            UPDATE biomie_user_contexts
               SET company_scope_map_json = CASE
                   WHEN context_type = 'company'
                        AND company_id IS NOT NULL
                        AND business_scope IN ('varejo', 'licitacao')
                   THEN JSON_OBJECT(
                       CAST(company_id AS CHAR),
                       JSON_ARRAY(business_scope)
                   )
                   ELSE JSON_OBJECT()
               END,
                   revoked_at = CASE
                       WHEN context_type = 'group'
                       THEN COALESCE(revoked_at, UTC_TIMESTAMP())
                       ELSE revoked_at
                   END
        SQL);

        $pdo->exec(
            'ALTER TABLE biomie_user_contexts
             MODIFY COLUMN company_scope_map_json LONGTEXT NOT NULL'
        );
    }

    /**
     * Não removemos a coluna no rollback: ela já pertence ao schema da migration
     * base atual e removê-la quebraria o TenantContextStore.
     */
    public function down(PDO $pdo): void
    {
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
        );
        $stmt->execute(['table_name' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = :table_name
                AND COLUMN_NAME = :column_name'
        );
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }
};
