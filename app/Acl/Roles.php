<?php

declare(strict_types=1);

namespace BIomie\Acl;

/**
 * Matriz de perfis e capacidades do BIomie.
 *
 * Perfis disponíveis (do mais restrito ao mais amplo):
 *   viewer   — acesso somente-leitura ao módulo comercial; sem dossiê, sem CPF/CNPJ, sem exportação
 *   analyst  — acesso a todos os módulos analíticos, incluindo dossiê de clientes
 *   manager  — analyst + exportação de dados + visualização de documentos completos (CPF/CNPJ)
 *   admin    — tudo, incluindo gestão de usuários e observabilidade
 *
 * Retrocompatibilidade: o role 'user' é sinônimo de 'analyst'.
 *
 * Capacidades (strings no formato módulo.ação):
 *   commercial.view       — painel comercial, vendedores, pedidos
 *   financial.view        — módulo financeiro
 *   fiscal.view           — NF-e, reconciliação fiscal
 *   geo.view              — mapa de vendas, análise geográfica
 *   intelligence.view     — inteligência comercial e oportunidades
 *   forecast.view         — projeções e forecasts
 *   dossier.view          — dossiê completo de clientes (client_profile)
 *   observability.view    — painel de observabilidade
 *   reconciliation.view   — painel de reconciliação fiscal
 *   documents.full        — ver CPF/CNPJ sem mascaramento
 *   export                — exportar dados para CSV/JSON
 *   users.manage          — criar, editar e desativar usuários
 */
final class Roles
{
    /** Roles aceitos na criação/edição de usuários (em ordem crescente de privilégio) */
    public const VALID_ROLES = ['viewer', 'analyst', 'manager', 'admin'];

    /** Alias legado → role canônico */
    private const ALIASES = ['user' => 'analyst'];

    /** Mapa role → lista de capacidades */
    private const CAPABILITIES = [
        'viewer' => [
            'sales.view',
            'commercial.view',
            'dashboard.view',
            'geo.view',
        ],
        'analyst' => [
            'sales.view',
            'commercial.view',
            'dashboard.view',
            'finance.view',
            'financial.view',
            'fiscal.view',
            'nf.view',
            'reconciliation.view',
            'geo.view',
            'omie.view',
            'jobs.view',
            'intelligence.view',
            'forecast.view',
            'dossier.view',
        ],
        'manager' => [
            'sales.view',
            'sales.sync',
            'sales.export',
            'commercial.view',
            'dashboard.view',
            'finance.view',
            'finance.export',
            'financial.view',
            'fiscal.view',
            'fiscal.export',
            'nf.view',
            'reconciliation.view',
            'geo.view',
            'geo.materialize',
            'omie.view',
            'jobs.view',
            'logs.view',
            'intelligence.view',
            'forecast.view',
            'dossier.view',
            'documents.full',
            'export',
            'admin.logs',
        ],
        'admin' => [
            'sales.view',
            'sales.sync',
            'sales.export',
            'commercial.view',
            'dashboard.view',
            'finance.view',
            'finance.sync',
            'finance.export',
            'financial.view',
            'fiscal.view',
            'fiscal.sync',
            'fiscal.export',
            'nf.view',
            'reconciliation.view',
            'geo.view',
            'geo.materialize',
            'omie.view',
            'omie.sync',
            'jobs.view',
            'jobs.run',
            'logs.view',
            'admin.view',
            'admin.manage',
            'settings.view',
            'settings.manage',
            'intelligence.view',
            'forecast.view',
            'dossier.view',
            'documents.full',
            'export',
            'users.manage',
            'admin.users',
            'admin.migrations',
            'admin.logs',
            'admin.console',
            'observability.view',
        ],
    ];

    /** Resolve alias e retorna o role canônico. Fallback para 'viewer' se inválido. */
    public static function resolve(string $role): string
    {
        $role = strtolower(trim($role));
        $role = self::ALIASES[$role] ?? $role;
        return array_key_exists($role, self::CAPABILITIES) ? $role : 'viewer';
    }

    /**
     * Retorna as capacidades de um role, considerando override de módulos por usuário.
     *
     * @param string        $role         Role do usuário (ex.: 'analyst')
     * @param list<string>|null $modulesOverride  Se não-null, intersecta as caps do role com os módulos permitidos
     * @return list<string>
     */
    public static function capabilities(string $role, ?array $modulesOverride = null): array
    {
        $canonical = self::resolve($role);
        $caps = self::CAPABILITIES[$canonical] ?? [];

        if ($modulesOverride === null || $modulesOverride === []) {
            return $caps;
        }

        // Override: mantém apenas caps cujo prefixo de módulo está na lista permitida
        return array_values(array_filter(
            $caps,
            static function (string $cap) use ($modulesOverride): bool {
                $module = explode('.', $cap)[0];
                return in_array($module, $modulesOverride, true)
                    // capacidades sem prefixo de módulo (export, documents.full, users.manage)
                    // são controladas pelo role base, não pelo override de módulo
                    || in_array($cap, $modulesOverride, true);
            }
        ));
    }

    /**
     * Retorna o label de exibição do role (para UI).
     */
    public static function label(string $role): string
    {
        return match (self::resolve($role)) {
            'viewer'  => 'Visualizador',
            'analyst' => 'Analista',
            'manager' => 'Gestor',
            'admin'   => 'Administrador',
            default   => ucfirst($role),
        };
    }

    /**
     * Verifica se um role é válido para criação/edição de usuários.
     */
    public static function isValid(string $role): bool
    {
        return in_array(strtolower(trim($role)), self::VALID_ROLES, true);
    }
}
