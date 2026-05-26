<?php

declare(strict_types=1);

use BIomie\Acl\Roles;
use BIomie\Admin\AdminGuard;
use BIomie\Admin\AdminLogService;
use BIomie\Admin\CsrfService;
use BIomie\Admin\Flash;
use BIomie\Admin\RbacService;
use BIomie\Admin\SystemHealthService;
use BIomie\AdminConsole\AdminCommandContext;
use BIomie\AdminConsole\AdminCommandRegistry;
use BIomie\Auth;
use BIomie\Database;
use BIomie\Migrations\MigrationFailedException;
use BIomie\Migrations\MigrationRunner;
use BIomie\PageRenderer;
use BIomie\RequestContext;

require __DIR__ . '/app/page_bootstrap.php';

$pdo = Database::connect($config, 'db');
$repo = Auth::repository();
$csrfService = new CsrfService();
$rbac = new RbacService($pdo);
$adminLogs = new AdminLogService($pdo, $logger);
$guard = new AdminGuard($rbac, $adminLogs);

// Idempotently ensure admin.console permission exists in the RBAC DB tables
$rbac->seedMissingDefaultPermissions();

$guard->requireAny(['admin.view', 'admin.manage', 'logs.view', 'settings.manage']);

$currentUser = Auth::currentUser();
$currentUserId = (int) ($currentUser['id'] ?? 0);

$tabByAction = [
    'create_user' => 'users',
    'update_user' => 'users',
    'reset_password' => 'users',
    'update_role_permissions' => 'permissions',
    'sync_user_roles' => 'profiles',
    'run_migrations' => 'migrations',
    'run_single_migration' => 'migrations',
    'run_console_command' => 'console',
];

$friendlyDbMessage = static function (Throwable $exception, string $fallback): string {
    $message = strtolower($exception->getMessage());
    if (str_contains($message, 'uniq_biomie_users_username') || str_contains($message, 'for key \'username\'')) {
        return 'Ja existe um usuario com esse login.';
    }
    if (str_contains($message, 'uniq_biomie_users_email') || str_contains($message, 'for key \'email\'')) {
        return 'Ja existe um usuario com esse e-mail.';
    }

    return $fallback;
};

