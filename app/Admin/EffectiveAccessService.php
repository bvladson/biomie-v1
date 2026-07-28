<?php

declare(strict_types=1);

namespace BIomie\Admin;

use BIomie\Acl\Roles;
use BIomie\Support\SellerVisibilityScope;
use PDO;

/**
 * ACESSO EFETIVO — o que o usuário REALMENTE consegue ver, já resolvido.
 *
 * ─── POR QUE EXISTE ─────────────────────────────────────────────────────────
 * O BIomie combina QUATRO camadas independentes para decidir uma tela:
 *   1. papel global (biomie_users.role)
 *   2. papel POR EMPRESA (biomie_user_companies.role_override) — vence o global
 *   3. canais varejo/licitação por empresa
 *   4. visibilidade de vendedores (SellerVisibilityScope)
 * Ler as quatro telas separadas e fazer a conta de cabeça é onde nasce a
 * pergunta clássica — "por que esse usuário está vendo isso?". Este serviço faz
 * a conta e mostra o RESULTADO, com a ORIGEM de cada decisão.
 *
 * É SOMENTE LEITURA: não concede nem revoga nada. Se divergir da realidade, o
 * bug está na camada que ele lê — nunca aqui.
 */
final class EffectiveAccessService
{
    /**
     * Módulos do app na linguagem do gestor, e a capability que os destranca.
     * Ordem = ordem de exibição.
     */
    private const MODULES = [
        ['label' => 'Painel Comercial',      'capability' => 'commercial.view'],
        ['label' => 'Agenda de Orçamentos',  'capability' => 'sales.quotes.followup.view'],
        ['label' => 'Minha carteira (vendedor)', 'capability' => 'sales.quotes.followup.self'],
        ['label' => 'Registrar cobrança',    'capability' => 'sales.quotes.followup.manage'],
        ['label' => 'Time Comercial',        'capability' => 'sales.view'],
        ['label' => 'Geografia',             'capability' => 'geo.view'],
        ['label' => 'Financeiro',            'capability' => 'finance.view'],
        ['label' => 'Inteligência Fiscal',   'capability' => 'fiscal.view'],
        ['label' => 'Conciliação',           'capability' => 'reconciliation.view'],
        ['label' => 'Exportar dados',        'capability' => 'export'],
        ['label' => 'Administração',         'capability' => 'admin.view'],
        ['label' => 'Gerenciar usuários',    'capability' => 'admin.manage'],
        ['label' => 'Migrations',            'capability' => 'admin.migrations'],
        ['label' => 'Console',               'capability' => 'admin.console'],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Acesso efetivo de um usuário, empresa por empresa.
     *
     * @return array<string,mixed>
     */
    public function forUser(int $userId): array
    {
        $user = $this->user($userId);
        if ($user === null) {
            return ['ok' => false, 'error' => 'user_not_found'];
        }

        $globalRole = (string) $user['role'];
        $scope = new SellerVisibilityScope($this->pdo);
        $companies = [];

        foreach ($this->companyLinks($userId) as $link) {
            $companyId = (int) $link['company_id'];
            $override  = trim((string) ($link['role_override'] ?? ''));
            $role      = $override !== '' ? $override : $globalRole;
            $caps      = Roles::capabilities($role);

            // Módulos: o que a capability resolvida libera.
            $modules = [];
            foreach (self::MODULES as $m) {
                $modules[] = [
                    'label'   => $m['label'],
                    'allowed' => in_array($m['capability'], $caps, true),
                    'capability' => $m['capability'],
                ];
            }

            // Vendedores: null = todos; [] = nenhum; lista = exatamente esses.
            $allowedIds = $scope->allowedSellerIds($companyId, $userId);
            $mode       = $scope->mode($companyId, $userId);

            $companies[] = [
                'company_id'   => $companyId,
                'slug'         => (string) $link['slug'],
                'name'         => (string) $link['nome_fantasia'],
                'role'         => $role,
                'role_source'  => $override !== ''
                    ? 'override da empresa (vence o papel global)'
                    : 'papel global do usuário',
                'role_is_override' => $override !== '',
                'channels'     => [
                    'varejo'    => (bool) $link['can_varejo'],
                    'licitacao' => (bool) $link['can_licitacao'],
                ],
                'is_default'   => (bool) $link['is_default'],
                'modules'      => $modules,
                'sellers'      => $this->sellerBreakdown($companyId, $allowedIds),
                'seller_scope' => [
                    'mode'        => $mode,
                    'mode_label'  => self::modeLabel($mode),
                    'restricted'  => $allowedIds !== null,
                    'allowed_count' => $allowedIds === null ? null : count($allowedIds),
                ],
            ];
        }

        return [
            'ok' => true,
            'user' => [
                'id' => (int) $user['id'],
                'username' => (string) $user['username'],
                'full_name' => (string) $user['full_name'],
                'role' => $globalRole,
                'is_active' => (bool) $user['is_active'],
            ],
            'companies' => $companies,
            'company_count' => count($companies),
            'note' => $companies === []
                ? 'Este usuário não tem acesso a nenhuma empresa — não vê dado algum.'
                : null,
        ];
    }

    /**
     * Vendedores da empresa marcados como visíveis/ocultos para o usuário.
     *
     * @param  list<string>|null $allowed
     * @return list<array{seller_id:string,name:string,visible:bool}>
     */
    private function sellerBreakdown(int $companyId, ?array $allowed): array
    {
        $st = $this->pdo->prepare(
            "SELECT codigo_omie, nome FROM biomie_vendedores_omie
              WHERE company_id = :c AND ativo = 1 AND codigo_omie NOT IN ('', '0')
           ORDER BY nome"
        );
        $st->execute([':c' => $companyId]);

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $code = (string) $r['codigo_omie'];
            $out[] = [
                'seller_id' => $code,
                'name' => (string) ($r['nome'] ?: $code),
                // null = sem restrição ⇒ todos visíveis.
                'visible' => $allowed === null || in_array($code, $allowed, true),
            ];
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    private function user(int $userId): ?array
    {
        $st = $this->pdo->prepare('SELECT id, username, full_name, role, is_active FROM biomie_users WHERE id = :id');
        $st->execute([':id' => $userId]);

        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return list<array<string,mixed>> */
    private function companyLinks(int $userId): array
    {
        $st = $this->pdo->prepare(
            'SELECT uc.company_id, uc.can_varejo, uc.can_licitacao, uc.is_default, uc.role_override,
                    c.slug, c.nome_fantasia
               FROM biomie_user_companies uc
               JOIN biomie_companies c ON c.id = uc.company_id
              WHERE uc.user_id = :u AND c.ativo = 1
           ORDER BY c.id'
        );
        $st->execute([':u' => $userId]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private static function modeLabel(string $mode): string
    {
        return match ($mode) {
            SellerVisibilityScope::MODE_ALL      => 'Todos os vendedores',
            SellerVisibilityScope::MODE_OWN      => 'Somente o próprio (vínculo)',
            SellerVisibilityScope::MODE_SELECTED => 'Personalizado',
            default => $mode,
        };
    }
}
