<?php

declare(strict_types=1);

namespace BIomie\Support;

use BIomie\Acl\Roles;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Resolve e cria contextos opacos. Toda autorização é revalidada no banco.
 */
final class TenantContextResolver
{
    private readonly TenantContextStore $store;

    /**
     * @param bool|null $mixedContextEnabled null = lê a flag do módulo em Config
     *   (features.mixed_company_context_enabled). Explícito nos testes. Gate SÓ
     *   da CRIAÇÃO de contexto mixed — a RESOLUÇÃO de um mixed já existente vale
     *   sempre (não se quebra um contexto válido porque a flag mudou).
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?bool $mixedContextEnabled = null,
    ) {
        $this->store = new TenantContextStore($pdo);
    }

    private function mixedEnabled(): bool
    {
        if ($this->mixedContextEnabled !== null) {
            return $this->mixedContextEnabled;
        }
        try {
            return (bool) (new \BIomie\Config())->get('features.mixed_company_context_enabled', false);
        } catch (Throwable) {
            return false;
        }
    }

    public function resolveFromRequest(?int $userId = null): CompanyContext
    {
        $resolvedUserId = $userId ?? (int) ($_SESSION['biomie_auth']['user_id'] ?? 0);
        if ($resolvedUserId <= 0) {
            throw new TenantContextUnavailableException(
                'context_unauthenticated',
                401,
                'É necessário autenticar o usuário antes de resolver o contexto.',
            );
        }

        $contextId = self::requestContextId();
        $row = $this->storageCall(
            fn (): ?array => $this->store->findOwned($contextId, $resolvedUserId),
        );
        if (!is_array($row)) {
            throw self::forbidden('context_not_found', 'Contexto inexistente ou pertencente a outro usuário.');
        }

        if (!empty($row['revoked_at'])) {
            throw self::forbidden('context_revoked', 'Este contexto foi revogado.');
        }
        if (self::isExpired((string) ($row['expires_at'] ?? ''))) {
            $this->bestEffortRevoke($contextId, $resolvedUserId);
            throw self::forbidden('context_expired', 'Este contexto expirou.');
        }

        $type = (string) ($row['context_type'] ?? '');
        if (!in_array($type, ['company', 'group'], true)) {
            $this->bestEffortRevoke($contextId, $resolvedUserId);
            throw self::forbidden('context_invalid', 'O contexto armazenado é inválido.');
        }

        $user = $this->activeUser($resolvedUserId);
        $storedVersion = (int) ($row['permissions_version'] ?? 0);
        $currentVersion = (int) ($user['permissions_version'] ?? 0);
        if ($storedVersion <= 0 || $storedVersion !== $currentVersion) {
            $this->bestEffortRevoke($contextId, $resolvedUserId);
            throw self::forbidden(
                'context_permissions_changed',
                'As permissões mudaram; selecione o contexto novamente.',
            );
        }

        $context = $type === 'company'
            ? $this->resolveStoredCompany($contextId, $row, $user)
            : $this->resolveStoredGroup($contextId, $row, $user);
        $context->assertCompanyAccess();

        if (self::shouldTouch((string) ($row['last_used_at'] ?? ''))) {
            $this->storageCall(fn (): null => $this->touchAndReturnNull($contextId, $resolvedUserId));
        }

        return $context;
    }

    public function createCompanyContext(
        int $userId,
        int $companyId,
        ?string $requestedBusinessScope = null,
    ): CompanyContext {
        if ($userId <= 0 || $companyId <= 0) {
            throw self::forbidden('context_target_invalid', 'Usuário ou empresa inválido.');
        }

        $user = $this->activeUser($userId);
        $company = $this->authorizedCompany($userId, $companyId);
        // Normaliza E autoriza numa peça só. mixed exige os 2 canais + flag;
        // combinação inválida falha explícita (nunca cai para varejo).
        // Sem canal pedido e com os dois canais, o padrão é "Todas as operações":
        // abrir num canal só escondia a venda do outro (caso Uni Design, 09/2026).
        $allowedScopes = self::companyAllowedScopes($company);
        $defaultScope = ($this->mixedEnabled() && count($allowedScopes) === 2)
            ? 'mixed'
            : (string) ($company['default_canal'] ?? $company['canal_padrao'] ?? 'varejo');
        $scope = CompanyScopeNormalizer::normalize(
            (string) ($requestedBusinessScope ?? ''),
            $allowedScopes,
            $defaultScope,
            $this->mixedEnabled(),
        );
        // scopeMap guarda os canais EFETIVOS: single = [ele], mixed = os dois.
        $channels = CompanyScopeNormalizer::effectiveChannels($scope);

        $role = self::effectiveRole($user, $company);
        $sellerHash = $this->storageCall(fn (): string => $this->sellerScopeHash($userId, [$companyId]));
        $permissionsVersion = (int) $user['permissions_version'];
        $contextId = $this->storageCall(fn (): string => $this->store->create(
            $userId,
            'company',
            $companyId,
            [$companyId],
            [$companyId => $channels],
            $scope,
            $role,
            $sellerHash,
            $permissionsVersion,
        ));

        return new CompanyContext(
            $contextId,
            'company',
            $userId,
            $companyId,
            (string) $company['slug'],
            (string) $company['nome_fantasia'],
            $scope,
            $role,
            $sellerHash,
            $permissionsVersion,
            [$companyId],
            [$companyId => $channels],
        );
    }

    /**
     * @param list<int> $requestedCompanyIds Vazio = todas as empresas autorizadas.
     */
    public function createGroupContext(int $userId, array $requestedCompanyIds = []): CompanyContext
    {
        if ($userId <= 0) {
            throw self::forbidden('context_target_invalid', 'Usuário inválido.');
        }

        $user = $this->activeUser($userId);
        $companies = $this->authorizedCompanies($userId);
        $byId = [];
        foreach ($companies as $company) {
            $byId[(int) $company['id']] = $company;
        }

        $requested = self::normalizeCompanyIds($requestedCompanyIds);
        $ids = $requested === [] ? array_keys($byId) : $requested;
        if (count($ids) < 2) {
            throw self::forbidden(
                'context_group_requires_multiple_companies',
                'O contexto consolidado requer pelo menos duas empresas autorizadas.',
            );
        }
        foreach ($ids as $id) {
            if (!isset($byId[$id])) {
                throw self::forbidden(
                    'context_company_forbidden',
                    'Uma das empresas solicitadas não está autorizada.',
                );
            }
        }

        sort($ids, SORT_NUMERIC);
        $scopeMap = [];
        foreach ($ids as $id) {
            $scopeMap[$id] = self::companyAllowedScopes($byId[$id]);
            if ($scopeMap[$id] === []) {
                throw self::forbidden(
                    'context_business_scope_forbidden',
                    'Uma das empresas não possui escopo de negócio autorizado.',
                );
            }
        }
        $sellerHash = $this->storageCall(fn (): string => $this->sellerScopeHash($userId, $ids));
        $permissionsVersion = (int) $user['permissions_version'];
        $role = 'viewer';
        $contextId = $this->storageCall(fn (): string => $this->store->create(
            $userId,
            'group',
            null,
            $ids,
            $scopeMap,
            'mixed',
            $role,
            $sellerHash,
            $permissionsVersion,
        ));

        return new CompanyContext(
            $contextId,
            'group',
            $userId,
            null,
            'CONSOLIDADO',
            'Consolidado Grupo',
            'mixed',
            $role,
            $sellerHash,
            $permissionsVersion,
            $ids,
            $scopeMap,
        );
    }

