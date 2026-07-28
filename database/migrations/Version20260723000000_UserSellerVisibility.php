<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * VISIBILIDADE COMERCIAL — Fase B: configuração por usuário × empresa.
 *
 * A Fase A já entregou o escopo com dois modos implícitos (all / own via vínculo
 * de identidade). Esta migration adiciona o modo `selected` — o admin escolhe a
 * dedo quais vendedores um usuário enxerga numa empresa — e persiste o MODO
 * explícito, para que a decisão não dependa mais só da presença de vínculo.
 *
 * DUAS TABELAS, RELAÇÕES DIFERENTES (não juntar):
 *   · biomie_user_seller_scope     — 1 linha por (empresa, usuário): o MODO.
 *   · biomie_user_visible_sellers  — N linhas: os códigos escolhidos no modo
 *                                    `selected`. Irrelevante para all/own.
 *
 * NÃO CONFUNDIR COM biomie_quote_seller_binding (IDENTIDADE — "quem eu sou",
 * UNIQUE company+seller). Visibilidade é "quem eu posso ver": vários usuários
 * podem ver o mesmo vendedor, então aqui a chave inclui user_id.
 *
 * TIPOS — casam com o schema real (não BIGINT como num rascunho ingênuo):
 *   · company_id TINYINT UNSIGNED  = biomie_companies.id
 *   · user_id    INT UNSIGNED      = biomie_users.id
 *   · seller_id  VARCHAR(32) utf8mb4_unicode_ci = biomie_vendedores_omie.codigo_omie
 *                                  (mesmo domínio de followup.seller_id)
 * Com os tipos certos, a FK volta a ser possível já na v1 — company_id e user_id
 * têm ON DELETE CASCADE: apagar usuário/empresa limpa a config órfã sozinho.
 * seller_id NÃO é FK (igual ao binding): a API valida existência; um código que
 * suma do cadastro não deve derrubar a config inteira do usuário.
 *
 * updated_by/created_by: trilha de quem-mexeu-na-permissão-de-quem. Mudança de
 * visibilidade sem autor é ponto cego de auditoria.
 *
 * FUSO: timestamps em UTC (NOW() do banco). Reversível: down() dropa as duas.
 */
return new class extends AbstractMigration {

    private const T_SCOPE   = 'biomie_user_seller_scope';
    private const T_VISIBLE = 'biomie_user_visible_sellers';

    public function getVersion(): string
    {
        return '20260723000000';
    }

    public function getDescription(): string
    {
        return 'Visibilidade Comercial Fase B: config usuário×empresa (modo all/own/selected) + vendedores escolhidos.';
    }

    public function up(PDO $pdo): void
    {
        // ── Modo por (empresa, usuário) ──────────────────────────────────────
        $pdo->exec(sprintf(<<<'SQL'
            CREATE TABLE IF NOT EXISTS %s (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                company_id TINYINT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                visibility_mode ENUM('all','own','selected') NOT NULL DEFAULT 'all'
                    COMMENT 'all=todos · own=carteira própria (vínculo) · selected=lista escolhida',

                created_by INT UNSIGNED NULL COMMENT 'quem criou (biomie_users.id)',
                updated_by INT UNSIGNED NULL COMMENT 'quem alterou por último',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'UTC',

                PRIMARY KEY (id),
                UNIQUE KEY uq_user_seller_scope (company_id, user_id),
                KEY idx_uss_user (user_id),
                CONSTRAINT fk_uss_company FOREIGN KEY (company_id)
                    REFERENCES biomie_companies (id) ON DELETE CASCADE,
                CONSTRAINT fk_uss_user FOREIGN KEY (user_id)
                    REFERENCES biomie_users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL, self::T_SCOPE));

        // ── Vendedores escolhidos (modo selected) ────────────────────────────
        $pdo->exec(sprintf(<<<'SQL'
            CREATE TABLE IF NOT EXISTS %s (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                company_id TINYINT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                seller_id VARCHAR(32) NOT NULL
                    COLLATE utf8mb4_unicode_ci
                    COMMENT 'codigo_omie do vendedor (mesmo domínio de followup.seller_id)',

                created_by INT UNSIGNED NULL COMMENT 'quem incluiu (biomie_users.id)',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',

                PRIMARY KEY (id),
                UNIQUE KEY uq_user_visible_seller (company_id, user_id, seller_id),
                KEY idx_uvs_lookup (company_id, user_id),
                CONSTRAINT fk_uvs_company FOREIGN KEY (company_id)
                    REFERENCES biomie_companies (id) ON DELETE CASCADE,
                CONSTRAINT fk_uvs_user FOREIGN KEY (user_id)
                    REFERENCES biomie_users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL, self::T_VISIBLE));
    }

    public function down(PDO $pdo): void
    {
        // Ordem irrelevante (não há FK entre as duas), mas dropar a de detalhe
        // primeiro é o hábito seguro.
        $this->dropTables($pdo, [self::T_VISIBLE, self::T_SCOPE]);
    }
};
