<?php

declare(strict_types=1);

namespace BIomie\Admin;

use BIomie\Acl\Roles;
use PDO;
use RuntimeException;
use Throwable;

final class RbacService
{
    /**
     * @var array<string,array{name:string,description:string,sort_order:int}>
     */
    public const DEFAULT_ROLES = [
        'admin' => [
            'name' => 'Administrador',
            'description' => 'Acesso total a administracao, dados, sincronizacao e migrations.',
            'sort_order' => 10,
        ],
        'manager' => [
            'name' => 'Gestor',
            'description' => 'Gestao operacional com leitura ampla e logs administrativos.',
            'sort_order' => 20,
        ],
        'analyst' => [
            'name' => 'Analista',
            'description' => 'Analise comercial, geografica, financeira e fiscal.',
            'sort_order' => 30,
        ],
        'viewer' => [
            'name' => 'Visualizador',
            'description' => 'Leitura basica do dashboard e geografia.',
            'sort_order' => 40,
        ],
    ];

    /**
     * @var array<string,array{name:string,description:string,group_key:string}>
     */
    public const DEFAULT_PERMISSIONS = [
        'sales.view' => [
            'name' => 'Ver comercial',
            'description' => 'Acessar dashboards comerciais, vendedores, pedidos e produtos.',
            'group_key' => 'comercial',
        ],
        'sales.sync' => [
            'name' => 'Sincronizar comercial',
            'description' => 'Executar atualizacao comercial e materializacoes de vendas.',
            'group_key' => 'comercial',
        ],
        'sales.export' => [
            'name' => 'Exportar comercial',
            'description' => 'Exportar dados comerciais.',
            'group_key' => 'comercial',
        ],
        'dashboard.view' => [
            'name' => 'Ver dashboard',
            'description' => 'Acessar a visao comercial principal.',
            'group_key' => 'comercial',
        ],
        'commercial.view' => [
            'name' => 'Ver comercial legado',
            'description' => 'Alias legado para acesso comercial.',
            'group_key' => 'comercial',
        ],
        'geo.view' => [
            'name' => 'Ver geografia',
            'description' => 'Acessar analises geograficas.',
            'group_key' => 'comercial',
        ],
        'geo.materialize' => [
            'name' => 'Materializar geografia',
            'description' => 'Executar materializacoes geograficas.',
            'group_key' => 'operacao',
        ],
        'finance.view' => [
            'name' => 'Ver financeiro',
            'description' => 'Acessar recebiveis e previsao financeira.',
            'group_key' => 'operacao',
        ],
        'financial.view' => [
            'name' => 'Ver financeiro legado',
            'description' => 'Alias legado para acesso financeiro.',
            'group_key' => 'operacao',
        ],
        'finance.sync' => [
            'name' => 'Sincronizar financeiro',
            'description' => 'Executar atualizacao financeira e titulos Omie.',
            'group_key' => 'financeiro',
        ],
        'finance.export' => [
            'name' => 'Exportar financeiro',
            'description' => 'Exportar dados financeiros.',
            'group_key' => 'financeiro',
        ],
        'fiscal.view' => [
            'name' => 'Ver fiscal',
            'description' => 'Acessar notas, reconciliacao e indicadores fiscais.',
            'group_key' => 'fiscal',
        ],
        'fiscal.sync' => [
            'name' => 'Sincronizar fiscal',
            'description' => 'Executar rotinas fiscais e reconciliacoes.',
            'group_key' => 'fiscal',
        ],
        'fiscal.export' => [
            'name' => 'Exportar fiscal',
            'description' => 'Exportar dados fiscais.',
            'group_key' => 'fiscal',
        ],
        'nf.view' => [
            'name' => 'Ver notas fiscais',
            'description' => 'Acessar notas fiscais e mix faturado.',
            'group_key' => 'operacao',
        ],
        'reconciliation.view' => [
            'name' => 'Ver reconciliacao legado',
            'description' => 'Alias legado para acesso fiscal/reconciliacao.',
            'group_key' => 'fiscal',
        ],
        'omie.view' => [
            'name' => 'Ver status Omie',
            'description' => 'Acessar status local da integracao Omie.',
            'group_key' => 'omie',
        ],
        'omie.sync' => [
            'name' => 'Sincronizar Omie',
            'description' => 'Executar sincronizacoes com Omie.',
            'group_key' => 'operacao',
        ],
        'jobs.view' => [
            'name' => 'Ver jobs',
            'description' => 'Acessar status de filas, syncs e rotinas.',
            'group_key' => 'operacao',
        ],
        'jobs.run' => [
            'name' => 'Executar jobs',
            'description' => 'Executar materializacoes, filas e rotinas operacionais.',
            'group_key' => 'operacao',
        ],
        'logs.view' => [
            'name' => 'Ver logs',
            'description' => 'Consultar logs administrativos e observabilidade.',
            'group_key' => 'sistema',
        ],
        'admin.view' => [
            'name' => 'Ver administracao',
            'description' => 'Acessar area administrativa.',
            'group_key' => 'sistema',
        ],
        'admin.manage' => [
            'name' => 'Gerenciar administracao',
            'description' => 'Gerenciar usuarios, permissoes e configuracoes criticas.',
            'group_key' => 'sistema',
        ],
        'settings.view' => [
            'name' => 'Ver configuracoes',
            'description' => 'Acessar configuracoes do sistema.',
            'group_key' => 'sistema',
        ],
        'settings.manage' => [
            'name' => 'Gerenciar configuracoes',
            'description' => 'Alterar configuracoes, politicas e rotinas internas.',
            'group_key' => 'sistema',
        ],
        'documents.full' => [
            'name' => 'Ver documentos completos',
            'description' => 'Visualizar CPF/CNPJ sem mascaramento.',
            'group_key' => 'dados',
        ],
        'export' => [
            'name' => 'Exportar dados legado',
            'description' => 'Permissao legada generica de exportacao.',
            'group_key' => 'dados',
        ],
        'admin.users' => [
            'name' => 'Administrar usuarios',
            'description' => 'Criar, editar, ativar e redefinir usuarios.',
            'group_key' => 'sistema',
        ],
        'admin.migrations' => [
            'name' => 'Executar migrations',
            'description' => 'Listar e executar migrations do sistema.',
            'group_key' => 'sistema',
        ],
        'admin.logs' => [
            'name' => 'Ver logs administrativos',
            'description' => 'Consultar trilhas administrativas e diagnosticos.',
            'group_key' => 'sistema',
        ],
        'admin.console' => [
            'name' => 'Console administrativo',
            'description' => 'Executar comandos internos no console administrativo auditado.',
            'group_key' => 'sistema',
        ],
    ];