    public function revoke(string $contextId, int $userId): void
    {
        if (preg_match('/^[a-f0-9]{32}$/', $contextId) !== 1 || $userId <= 0) {
            throw self::forbidden('context_invalid', 'Contexto inválido.');
        }
        $this->storageCall(fn (): null => $this->revokeAndReturnNull($contextId, $userId));
    }

    public static function requestContextId(): string
    {
        $present = [];
        foreach ([
            'header' => $_SERVER['HTTP_X_BIOMIE_CONTEXT'] ?? null,
            'query' => $_GET['ctx'] ?? null,
            'form' => $_POST['ctx'] ?? null,
        ] as $source => $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }
            if (!is_string($candidate)) {
                throw self::forbidden('context_invalid', "Contexto inválido em {$source}.");
            }
            $normalized = strtolower(trim($candidate));
            if (preg_match('/^[a-f0-9]{32}$/', $normalized) !== 1) {
                throw self::forbidden('context_invalid', "Contexto adulterado em {$source}.");
            }
            $present[$source] = $normalized;
        }

        if ($present === []) {
            throw self::forbidden('context_required', 'Informe explicitamente o contexto desta aba.');
        }
        if (count(array_unique(array_values($present))) !== 1) {
            throw self::forbidden('context_ambiguous', 'Foram enviados contextos divergentes.');
        }

