<?php

declare(strict_types=1);

namespace BIomie\Install;

use BIomie\Admin\RbacService;
use BIomie\AuthRepository;
use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Support\EnvWriter;
use PDO;
use Throwable;

final class InstallService
{
    public const STEPS = [
        'welcome',
        'requirements',
        'permissions',
        'database',
        'migrations',
        'migrate',
        'admin',
        'finish',
        'diagnostic',
    ];

    private InstallRequirementChecker $requirements;
    private InstallPermissionChecker $permissions;
    private InstallDatabaseTester $databaseTester;
    private InstallMigrationRunner $migrations;
    private InstallTableInspector $tables;

    public function __construct(private readonly InstallLogger $logger)
    {
        $this->requirements = new InstallRequirementChecker();
        $this->permissions = new InstallPermissionChecker();
        $this->databaseTester = new InstallDatabaseTester();
        $this->migrations = new InstallMigrationRunner($logger);
        $this->tables = new InstallTableInspector();
    }

    public function lockPath(): string
    {
        return Bootstrap::rootPath('config/installed.lock');
    }

    public function isInstalled(): bool
    {
        return is_file($this->lockPath());
    }

    /**
     * @return array<string,mixed>|null
     */
    public function lockData(): ?array
    {
        if (!$this->isInstalled()) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($this->lockPath()), true);
        return is_array($decoded) ? $decoded : null;
    }

    public function csrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION['biomie_install_csrf'] ??= bin2hex(random_bytes(32));
        return (string) $_SESSION['biomie_install_csrf'];
    }

    public function validateCsrf(?string $token): bool
    {
        $stored = (string) ($_SESSION['biomie_install_csrf'] ?? '');
        $ok = $stored !== '' && is_string($token) && hash_equals($stored, $token);
        if (!$ok) {
            $this->logger->warning('csrf_failed', ['step' => $_POST['step'] ?? null, 'action' => $_POST['action'] ?? null]);
        }
        return $ok;
    }

    /**
     * @return array{rows:list<array<string,string|bool>>,blocking:bool}
     */
    public function requirements(): array
    {
        return $this->requirements->check();
    }

    /**
     * @return array{rows:list<array<string,string|bool>>,blocking:bool}
     */
    public function permissions(): array
    {
        return $this->permissions->check();
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public function testDatabase(array $post): array
    {
        if (!$this->allowAttempt('database', 8, 900)) {
            return ['ok' => false, 'message' => 'Muitas tentativas. Aguarde alguns minutos e tente novamente.', 'checks' => []];
        }

        $mode = (string) ($post['config_mode'] ?? 'replace');
        if ($mode === 'use_existing') {
            return $this->testExistingConfig();
        }

        $input = [
            'DB_HOST' => trim((string) ($post['DB_HOST'] ?? '')),
            'DB_PORT' => trim((string) ($post['DB_PORT'] ?? '3306')),
            'DB_DATABASE' => trim((string) ($post['DB_DATABASE'] ?? '')),
            'DB_USERNAME' => trim((string) ($post['DB_USERNAME'] ?? '')),
            'DB_PASSWORD' => (string) ($post['DB_PASSWORD'] ?? ''),
            'DB_CHARSET' => trim((string) ($post['DB_CHARSET'] ?? 'utf8mb4')) ?: 'utf8mb4',
            'APP_URL' => trim((string) ($post['APP_URL'] ?? '')),
            'APP_ENV' => trim((string) ($post['APP_ENV'] ?? 'production')) ?: 'production',
        ];

        $result = $this->databaseTester->test($input);
        if (!$result['ok']) {
            $this->logger->warning('database_test_failed', ['error' => $result['error'] ?? null, 'host' => $input['DB_HOST'], 'database' => $input['DB_DATABASE']]);
            return $result + ['config_written' => false];
        }

        $localConfig = Bootstrap::rootPath('config/.env.local.php');
        $exists = is_file($localConfig);
        $confirmed = isset($post['confirm_replace_config']) && (string) $post['confirm_replace_config'] === '1';
        if ($exists && !$confirmed) {
            return [
                'ok' => false,
                'message' => 'Ja existe configuracao local. Confirme backup e substituicao, ou use a configuracao existente.',
                'checks' => $result['checks'],
                'config_written' => false,
            ];
        }

        try {
            $writer = new EnvWriter();
            $write = $writer->writeLocalPhp($localConfig, [
                'APP_ENV' => $input['APP_ENV'],
                'APP_URL' => $input['APP_URL'],
                'DB_HOST' => $input['DB_HOST'],
                'DB_PORT' => $input['DB_PORT'],
                'DB_NAME' => $input['DB_DATABASE'],
                'DB_USER' => $input['DB_USERNAME'],
                'DB_PASS' => $input['DB_PASSWORD'],
                'DB_CHARSET' => $input['DB_CHARSET'],
            ], $exists);

            $_SESSION['biomie_install']['database_ok'] = true;
            $_SESSION['biomie_install']['database_name'] = $input['DB_DATABASE'];
            $this->logger->info('database_config_written', [
                'database' => $input['DB_DATABASE'],
                'backup' => $write['backup'] ? basename($write['backup']) : null,
            ]);

            return $result + [
                'config_written' => true,
                'backup' => $write['backup'] ? basename($write['backup']) : null,
            ];
        } catch (Throwable $exception) {
            $this->logger->error('database_config_write_failed', ['message' => $exception->getMessage()]);
            return [
                'ok' => false,
                'message' => 'Conexao OK, mas nao foi possivel gravar a configuracao local.',
                'checks' => $result['checks'],
                'config_written' => false,
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function migrationStatus(): array
    {
        return $this->migrations->status();
    }

    /**
     * @return array<string,mixed>
     */
    public function migrate(): array
    {
        if (($_SESSION['biomie_install']['database_ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'message' => 'Teste e valide a conexao do banco antes de aplicar migrations.',
                'result' => [],
            ];
        }

        return $this->migrations->migrate();
    }

    /**
     * @return array<string,mixed>
     */
    public function tableStatus(): array
    {
        try {
            $pdo = $this->pdo();
            return ['ok' => true, 'groups' => $this->tables->inspect($pdo)];
        } catch (Throwable $exception) {
            $this->logger->warning('table_status_failed', ['message' => $exception->getMessage()]);
            return ['ok' => false, 'groups' => [], 'message' => 'Nao foi possivel consultar tabelas agora.'];
        }
    }

    /**
     * @return array{exists:bool,count:int,admins:int,ready:bool,message:string}
     */
    public function adminStatus(): array
    {
        try {
            $pdo = $this->pdo();
            if (!$this->tables->tableExists($pdo, 'biomie_users')) {
                return ['exists' => false, 'count' => 0, 'admins' => 0, 'ready' => false, 'message' => 'Tabela biomie_users ausente. Aplique as migrations primeiro.'];
            }

            $count = (int) $pdo->query('SELECT COUNT(*) FROM biomie_users')->fetchColumn();
            $admins = $this->countAdmins($pdo);
            return [
                'exists' => true,
                'count' => $count,
                'admins' => $admins,
                'ready' => true,
                'message' => $admins > 0 ? 'Ja existe administrador cadastrado.' : 'Nenhum administrador encontrado.',
            ];
        } catch (Throwable $exception) {
            $this->logger->warning('admin_status_failed', ['message' => $exception->getMessage()]);
            return ['exists' => false, 'count' => 0, 'admins' => 0, 'ready' => false, 'message' => 'Nao foi possivel consultar usuarios.'];
        }
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public function createAdmin(array $post): array
    {
        if (!$this->allowAttempt('admin', 6, 900)) {
            return ['ok' => false, 'message' => 'Muitas tentativas. Aguarde alguns minutos e tente novamente.'];
        }

        $status = $this->adminStatus();
        if (!$status['ready']) {
            return ['ok' => false, 'message' => $status['message']];
        }
        if ($status['admins'] > 0) {
            return ['ok' => true, 'message' => 'Ja existe administrador cadastrado.', 'skipped' => true];
        }

        $username = trim((string) ($post['username'] ?? ''));
        $fullName = trim((string) ($post['full_name'] ?? ''));
        $email = trim((string) ($post['email'] ?? ''));
        $password = (string) ($post['password'] ?? '');
        $confirm = (string) ($post['password_confirm'] ?? '');

        if ($fullName === '' || $username === '') {
            return ['ok' => false, 'message' => 'Informe nome e login do administrador.'];
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'message' => 'E-mail invalido.'];
        }
        if ($password !== $confirm) {
            return ['ok' => false, 'message' => 'A confirmacao de senha nao confere.'];
        }
        if (!$this->strongPassword($password)) {
            return ['ok' => false, 'message' => 'Use senha com 10+ caracteres, letra, numero e simbolo.'];
        }

        try {
            $pdo = $this->pdo();
            $repo = new AuthRepository($pdo, new Logger(Bootstrap::rootPath('storage/logs/biomie.log')));
            $userId = $repo->createUser($username, $fullName, $email !== '' ? $email : null, $password, 'admin', true, null);

            $rbac = new RbacService($pdo);
            if ($rbac->tablesReady()) {
                $rbac->assignSingleRole($userId, 'admin', null);
            }

            $_SESSION['biomie_install']['admin_user_id'] = $userId;
            $this->logger->info('admin_created', ['user_id' => $userId, 'username' => $username]);

            return ['ok' => true, 'message' => 'Administrador criado com sucesso.', 'admin_user_id' => $userId];
        } catch (Throwable $exception) {
            $this->logger->error('admin_create_failed', ['message' => $exception->getMessage(), 'username' => $username]);
            return ['ok' => false, 'message' => 'Nao foi possivel criar o administrador. Veja o log sanitizado.'];
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function finish(): array
    {
        try {
            $pdo = $this->pdo();
            $adminStatus = $this->adminStatus();
            if ($adminStatus['admins'] <= 0) {
                return ['ok' => false, 'message' => 'Crie o primeiro administrador antes de finalizar.'];
            }

            $migrationStatus = $this->migrationStatus();
            $summary = $migrationStatus['result']['summary'] ?? [];
            if (($summary['connection_error'] ?? 0) > 0 || ($summary['missing_connection'] ?? 0) > 0) {
                return ['ok' => false, 'message' => 'Ainda ha erro de conexao em migrations. Corrija antes de finalizar.'];
            }
            if (($summary['pending'] ?? 0) > 0) {
                return ['ok' => false, 'message' => 'Ainda ha migrations pendentes. Aplique antes de finalizar.'];
            }

            $config = new Config();
            $lock = [
                'installed' => true,
                'installed_at' => date('Y-m-d H:i:s'),
                'app' => 'BIomie',
                'version' => (string) $config->get('app.version', 'dev'),
                'database' => (string) $config->get('db.name', ''),
                'admin_user_id' => (int) ($_SESSION['biomie_install']['admin_user_id'] ?? $this->firstAdminId($pdo)),
            ];

            $json = json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false || file_put_contents($this->lockPath(), $json . PHP_EOL, LOCK_EX) === false) {
                return ['ok' => false, 'message' => 'Nao foi possivel criar config/installed.lock. Revise permissao em config/.'];
            }

            unset($_SESSION['biomie_install']);
            $this->logger->info('installed_lock_created', ['database' => $lock['database'], 'admin_user_id' => $lock['admin_user_id']]);

            return ['ok' => true, 'message' => 'BIomie instalado com sucesso.', 'lock' => $lock];
        } catch (Throwable $exception) {
            $this->logger->error('finish_failed', ['message' => $exception->getMessage()]);
            return ['ok' => false, 'message' => 'Nao foi possivel finalizar a instalacao agora.'];
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function diagnostic(): array
    {
        return [
            'requirements' => $this->requirements(),
            'permissions' => $this->permissions(),
            'migrations' => $this->migrationStatus(),
            'tables' => $this->tableStatus(),
            'admin' => $this->adminStatus(),
            'lock' => [
                'exists' => $this->isInstalled(),
                'data' => $this->lockData(),
            ],
            'environment' => [
                'php' => PHP_VERSION,
                'app_env' => getenv('APP_ENV') ?: 'production',
                'app_url' => getenv('APP_URL') ?: '',
                'install_folder_exists' => is_dir(Bootstrap::rootPath('install')),
            ],
            'install_log' => $this->logger->tail(20),
        ];
    }

    public function configLocalExists(): bool
    {
        return is_file(Bootstrap::rootPath('config/.env.local.php'));
    }

    public function databaseValidated(): bool
    {
        return ($_SESSION['biomie_install']['database_ok'] ?? false) === true;
    }

    private function testExistingConfig(): array
    {
        try {
            $pdo = $this->pdo();
            $pdo->query('SELECT 1');
            $config = new Config();
            $_SESSION['biomie_install']['database_ok'] = true;
            $_SESSION['biomie_install']['database_name'] = (string) $config->get('db.name', '');
            $this->logger->info('database_existing_config_ok', ['database' => (string) $config->get('db.name', '')]);

            return [
                'ok' => true,
                'message' => 'Configuracao existente validada com sucesso.',
                'checks' => [
                    ['name' => 'Conexao', 'found' => 'conectado', 'required' => 'conectado', 'status' => 'OK', 'ok' => true, 'action' => 'Tudo certo'],
                    ['name' => 'SELECT', 'found' => 'permitido', 'required' => 'permitido', 'status' => 'OK', 'ok' => true, 'action' => 'Tudo certo'],
                ],
                'config_written' => false,
            ];
        } catch (Throwable $exception) {
            $this->logger->warning('database_existing_config_failed', ['message' => $exception->getMessage()]);
            return [
                'ok' => false,
                'message' => 'A configuracao existente nao conectou ao banco.',
                'checks' => [],
                'config_written' => false,
            ];
        }
    }

    private function pdo(): PDO
    {
        return Database::connect(new Config(), 'db');
    }

    private function countAdmins(PDO $pdo): int
    {
        if ($this->tables->tableExists($pdo, 'biomie_user_roles') && $this->tables->tableExists($pdo, 'biomie_roles')) {
            $stmt = $pdo->query(<<<'SQL'
SELECT COUNT(DISTINCT u.id)
FROM biomie_users u
LEFT JOIN biomie_user_roles ur ON ur.user_id = u.id
LEFT JOIN biomie_roles r ON r.id = ur.role_id
WHERE u.is_active = 1 AND (LOWER(u.role) = 'admin' OR r.role_key = 'admin')
SQL);
            return (int) $stmt->fetchColumn();
        }

        $stmt = $pdo->query("SELECT COUNT(*) FROM biomie_users WHERE is_active = 1 AND LOWER(role) = 'admin'");
        return (int) $stmt->fetchColumn();
    }

    private function firstAdminId(PDO $pdo): int
    {
        $stmt = $pdo->query("SELECT id FROM biomie_users WHERE is_active = 1 AND LOWER(role) = 'admin' ORDER BY id ASC LIMIT 1");
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function strongPassword(string $password): bool
    {
        return strlen($password) >= 10
            && preg_match('/[A-Za-z]/', $password) === 1
            && preg_match('/\d/', $password) === 1
            && preg_match('/[^A-Za-z0-9]/', $password) === 1;
    }

    private function allowAttempt(string $key, int $max, int $windowSeconds): bool
    {
        $now = time();
        $_SESSION['biomie_install_attempts'][$key] ??= [];
        $_SESSION['biomie_install_attempts'][$key] = array_values(array_filter(
            $_SESSION['biomie_install_attempts'][$key],
            static fn (int $time): bool => ($now - $time) <= $windowSeconds
        ));

        if (count($_SESSION['biomie_install_attempts'][$key]) >= $max) {
            return false;
        }

        $_SESSION['biomie_install_attempts'][$key][] = $now;
        return true;
    }
}
