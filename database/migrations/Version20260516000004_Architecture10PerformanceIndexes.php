<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function getVersion(): string
    {
        return '20260516000004';
    }

    public function getDescription(): string
    {
        return 'Arquitetura 10/10: indices para mix faturado, mapa geo e dashboards.';
    }

    public function up(\PDO $pdo): void
    {
        $indexes = [
            ['biomie_notas_fiscais_canonicas',    'idx_nf_status_comp_nota',           '(status_fiscal, data_competencia, nota_key)'],
            ['biomie_notas_fiscais_canonicas',    'idx_nf_status_comp_cliente',         '(status_fiscal, data_competencia, codigo_cliente_omie)'],
            ['biomie_notas_fiscais_canonicas',    'idx_nf_status_comp_vendor_arch10',   '(status_fiscal, data_competencia, codigo_vendedor_omie)'],
            ['biomie_notas_fiscais_itens_canonicos', 'idx_itens_nota_produto_arch10',   '(nota_key, codigo_produto)'],
            ['biomie_notas_fiscais_itens_canonicos', 'idx_itens_produto_nota_arch10',   '(codigo_produto, nota_key)'],
            ['mart_compradores_por_geo',          'idx_geo_compradores_city_bairro_comp', '(cidade_norm, bairro_norm, competencia)'],
        ];

        foreach ($indexes as [$table, $name, $cols]) {
            $exists = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE table_schema = DATABASE()
                   AND table_name   = '{$table}'
                   AND index_name   = '{$name}'"
            )?->fetchColumn();

            if (!$exists) {
                $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$name}` {$cols}");
            }
        }
    }

    public function down(\PDO $pdo): void
    {
        $drops = [
            ['biomie_notas_fiscais_canonicas',       'idx_nf_status_comp_nota'],
            ['biomie_notas_fiscais_canonicas',        'idx_nf_status_comp_cliente'],
            ['biomie_notas_fiscais_canonicas',        'idx_nf_status_comp_vendor_arch10'],
            ['biomie_notas_fiscais_itens_canonicos',  'idx_itens_nota_produto_arch10'],
            ['biomie_notas_fiscais_itens_canonicos',  'idx_itens_produto_nota_arch10'],
            ['mart_compradores_por_geo',              'idx_geo_compradores_city_bairro_comp'],
        ];

        foreach ($drops as [$table, $name]) {
            try {
                $pdo->exec("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
            } catch (\Throwable) {}
        }
    }
};