    /** @var array<string,list<string>> */
    public const DEFAULT_MATRIX = [
        'admin' => [
            'sales.view',
            'sales.sync',
            'sales.export',
            'dashboard.view',
            'commercial.view',
            'geo.view',
            'geo.materialize',
            'finance.view',
            'financial.view',
            'finance.sync',
            'finance.export',
            'fiscal.view',
            'fiscal.sync',
            'fiscal.export',
            'nf.view',
            'reconciliation.view',
            'omie.view',
            'omie.sync',
            'jobs.view',
            'jobs.run',
            'logs.view',
            'admin.view',
            'admin.manage',
            'settings.view',
            'settings.manage',
            'documents.full',
            'export',
            'admin.users',
            'admin.migrations',
            'admin.logs',
            'admin.console',
        ],
        'manager' => [
            'sales.view',
            'sales.sync',
            'sales.export',
            'dashboard.view',
            'commercial.view',
            'geo.view',
            'geo.materialize',
            'finance.view',
            'financial.view',
            'finance.export',
            'fiscal.view',
            'fiscal.export',
            'nf.view',
            'reconciliation.view',
            'omie.view',
            'jobs.view',
            'logs.view',
            'documents.full',
            'export',
            'admin.logs',
        ],
        'analyst' => [
            'sales.view',
            'dashboard.view',
            'commercial.view',
            'geo.view',
            'finance.view',
            'financial.view',
            'fiscal.view',
            'nf.view',
            'reconciliation.view',
            'omie.view',
            'jobs.view',
        ],
        'viewer' => [
            'sales.view',
            'dashboard.view',
            'commercial.view',
            'geo.view',
        ],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function tablesReady(): bool
    {
        foreach (['biomie_roles', 'biomie_permissions', 'biomie_role_permissions', 'biomie_user_roles'] as $table) {
            if (!$this->tableExists($table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listRoles(): array
    {
        if (!$this->tablesReady()) {
            return $this->defaultRoleRows();
        }

        $stmt = $this->pdo->query(<<<'SQL'
SELECT id, role_key, name, description, is_system, is_active, sort_order, created_at, updated_at
FROM biomie_roles
ORDER BY sort_order ASC, name ASC
SQL);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listPermissions(): array
    {
        if (!$this->tablesReady()) {
            return $this->defaultPermissionRows();
        }

        $stmt = $this->pdo->query(<<<'SQL'
SELECT id, permission_key, name, description, group_key, is_active, created_at, updated_at
FROM biomie_permissions
ORDER BY group_key ASC, permission_key ASC
SQL);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string,list<string>>
     */
    public function permissionMatrix(): array
    {
        if (!$this->tablesReady()) {
            return self::DEFAULT_MATRIX;
        }

        $stmt = $this->pdo->query(<<<'SQL'
SELECT r.role_key, p.permission_key
FROM biomie_role_permissions rp
JOIN biomie_roles r ON r.id = rp.role_id
JOIN biomie_permissions p ON p.id = rp.permission_id
WHERE r.is_active = 1 AND p.is_active = 1
ORDER BY r.sort_order ASC, p.permission_key ASC
SQL);

        $matrix = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $roleKey = (string) $row['role_key'];
            $matrix[$roleKey] ??= [];
            $matrix[$roleKey][] = (string) $row['permission_key'];
        }

        foreach (array_keys(self::DEFAULT_ROLES) as $roleKey) {
            $matrix[$roleKey] ??= [];
        }

        return $matrix;
    }

    /**
     * @return list<string>
     */
    public function userPermissions(int $userId, ?string $legacyRole = null): array
    {
        if (!$this->tablesReady()) {
            return $this->fallbackPermissions($legacyRole ?? 'viewer');
        }

        $stmt = $this->pdo->prepare(<<<'SQL'
SELECT DISTINCT p.permission_key
FROM biomie_user_roles ur
JOIN biomie_roles r ON r.id = ur.role_id AND r.is_active = 1
JOIN biomie_role_permissions rp ON rp.role_id = r.id
JOIN biomie_permissions p ON p.id = rp.permission_id AND p.is_active = 1
WHERE ur.user_id = :user_id
ORDER BY p.permission_key ASC
SQL);
        $stmt->execute([':user_id' => $userId]);
        $permissions = array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));

        if ($permissions === [] && $legacyRole !== null) {
            return $this->fallbackPermissions($legacyRole);
        }

        return $permissions;
    }

    public function userHasPermission(int $userId, string $permission, ?string $legacyRole = null): bool
    {
        return $this->userHasAnyPermission($userId, [$permission], $legacyRole);
    }

    /**
     * @param list<string> $permissions
     */
    public function userHasAnyPermission(int $userId, array $permissions, ?string $legacyRole = null): bool
    {
        $owned = $this->userPermissions($userId, $legacyRole);
        foreach ($this->permissionCandidates($permissions) as $permission) {
            if (in_array($permission, $owned, true)) {
                return true;
            }
        }

        return false;
    }

    public function primaryRoleForUser(int $userId, ?string $legacyRole = null): string
    {
        if ($this->tablesReady()) {
            $stmt = $this->pdo->prepare(<<<'SQL'
SELECT r.role_key
FROM biomie_user_roles ur
JOIN biomie_roles r ON r.id = ur.role_id
WHERE ur.user_id = :user_id
ORDER BY r.sort_order ASC
LIMIT 1
SQL);
            $stmt->execute([':user_id' => $userId]);
            $role = $stmt->fetchColumn();
            if (is_string($role) && $role !== '') {
                return Roles::resolve($role);
            }
        }

        return Roles::resolve((string) ($legacyRole ?? 'viewer'));
    }

    public function assignSingleRole(int $userId, string $roleKey, ?int $actorId = null): void
    {
        $roleKey = Roles::resolve($roleKey);
        if (!isset(self::DEFAULT_ROLES[$roleKey])) {
            throw new RuntimeException('Perfil invalido.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('UPDATE biomie_users SET role = :role, updated_by = :actor WHERE id = :id');
            $stmt->execute([
                ':id' => $userId,
                ':role' => $roleKey,
                ':actor' => $actorId,
            ]);

            if ($this->tablesReady()) {
                $roleId = $this->roleId($roleKey);
                $this->pdo->prepare('DELETE FROM biomie_user_roles WHERE user_id = :user_id')
                    ->execute([':user_id' => $userId]);
                $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO biomie_user_roles (user_id, role_id, assigned_by)
VALUES (:user_id, :role_id, :assigned_by)
SQL);
                $insert->execute([
                    ':user_id' => $userId,
                    ':role_id' => $roleId,
                    ':assigned_by' => $actorId,
                ]);
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * @param list<string> $permissionKeys
     */
    public function updateRolePermissions(string $roleKey, array $permissionKeys): void
    {
        if (!$this->tablesReady()) {
            throw new RuntimeException('Execute as migrations de RBAC antes de alterar permissoes.');
        }

        $roleKey = Roles::resolve($roleKey);
        $permissionKeys = $roleKey === 'admin'
            ? array_keys(self::DEFAULT_PERMISSIONS)
            : $this->validPermissionKeys($permissionKeys);

        $roleId = $this->roleId($roleKey);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM biomie_role_permissions WHERE role_id = :role_id')
                ->execute([':role_id' => $roleId]);

            if ($permissionKeys !== []) {
                $permissionIds = $this->permissionIds($permissionKeys);
                $insert = $this->pdo->prepare(<<<'SQL'
INSERT IGNORE INTO biomie_role_permissions (role_id, permission_id)
VALUES (:role_id, :permission_id)
SQL);
                foreach ($permissionIds as $permissionId) {
                    $insert->execute([
                        ':role_id' => $roleId,
                        ':permission_id' => $permissionId,
                    ]);
                }
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function countActiveAdmins(?int $excludeUserId = null): int
    {
        if ($this->tablesReady()) {
            $sql = <<<'SQL'
SELECT COUNT(DISTINCT u.id)
FROM biomie_users u
LEFT JOIN biomie_user_roles ur ON ur.user_id = u.id
LEFT JOIN biomie_roles r ON r.id = ur.role_id
WHERE u.is_active = 1
  AND (LOWER(u.role) = 'admin' OR r.role_key = 'admin')
SQL;
            if ($excludeUserId !== null) {
                $sql .= ' AND u.id <> :exclude_id';
            }
            $stmt = $this->pdo->prepare($sql);
            if ($excludeUserId !== null) {
                $stmt->bindValue(':exclude_id', $excludeUserId, PDO::PARAM_INT);
            }
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        }

        $sql = "SELECT COUNT(*) FROM biomie_users WHERE is_active = 1 AND LOWER(role) = 'admin'";
        if ($excludeUserId !== null) {
            $sql .= ' AND id <> :exclude_id';
        }
        $stmt = $this->pdo->prepare($sql);
        if ($excludeUserId !== null) {
            $stmt->bindValue(':exclude_id', $excludeUserId, PDO::PARAM_INT);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function syncMissingUserRoles(?int $actorId = null): int
    {
        if (!$this->tablesReady()) {
            return 0;
        }

        $stmt = $this->pdo->prepare(<<<'SQL'
SELECT u.id, u.role
FROM biomie_users u
WHERE NOT EXISTS (SELECT 1 FROM biomie_user_roles ur WHERE ur.user_id = u.id)
SQL);
        $stmt->execute();

        $count = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $this->assignSingleRole((int) $row['id'], (string) ($row['role'] ?? 'viewer'), $actorId);
            $count++;
        }

        return $count;
    }

    /**
     * @return array<string,mixed>
     */
    public function diagnostics(): array
    {
        $tables = [];
        foreach (['biomie_roles', 'biomie_permissions', 'biomie_role_permissions', 'biomie_user_roles', 'biomie_admin_logs'] as $table) {
            $tables[$table] = [
                'exists' => $this->tableExists($table),
                'rows' => $this->tableExists($table) ? $this->countRows($table) : null,
            ];
        }

        return [
            'tables' => $tables,
            'users_without_role' => $this->usersWithoutRole(),
            'permissions_without_role' => $this->permissionsWithoutRole(),
            'latest_migration' => $this->latestMigration(),
            'charset' => $this->charset(),
        ];
    }

    /**
     * @return list<string>
     */
    private function fallbackPermissions(string $role): array
    {
        $role = Roles::resolve($role);
        return self::DEFAULT_MATRIX[$role] ?? self::DEFAULT_MATRIX['viewer'];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function defaultRoleRows(): array
    {
        $rows = [];
        foreach (self::DEFAULT_ROLES as $roleKey => $role) {
            $rows[] = [
                'id' => null,
                'role_key' => $roleKey,
                'name' => $role['name'],
                'description' => $role['description'],
                'is_system' => 1,
                'is_active' => 1,
                'sort_order' => $role['sort_order'],
            ];
        }
        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function defaultPermissionRows(): array
    {
        $rows = [];
        foreach (self::DEFAULT_PERMISSIONS as $permissionKey => $permission) {
            $rows[] = [
                'id' => null,
                'permission_key' => $permissionKey,
                'name' => $permission['name'],
                'description' => $permission['description'],
                'group_key' => $permission['group_key'],
                'is_active' => 1,
            ];
        }
        return $rows;
    }

    private function roleId(string $roleKey): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM biomie_roles WHERE role_key = :role_key LIMIT 1');
        $stmt->execute([':role_key' => $roleKey]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new RuntimeException('Perfil RBAC nao encontrado.');
        }
        return (int) $id;
    }

    /**
     * @param list<string> $permissionKeys
     * @return array<int,int>
     */
    private function permissionIds(array $permissionKeys): array
    {
        $permissionKeys = $this->validPermissionKeys($permissionKeys);
        if ($permissionKeys === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($permissionKeys), '?'));
        $stmt = $this->pdo->prepare("SELECT id FROM biomie_permissions WHERE permission_key IN ($placeholders)");
        $stmt->execute($permissionKeys);

        return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
    }

    /**
     * @param list<string> $permissionKeys
     * @return list<string>
     */
    private function validPermissionKeys(array $permissionKeys): array
    {
        $valid = array_keys(self::DEFAULT_PERMISSIONS);
        return array_values(array_intersect(array_values(array_unique(array_map('strval', $permissionKeys))), $valid));
    }

    /**
     * @param list<string> $permissionKeys
     * @return list<string>
     */
    private function permissionCandidates(array $permissionKeys): array
    {
        $aliases = [
            'dashboard.view' => 'sales.view',
            'commercial.view' => 'sales.view',
            'financial.view' => 'finance.view',
            'nf.view' => 'fiscal.view',
            'reconciliation.view' => 'fiscal.view',
            'observability.view' => 'logs.view',
            'admin.logs' => 'logs.view',
            'admin.users' => 'admin.manage',
            'users.manage' => 'admin.manage',
            'admin.migrations' => 'settings.manage',
            'admin.console' => 'settings.manage',
        ];
        $equivalents = [
            'sales.view' => ['dashboard.view', 'commercial.view'],
            'finance.view' => ['financial.view'],
            'fiscal.view' => ['nf.view', 'reconciliation.view'],
            'logs.view' => ['admin.logs', 'observability.view'],
            'admin.view' => ['admin.users', 'admin.logs', 'admin.migrations', 'admin.console'],
            'admin.manage' => ['admin.users', 'users.manage'],
            'settings.manage' => ['admin.console', 'admin.migrations'],
            'jobs.run' => ['admin.console', 'admin.migrations'],
            'sales.export' => ['export'],
            'finance.export' => ['export'],
            'fiscal.export' => ['export'],
        ];

        $candidates = [];
        foreach ($permissionKeys as $permissionKey) {
            $raw = strtolower(trim((string) $permissionKey));
            $normalized = $aliases[$raw] ?? $raw;
            $candidates[] = $raw;
            $candidates[] = $normalized;
            foreach ($equivalents[$normalized] ?? [] as $equivalent) {
                $candidates[] = $equivalent;
            }
        }

        return array_values(array_unique(array_filter($candidates, static fn (string $value): bool => $value !== '')));
    }

    private function tableExists(string $table): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
            );
            $stmt->execute([':table' => $table]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function countRows(string $table): int
    {
        try {
            return (int) $this->pdo->query(sprintf('SELECT COUNT(*) FROM `%s`', str_replace('`', '``', $table)))->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    private function usersWithoutRole(): int
    {
        if (!$this->tablesReady()) {
            return 0;
        }

        $stmt = $this->pdo->query(<<<'SQL'
SELECT COUNT(*)
FROM biomie_users u
WHERE NOT EXISTS (SELECT 1 FROM biomie_user_roles ur WHERE ur.user_id = u.id)
SQL);

        return (int) $stmt->fetchColumn();
    }

    private function permissionsWithoutRole(): int
    {
        if (!$this->tablesReady()) {
            return 0;
        }

        $stmt = $this->pdo->query(<<<'SQL'
SELECT COUNT(*)
FROM biomie_permissions p
WHERE p.is_active = 1
  AND NOT EXISTS (SELECT 1 FROM biomie_role_permissions rp WHERE rp.permission_id = p.id)
SQL);

        return (int) $stmt->fetchColumn();
    }

    private function latestMigration(): ?array
    {
        if (!$this->tableExists('biomie_schema_migrations')) {
            return null;
        }

        try {
            $stmt = $this->pdo->query(<<<'SQL'
SELECT schema_version, description, status, executed_at
FROM biomie_schema_migrations
ORDER BY executed_at DESC, id DESC
LIMIT 1
SQL);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function charset(): string
    {
        try {
            $stmt = $this->pdo->query('SELECT @@character_set_database AS charset_name, @@collation_database AS collation_name');
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            return trim((string) ($row['charset_name'] ?? '') . ' / ' . (string) ($row['collation_name'] ?? ''), ' /');
        } catch (Throwable) {
            return '-';
        }
    }

    /**
     * Idempotently ensures that all DEFAULT_PERMISSIONS exist in the DB
     * and that the admin role has all its DEFAULT_MATRIX permissions assigned.
     * Safe to call on every admin page load — uses INSERT IGNORE.
     */
    public function seedMissingDefaultPermissions(): void
    {
        if (!$this->tablesReady()) {
            return;
        }

        try {
            $upsertPerm = $this->pdo->prepare(<<<'SQL'
INSERT IGNORE INTO biomie_permissions (permission_key, name, description, group_key, is_active, created_at, updated_at)
VALUES (:key, :name, :description, :group_key, 1, NOW(), NOW())
SQL);

            foreach (self::DEFAULT_PERMISSIONS as $key => $def) {
                $upsertPerm->execute([
                    ':key'         => $key,
                    ':name'        => $def['name'],
                    ':description' => $def['description'],
                    ':group_key'   => $def['group_key'],
                ]);
            }

            // Ensure admin role has all its default permissions
            $adminPermissions = self::DEFAULT_MATRIX['admin'] ?? [];
            if ($adminPermissions === []) {
                return;
            }

            $adminRoleStmt = $this->pdo->prepare('SELECT id FROM biomie_roles WHERE role_key = :key LIMIT 1');
            $adminRoleStmt->execute([':key' => 'admin']);
            $adminRoleId = $adminRoleStmt->fetchColumn();
            if (!$adminRoleId) {
                return;
            }

            $linkStmt = $this->pdo->prepare(<<<'SQL'
INSERT IGNORE INTO biomie_role_permissions (role_id, permission_id)
SELECT :role_id, id FROM biomie_permissions WHERE permission_key = :perm_key
SQL);

            foreach ($adminPermissions as $permKey) {
                $linkStmt->execute([':role_id' => (int) $adminRoleId, ':perm_key' => $permKey]);
            }
        } catch (Throwable) {
            // Non-fatal — permission sync is best-effort
        }
    }
}
