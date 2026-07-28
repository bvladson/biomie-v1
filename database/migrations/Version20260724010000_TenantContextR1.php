<?php

declare(strict_types=1);

use BIomie\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    /** @var list<string> */
    private const TRIGGERS = [
        'trg_biomie_users_permissions_bu',
        'trg_user_companies_permissions_ai',
        'trg_user_companies_permissions_au',
        'trg_user_companies_permissions_ad',
        'trg_seller_scope_permissions_ai',
        'trg_seller_scope_permissions_au',
        'trg_seller_scope_permissions_ad',
        'trg_visible_sellers_permissions_ai',
        'trg_visible_sellers_permissions_au',
        'trg_visible_sellers_permissions_ad',
        'trg_quote_binding_permissions_ai',
        'trg_quote_binding_permissions_au',
        'trg_quote_binding_permissions_ad',
        'trg_user_roles_permissions_ai',
        'trg_user_roles_permissions_au',
        'trg_user_roles_permissions_ad',
        'trg_role_permissions_permissions_ai',
        'trg_role_permissions_permissions_au',
        'trg_role_permissions_permissions_ad',
        'trg_roles_permissions_au',
        'trg_permissions_permissions_au',
    ];

    public function getVersion(): string
    {
        return '20260724010000';
    }

    public function getDescription(): string
    {
        return 'Contexto Multiempresa R1: ctx opaco server-side, expiração/revogação, versão de permissões e auditoria.';
    }

    public function getConnectionName(): string
    {
        return 'primary';
    }

    public function up(PDO $pdo): void
    {
        if (!$this->columnExists($pdo, 'biomie_users', 'permissions_version')) {
            $pdo->exec(
                "ALTER TABLE biomie_users
                 ADD COLUMN permissions_version BIGINT UNSIGNED NOT NULL DEFAULT 1
                 COMMENT 'Incrementa sempre que a autorização efetiva do usuário muda'"
            );
        }

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_user_contexts (
                context_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                context_type ENUM('company','group') NOT NULL,
                company_id TINYINT UNSIGNED NULL,
                allowed_company_ids_json LONGTEXT NOT NULL,
                company_scope_map_json LONGTEXT NOT NULL,
                business_scope ENUM('varejo','licitacao','mixed') NOT NULL,
                role_name VARCHAR(20) NOT NULL,
                seller_scope_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                permissions_version BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL,
                last_used_at DATETIME NOT NULL,
                revoked_at DATETIME NULL,
                PRIMARY KEY (context_id),
                KEY idx_user_context_active (user_id, revoked_at, expires_at),
                KEY idx_context_expiry (expires_at),
                KEY idx_context_company (company_id, user_id),
                CONSTRAINT fk_user_context_user FOREIGN KEY (user_id)
                    REFERENCES biomie_users (id) ON DELETE CASCADE,
                CONSTRAINT fk_user_context_company FOREIGN KEY (company_id)
                    REFERENCES biomie_companies (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Referências opacas de autorização multiempresa; nunca contém token autossuficiente'
        SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS biomie_context_switch_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NULL,
                from_context_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
                from_context_type ENUM('company','group') NULL,
                from_company_id TINYINT UNSIGNED NULL,
                to_context_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
                to_context_type ENUM('company','group') NULL,
                to_company_id TINYINT UNSIGNED NULL,
                request_id VARCHAR(64) NOT NULL,
                ip_address VARCHAR(45) NOT NULL DEFAULT '',
                user_agent VARCHAR(512) NOT NULL DEFAULT '',
                status ENUM('success','blocked','error') NOT NULL,
                reason VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_context_switch_user_time (user_id, created_at),
                KEY idx_context_switch_status_time (status, created_at),
                KEY idx_context_switch_request (request_id),
                CONSTRAINT fk_context_switch_user FOREIGN KEY (user_id)
                    REFERENCES biomie_users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Auditoria de sucesso e bloqueios na troca de contexto'
        SQL);

        $this->createPermissionVersionTriggers($pdo);
    }

    public function down(PDO $pdo): void
    {
        foreach (self::TRIGGERS as $trigger) {
            $pdo->exec('DROP TRIGGER IF EXISTS `' . $trigger . '`');
        }
        $this->dropTables($pdo, [
            'biomie_context_switch_log',
            'biomie_user_contexts',
        ]);
        if ($this->columnExists($pdo, 'biomie_users', 'permissions_version')) {
            $pdo->exec('ALTER TABLE biomie_users DROP COLUMN permissions_version');
        }
    }

    private function createPermissionVersionTriggers(PDO $pdo): void
    {
        foreach (self::TRIGGERS as $trigger) {
            $pdo->exec('DROP TRIGGER IF EXISTS `' . $trigger . '`');
        }

        $pdo->exec(<<<'SQL'
            CREATE TRIGGER trg_biomie_users_permissions_bu
            BEFORE UPDATE ON biomie_users
            FOR EACH ROW
            BEGIN
                IF NOT (OLD.role <=> NEW.role)
                   OR NOT (OLD.modules_json <=> NEW.modules_json)
                   OR NOT (OLD.is_active <=> NEW.is_active) THEN
                    SET NEW.permissions_version = OLD.permissions_version + 1;
                END IF;
            END
        SQL);

        $this->createUserTargetTriggers($pdo, 'biomie_user_companies', 'user_companies_permissions');

        if ($this->tableExists($pdo, 'biomie_user_seller_scope')) {
            $this->createUserTargetTriggers($pdo, 'biomie_user_seller_scope', 'seller_scope_permissions');
        }
        if ($this->tableExists($pdo, 'biomie_user_visible_sellers')) {
            $this->createUserTargetTriggers($pdo, 'biomie_user_visible_sellers', 'visible_sellers_permissions');
        }
        if ($this->tableExists($pdo, 'biomie_quote_seller_binding')) {
            $this->createUserTargetTriggers($pdo, 'biomie_quote_seller_binding', 'quote_binding_permissions');
        }
        if ($this->tableExists($pdo, 'biomie_user_roles')) {
            $this->createUserTargetTriggers($pdo, 'biomie_user_roles', 'user_roles_permissions');
        }
        if ($this->tableExists($pdo, 'biomie_role_permissions')
            && $this->tableExists($pdo, 'biomie_user_roles')
        ) {
            $pdo->exec(<<<'SQL'
                CREATE TRIGGER trg_role_permissions_permissions_ai
                AFTER INSERT ON biomie_role_permissions
                FOR EACH ROW
                UPDATE biomie_users u
                  JOIN biomie_user_roles ur ON ur.user_id = u.id
                   SET u.permissions_version = u.permissions_version + 1
                 WHERE ur.role_id = NEW.role_id
            SQL);
            $pdo->exec(<<<'SQL'
                CREATE TRIGGER trg_role_permissions_permissions_ad
                AFTER DELETE ON biomie_role_permissions
                FOR EACH ROW
                UPDATE biomie_users u
                  JOIN biomie_user_roles ur ON ur.user_id = u.id
                   SET u.permissions_version = u.permissions_version + 1
                 WHERE ur.role_id = OLD.role_id
            SQL);
            $pdo->exec(<<<'SQL'
                CREATE TRIGGER trg_role_permissions_permissions_au
                AFTER UPDATE ON biomie_role_permissions
                FOR EACH ROW
                BEGIN
                    UPDATE biomie_users u
                      JOIN biomie_user_roles ur ON ur.user_id = u.id
                       SET u.permissions_version = u.permissions_version + 1
                     WHERE ur.role_id = OLD.role_id;
                    IF NEW.role_id <> OLD.role_id THEN
                        UPDATE biomie_users u
                          JOIN biomie_user_roles ur ON ur.user_id = u.id
                           SET u.permissions_version = u.permissions_version + 1
                         WHERE ur.role_id = NEW.role_id;
                    END IF;
                END
            SQL);
        }
        if ($this->tableExists($pdo, 'biomie_roles')
            && $this->tableExists($pdo, 'biomie_user_roles')
        ) {
            $pdo->exec(<<<'SQL'
                CREATE TRIGGER trg_roles_permissions_au
                AFTER UPDATE ON biomie_roles
                FOR EACH ROW
                UPDATE biomie_users u
                  JOIN biomie_user_roles ur ON ur.user_id = u.id
                   SET u.permissions_version = u.permissions_version + 1
                 WHERE ur.role_id = NEW.id
                   AND (
                       NOT (OLD.role_key <=> NEW.role_key)
                       OR NOT (OLD.is_active <=> NEW.is_active)
                   )
            SQL);
        }
        if ($this->tableExists($pdo, 'biomie_permissions')
            && $this->tableExists($pdo, 'biomie_role_permissions')
            && $this->tableExists($pdo, 'biomie_user_roles')
        ) {
            $pdo->exec(<<<'SQL'
                CREATE TRIGGER trg_permissions_permissions_au
                AFTER UPDATE ON biomie_permissions
                FOR EACH ROW
                UPDATE biomie_users u
                  JOIN biomie_user_roles ur ON ur.user_id = u.id
                  JOIN biomie_role_permissions rp ON rp.role_id = ur.role_id
                   SET u.permissions_version = u.permissions_version + 1
                 WHERE rp.permission_id = NEW.id
                   AND (
                       NOT (OLD.permission_key <=> NEW.permission_key)
                       OR NOT (OLD.is_active <=> NEW.is_active)
                   )
            SQL);
        }
    }

    private function createUserTargetTriggers(PDO $pdo, string $table, string $prefix): void
    {
        $quotedTable = '`' . str_replace('`', '``', $table) . '`';
        $pdo->exec(
            "CREATE TRIGGER trg_{$prefix}_ai
             AFTER INSERT ON {$quotedTable}
             FOR EACH ROW
             UPDATE biomie_users
                SET permissions_version = permissions_version + 1
              WHERE id = NEW.user_id"
        );
        $pdo->exec(
            "CREATE TRIGGER trg_{$prefix}_ad
             AFTER DELETE ON {$quotedTable}
             FOR EACH ROW
             UPDATE biomie_users
                SET permissions_version = permissions_version + 1
              WHERE id = OLD.user_id"
        );
        $pdo->exec(
            "CREATE TRIGGER trg_{$prefix}_au
             AFTER UPDATE ON {$quotedTable}
             FOR EACH ROW
             BEGIN
                 UPDATE biomie_users
                    SET permissions_version = permissions_version + 1
                  WHERE id = OLD.user_id;
                 IF NEW.user_id <> OLD.user_id THEN
                     UPDATE biomie_users
                        SET permissions_version = permissions_version + 1
                      WHERE id = NEW.user_id;
                 END IF;
             END"
        );
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
        );
        $stmt->execute(['table_name' => $table, 'column_name' => $column]);

        return (int) $stmt->fetchColumn() > 0;
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
};
