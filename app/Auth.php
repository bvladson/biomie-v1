<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Admin\RbacService;

final class Auth
{
    private const SESSION_KEY = 'biomie_auth';
    private const CSRF_KEY = 'biomie_csrf';
    private const SESSION_USER_CACHE = 'biomie_user_cache';
    private const USER_CACHE_TTL = 600;

    private static ?Config $config = null;
    private static ?Logger $logger = null;
    private static ?AuthRepository $repository = null;
    private static ?array $currentUser = null;
    private static ?string $basePath = null;
    private static bool $schemaEnsured = false;
    private static ?RbacService $rbacService = null;
    /** @var list<string>|null */
    private static ?array $authorizationPermissions = null;
    /** @var list<string>|null */
    private static ?array $legacyCapabilities = null;
    /** @var array<string,bool> */
    private static array $capabilityCache = [];
    private static bool $rbacLookupFailed = false;
    private static bool $legacyAuthorizationModeLogged = false;

    private const CAPABILITY_ALIASES = [
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

    private const CAPABILITY_EQUIVALENTS = [
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

    /**
     * Detecta o subdiretório onde o BIomie está instalado.
     * Ex.: se o script roda em /biomie/index.php, retorna "/biomie".
     * Se roda na raiz, retorna "".
     */
    public static function basePath(): string
    {
        if (self::$basePath !== null) {
            return self::$basePath;
        }

        // Tenta via config primeiro
        if (self::$config !== null) {
            $configured = trim((string) self::$config->get('app.base_path', ''));
            if ($configured !== '') {
                self::$basePath = '/' . trim($configured, '/');
                return self::$basePath;
            }
        }

        // Auto-detecta a partir de SCRIPT_NAME
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $dir = rtrim(dirname($scriptName), '/\\');
        self::$basePath = ($dir === '.' || $dir === '/') ? '' : $dir;
        return self::$basePath;
    }

    /**
     * Gera URL absoluta dentro do BIomie, ex.: Auth::url('login.php') => "/biomie/login.php"
     */
    public static function url(string $path = ''): string
    {
        $base = self::basePath();
        $path = ltrim($path, '/');
        return $base . '/' . $path;
    }

    public static function boot(Config $config, Logger $logger): void
    {
        if (self::$config !== null && self::$config !== $config) {
            self::$repository = null;
            self::$currentUser = null;
            self::$basePath = null;
            self::$rbacService = null;
            self::resetAuthorizationCache();
        }

        self::$config = $config;
        self::$logger = $logger;

        if (session_status() !== PHP_SESSION_ACTIVE) {
            $isSecure = self::isHttps();
            session_name((string) $config->get('auth.session_name', 'BIOMIESESSID'));
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => $isSecure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }

        $timeout = (int) $config->get('auth.idle_timeout_seconds', 7200);
        $lastActivity = (int) ($_SESSION[self::SESSION_KEY]['last_activity'] ?? 0);
        if ($lastActivity > 0 && (time() - $lastActivity) > $timeout) {
            self::logout('idle_timeout');
            return;
        }

        $_SESSION[self::SESSION_KEY]['last_activity'] = time();
        $_SESSION[self::CSRF_KEY] ??= bin2hex(random_bytes(32));
    }

    public static function repository(): AuthRepository
    {
        if (self::$repository !== null) {
            return self::$repository;
        }

        if (self::$config === null || self::$logger === null) {
            throw new \RuntimeException('Auth::boot() deve ser executado antes de usar o repositório.');
        }

        $pdo = Database::connect(self::$config, 'db');
        self::$repository = new AuthRepository($pdo, self::$logger);

        if (!self::$schemaEnsured) {
            $marker = Bootstrap::rootPath('storage/cache/auth_schema_v1.ready');
            if (!is_file($marker)) {
                self::$repository->ensureSchema();
                @file_put_contents($marker, date(DATE_ATOM), LOCK_EX);
            }
            self::$schemaEnsured = true;
        }

        return self::$repository;
    }

    public static function userCount(): int
    {
        return self::repository()->countUsers();
    }

    public static function currentUser(): ?array
    {
        if (self::$currentUser !== null) {
            return self::$currentUser;
        }

        $userId = (int) ($_SESSION[self::SESSION_KEY]['user_id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }

        // Session cache — avoids a DB connection on every request; refreshed after USER_CACHE_TTL seconds
        $cached = $_SESSION[self::SESSION_USER_CACHE] ?? null;
        if (is_array($cached)
            && isset($cached['data'], $cached['ts'])
            && is_array($cached['data'])
            && (int) ($cached['data']['id'] ?? 0) === $userId
            && (time() - (int) $cached['ts']) < self::USER_CACHE_TTL) {
            self::$currentUser = $cached['data'];
            return self::$currentUser;
        }

        $user = self::repository()->findActiveUserById($userId);
        if ($user === null) {
            self::logout('user_missing_or_inactive');
            return null;
        }

        $_SESSION[self::SESSION_USER_CACHE] = ['data' => $user, 'ts' => time()];
        self::$currentUser = $user;
        return self::$currentUser;
    }

    public static function isAuthenticated(): bool
    {
        return self::currentUser() !== null;
    }

    public static function isAdmin(): bool
    {
        if (\BIomie\Support\TenantContext::isBooted()) {
            return \BIomie\Support\TenantContext::role() === 'admin';
        }

        return \BIomie\Acl\Roles::resolve((string) (self::currentUser()['role'] ?? '')) === 'admin';
    }

    // ── Fase 12: capability checks ───────────────────────────────────────────

    /**
     * Retorna true se o usuário autenticado possui a capacidade solicitada.
     * Considera o role base e o override de módulos por usuário (modules_json).
     */
    public static function hasCapability(string $capability): bool
    {
        $capability = self::normalizeCapability($capability);
        $user = self::currentUser();
        if ($user === null) {
            return false;
        }

        $contextCacheKey = \BIomie\Support\TenantContext::isBooted()
            ? \BIomie\Support\TenantContext::contextId()
            : 'no-context';
        $cacheKey = (int) ($user['id'] ?? 0) . ':' . $contextCacheKey . ':' . $capability;
        if (array_key_exists($cacheKey, self::$capabilityCache)) {
            return self::$capabilityCache[$cacheKey];
        }

        $capabilityCandidates = self::capabilityCandidates($capability);
        $permissions = self::rbacPermissions($user);
        if ($permissions !== null) {
            foreach ($capabilityCandidates as $candidate) {
                if (in_array($candidate, $permissions, true)) {
                    return self::$capabilityCache[$cacheKey] = true;
                }
            }

            return self::$capabilityCache[$cacheKey] = false;
        }

        $roleCapabilities = self::legacyCapabilities($user);
        foreach ($capabilityCandidates as $candidate) {
            if (in_array($candidate, $roleCapabilities, true)) {
                return self::$capabilityCache[$cacheKey] = true;
            }
        }

        return self::$capabilityCache[$cacheKey] = false;
    }

    /**
     * @param list<string> $capabilities
     */
    public static function hasAnyCapability(array $capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if (self::hasCapability($capability)) {
                return true;
            }
        }

        return false;
    }

    public static function canAccessAdminArea(): bool
    {
        return self::hasAnyCapability(['admin.view', 'admin.manage', 'logs.view', 'settings.manage']);
    }

    private static function normalizeCapability(string $capability): string
    {
        $capability = strtolower(trim($capability));
        return self::CAPABILITY_ALIASES[$capability] ?? $capability;
    }

    /**
     * @return list<string>
     */
    private static function capabilityCandidates(string $capability): array
    {
        $normalized = self::normalizeCapability($capability);
        $candidates = [$normalized, strtolower(trim($capability))];
        foreach (self::CAPABILITY_EQUIVALENTS[$normalized] ?? [] as $alias) {
            $candidates[] = $alias;
        }

        return array_values(array_unique(array_filter($candidates, static fn (string $value): bool => $value !== '')));
    }

    /** Pode visualizar o dossiê completo de um cliente. */
    public static function canViewDossier(): bool
    {
        return self::hasCapability('dossier.view');
    }

    /** Pode ver CPF/CNPJ sem mascaramento. */
    public static function canViewFullDocuments(): bool
    {
        return self::hasCapability('documents.full');
    }

    /** Pode exportar dados para CSV/JSON. */
    public static function canExport(): bool
    {
        return self::hasAnyCapability(['sales.export', 'finance.export', 'fiscal.export', 'export']);
    }

    /** Pode acessar o módulo especificado (ex.: 'financial', 'geo', 'observability'). */
    public static function canAccessModule(string $module): bool
    {
        return self::hasCapability($module . '.view');
    }

    /**
     * Interrompe a execução se o usuário não tiver acesso ao módulo.
     * Registra o acesso negado no log.
     */
    public static function requireModule(string $module, bool $api = false): void
    {
        self::requireCapability($module . '.view', $api, 'Seu perfil nao tem acesso a este modulo.');
    }

    public static function requireCapability(string $capability, bool $api = false, ?string $message = null): void
    {
        self::requireLogin($api);

        $capability = self::normalizeCapability($capability);
        if (self::hasCapability($capability)) {
            return;
        }

        self::logger()?->warning('Acesso negado por capacidade.', [
            'capability' => $capability,
            'user' => self::currentUser()['username'] ?? null,
            'role' => self::currentUser()['role'] ?? null,
            'route' => trim((string) ($_SERVER['REQUEST_URI'] ?? '/')),
            'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        ]);

        self::forbidden($api, $message, $capability, null);
    }

    /**
     * @param list<string> $capabilities
     */
    public static function requireAnyCapability(array $capabilities, bool $api = false, ?string $message = null): void
    {
        self::requireLogin($api);

        $normalized = array_values(array_unique(array_map(
            static fn (string $capability): string => self::normalizeCapability($capability),
            array_values($capabilities)
        )));

        if (self::hasAnyCapability($normalized)) {
            return;
        }

        self::logger()?->warning('Acesso negado por capacidades.', [
            'capabilities' => $normalized,
            'user' => self::currentUser()['username'] ?? null,
            'role' => self::currentUser()['role'] ?? null,
            'route' => trim((string) ($_SERVER['REQUEST_URI'] ?? '/')),
            'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        ]);

        self::forbidden($api, $message, null, $normalized);
    }

    private static function logger(): ?Logger
    {
        return self::$logger;
    }

    // ────────────────────────────────────────────────────────────────────────

    public static function requireLogin(bool $api = false): void
    {
        if (self::isAuthenticated()) {
            return;
        }

        if ($api) {
            self::unauthenticated();
        }

        $redirect = self::currentPath();
        header('Location: ' . self::url('login.php') . '?redirect=' . rawurlencode($redirect));
        exit;
    }

    public static function requireAdmin(bool $api = false): void
    {
        self::requireLogin($api);
        if (self::isAdmin()) {
            return;
        }

        self::forbidden($api, 'Acesso permitido apenas para administradores.', 'admin', null);
    }

    public static function attemptLogin(string $identifier, string $password): array
    {
        $identifier = trim($identifier);
        $password = (string) $password;
        $ip = self::clientIp();
        $route = self::currentPath();
        $agent = self::userAgent();
        $repo = self::repository();

        if ($identifier === '' || $password === '') {
            return ['ok' => false, 'message' => 'Informe usuário/e-mail e senha.'];
        }

        if ($repo->countRecentFailedLogins($identifier, $ip, 15) >= 5) {
            $repo->recordLog('login_blocked', null, $identifier, $route, $ip, $agent, [
                'reason' => 'too_many_attempts',
            ]);
            return ['ok' => false, 'message' => 'Muitas tentativas inválidas. Aguarde alguns minutos e tente novamente.'];
        }

        $user = $repo->findUserForLogin($identifier);
        if ($user === null || !password_verify($password, (string) ($user['password_hash'] ?? ''))) {
            $repo->recordLog('login_failed', null, $identifier, $route, $ip, $agent, [
                'reason' => 'invalid_credentials',
            ]);
            return ['ok' => false, 'message' => 'Usuário ou senha inválidos.'];
        }

        if (!(bool) ($user['is_active'] ?? false)) {
            $repo->recordLog('login_failed', (int) $user['id'], $identifier, $route, $ip, $agent, [
                'reason' => 'inactive_user',
            ]);
            return ['ok' => false, 'message' => 'Usuário inativo. Contate o administrador.'];
        }

        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = [
            'user_id' => (int) $user['id'],
            'last_activity' => time(),
            'logged_at' => time(),
        ];
        $_SESSION[self::CSRF_KEY] ??= bin2hex(random_bytes(32));
        unset($_SESSION[self::SESSION_USER_CACHE]);
        self::$currentUser = null;
        self::resetAuthorizationCache();

        $repo->touchLastLogin((int) $user['id']);
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $repo->updatePasswordHash((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
        }

        // Set default tenant context in session so first page load resolves correctly
        try {
            $tenantPdo = Database::connect(self::$config, 'db');
            $tenantStmt = $tenantPdo->prepare(<<<'SQL'
                SELECT uc.company_id, uc.default_canal
                  FROM biomie_user_companies uc
                  JOIN biomie_companies c ON c.id = uc.company_id
                 WHERE uc.user_id = :uid AND uc.is_default = 1 AND c.ativo = 1
                 LIMIT 1
            SQL);
            $tenantStmt->execute(['uid' => (int) $user['id']]);
            $tenant = $tenantStmt->fetch(\PDO::FETCH_ASSOC);
            if ($tenant) {
                \BIomie\Support\TenantContext::setInSession(
                    (int)    $tenant['company_id'],
                    (string) $tenant['default_canal'],
                );
            }
        } catch (\Throwable) {
            // Silent — TenantContext::boot() on the next request resolves the fallback
        }

        $repo->recordLog('login_success', (int) $user['id'], $identifier, $route, $ip, $agent, [
            'role' => $user['role'] ?? 'user',
        ]);

        return ['ok' => true, 'message' => 'Login realizado com sucesso.'];
    }

    public static function createFirstAdmin(string $username, string $fullName, ?string $email, string $password): array
    {
        $repo = self::repository();
        if ($repo->countUsers() > 0) {
            return ['ok' => false, 'message' => 'O primeiro administrador já foi criado.'];
        }

        $validation = self::validateUserPayload($username, $fullName, $email, $password, 'admin');
        if ($validation['ok'] === false) {
            return $validation;
        }

        $userId = $repo->createFirstAdmin($username, $fullName, $email, $password);
        $repo->recordLog('first_admin_created', $userId, $username, self::currentPath(), self::clientIp(), self::userAgent(), null);

        // Auto-login do primeiro admin criado
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = [
            'user_id' => $userId,
            'last_activity' => time(),
            'logged_at' => time(),
        ];
        unset($_SESSION[self::SESSION_USER_CACHE]);
        self::$currentUser = null;
        self::resetAuthorizationCache();
        $repo->touchLastLogin($userId);

        return ['ok' => true, 'message' => 'Administrador criado e logado com sucesso.', 'auto_login' => true];
    }

    public static function createUser(string $username, string $fullName, ?string $email, string $password, string $role, bool $isActive): array
    {
        $validation = self::validateUserPayload($username, $fullName, $email, $password, $role);
        if ($validation['ok'] === false) {
            return $validation;
        }

        try {
            $userId = self::repository()->createUser(
                $username,
                $fullName,
                $email,
                $password,
                $role,
                $isActive,
                (int) (self::currentUser()['id'] ?? 0) ?: null,
            );
        } catch (\Throwable $exception) {
            return ['ok' => false, 'message' => self::friendlyDbMessage($exception->getMessage(), 'Não foi possível criar o usuário.')];
        }

        self::repository()->recordLog('user_created', $userId, $username, self::currentPath(), self::clientIp(), self::userAgent(), [
            'created_by' => self::currentUser()['username'] ?? null,
            'role' => $role,
            'is_active' => $isActive,
        ]);

        return ['ok' => true, 'message' => 'Usuário criado com sucesso.'];
    }

    public static function updateUser(int $userId, string $fullName, ?string $email, string $role, bool $isActive): array
    {
        if ($userId <= 0) {
            return ['ok' => false, 'message' => 'Usuário inválido.'];
        }

        $validation = self::validateUserPayload('tempuser', $fullName, $email, null, $role, false);
        if ($validation['ok'] === false) {
            return $validation;
        }

        $current = self::currentUser();
        if ($current !== null && (int) $current['id'] === $userId && !$isActive) {
            return ['ok' => false, 'message' => 'Você não pode desativar o seu próprio usuário nesta tela.'];
        }

        try {
            self::repository()->updateUser(
                $userId,
                $fullName,
                $email,
                $role,
                $isActive,
                (int) ($current['id'] ?? 0) ?: null,
            );
        } catch (\Throwable $exception) {
            return ['ok' => false, 'message' => self::friendlyDbMessage($exception->getMessage(), 'Não foi possível atualizar o usuário.')];
        }

        self::repository()->recordLog('user_updated', $userId, null, self::currentPath(), self::clientIp(), self::userAgent(), [
            'updated_by' => $current['username'] ?? null,
            'role' => $role,
            'is_active' => $isActive,
        ]);

        if ($current !== null && (int) $current['id'] === $userId) {
            self::$currentUser = null;
            unset($_SESSION[self::SESSION_USER_CACHE]);
            self::resetAuthorizationCache();
        }

        return ['ok' => true, 'message' => 'Usuário atualizado com sucesso.'];
    }

    public static function resetUserPassword(int $userId, string $password): array
    {
        if ($userId <= 0) {
            return ['ok' => false, 'message' => 'Usuário inválido.'];
        }
        if (!self::isStrongPassword($password)) {
            return ['ok' => false, 'message' => 'A senha deve ter no mínimo 8 caracteres, incluindo letras e números.'];
        }

        self::repository()->updatePassword($userId, $password, (int) (self::currentUser()['id'] ?? 0) ?: null);
        self::repository()->recordLog('password_reset', $userId, null, self::currentPath(), self::clientIp(), self::userAgent(), [
            'reset_by' => self::currentUser()['username'] ?? null,
        ]);

        return ['ok' => true, 'message' => 'Senha atualizada com sucesso.'];
    }

    public static function logout(string $reason = 'logout'): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $userId = (int) ($_SESSION[self::SESSION_KEY]['user_id'] ?? 0);
        if ($userId > 0) {
            try {
                self::repository()->recordLog($reason === 'logout' ? 'logout' : $reason, $userId, null, self::currentPath(), self::clientIp(), self::userAgent(), null);
            } catch (\Throwable) {
                // evita quebrar logout por falha de log
            }
            try {
                if (self::$config !== null) {
                    $contextPdo = Database::connect(self::$config, 'db');
                    (new \BIomie\Support\TenantContextStore($contextPdo))
                        ->revokeAllForUser($userId);
                }
            } catch (\Throwable) {
                // Logout continua fail-safe mesmo se o storage R1 estiver indisponível.
            }
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        self::$currentUser = null;
        self::resetAuthorizationCache();
    }

    public static function csrfToken(): string
    {
        return (string) ($_SESSION[self::CSRF_KEY] ?? '');
    }

    public static function verifyCsrf(?string $token): bool
    {
        $stored = (string) ($_SESSION[self::CSRF_KEY] ?? '');
        return $stored !== '' && is_string($token) && hash_equals($stored, $token);
    }

    public static function redirectAfterLogin(?string $redirect): string
    {
        $redirect = trim((string) $redirect);
        $base = self::basePath();
        $defaultPage = self::url('index.php');

        // Redireciona para index se veio vazio ou é a própria página de login
        if ($redirect === '' || str_ends_with($redirect, 'login.php') || $redirect === '/') {
            return $defaultPage;
        }
        // Bloqueia redirecionamentos externos
        if (str_contains($redirect, '://') || str_starts_with($redirect, '//')) {
            return $defaultPage;
        }

        // Se o redirect já começa com o basePath, aceita como está
        if ($base !== '' && str_starts_with($redirect, $base)) {
            return $redirect;
        }

        // Se não começa com /, adiciona o basePath
        if (!str_starts_with($redirect, '/')) {
            return self::url($redirect);
        }

        return $redirect;
    }

    public static function validateUserPayload(
        string $username,
        string $fullName,
        ?string $email,
        ?string $password,
        string $role,
        bool $requirePassword = true,
    ): array {
        $username = trim($username);
        $fullName = trim($fullName);
        $email = $email !== null ? trim($email) : null;
        $role = strtolower(trim($role));

        if ($username !== 'tempuser' && !preg_match('/^[a-zA-Z0-9._-]{3,40}$/', $username)) {
            return ['ok' => false, 'message' => 'O usuário deve ter entre 3 e 40 caracteres e pode usar letras, números, ponto, underscore e hífen.'];
        }
        if ($fullName === '' || mb_strlen($fullName) < 3) {
            return ['ok' => false, 'message' => 'Informe o nome completo do usuário.'];
        }
        if ($email !== null && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'message' => 'E-mail inválido.'];
        }
        // Aceita roles da Fase 12 + alias legado 'user'
        if (!in_array($role, ['viewer', 'analyst', 'manager', 'admin', 'user'], true)) {
            return ['ok' => false, 'message' => 'Perfil inválido. Use: viewer, analyst, manager ou admin.'];
        }
        if ($requirePassword && !self::isStrongPassword((string) $password)) {
            return ['ok' => false, 'message' => 'A senha deve ter no mínimo 8 caracteres, incluindo letras e números.'];
        }

        return ['ok' => true, 'message' => 'ok'];
    }

    private static function isStrongPassword(string $password): bool
    {
        return strlen($password) >= 8
            && preg_match('/[A-Za-z]/', $password) === 1
            && preg_match('/\d/', $password) === 1;
    }

    private static function clientIp(): string
    {
        return trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) ?: 'unknown';
    }

    private static function userAgent(): string
    {
        return trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown')) ?: 'unknown';
    }

    private static function currentPath(): string
    {
        return trim((string) ($_SERVER['REQUEST_URI'] ?? '/')) ?: '/';
    }

    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        return (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }

    private static function friendlyDbMessage(string $message, string $fallback): string
    {
        $normalized = strtolower($message);
        if (str_contains($normalized, 'uniq_biomie_users_username') || str_contains($normalized, 'for key \'username\'')) {
            return 'Já existe um usuário com esse login.';
        }
        if (str_contains($normalized, 'uniq_biomie_users_email') || str_contains($normalized, 'for key \'email\'')) {
            return 'Já existe um usuário com esse e-mail.';
        }

        return $fallback;
    }

    /**
     * @param array<string,mixed> $user
     * @return list<string>|null
     */
    private static function rbacPermissions(array $user): ?array
    {
        if (self::legacyAuthorizationEnabled()) {
            if (!self::$legacyAuthorizationModeLogged) {
                self::$legacyAuthorizationModeLogged = true;
                self::logger()?->warning('Modo de autorizacao legado habilitado explicitamente.', [
                    'authorization_mode' => 'legacy',
                ]);
            }

            return null;
        }

        if (self::$authorizationPermissions !== null) {
            return self::$authorizationPermissions;
        }
        if (self::$rbacLookupFailed) {
            return [];
        }
        if (self::$config === null) {
            return self::denyBecauseRbacUnavailable('auth_not_booted');
        }

        try {
            self::$rbacService ??= new RbacService(Database::connect(self::$config, 'db'));
            if (!self::$rbacService->tablesReady()) {
                return self::denyBecauseRbacUnavailable('rbac_schema_unavailable');
            }

            self::$authorizationPermissions = \BIomie\Support\TenantContext::isBooted()
                ? self::$rbacService->permissionsForRole(\BIomie\Support\TenantContext::role())
                : self::$rbacService->userPermissions(
                    (int) ($user['id'] ?? 0),
                    (string) ($user['role'] ?? 'viewer'),
                );
            return self::$authorizationPermissions;
        } catch (\Throwable $exception) {
            return self::denyBecauseRbacUnavailable('rbac_lookup_failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private static function legacyAuthorizationEnabled(): bool
    {
        $mode = getenv('BIOMIE_AUTHORIZATION_MODE');
        return is_string($mode) && strtolower(trim($mode)) === 'legacy';
    }

    /**
     * @param array<string,mixed> $context
     * @return list<string>
     */
    private static function denyBecauseRbacUnavailable(string $reason, array $context = []): array
    {
        self::$rbacLookupFailed = true;
        self::$authorizationPermissions = [];
        self::logger()?->warning('RBAC indisponivel; autorizacao negada por padrao seguro.', array_merge([
            'reason' => $reason,
            'authorization_mode' => 'rbac',
        ], $context));

        return [];
    }

    /**
     * @param array<string,mixed> $user
     * @return list<string>
     */
    private static function legacyCapabilities(array $user): array
    {
        if (self::$legacyCapabilities !== null) {
            return self::$legacyCapabilities;
        }

        $effectiveRole = self::companyEffectiveRole($user);

        $modules = null;
        $modulesRaw = $user['modules_json'] ?? null;
        if (is_string($modulesRaw) && $modulesRaw !== '') {
            $decoded = json_decode($modulesRaw, true);
            $modules = is_array($decoded) ? $decoded : null;
        }

        return self::$legacyCapabilities = \BIomie\Acl\Roles::capabilities(
            $effectiveRole,
            $modules,
        );
    }

    /**
     * Returns the role that applies to the user FOR THE CURRENT COMPANY.
     * If `biomie_user_companies.role_override` is set for (user, currentCompany),
     * that role takes precedence over the user's global role.
     *
     * @param array<string,mixed> $user
     */
    private static function companyEffectiveRole(array $user): string
    {
        $globalRole = (string) ($user['role'] ?? 'viewer');
        try {
            $companyId = \BIomie\Support\TenantContext::companyId();
            if ($companyId > 0) {
                foreach (\BIomie\Support\TenantContext::cachedAccessible() as $company) {
                    if ((int) ($company['id'] ?? 0) !== $companyId) {
                        continue;
                    }
                    $override = trim((string) ($company['role_override'] ?? ''));
                    if ($override !== '' && in_array($override, \BIomie\Acl\Roles::VALID_ROLES, true)) {
                        return $override;
                    }
                    break;
                }
            }
        } catch (\Throwable) {
            // TenantContext may not be initialized (CLI without --company, or pre-login) — use global role
        }
        return $globalRole;
    }

    private static function resetAuthorizationCache(): void
    {
        self::$authorizationPermissions = null;
        self::$legacyCapabilities = null;
        self::$capabilityCache = [];
        self::$rbacLookupFailed = false;
        self::$legacyAuthorizationModeLogged = false;
    }

    private static function unauthenticated(): never
    {
        \BIomie\Http::json([
            'ok' => false,
            'error' => 'unauthenticated',
            'message' => 'Sessao invalida ou expirada. Faca login novamente.',
            'request_id' => \BIomie\RequestContext::id(),
        ], 401);
    }

    /**
     * @param list<string>|null $requiredAny
     */
    private static function forbidden(bool $api, ?string $message, ?string $required, ?array $requiredAny): never
    {
        $message ??= 'Seu perfil nao tem permissao para acessar este recurso.';

        if ($api) {
            $payload = [
                'ok' => false,
                'error' => 'forbidden',
                'message' => $message,
                'request_id' => \BIomie\RequestContext::id(),
            ];
            if ($required !== null) {
                $payload['required_permission'] = $required;
            }
            if ($requiredAny !== null) {
                $payload['required_any_permission'] = $requiredAny;
            }

            \BIomie\Http::json($payload, 403);
        }

        try {
            \BIomie\Http\ErrorBoundary::renderForbiddenPage($message, [
                'required_permission' => $required,
                'required_any_permission' => $requiredAny,
                'route' => self::currentPath(),
            ]);
            exit;
        } catch (\Throwable) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            echo 'Acesso nao autorizado. Request ID: ' . htmlspecialchars(\BIomie\RequestContext::id(), ENT_QUOTES, 'UTF-8');
            exit;
        }
    }
}
