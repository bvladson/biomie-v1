<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * Licitação da MAG Filial volta a entrar no BIomie.
 *
 * Em 08/06/2026 foram criadas regras de EXCLUSÃO DURA na MAG Filial
 * (biomie_data_exclusion_rules, motivo "Remocao solicitada: dados de Licitacao"):
 * o vendedor operacional "Licitação" (código 8766069381) + 11 NFs, 11 pedidos,
 * 4 títulos e 7 XMLs. A escrita canônica (CanonicalWriteRepository::
 * isHardExcluded) descarta tudo que casa com essas regras ANTES de gravar —
 * nenhum contexto, canal ou filtro consegue mostrar esses valores.
 *
 * Em 29/09/2026 o dono do BIomie definiu que licitação é venda e entra nos
 * números, e que todas as empresas licitam, exceto a MAG Matriz. Medido no
 * bronze: 7 NFs de licitação da Filial entre 01/07 e 23/09/2026 (R$ 472.397,50,
 * sendo R$ 80.750,00 em setembro) foram descartadas por essas regras.
 *
 * up() DESATIVA (active = 0) só as regras com esse motivo; não apaga nada.
 * down() as reativa. Os dados descartados NÃO voltam sozinhos: é preciso
 * buscar de novo no Omie (runbook: bin/initial_sync_company.php e/ou
 * bin/omie_nfe_backfill.php para a MAG Filial) e depois reclassificar.
 */
return new class extends AbstractMigration {
    private const REASON = 'Remocao solicitada: dados de Licitacao';

    public function getVersion(): string
    {
        return '20260929150000';
    }

    public function getDescription(): string
    {
        return 'Desativa as regras de exclusão de licitação da MAG Filial (08/06/2026); reversível.';
    }

    public function getType(): string
    {
        return 'data';
    }

    public function up(PDO $pdo): void
    {
        if (!$this->tableExists($pdo)) {
            return;
        }
        $pdo->prepare(
            'UPDATE biomie_data_exclusion_rules SET active = 0 WHERE reason = :reason AND active = 1'
        )->execute(['reason' => self::REASON]);
    }

    public function down(PDO $pdo): void
    {
        if (!$this->tableExists($pdo)) {
            return;
        }
        $pdo->prepare(
            'UPDATE biomie_data_exclusion_rules SET active = 1 WHERE reason = :reason AND active = 0'
        )->execute(['reason' => self::REASON]);
    }

    private function tableExists(PDO $pdo): bool
    {
        return (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biomie_data_exclusion_rules'"
        )->fetchColumn() > 0;
    }
};
