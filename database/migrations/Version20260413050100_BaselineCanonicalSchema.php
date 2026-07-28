<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260413050100';
    }

    public function getDescription(): string
    {
        return 'Baseline da camada canonica, bronze, event store e pipeline operacional.';
    }

    /**
     * @return list<string>
     */
    public function getChecksumSources(): array
    {
        return [
            __DIR__ . '/sql/Version20260413050100_BaselineCanonicalSchema.sql',
        ];
    }

    public function up(\PDO $pdo): void
    {
        $this->executeSqlFile($pdo, __DIR__ . '/sql/Version20260413050100_BaselineCanonicalSchema.sql');
    }

    public function down(\PDO $pdo): void
    {
        $this->dropTables($pdo, [
            'biomie_shadow_validations',
            'biomie_sync_runs',
            'biomie_sync_cursors',
            'biomie_webhook_inbox',
            'biomie_sync_state',
            'biomie_divergencias',
            'biomie_eventos_canonicos',
            'biomie_titulos_financeiros_canonicos',
            'biomie_documentos_xml_canonicos',
            'biomie_notas_fiscais_itens_canonicos',
            'biomie_notas_fiscais_canonicas',
            'biomie_pedidos_canonicos',
            'biomie_omie_bronze',
            'biomie_vendedores_omie',
        ]);
    }
};
