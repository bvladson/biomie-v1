<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

/**
 * FILTRO COMERCIAL INTELIGENTE — persistência server-side por recorte.
 *
 * O CONTEXTO define o universo máximo (empresa + canais autorizados); os FILTROS
 * apenas refinam dentro dele. Esta tabela guarda a INTENÇÃO de refino do usuário
 * (canais e vendedores escolhidos) para que ela sobreviva à sessão, à URL e à
 * troca de aba — a resolução em runtime continua sendo URL → preferência → default,
 * sempre cruzada com a autorização atual (a preferência nunca amplia o acesso).
 *
 * CHAVE COMPOSTA (company_id, user_id, page, business_scope): a mesma página sob
 * "Todas as operações", "Varejo" ou "Licitação" é um recorte DIFERENTE e merece
 * memória própria — senão trocar de canal apagaria o filtro do outro.
 *
 * NULL ≠ LISTA VAZIA — a mesma trava de segurança do SellerVisibilityScope, agora
 * persistida:
 *   seller_ids_json NULL  → todos os vendedores autorizados (sem restrição salva)
 *   seller_ids_json '[]'  → NENHUM vendedor (escolha explícita de vazio)
 *   seller_ids_json '[…]' → exatamente esses códigos
 *   channels_json  NULL   → todos os canais autorizados
 *   channels_json  '[…]'  → exatamente esses canais (subconjunto pedido)
 * Guardar "" ou coluna ausente jamais pode virar "todos" na leitura — a distinção
 * mora aqui e o Store a preserva.
 *
 * TIPOS — casam com o schema real (mesmo padrão de biomie_user_seller_scope):
 *   · company_id TINYINT UNSIGNED = biomie_companies.id
 *   · user_id    INT UNSIGNED     = biomie_users.id
 * FKs com ON DELETE CASCADE: apagar usuário/empresa limpa a preferência órfã.
 *
 * page: só recortes de UMA empresa persistem (o consolidado/grupo não usa esta
 * tabela). business_scope reflete o TenantContext do momento em que foi salvo.
 *
 * FUSO: timestamps em UTC (banco). Reversível: down() dropa a tabela.
 */
return new class extends AbstractMigration {

    private const T_PREFS = 'biomie_user_commercial_filters';

    public function getVersion(): string
    {
        return '20260726000000';
    }

    public function getDescription(): string
    {
        return 'Filtro Comercial Inteligente: preferência de canais+vendedores por usuário×empresa×página×business_scope.';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(sprintf(<<<'SQL'
            CREATE TABLE IF NOT EXISTS %s (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                company_id TINYINT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                page VARCHAR(48) NOT NULL
                    COLLATE utf8mb4_unicode_ci
                    COMMENT 'chave da página/superfície (ex: commercial_dashboard)',
                business_scope ENUM('varejo','licitacao','mixed') NOT NULL
                    COMMENT 'recorte de contexto em que a preferência foi salva',

                channels_json VARCHAR(64) NULL
                    COMMENT 'JSON list de canais pedidos; NULL = todos os autorizados',
                seller_ids_json TEXT NULL
                    COMMENT 'JSON list de códigos; NULL = todos autorizados · []=nenhum',

                updated_by INT UNSIGNED NULL COMMENT 'quem salvou por último (biomie_users.id)',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP COMMENT 'UTC',

                PRIMARY KEY (id),
                UNIQUE KEY uq_user_commercial_filter (company_id, user_id, page, business_scope),
                KEY idx_ucf_user (user_id),
                CONSTRAINT fk_ucf_company FOREIGN KEY (company_id)
                    REFERENCES biomie_companies (id) ON DELETE CASCADE,
                CONSTRAINT fk_ucf_user FOREIGN KEY (user_id)
                    REFERENCES biomie_users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL, self::T_PREFS));
    }

    public function down(PDO $pdo): void
    {
        $this->dropTables($pdo, [self::T_PREFS]);
    }
};