        return (string) reset($present);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $user
     */
    private function resolveStoredCompany(string $contextId, array $row, array $user): CompanyContext
    {
        $userId = (int) $user['id'];
        $companyId = (int) ($row['company_id'] ?? 0);
        if ($companyId <= 0) {
            throw self::forbidden('context_invalid', 'O contexto não identifica uma empresa.');
        }

        $allowedIds = $this->decodeAllowedIds($row['allowed_company_ids_json'] ?? null);
        if ($allowedIds !== [$companyId]) {
            throw self::forbidden('context_invalid', 'O escopo de empresas do contexto é inválido.');
        }

        $company = $this->authorizedCompany($userId, $companyId);
        $scope = (string) ($row['business_scope'] ?? '');
        if (!in_array($scope, ['varejo', 'licitacao', 'mixed'], true)) {
            throw self::forbidden('context_invalid', 'O escopo de negócio do contexto é inválido.');
        }
        // Autorização RECALCULADA: mixed exige os dois canais AGORA. Se o admin
        // removeu um canal, o contexto mixed é REVOGADO com motivo específico —
        // ANTES do assertScopeAllowed genérico, para não herdar acesso a um canal
        // perdido nem devolver um motivo vago (channel_scope_changed é acionável).
        if ($scope === 'mixed' && self::companyAllowedScopes($company) !== CompanyScopeNormalizer::effectiveChannels('mixed')) {
            $this->bestEffortRevoke($contextId, $userId);
            throw self::forbidden(
                'context_channel_scope_changed',
                'Um canal comercial foi alterado; selecione o contexto novamente.',
            );
        }
        self::assertScopeAllowed($company, $scope);
        // scopeMap esperado = canais EFETIVOS do escopo (single = [ele], mixed = dois).
        $expectedChannels = CompanyScopeNormalizer::effectiveChannels($scope);
        $scopeMap = $this->decodeScopeMap($row['company_scope_map_json'] ?? null, $allowedIds);
        if ($scopeMap !== [$companyId => $expectedChannels]) {
            throw self::forbidden('context_invalid', 'O mapa de escopo da empresa é inválido.');
        }

        $role = self::effectiveRole($user, $company);
        $sellerHash = $this->storageCall(fn (): string => $this->sellerScopeHash($userId, [$companyId]));
        $this->assertSnapshotMatches($contextId, $userId, $row, $role, $sellerHash);

        return new CompanyContext(
            $contextId,
            'company',
            $userId,
            $companyId,
            (string) $company['slug'],
            (string) $company['nome_fantasia'],
            $scope,
            $role,
            $sellerHash,
            (int) $user['permissions_version'],
            [$companyId],
            $scopeMap,
        );
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $user
     */
    private function resolveStoredGroup(string $contextId, array $row, array $user): CompanyContext
    {
        if (($row['company_id'] ?? null) !== null) {
            throw self::forbidden('context_invalid', 'Contexto de grupo não pode ter empresa única.');
        }
        if ((string) ($row['business_scope'] ?? '') !== 'mixed') {
            throw self::forbidden('context_invalid', 'O escopo do grupo é inválido.');
        }

        $ids = $this->decodeAllowedIds($row['allowed_company_ids_json'] ?? null);
        if (count($ids) < 2) {
            throw self::forbidden('context_invalid', 'O contexto de grupo está vazio.');
        }

        $authorized = $this->authorizedCompanies((int) $user['id']);
        $scopeMap = $this->decodeScopeMap($row['company_scope_map_json'] ?? null, $ids);
        $authorizedIds = self::normalizeCompanyIds(array_column($authorized, 'id'));
        $authorizedById = [];
        foreach ($authorized as $company) {
            $authorizedById[(int) $company['id']] = $company;
        }
        foreach ($ids as $id) {
            if (!in_array($id, $authorizedIds, true)) {
                $this->bestEffortRevoke($contextId, (int) $user['id']);
                throw self::forbidden(
                    'context_company_forbidden',
                    'O usuário perdeu acesso a uma empresa deste contexto.',
                );
            }
            if (($scopeMap[$id] ?? []) !== self::companyAllowedScopes($authorizedById[$id])) {
                $this->bestEffortRevoke($contextId, (int) $user['id']);
                throw self::forbidden(
                    'context_permissions_changed',
                    'O escopo de negócio de uma empresa do grupo mudou.',
                );
            }
        }

        $sellerHash = $this->storageCall(
            fn (): string => $this->sellerScopeHash((int) $user['id'], $ids),
        );
        $this->assertSnapshotMatches($contextId, (int) $user['id'], $row, 'viewer', $sellerHash);

        return new CompanyContext(
            $contextId,
            'group',
            (int) $user['id'],
            null,
            'CONSOLIDADO',
            'Consolidado Grupo',
            'mixed',
            'viewer',
            $sellerHash,
            (int) $user['permissions_version'],
            $ids,
            $scopeMap,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function activeUser(int $userId): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, role, is_active, permissions_version
                   FROM biomie_users
                  WHERE id = :user_id AND is_active = 1
                  LIMIT 1'
            );
            $stmt->execute(['user_id' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $exception) {
            throw self::storageUnavailable($exception);
        }

        if (!is_array($row) || (int) ($row['permissions_version'] ?? 0) <= 0) {
            throw self::forbidden('context_user_inactive', 'Usuário inativo ou sem versão de permissões.');
        }

        return $row;
    }

    /**
     * @return array<string,mixed>
     */
    private function authorizedCompany(int $userId, int $companyId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.slug, c.nome_fantasia, c.canal_padrao,
                    uc.can_varejo, uc.can_licitacao, uc.default_canal, uc.role_override
               FROM biomie_companies c
               JOIN biomie_user_companies uc ON uc.company_id = c.id
              WHERE uc.user_id = :user_id
                AND c.id = :company_id
                AND c.ativo = 1
              LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'company_id' => $companyId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw self::forbidden('context_company_forbidden', 'Empresa inativa ou não autorizada.');
        }

        return $row;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function authorizedCompanies(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.slug, c.nome_fantasia, c.canal_padrao,
                    uc.can_varejo, uc.can_licitacao, uc.default_canal, uc.role_override
               FROM biomie_companies c
               JOIN biomie_user_companies uc ON uc.company_id = c.id
              WHERE uc.user_id = :user_id
                AND c.ativo = 1
                AND (uc.can_varejo = 1 OR uc.can_licitacao = 1)
              ORDER BY c.id'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Hash canônico do modo/lista de vendedores em todas as empresas do contexto.
     *
     * @param list<int> $companyIds
     */
    private function sellerScopeHash(int $userId, array $companyIds): string
    {
        $companyIds = self::normalizeCompanyIds($companyIds);
        if ($companyIds === []) {
            throw self::forbidden('context_seller_scope_invalid', 'Contexto sem empresas.');
        }

        $params = [];
        $inByKind = [];
        foreach (['mode', 'own', 'selected'] as $kind) {
            $params[$kind . '_user'] = $userId;
            $placeholders = [];
            foreach ($companyIds as $index => $companyId) {
                $key = $kind . '_company_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $companyId;
            }
            $inByKind[$kind] = implode(',', $placeholders);
        }

        $modes = [];
        $ownByCompany = [];
        $selectedByCompany = [];
        $snapshotStmt = $this->pdo->prepare(
            "SELECT company_id, 'mode' AS item_type, visibility_mode AS item_value
               FROM biomie_user_seller_scope
              WHERE user_id = :mode_user AND company_id IN ({$inByKind['mode']})
             UNION ALL
             SELECT company_id, 'own' AS item_type, seller_id AS item_value
               FROM biomie_quote_seller_binding
              WHERE user_id = :own_user AND company_id IN ({$inByKind['own']})
             UNION ALL
             SELECT company_id, 'selected' AS item_type, seller_id AS item_value
               FROM biomie_user_visible_sellers
              WHERE user_id = :selected_user AND company_id IN ({$inByKind['selected']})
             ORDER BY company_id, item_type, item_value"
        );
        $snapshotStmt->execute($params);
        foreach ($snapshotStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $companyId = (int) ($row['company_id'] ?? 0);
            $kind = (string) ($row['item_type'] ?? '');
            $value = trim((string) ($row['item_value'] ?? ''));
            if ($companyId <= 0 || $value === '') {
                continue;
            }
            if ($kind === 'mode') {
                $modes[$companyId] = $value;
            } elseif ($kind === 'own' && $value !== '0') {
                $ownByCompany[$companyId][] = $value;
            } elseif ($kind === 'selected' && $value !== '0') {
                $selectedByCompany[$companyId][] = $value;
            }
        }
        foreach ($ownByCompany as &$sellerIds) {
            $sellerIds = array_values(array_unique($sellerIds));
            sort($sellerIds, SORT_STRING);
        }
        unset($sellerIds);
        foreach ($selectedByCompany as &$sellerIds) {
            $sellerIds = array_values(array_unique($sellerIds));
            sort($sellerIds, SORT_STRING);
        }
        unset($sellerIds);

        $snapshot = [];
        foreach ($companyIds as $companyId) {
            $own = $ownByCompany[$companyId] ?? [];
            $mode = $modes[$companyId] ?? null;
            if ($mode === null) {
                $mode = $own !== [] ? 'own' : 'all';
            }

            if ($mode === 'selected') {
                $ids = $selectedByCompany[$companyId] ?? [];
            } elseif ($mode === 'own') {
                $ids = $own;
            } elseif ($mode === 'all') {
                $ids = ['*'];
            } else {
                throw self::forbidden('context_seller_scope_invalid', 'Escopo de vendedores inválido.');
            }

            $snapshot[] = [
                'company_id' => $companyId,
                'mode' => (string) $mode,
                'seller_ids' => $ids,
            ];
        }

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string,mixed> $row
     */
    private function assertSnapshotMatches(
        string $contextId,
        int $userId,
        array $row,
        string $role,
        string $sellerHash,
    ): void {
        if (!hash_equals((string) ($row['role_name'] ?? ''), $role)
            || !hash_equals((string) ($row['seller_scope_hash'] ?? ''), $sellerHash)
        ) {
            $this->bestEffortRevoke($contextId, $userId);
            throw self::forbidden(
                'context_permissions_changed',
                'O papel ou o escopo de vendedores mudou; selecione o contexto novamente.',
            );
        }
    }

    /**
     * @return list<int>
     */
    private function decodeAllowedIds(mixed $encoded): array
    {
        try {
            $decoded = json_decode((string) $encoded, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw self::forbidden('context_invalid', 'Escopo de empresas corrompido.');
        }
        if (!is_array($decoded)) {
            throw self::forbidden('context_invalid', 'Escopo de empresas inválido.');
        }

        $ids = self::normalizeCompanyIds($decoded);
        if (count($ids) !== count($decoded)) {
            throw self::forbidden('context_invalid', 'Escopo de empresas não está normalizado.');
        }

        return $ids;
    }

    /**
     * @param list<int> $expectedCompanyIds
     * @return array<int,list<string>>
     */
    private function decodeScopeMap(mixed $encoded, array $expectedCompanyIds): array
    {
        try {
            $decoded = json_decode((string) $encoded, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw self::forbidden('context_invalid', 'Mapa de escopos de negócio corrompido.');
        }
        if (!is_array($decoded)) {
            throw self::forbidden('context_invalid', 'Mapa de escopos de negócio inválido.');
        }

        $map = [];
        foreach ($expectedCompanyIds as $companyId) {
            $rawScopes = $decoded[$companyId] ?? $decoded[(string) $companyId] ?? null;
            if (!is_array($rawScopes)) {
                throw self::forbidden('context_invalid', 'Empresa sem escopo de negócio no contexto.');
            }
            $scopes = array_values(array_unique(array_filter(
                array_map(static fn (mixed $scope): string => strtolower(trim((string) $scope)), $rawScopes),
                static fn (string $scope): bool => in_array($scope, ['varejo', 'licitacao'], true),
            )));
            sort($scopes, SORT_STRING);
            if ($scopes === [] || count($scopes) !== count($rawScopes)) {
                throw self::forbidden('context_invalid', 'Escopo de negócio não normalizado.');
            }
            $map[$companyId] = $scopes;
        }
        if (count($decoded) !== count($map)) {
            throw self::forbidden('context_invalid', 'Mapa contém empresas fora do contexto.');
        }

        return $map;
    }

    /**
     * @param list<mixed> $ids
     * @return list<int>
     */
    private static function normalizeCompanyIds(array $ids): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            if (filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id <= 0) {
                continue;
            }
            $normalized[] = (int) $id;
        }
        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    /**
     * @param array<string,mixed> $company
     */
    private static function assertScopeAllowed(array $company, string $scope): void
    {
        $varejo = (bool) ($company['can_varejo'] ?? false);
        $licit  = (bool) ($company['can_licitacao'] ?? false);
        $allowed = match ($scope) {
            'varejo'    => $varejo,
            'licitacao' => $licit,
            'mixed'     => $varejo && $licit,   // "Todas as operações" exige os dois
            default     => false,
        };
        if (!$allowed) {
            throw self::forbidden(
                'context_business_scope_forbidden',
                'O usuário não possui acesso a este escopo de negócio.',
            );
        }
    }

    /**
     * @param array<string,mixed> $company
     * @return list<string>
     */
    private static function companyAllowedScopes(array $company): array
    {
        $scopes = [];
        if ((bool) ($company['can_varejo'] ?? false)) {
            $scopes[] = 'varejo';
        }
        if ((bool) ($company['can_licitacao'] ?? false)) {
            $scopes[] = 'licitacao';
        }
        sort($scopes, SORT_STRING);

        return $scopes;
    }

    // normalizeCompanyScope() foi substituído por CompanyScopeNormalizer::normalize(),
    // que valida a combinação (mixed exige os dois canais) em vez de só o valor.

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $company
     */
    private static function effectiveRole(array $user, array $company): string
    {
        $global = Roles::resolve((string) ($user['role'] ?? 'viewer'));
        $override = strtolower(trim((string) ($company['role_override'] ?? '')));
        $role = $override !== '' ? Roles::resolve($override) : $global;
        if (!in_array($role, Roles::VALID_ROLES, true)) {
            throw self::forbidden('context_role_invalid', 'O papel efetivo do usuário é inválido.');
        }

        return $role;
    }

    private static function isExpired(string $expiresAt): bool
    {
        if ($expiresAt === '') {
            return true;
        }
        try {
            $expiry = new DateTimeImmutable($expiresAt, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return true;
        }

        return $expiry <= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function shouldTouch(string $lastUsedAt): bool
    {
        if ($lastUsedAt === '') {
            return true;
        }
        try {
            $lastUsed = new DateTimeImmutable($lastUsedAt, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return true;
        }

        return $lastUsed->getTimestamp() < time() - 300;
    }

    private function bestEffortRevoke(string $contextId, int $userId): void
    {
        try {
            $this->store->revoke($contextId, $userId);
        } catch (Throwable) {
        }
    }

    private function touchAndReturnNull(string $contextId, int $userId): null
    {
        $this->store->touch($contextId, $userId);
        return null;
    }

    private function revokeAndReturnNull(string $contextId, int $userId): null
    {
        $this->store->revoke($contextId, $userId);
        return null;
    }

    /**
     * @template T
     * @param callable():T $operation
     * @return T
     */
    private function storageCall(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (TenantContextUnavailableException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw self::storageUnavailable($exception);
        }
    }

    private static function storageUnavailable(Throwable $previous): TenantContextUnavailableException
    {
        return new TenantContextUnavailableException(
            'context_storage_unavailable',
            503,
            'O armazenamento seguro de contextos não está disponível. Aplique a migration R1.',
        );
    }

    private static function forbidden(string $reason, string $message): TenantContextUnavailableException
    {
        return new TenantContextUnavailableException($reason, 403, $message);
    }
}