$redirect = static function (string $tab): never {
    header('Location: admin.php?tab=' . rawurlencode($tab));
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $tab = $tabByAction[$action] ?? 'users';

    $csrfError = $csrfService->validatePost($adminLogs);
    if ($csrfError !== null) {
        Flash::set('error', $csrfError);
        $redirect($tab);
    }

    try {
        if ($action === 'create_user') {
            if (($message = $guard->assert('admin.manage')) !== null) {
                Flash::set('error', $message);
                $redirect($tab);
            }

            $username = trim((string) ($_POST['username'] ?? ''));
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $role = Roles::resolve((string) ($_POST['role'] ?? 'viewer'));
            $isActive = isset($_POST['is_active']);
            $password = (string) ($_POST['password'] ?? '');

            $validation = Auth::validateUserPayload($username, $fullName, $email !== '' ? $email : null, $password, $role);
            if (($validation['ok'] ?? false) === false) {
                Flash::set('error', (string) $validation['message']);
                $redirect($tab);
            }

            $userId = $repo->createUser($username, $fullName, $email !== '' ? $email : null, $password, $role, $isActive, $currentUserId ?: null);
            $rbac->assignSingleRole($userId, $role, $currentUserId ?: null);
            $adminLogs->log('user_created', $userId, [
                'username' => $username,
                'role' => $role,
                'is_active' => $isActive,
            ]);

            Flash::set('success', 'Usuario criado com sucesso.');
            $redirect($tab);
        }

        if ($action === 'update_user') {
            if (($message = $guard->assert('admin.manage')) !== null) {
                Flash::set('error', $message);
                $redirect($tab);
            }

            $userId = (int) ($_POST['user_id'] ?? 0);
            $before = $repo->findUserById($userId);
            if ($before === null) {
                Flash::set('error', 'Usuario nao encontrado.');
                $redirect($tab);
            }

            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $role = Roles::resolve((string) ($_POST['role'] ?? 'viewer'));
            $isActive = isset($_POST['is_active']);

            $validation = Auth::validateUserPayload('tempuser', $fullName, $email !== '' ? $email : null, null, $role, false);
            if (($validation['ok'] ?? false) === false) {
                Flash::set('error', (string) $validation['message']);
                $redirect($tab);
            }

            $wasAdmin = $rbac->primaryRoleForUser($userId, (string) ($before['role'] ?? 'viewer')) === 'admin';
            $willAdmin = $role === 'admin';
            if ($wasAdmin && (!$willAdmin || !$isActive) && $rbac->countActiveAdmins($userId) < 1) {
                Flash::set('error', 'Nao e possivel remover ou desativar o unico administrador ativo.');
                $redirect($tab);
            }

            if ($currentUserId === $userId && !$isActive) {
                Flash::set('error', 'Voce nao pode desativar o proprio usuario.');
                $redirect($tab);
            }

            $repo->updateUser($userId, $fullName, $email !== '' ? $email : null, $role, $isActive, $currentUserId ?: null);
            $rbac->assignSingleRole($userId, $role, $currentUserId ?: null);

            $adminLogs->log('user_updated', $userId, [
                'role' => $role,
                'is_active' => $isActive,
            ]);
            if (Roles::resolve((string) ($before['role'] ?? 'viewer')) !== $role) {
                $adminLogs->log('profile_changed', $userId, [
                    'from' => Roles::resolve((string) ($before['role'] ?? 'viewer')),
                    'to' => $role,
                ]);
            }
            if ((int) ($before['is_active'] ?? 0) !== ($isActive ? 1 : 0)) {
                $adminLogs->log($isActive ? 'user_activated' : 'user_deactivated', $userId);
            }

            Flash::set('success', 'Usuario atualizado com sucesso.');
            $redirect($tab);
        }

        if ($action === 'reset_password') {
            if (($message = $guard->assert('admin.manage')) !== null) {
                Flash::set('error', $message);
                $redirect($tab);
            }

            $userId = (int) ($_POST['user_id'] ?? 0);
            $result = Auth::resetUserPassword($userId, (string) ($_POST['password'] ?? ''));
            if (($result['ok'] ?? false) === true) {
                $adminLogs->log('password_reset', $userId);
                Flash::set('success', 'Senha redefinida com sucesso.');
            } else {
                Flash::set('error', (string) ($result['message'] ?? 'Nao foi possivel redefinir a senha.'));
            }
            $redirect($tab);
        }

        if ($action === 'update_role_permissions') {
            if (($message = $guard->assert('admin.manage')) !== null) {
                Flash::set('error', $message);
                $redirect($tab);
            }

            $role = Roles::resolve((string) ($_POST['role_key'] ?? 'viewer'));
            $permissions = array_values(array_filter(array_map('strval', (array) ($_POST['permission_keys'] ?? []))));
            $rbac->updateRolePermissions($role, $permissions);
            $adminLogs->log('permission_changed', null, [
                'role' => $role,
                'permissions' => $role === 'admin' ? array_keys(RbacService::DEFAULT_PERMISSIONS) : $permissions,
            ]);

            Flash::set('success', 'Permissoes atualizadas com sucesso.');
            $redirect($tab);
        }

        if ($action === 'sync_user_roles') {
            if (($message = $guard->assert('admin.manage')) !== null) {
                Flash::set('error', $message);
                $redirect($tab);
            }

            $synced = $rbac->syncMissingUserRoles($currentUserId ?: null);
            $adminLogs->log('profile_changed', null, ['synced_users' => $synced]);
            Flash::set('success', $synced > 0 ? "Perfis sincronizados para {$synced} usuario(s)." : 'Perfis ja estavam sincronizados.');
            $redirect($tab);
        }

        if ($action === 'run_migrations') {
            if (($message = $guard->assert('settings.manage')) !== null) {
                Flash::set('error', $message);
                $redirect($tab);
            }

            $runner = new MigrationRunner($config, $logger);
            $adminLogs->log('migration_run_requested', null, ['source' => 'admin_web']);

            try {
                $result = $runner->migrate();
                $applied = $result['applied'] ?? [];
                $skipped = $result['skipped'] ?? [];

                foreach ($applied as $migrationResult) {
                    $adminLogs->log('migration_executed', null, [
                        'version' => $migrationResult['version'] ?? '',
                        'description' => $migrationResult['description'] ?? '',
                        'connection' => $migrationResult['connection'] ?? '',
                        'execution_ms' => $migrationResult['execution_ms'] ?? 0,
                    ]);
                }

                $appliedCount = count($applied);
                $adminLogs->log('migration_run_completed', null, [
                    'applied' => $appliedCount,
                    'skipped' => count($skipped),
                ]);

                Flash::set('success', $appliedCount > 0
                    ? "Migrations executadas com sucesso: {$appliedCount}."
                    : 'Nenhuma migration pendente encontrada.');
            } catch (MigrationFailedException $migEx) {
                $sanitized = preg_replace('/SQLSTATE\[\w+\]:[^.]+/i', '[erro de banco]', $migEx->getMessage()) ?? $migEx->getMessage();
                $sanitized = preg_replace('/(?:app_key|app_secret|password|token|secret)\s*[=:]\s*["\']?[^\s"\']+["\']?/i', '[CREDENCIAL REMOVIDA]', $sanitized) ?? $sanitized;
                $sanitized = mb_substr(trim($sanitized), 0, 300);

                $adminLogs->log('migration_failed', null, [
                    'schema_version' => $migEx->schemaVersion,
                    'description' => $migEx->description,
                    'file' => $migEx->migrationFile,
                    'connection' => $migEx->connection,
                    'execution_ms' => $migEx->executionMs,
                    'error' => $sanitized,
                    'source' => 'admin_web',
                ]);
                $logger->error('Migration falhou via admin.', [
                    'version' => $migEx->schemaVersion,
                    'message' => $migEx->getMessage(),
                ]);

                Flash::set('error', sprintf(
                    'Migration %s (%s) falhou apos %dms em [%s]. %s',
                    $migEx->schemaVersion,
                    $migEx->description,
                    $migEx->executionMs,
                    $migEx->connection,
                    $sanitized
                ));
            } catch (Throwable $migrationError) {
                $sanitized = preg_replace('/SQLSTATE\[\w+\]:[^.]+/i', '[erro de banco]', $migrationError->getMessage()) ?? $migrationError->getMessage();
                $sanitized = preg_replace('/(?:app_key|app_secret|password|token|secret)\s*[=:]\s*["\']?[^\s"\']+["\']?/i', '[CREDENCIAL REMOVIDA]', $sanitized) ?? $sanitized;
                $sanitized = mb_substr(trim($sanitized), 0, 300);

                $adminLogs->log('migration_failed', null, [
                    'error' => $sanitized,
                    'source' => 'admin_web',
                ]);
                $logger->error('Falha ao executar migrations via admin.', [
                    'message' => $migrationError->getMessage(),
                ]);

                Flash::set('error', 'A execucao de migrations foi interrompida. ' . $sanitized);
            }

            $redirect($tab);
        }

        if ($action === 'run_single_migration') {
            if (($message = $guard->assert('settings.manage')) !== null) {
                Flash::set('error', $message);
                $redirect($tab);
            }

            $targetVersion = preg_replace('/[^0-9]/', '', (string) ($_POST['version'] ?? ''));
            if ($targetVersion === '') {
                Flash::set('error', 'Versao da migration nao informada.');
                $redirect($tab);
            }

            $runner = new MigrationRunner($config, $logger);
            $adminLogs->log('migration_single_requested', null, [
                'version' => $targetVersion,
                'source' => 'admin_web',
            ]);

            try {
                $result = $runner->migrateOne($targetVersion);
                $applied = $result['applied'] ?? [];

                foreach ($applied as $migrationResult) {
                    $adminLogs->log('migration_executed', null, [
                        'version' => $migrationResult['version'] ?? '',
                        'description' => $migrationResult['description'] ?? '',
                        'connection' => $migrationResult['connection'] ?? '',
                        'execution_ms' => $migrationResult['execution_ms'] ?? 0,
                    ]);
                }

                Flash::set('success', count($applied) > 0
                    ? "Migration {$targetVersion} executada com sucesso."
                    : "Migration {$targetVersion} ja estava aplicada.");
            } catch (MigrationFailedException $migEx) {
                $sanitized = preg_replace('/SQLSTATE\[\w+\]:[^.]+/i', '[erro de banco]', $migEx->getMessage()) ?? $migEx->getMessage();
                $sanitized = preg_replace('/(?:app_key|app_secret|password|token|secret)\s*[=:]\s*["\']?[^\s"\']+["\']?/i', '[CREDENCIAL REMOVIDA]', $sanitized) ?? $sanitized;
                $sanitized = mb_substr(trim($sanitized), 0, 300);

                $adminLogs->log('migration_failed', null, [
                    'schema_version' => $migEx->schemaVersion,
                    'description' => $migEx->description,
                    'file' => $migEx->migrationFile,
                    'connection' => $migEx->connection,
                    'execution_ms' => $migEx->executionMs,
                    'error' => $sanitized,
                    'source' => 'admin_web',
                ]);
                $logger->error('Migration individual falhou via admin.', [
                    'version' => $migEx->schemaVersion,
                    'message' => $migEx->getMessage(),
                ]);

                Flash::set('error', sprintf(
                    'Migration %s (%s) falhou apos %dms em [%s]. %s',
                    $migEx->schemaVersion,
                    $migEx->description,
                    $migEx->executionMs,
                    $migEx->connection,
                    $sanitized
                ));
            } catch (Throwable $migrationError) {
                Flash::set('error', 'Nao foi possivel executar a migration. ' . mb_substr($migrationError->getMessage(), 0, 200));
            }

            $redirect($tab);
        }

        if ($action === 'run_console_command') {
            if (($message = $guard->assert('settings.manage')) !== null) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Simple session-based rate limit: 20 executions per 60-second window per user
            $rateKey = 'console_rate_' . $currentUserId;
            $rateData = $_SESSION[$rateKey] ?? ['count' => 0, 'window' => time()];
            if (time() - (int) $rateData['window'] > 60) {
                $rateData = ['count' => 0, 'window' => time()];
            }
            if ((int) $rateData['count'] >= 20) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Limite de execucoes atingido. Aguarde alguns instantes.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $rateData['count'] = (int) $rateData['count'] + 1;
            $_SESSION[$rateKey] = $rateData;

            $commandName = preg_replace('/[^a-z0-9:_-]/', '', strtolower((string) ($_POST['command'] ?? '')));
            $rawArgs = is_array($_POST['args'] ?? null) ? $_POST['args'] : [];
            $args = [];
            foreach ($rawArgs as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $args[preg_replace('/[^a-z0-9_-]/', '', strtolower($k))] = mb_substr($v, 0, 200);
                }
            }
            $confirmFlag = (string) ($_POST['confirm'] ?? '');

            $registry = AdminCommandRegistry::build($pdo, $logger, $config);

            if (!$registry->has($commandName)) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Comando nao permitido. Use help para ver os comandos disponiveis.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $command = $registry->get($commandName);

            if ($command === null) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Comando nao encontrado.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $argError = $command->validateArgs($args);
            if ($argError !== null) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => $argError], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($command->requiresConfirmation() && $confirmFlag !== '1') {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Confirme a execucao para continuar.', 'requires_confirmation' => true], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $currentUserData = Auth::currentUser();
            $userPermissions = $rbac->userPermissions($currentUserId, (string) ($currentUserData['role'] ?? 'viewer'));

            $context = new AdminCommandContext(
                userId: $currentUserId,
                username: (string) ($currentUserData['username'] ?? ''),
                role: (string) ($currentUserData['role'] ?? 'viewer'),
                permissions: $userPermissions,
                pdo: $pdo,
                requestId: RequestContext::id(),
                ipAddress: (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
                userAgent: mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                appEnv: (string) $config->get('app.env', getenv('APP_ENV') ?: 'producao'),
                startedAt: microtime(true),
            );

            $adminLogs->log('console_command_started', null, [
                'command' => $commandName,
                'args_keys' => array_keys($args),
                'request_id' => RequestContext::id(),
            ]);

            $t0 = microtime(true);
            try {
                $result = $command->run($context, $args);
                $durationMs = (int) round((microtime(true) - $t0) * 1000);
                $result = $result->withDurationMs($durationMs);

                $adminLogs->log('console_command_finished', null, [
                    'command' => $commandName,
                    'status' => $result->status,
                    'duration_ms' => $durationMs,
                    'request_id' => RequestContext::id(),
                ]);

                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok'         => true,
                    'result'     => $result->toArray(),
                    'meta' => [
                        'request_id'  => RequestContext::id(),
                        'command'     => $commandName,
                        'duration_ms' => $durationMs,
                        'executed_at' => date('Y-m-d H:i:s'),
                        'user_id'     => $currentUserId,
                        'span_id'     => substr(bin2hex(random_bytes(8)), 0, 16),
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            } catch (Throwable $consoleError) {
                $durationMs = (int) round((microtime(true) - $t0) * 1000);

                // Sanitize: strip Windows/Unix paths and limit length
                $rawMsg  = $consoleError->getMessage();
                $safeMsg = (string) preg_replace('#[A-Za-z]:\\\\[^\s"\'<>]*|/[^\s"\'<>]{4,}#', '[path]', $rawMsg);
                $safeMsg = mb_substr($safeMsg, 0, 500);

                $shortClass = (new \ReflectionClass($consoleError))->getShortName();
                $sqlstate   = '';
                $driverCode = 0;
                $driverMsg  = '';
                if ($consoleError instanceof \PDOException && is_array($consoleError->errorInfo)) {
                    $sqlstate   = (string)  ($consoleError->errorInfo[0] ?? '');
                    $driverCode = (int)     ($consoleError->errorInfo[1] ?? 0);
                    $rawDriver  = (string)  ($consoleError->errorInfo[2] ?? '');
                    $driverMsg  = mb_substr((string) preg_replace('#[A-Za-z]:\\\\[^\s"\'<>]*|/[^\s"\'<>]{4,}#', '[path]', $rawDriver), 0, 300);
                }

                $logger->error('AdminConsole: command execution failed.', [
                    'command'   => $commandName,
                    'exception' => $consoleError::class,
                    'message'   => $rawMsg,
                    'sqlstate'  => $sqlstate ?: null,
                ]);
                $adminLogs->log('console_command_failed', null, [
                    'command'          => $commandName,
                    'duration_ms'      => $durationMs,
                    'request_id'       => RequestContext::id(),
                    'exception_class'  => $shortClass,
                    'sqlstate'         => $sqlstate ?: null,
                    'driver_error_code'=> $driverCode ?: null,
                ]);

                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok'    => false,
                    'error' => 'Nao foi possivel executar o comando agora. O erro foi registrado.',
                    'debug' => [
                        'command'                 => $commandName,
                        'request_id'              => RequestContext::id(),
                        'duration_ms'             => $durationMs,
                        'exception_class'         => $shortClass,
                        'error_message_sanitized' => $safeMsg,
                        'sqlstate'                => $sqlstate,
                        'driver_error_code'       => $driverCode,
                        'driver_message'          => $driverMsg,
                    ],
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        Flash::set('warning', 'Acao administrativa desconhecida.');
        $redirect('users');
    } catch (Throwable $exception) {
        $logger->error('Falha em acao administrativa.', [
            'action' => $action,
            'message' => $exception->getMessage(),
        ]);
        $adminLogs->log('admin_action_failed', null, [
            'action' => $action,
            'message' => $exception->getMessage(),
        ]);
        Flash::set('error', $friendlyDbMessage($exception, 'Nao foi possivel concluir a acao administrativa agora.'));
        $redirect($tab);
    }
}

$activeTab = preg_replace('/[^a-z_-]/', '', (string) ($_GET['tab'] ?? 'users')) ?: 'users';
$can = [
    'users' => $guard->can('admin.manage'),
    'logs' => $guard->can('logs.view'),
    'migrations' => $guard->can('settings.manage'),
    'console' => $guard->can('settings.manage'),
    'operations' => $guard->can('admin.view') || $guard->can('admin.manage'),
];

$users = $repo->listUsers();
foreach ($users as &$listedUser) {
    $listedUser['primary_role'] = $rbac->primaryRoleForUser((int) $listedUser['id'], (string) ($listedUser['role'] ?? 'viewer'));
}
unset($listedUser);

$roles = $rbac->listRoles();
$permissions = $rbac->listPermissions();
$permissionMatrix = $rbac->permissionMatrix();
$adminLogRows = $can['logs'] ? $adminLogs->list(120) : [];
$rbacDiagnostics = $rbac->diagnostics();

try {
    $systemHealth = (new SystemHealthService($pdo, 'db'))->diagnose();
} catch (Throwable $exception) {
    $logger->warning('Diagnostico administrativo indisponivel.', ['message' => $exception->getMessage()]);
    $systemHealth = [
        'status' => 'warning',
        'messages' => ['Diagnostico indisponivel no momento.'],
        'tables' => [],
        'migrations' => [],
    ];
}

try {
    $migrationStatus = (new MigrationRunner($config, $logger))->statusWithDiagnostics();
} catch (Throwable $exception) {
    $logger->warning('Status de migrations indisponivel.', ['message' => $exception->getMessage()]);
    $migrationStatus = [
        'summary' => ['discovered' => 0, 'applied' => 0, 'pending' => 0, 'rolled_back' => 0, 'missing_connection' => 0, 'connection_error' => 0, 'checksum_mismatch' => 0],
        'migrations' => [],
        'diagnostics' => [],
        'error' => 'Nao foi possivel listar migrations agora. Verifique a conexao com o banco.',
    ];
}

$flash = Flash::consume();
$csrf = $csrfService->token();

$consoleCommandList = [];
if ($can['console']) {
    try {
        $consoleRegistry = AdminCommandRegistry::build($pdo, $logger, $config);
        foreach ($consoleRegistry->list() as $cmd) {
            $consoleCommandList[] = [
                'name'                 => $cmd->name(),
                'description'          => $cmd->description(),
                'group'                => $cmd->group(),
                'requires_confirmation' => $cmd->requiresConfirmation(),
                'is_destructive'       => $cmd->isDestructive(),
            ];
        }
    } catch (Throwable $consoleListError) {
        $logger->warning('Nao foi possivel listar comandos do console.', ['message' => $consoleListError->getMessage()]);
    }
}

echo PageRenderer::renderPhp('admin.php', [
    'page_key' => 'admin',
    'user' => $currentUser,
    'is_admin' => true,
    'users' => $users,
    'roles' => $roles,
    'permissions' => $permissions,
    'permissionMatrix' => $permissionMatrix,
    'adminLogs' => $adminLogRows,
    'rbacDiagnostics' => $rbacDiagnostics,
    'systemHealth' => $systemHealth,
    'migrationStatus' => $migrationStatus,
    'csrf' => $csrf,
    'flash' => $flash,
    'activeTab' => $activeTab,
    'can' => $can,
    'environment' => (string) $config->get('app.env', getenv('APP_ENV') ?: 'producao'),
    'consoleCommandList' => $consoleCommandList,
]);
