<?php

declare(strict_types=1);

namespace BIomie\AdminConsole\Commands;

use BIomie\AdminConsole\AdminCommandContext;
use BIomie\AdminConsole\AdminCommandInterface;
use BIomie\AdminConsole\AdminCommandResult;
use PDO;
use Throwable;

final class RbacStatusCommand implements AdminCommandInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function name(): string { return 'rbac:status'; }
    public function description(): string { return 'Exibe roles, permissoes e usuario atual do RBAC.'; }
    public function group(): string { return 'rbac'; }
    public function permission(): string { return 'admin.console'; }
    public function isDestructive(): bool { return false; }
    public function requiresConfirmation(): bool { return false; }

    public function validateArgs(array $args): ?string { return null; }

    public function run(AdminCommandContext $context, array $args = []): AdminCommandResult
    {
        $lines = [];
        $tables = [];
        $warnings = [];

        $lines[] = "Usuario atual: {$context->username} (ID {$context->userId})";
        $lines[] = "Role: {$context->role}";
        $lines[] = 'Permissoes: ' . (count($context->permissions) > 0
            ? implode(', ', $context->permissions)
            : 'nenhuma registrada');

        // Roles table
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
            );
            $stmt->execute([':t' => 'biomie_roles']);
            $rolesTableExists = (int) $stmt->fetchColumn() > 0;

            if ($rolesTableExists) {
                $roles = $this->pdo->query(
                    'SELECT role_key, name, is_active FROM biomie_roles ORDER BY sort_order ASC'
                )?->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $roleRows = array_map(
                    static fn (array $r): array => [
                        (string) ($r['role_key'] ?? ''),
                        (string) ($r['name'] ?? ''),
                        ((int) ($r['is_active'] ?? 0)) === 1 ? 'ativo' : 'inativo',
                    ],
                    $roles,
                );

                if ($roleRows !== []) {
                    $tables[] = [
                        'title'   => 'Roles',
                        'headers' => ['Chave', 'Nome', 'Status'],
                        'rows'    => $roleRows,
                    ];
                }
            } else {
                $warnings[] = 'Tabela biomie_roles nao existe.';
            }
        } catch (Throwable) {
            $warnings[] = 'Nao foi possivel consultar tabela biomie_roles.';
        }

        // Permissions table
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
            );
            $stmt->execute([':t' => 'biomie_permissions']);
            $permsTableExists = (int) $stmt->fetchColumn() > 0;

            if ($permsTableExists) {
                $perms = $this->pdo->query(
                    'SELECT permission_key, name, group_key, is_active FROM biomie_permissions ORDER BY group_key, permission_key'
                )?->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $permRows = array_map(
                    static fn (array $p): array => [
                        (string) ($p['permission_key'] ?? ''),
                        (string) ($p['name'] ?? ''),
                        (string) ($p['group_key'] ?? ''),
                        ((int) ($p['is_active'] ?? 0)) === 1 ? 'ativo' : 'inativo',
                    ],
                    $perms,
                );

                if ($permRows !== []) {
                    $tables[] = [
                        'title'   => 'Permissoes',
                        'headers' => ['Chave', 'Nome', 'Grupo', 'Status'],
                        'rows'    => $permRows,
                    ];
                }
            } else {
                $warnings[] = 'Tabela biomie_permissions nao existe.';
            }
        } catch (Throwable) {
            $warnings[] = 'Nao foi possivel consultar tabela biomie_permissions.';
        }

        // Users without role
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
            );
            $stmt->execute([':t' => 'biomie_user_roles']);
            $userRolesExists = (int) $stmt->fetchColumn() > 0;

            if ($userRolesExists) {
                $noRoleCount = (int) $this->pdo->query(
                    'SELECT COUNT(*) FROM biomie_users u LEFT JOIN biomie_user_roles ur ON ur.user_id = u.id WHERE ur.user_id IS NULL AND u.is_active = 1'
                )->fetchColumn();

                $lines[] = "Usuarios ativos sem perfil RBAC: {$noRoleCount}";
                if ($noRoleCount > 0) {
                    $warnings[] = "{$noRoleCount} usuario(s) ativo(s) sem perfil RBAC vinculado.";
                }
            }
        } catch (Throwable) {
            // silently skip if tables not ready
        }

        $metrics = [
            'user_id'           => $context->userId,
            'permissions_count' => count($context->permissions),
        ];

        if ($warnings !== []) {
            return AdminCommandResult::warning(
                'Status RBAC',
                'RBAC com alertas.',
                outputLines: $lines,
                warnings: $warnings,
            );
        }

        return AdminCommandResult::ok(
            'Status RBAC',
            sprintf('RBAC operacional. %d permissao(oes) para o usuario atual.', count($context->permissions)),
            outputLines: $lines,
            metrics: $metrics,
            tables: $tables,
        );
    }
}
