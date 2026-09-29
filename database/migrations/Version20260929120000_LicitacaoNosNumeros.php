<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * Licitação entra nos números: todo vínculo usuário × empresa passa a enxergar
 * os DOIS canais (varejo e licitação).
 *
 * POR QUE
 * O acesso por canal nasceu do canal_padrao da empresa (can_varejo só onde a
 * empresa era "varejo"). Na Uni Design — canal_padrao licitacao, mas com as 33
 * NFs de setembro/2026 classificadas como varejo — o único contexto disponível
 * era "Licitação", e o painel mostrava R$ 0,00 com R$ 46.710,95 faturados. Com
 * os dois canais, a empresa abre em "Todas as operações" e varejo/licitação
 * viram filtro dentro da tela, como o seletor de vendedores.
 *
 * REVERSÍVEL
 * O estado anterior de cada vínculo alterado fica em
 * biomie_user_companies_canais_20260929; down() o restaura exatamente.
 * Os triggers de biomie_user_companies incrementam permissions_version, o que
 * revoga os contextos abertos: cada usuário escolhe de novo e cai no padrão
 * "Todas as operações".
 */
return new class extends AbstractMigration {
    private const SNAPSHOT = 'biomie_user_companies_canais_20260929';

    public function getVersion(): string
    {
        return '20260929120000';
    }

    public function getDescription(): string
    {
        return 'Licitação nos números: vínculos usuário×empresa com varejo e licitação (estado anterior preservado).';
    }

    public function getType(): string
    {
        return 'data';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::SNAPSHOT . ' (
                user_id INT UNSIGNED NOT NULL,
                company_id TINYINT UNSIGNED NOT NULL,
                can_varejo TINYINT(1) NOT NULL,
                can_licitacao TINYINT(1) NOT NULL,
                default_canal VARCHAR(20) NULL,
                captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, company_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        // INSERT IGNORE: reexecutar não sobrescreve o estado ORIGINAL guardado.
        $pdo->exec(
            'INSERT IGNORE INTO ' . self::SNAPSHOT . '
                (user_id, company_id, can_varejo, can_licitacao, default_canal)
             SELECT user_id, company_id, can_varejo, can_licitacao, default_canal
               FROM biomie_user_companies
              WHERE can_varejo = 0 OR can_licitacao = 0'
        );

        $pdo->exec(
            'UPDATE biomie_user_companies
                SET can_varejo = 1,
                    can_licitacao = 1
              WHERE can_varejo = 0 OR can_licitacao = 0'
        );
    }

    public function down(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, self::SNAPSHOT)) {
            return;
        }

        $pdo->exec(
            'UPDATE biomie_user_companies uc
               JOIN ' . self::SNAPSHOT . ' s
                 ON s.user_id = uc.user_id
                AND s.company_id = uc.company_id
                SET uc.can_varejo = s.can_varejo,
                    uc.can_licitacao = s.can_licitacao'
        );
        $pdo->exec('DROP TABLE ' . self::SNAPSHOT);
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
        );
        $stmt->execute(['t' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }
};
