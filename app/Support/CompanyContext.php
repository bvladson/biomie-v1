<?php

declare(strict_types=1);

namespace BIomie\Support;

use InvalidArgumentException;

/**
 * Contexto de autorização imutável, válido por uma única requisição.
 *
 * O identificador é apenas uma referência opaca. Todas as decisões sensíveis
 * são reconstruídas pelo servidor antes desta instância ser criada.
 */
final readonly class CompanyContext
{
    /**
     * @param list<int> $allowedCompanyIds
     * @param array<int,list<string>> $allowedBusinessScopes
     */
    public function __construct(
        public string $contextId,
        public string $type,
        public int $userId,
        public ?int $companyId,
        public string $companySlug,
        public string $companyName,
        public string $businessScope,
        public string $role,
        public string $sellerScopeHash,
        public int $permissionsVersion,
        public array $allowedCompanyIds,
        public array $allowedBusinessScopes,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/', $this->contextId) !== 1) {
            throw new InvalidArgumentException('contextId opaco inválido.');
        }
        if (!in_array($this->type, ['company', 'group'], true)) {
            throw new InvalidArgumentException('Tipo de contexto inválido.');
        }
        if ($this->userId < 0 || $this->permissionsVersion < 0
            || ($this->userId === 0 && $this->role !== 'system')
        ) {
            throw new InvalidArgumentException('Identidade ou versão de permissão inválida.');
        }
        if (!in_array($this->businessScope, ['varejo', 'licitacao', 'mixed'], true)) {
            throw new InvalidArgumentException('Escopo de negócio inválido.');
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $this->allowedCompanyIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($ids !== $this->allowedCompanyIds) {
            throw new InvalidArgumentException('Lista de empresas do contexto não está normalizada.');
        }

        if ($this->type === 'company') {
            $scopes = $this->allowedBusinessScopes[$this->companyId] ?? null;
            // mixed = os DOIS canais no mapa (o label 'mixed' não entra na lista);
            // single = o único canal, e ele é o próprio businessScope.
            $scopeMapOk = is_array($scopes) && ($this->businessScope === 'mixed'
                ? $scopes === ['licitacao', 'varejo']
                : $scopes === [$this->businessScope]);
            if ($this->companyId === null
                || $this->companyId <= 0
                || $ids !== [$this->companyId]
                || $this->companySlug === ''
                || array_keys($this->allowedBusinessScopes) !== [$this->companyId]
                || !$scopeMapOk
            ) {
                throw new InvalidArgumentException('Contexto de empresa incompleto.');
            }
            return;
        }

        if ($this->companyId !== null
            || count($ids) < 2
            || array_keys($this->allowedBusinessScopes) !== $ids
        ) {
            throw new InvalidArgumentException('Contexto de grupo incompleto.');
        }
        foreach ($this->allowedBusinessScopes as $companyId => $scopes) {
            if (!in_array($companyId, $ids, true)
                || $scopes === []
                || count(array_diff($scopes, ['varejo', 'licitacao'])) > 0
                || $scopes !== array_values(array_unique($scopes))
            ) {
                throw new InvalidArgumentException('Mapa de escopos de negócio inválido.');
            }
        }
    }

    public function assertCompanyAccess(?int $companyId = null): void
    {
        if ($this->allowedCompanyIds === []) {
            throw new TenantContextUnavailableException(
                'context_company_forbidden',
                403,
                'O contexto não autoriza nenhuma empresa.',
            );
        }

        if ($companyId !== null && !in_array($companyId, $this->allowedCompanyIds, true)) {
            throw new TenantContextUnavailableException(
                'context_company_forbidden',
                403,
                'A empresa solicitada não pertence ao contexto atual.',
            );
        }
    }

    public function isGroup(): bool
    {
        return $this->type === 'group';
    }
}
