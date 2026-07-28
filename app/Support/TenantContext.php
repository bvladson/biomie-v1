<?php

declare(strict_types=1);

namespace BIomie\Support;

use PDO;

/**
 * Fonte única do contexto multiempresa da requisição.
 *
 * HTTP nunca usa empresa de sessão, empresa padrão ou primeiro cadastro como
 * fallback. O ctx opaco é resolvido server-side e vinculado ao usuário.
 */
final class TenantContext
{
    private static ?CompanyContext $instance = null;
    private static ?PDO $pdo = null;

    /** @var list<array<string,mixed>> */
    private static array $accessibleCompanies = [];
    private static bool $accessibleLoaded = false;

    public static function boot(PDO $pdo, bool $allowGroup = false): void
    {
        self::$instance = null;
        self::$pdo = $pdo;
        self::$accessibleCompanies = [];
        self::$accessibleLoaded = false;

        $userId = (int) ($_SESSION['biomie_auth']['user_id'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        $context = (new TenantContextResolver($pdo))->resolveFromRequest($userId);
        if ($context->isGroup() && !$allowGroup) {
            throw new TenantContextUnavailableException(
                'context_group_route_unsupported',
                403,
                'O contexto de grupo ainda não é permitido nesta rota. Selecione uma empresa.',
            );
        }
        $context->assertCompanyAccess();

        self::$instance = $context;
    }

    /**
     * Bootstrap explícito e sem contexto somente para a tela de seleção.
     *
     * Não deve ser usado por dashboard, API, job ou consulta operacional.
     */
    public static function bootContextSelector(PDO $pdo): void
    {
        self::$instance = null;
        self::$pdo = $pdo;
        $userId = (int) ($_SESSION['biomie_auth']['user_id'] ?? 0);
        self::$accessibleCompanies = $userId > 0
            ? self::listAccessible($pdo, $userId)
            : [];
        self::$accessibleLoaded = true;
    }

    public static function current(): CompanyContext
    {
        if (self::$instance === null) {
            throw new TenantContextUnavailableException(
                'context_required',
                403,
                'TenantContext não inicializado. Informe explicitamente o ctx da requisição.',
            );
        }

        return self::$instance;
    }

    public static function isBooted(): bool
    {
        return self::$instance !== null;
    }

    /**
     * Reutiliza a conexao que ja resolveu o contexto nesta requisicao. Evita
     * probes redundantes do pool em leitores que usam o mesmo banco canonico.
     */
    public static function storageConnection(): PDO
    {
        if (!self::$pdo instanceof PDO) {
            throw new TenantContextUnavailableException(
                'context_storage_unavailable',
                503,
                'Conexao do armazenamento de contexto nao foi inicializada.',
            );
        }

        return self::$pdo;
    }

    public static function contextId(): string
    {
        return self::current()->contextId;
    }

    public static function contextType(): string
    {
        return self::current()->type;
    }

    public static function userId(): int
    {
        return self::current()->userId;
    }

    public static function companyId(): int
    {
        $companyId = self::current()->companyId;
        if ($companyId === null) {
            throw new TenantContextUnavailableException(
                'context_company_required',
                409,
                'Esta operação exige o contexto explícito de uma única empresa.',
            );
        }

        return $companyId;
    }

    /**
     * Leitura semântica para metadados. Operações por empresa devem usar
     * companyId(), que falha fechado em contexto de grupo.
     */
    public static function companyIdOrNull(): ?int
    {
        return self::current()->companyId;
    }

    public static function slug(): string
    {
        return self::current()->companySlug;
    }

    public static function companyName(): string
    {
        return self::current()->companyName;
    }

    public static function businessScope(): string
    {
        return self::current()->businessScope;
    }

    public static function role(): string
    {
        return self::current()->role;
    }

    public static function sellerScopeHash(): string
    {
        return self::current()->sellerScopeHash;
    }

    public static function permissionsVersion(): int
    {
        return self::current()->permissionsVersion;
    }

    /**
     * Alias legado seguro. Contexto group é representado como consolidado.
     */
    public static function canal(): string
    {
        return self::isConsolidado() ? 'consolidado' : self::businessScope();
    }

    public static function isVarejo(): bool
    {
        return self::businessScope() === 'varejo';
    }

    public static function isLicitacao(): bool
    {
        return self::businessScope() === 'licitacao';
    }

    /**
     * Contexto "Todas as operações": varejo + licitação numa MESMA empresa.
     * Diferente de isConsolidado() (grupo de EMPRESAS).
     */
    public static function isMixed(): bool
    {
        return !self::isConsolidado() && self::businessScope() === 'mixed';
    }

    public static function isConsolidado(): bool
    {
        return self::current()->isGroup();
    }

    /**
     * Feature gate R1. Só pode virar true depois de todas as rotas expostas ao
     * grupo aplicarem o mapa de canais por empresa no servidor.
     */
    public static function groupContextEnabled(): bool
    {
        return false;
    }

    /** @return list<int> */
    public static function allowedCompanyIds(): array
    {
        return self::current()->allowedCompanyIds;
    }

    /** @return array<int,list<string>> */
    public static function allowedBusinessScopes(): array
    {
        return self::current()->allowedBusinessScopes;
    }

    public static function assertCompanyAccess(?int $companyId = null): void
    {
        self::current()->assertCompanyAccess($companyId);
    }

    /**
     * Metadados mínimos para toda resposta de API dependente de dados.
     *
     * @return array<string,mixed>
     */
    public static function apiMeta(): array
    {
        $context = self::current();

        return [
            'context_id' => $context->contextId,
            'context_type' => $context->type,
            'company_id' => $context->companyId,
            'company_slug' => $context->companySlug,
            'business_scope' => $context->businessScope,
            'seller_scope_hash' => $context->sellerScopeHash,
            'permissions_version' => $context->permissionsVersion,
            'allowed_company_ids' => $context->allowedCompanyIds,
            'allowed_business_scopes' => $context->allowedBusinessScopes,
        ];
    }

    /** @return array<string,mixed> */
    public static function publicContext(): array
    {
        $context = self::current();

        return [
            'id' => $context->contextId,
            'type' => $context->type,
            'user_id' => $context->userId,
            'company_id' => $context->companyId,
            'company_slug' => $context->companySlug,
            'company_name' => $context->companyName,
            'business_scope' => $context->businessScope,
            'role' => $context->role,
            'seller_scope_hash' => $context->sellerScopeHash,
            'permissions_version' => $context->permissionsVersion,
            'allowed_company_ids' => $context->allowedCompanyIds,
            'allowed_business_scopes' => $context->allowedBusinessScopes,
        ];
    }

    /**
     * Contexto explícito de CLI/worker. Não depende de sessão.
     */
    public static function bootForCompany(PDO $pdo, int $companyId, ?string $canal = null): void
    {
        if ($companyId <= 0) {
            throw new \InvalidArgumentException('company_id inválido.');
        }

        $stmt = $pdo->prepare(
            'SELECT id, slug, nome_fantasia, canal_padrao
               FROM biomie_companies
              WHERE id = :id AND ativo = 1
              LIMIT 1'
        );
        $stmt->execute(['id' => $companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($company)) {
            throw new \RuntimeException("Empresa ativa não encontrada para company_id={$companyId}.");
        }

        $scope = strtolower(trim((string) ($canal ?: $company['canal_padrao'] ?: 'varejo')));
        if (!in_array($scope, ['varejo', 'licitacao'], true)) {
            throw new \InvalidArgumentException('Canal inválido para contexto de execução.');
        }

        self::$pdo = $pdo;
        self::$accessibleCompanies = [];
        self::$accessibleLoaded = true;
        self::$instance = new CompanyContext(
            substr(hash('sha256', 'cli|' . $companyId . '|' . $scope . '|' . microtime(true)), 0, 32),
            'company',
            0,
            (int) $company['id'],
            (string) $company['slug'],
            (string) $company['nome_fantasia'],
            $scope,
            'system',
            hash('sha256', 'cli-unrestricted-explicit-company'),
            0,
            [(int) $company['id']],
            [(int) $company['id'] => [$scope]],
        );
    }

    public static function sqlFilter(string $alias = ''): string
    {
        $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
        $ids = self::allowedCompanyIds();
        if ($ids === []) {
            return '1=0';
        }
        if (count($ids) === 1) {
            return $prefix . 'company_id = ' . $ids[0];
        }

        return $prefix . 'company_id IN (' . implode(',', $ids) . ')';
    }

    /**
     * Compatibilidade segura: registra apenas preferência; não cria contexto.
     */
    public static function setInSession(int $companyId, string $canal): void
    {
        if ($companyId <= 0 || !in_array($canal, ['varejo', 'licitacao'], true)) {
            throw new \InvalidArgumentException('Preferência de contexto inválida.');
        }
        $_SESSION['biomie_tenant_preference'] = [
            'company_id' => $companyId,
            'business_scope' => $canal,
        ];
        self::$instance = null;
    }

    /**
     * Compatibilidade temporária para chamadores antigos: sempre cria outro ID;
     * o ID informado nunca é reutilizado ou alterado.
     */
    public static function setNamedContext(?string $ignoredContextId, int $companyId, string $canal): string
    {
        $resolver = self::resolver();
        $userId = (int) ($_SESSION['biomie_auth']['user_id'] ?? 0);

        return $resolver->createCompanyContext($userId, $companyId, $canal)->contextId;
    }

    /** @param list<int> $companyIds */
    public static function setNamedConsolidatedContext(?string $ignoredContextId, array $companyIds): string
    {
        $resolver = self::resolver();
        $userId = (int) ($_SESSION['biomie_auth']['user_id'] ?? 0);

        return $resolver->createGroupContext($userId, $companyIds)->contextId;
    }

    public static function cloneCurrentNamedContext(): string
    {
        $context = self::current();
        $resolver = self::resolver();

        return $context->isGroup()
            ? $resolver->createGroupContext($context->userId, $context->allowedCompanyIds)->contextId
            : $resolver->createCompanyContext(
                $context->userId,
                (int) $context->companyId,
                $context->businessScope,
            )->contextId;
    }

    /**
     * O contexto consolidado legado sem ID opaco não é mais permitido.
     *
     * @param list<int> $companyIds
     */
    public static function setConsolidadoInSession(array $companyIds): void
    {
        throw new \LogicException('Use TenantContextResolver::createGroupContext().');
    }

    /** @return list<int> */
    public static function accessibleCompanyIds(): array
    {
        return array_map('intval', array_column(self::cachedAccessible(), 'id'));
    }

    /** @return list<array<string,mixed>> */
    public static function cachedAccessible(): array
    {
        if (!self::$accessibleLoaded && self::$pdo instanceof PDO && self::$instance !== null) {
            self::$accessibleCompanies = self::listAccessible(
                self::$pdo,
                self::$instance->userId,
            );
            self::$accessibleLoaded = true;
        }

        return self::$accessibleCompanies;
    }

    /** @return list<array<string,mixed>> */
    public static function listAccessible(PDO $pdo, int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $stmt = $pdo->prepare(<<<'SQL'
            SELECT c.id, c.slug, c.nome_fantasia, c.cnpj, c.canal_padrao,
                   uc.can_varejo, uc.can_licitacao, uc.is_default, uc.default_canal,
                   uc.role_override,
                   COALESCE(i.active, 0) AS integration_active,
                   CASE
                       WHEN COALESCE(i.app_key, '') <> ''
                        AND COALESCE(i.app_secret_encrypted, '') <> ''
                       THEN 1 ELSE 0
                   END AS integration_configured,
                   i.last_success_at AS integration_last_success_at,
                   i.last_error_at AS integration_last_error_at
              FROM biomie_companies c
              JOIN biomie_user_companies uc ON uc.company_id = c.id
              LEFT JOIN biomie_company_integrations i
                ON i.company_id = c.id
               AND i.provider = 'omie'
             WHERE uc.user_id = :uid
               AND c.ativo = 1
               AND (uc.can_varejo = 1 OR uc.can_licitacao = 1)
             ORDER BY uc.is_default DESC, c.nome_fantasia ASC
        SQL);
        $stmt->execute(['uid' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private static function resolver(): TenantContextResolver
    {
        if (!self::$pdo instanceof PDO) {
            throw new TenantContextUnavailableException(
                'context_storage_unavailable',
                503,
                'Conexão do armazenamento de contexto não foi inicializada.',
            );
        }

        return new TenantContextResolver(self::$pdo);
    }
}
