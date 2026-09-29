<?php

declare(strict_types=1);

namespace BIomie\Admin;

use BIomie\Acl\Roles;
use BIomie\AuthRepository;
use PDO;
use Throwable;

/**
 * PROVISIONAMENTO DE USUÁRIO — identidade + acessos + visibilidade num ÚNICO commit.
 *
 * ─── POR QUE EXISTE ─────────────────────────────────────────────────────────
 * Criar o usuário numa aba, dar empresa em outra e configurar vendedores numa
 * terceira produz CONTA PARCIALMENTE CONFIGURADA sempre que o admin é
 * interrompido no meio — e uma conta com acesso e sem escopo é justamente a que
 * enxerga demais. Aqui as três coisas nascem juntas ou nenhuma nasce.
 *
 * ─── GARANTIAS ─────────────────────────────────────────────────────────────
 *  1. NADA é gravado antes da confirmação — o assistente vive no cliente.
 *  2. TUDO valida ANTES de abrir a transação (falha barata, sem estado sujo).
 *  3. UMA transação: usuário → papel → acessos → visibilidade → auditoria.
 *     Qualquer falha desfaz tudo, inclusive a auditoria.
 *  4. Autorização RECALCULADA aqui — o payload do cliente nunca é confiado:
 *     · não se concede papel acima do próprio (admin comercial não cria admin);
 *     · não se concede empresa a que o próprio ator não tem acesso;
 *     · vendedor precisa pertencer À EMPRESA em que foi escolhido;
 *     · exatamente UMA empresa padrão.
 *  5. Erros nunca vazam SQL, stack trace, hash ou segredo.
 *
 * O nonce de uso único (anti-duplo-clique) é responsabilidade do endpoint —
 * aqui a defesa complementar é o UNIQUE de username/email no banco.
 */
final class UserProvisioningService
{
    /**
     * Hierarquia de papéis — DECLARADA, não derivada de id de tabela nem ordem
     * alfabética. Ninguém concede acima do próprio nível.
     *
     * Um papel NOVO que entre em Roles::CAPABILITIES mas não aqui é tratado como
     * o mais alto possível ({@see rank}) — logo, INCONCEDÍVEL até ser
     * classificado. É fail-closed deliberado: preferimos um papel novo
     * temporariamente inacessível pelo assistente a um papel novo concedido sem
     * saber onde ele fica na hierarquia.
     */
    private const ROLE_RANK = ['viewer' => 1, 'analyst' => 2, 'manager' => 3, 'admin' => 4];

    /**
     * Nível de um papel na hierarquia. Papel não classificado ⇒ PHP_INT_MAX
     * (inconcedível). Papel do ATOR não classificado ⇒ trate como 0 via o
     * segundo argumento (não concede nada).
     */
    private static function rank(string $role, int $unranked = PHP_INT_MAX): int
    {
        return self::ROLE_RANK[$role] ?? $unranked;
    }

    public function __construct(
        private readonly PDO $pdo,
        private readonly RbacService $rbac,
        private readonly AdminLogService $logs,
        private readonly \BIomie\Logger $logger,
    ) {
    }

    /**
     * @param  array<string,mixed> $input
     * @return array<string,mixed> resposta pronta para o endpoint (com http_status)
     */
    public function provision(array $input, int $actorId, string $actorRole): array
    {
        // ── 1. VALIDAÇÃO (antes de qualquer escrita) ────────────────────────
        $errors = [];

        $fullName = trim((string) ($input['name'] ?? ''));
        $username = strtolower(trim((string) ($input['username'] ?? '')));
        $email    = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $isActive = (bool) ($input['is_active'] ?? true);

        if ($fullName === '')                                   { $errors['name'] = 'Informe o nome completo.'; }
        if ($username === '')                                   { $errors['username'] = 'Informe o nome de usuário.'; }
        elseif (preg_match('/^[a-z0-9._-]{3,32}$/', $username) !== 1) {
            $errors['username'] = 'Use 3 a 32 caracteres: letras, números, ponto, hífen ou sublinhado.';
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'E-mail inválido.';
        }
        if (strlen($password) < 8) {
            $errors['password'] = 'A senha inicial precisa de ao menos 8 caracteres.';
        }

        // Unicidade: o UNIQUE do banco é a rede final, mas conferir aqui devolve
        // erro de campo em vez de "falha interna".
        if ($username !== '' && $this->exists('username', $username)) {
            $errors['username'] = 'Este nome de usuário já está em uso.';
        }
        if ($email !== '' && $this->exists('email', $email)) {
            $errors['email'] = 'Este e-mail já está em uso.';
        }

        // ── Empresas ────────────────────────────────────────────────────────
        $companies = is_array($input['companies'] ?? null) ? array_values($input['companies']) : [];
        if ($companies === []) {
            $errors['companies'] = 'Selecione ao menos uma empresa.';
        }

        $actorCompanies = $this->actorCompanyIds($actorId);
        // Ator com papel não classificado ⇒ nível 0: não concede nada.
        $actorRank = self::rank(Roles::resolve($actorRole), 0);
        $validCompanies = $this->activeCompanies();

        $normalized = [];
        $defaults = 0;
        foreach ($companies as $i => $row) {
            $cid  = (int) ($row['company_id'] ?? 0);
            $key  = "companies.{$i}";

            // Papel é um conjunto FECHADO — valida o valor BRUTO por allowlist.
            // NÃO usar Roles::resolve aqui: ele rebaixa desconhecido a 'viewer'
            // em silêncio, o que ACEITARIA um payload adulterado ('superhacker')
            // em vez de recusá-lo. Um valor fora da lista num campo de enum é
            // sinal de adulteração e deve virar 422, não uma normalização muda.
            // (O fallback de Roles::resolve segue valendo em LEITURAS legadas.)
            $rawRole = strtolower(trim((string) ($row['role'] ?? '')));
            if (!array_key_exists($rawRole, self::ROLE_RANK)) {
                $errors["{$key}.role"] = 'INVALID_ROLE: o papel informado não é válido.';
                continue;
            }
            $role = $rawRole;

            if (!isset($validCompanies[$cid])) {
                $errors["{$key}.company_id"] = 'Empresa inexistente ou inativa.';
                continue;
            }
            // Não se concede acesso a empresa que o próprio ator não possui.
            if (!in_array($cid, $actorCompanies, true)) {
                $errors["{$key}.company_id"] = 'Você não tem acesso a ' . $validCompanies[$cid]['nome'] . ' e não pode concedê-la.';
                continue;
            }
            // Não se concede papel acima do próprio.
            if (self::rank($role) > $actorRank) {
                $errors["{$key}.role"] = 'Você não pode conceder o perfil "' . $role . '".';
                continue;
            }

            $isDefault = (bool) ($row['is_default'] ?? false);
            if ($isDefault) { $defaults++; }

            $normalized[$cid] = [
                'company_id'    => $cid,
                'role'          => $role,
                // Licitação é venda: acesso novo enxerga os dois canais, a menos
                // que o admin desmarque explicitamente.
                'can_varejo'    => (bool) ($row['can_varejo'] ?? true),
                'can_licitacao' => (bool) ($row['can_licitacao'] ?? true),
                'is_default'    => $isDefault,
            ];
            // Acesso sem canal nenhum não vê dado algum — Varejo é o padrão.
            if (!$normalized[$cid]['can_varejo'] && !$normalized[$cid]['can_licitacao']) {
                $normalized[$cid]['can_varejo'] = true;
            }
        }

        if ($normalized !== [] && $defaults !== 1) {
            $errors['companies.default'] = $defaults === 0
                ? 'Escolha uma empresa padrão.'
                : 'Só pode haver UMA empresa padrão.';
        }

        // ── Visibilidade por empresa ────────────────────────────────────────
        $visibilityIn = is_array($input['visibility'] ?? null) ? $input['visibility'] : [];
        $visibility = [];
        foreach ($normalized as $cid => $_) {
            $conf = is_array($visibilityIn[(string) $cid] ?? null) ? $visibilityIn[(string) $cid] : [];
            $mode = strtolower(trim((string) ($conf['mode'] ?? 'all')));
            if (!in_array($mode, ['all', 'own', 'selected'], true)) {
                $errors["visibility.{$cid}.mode"] = 'Modo de visibilidade inválido.';
                continue;
            }

            $sellerIds = [];
            if ($mode === 'selected') {
                $raw = array_values(array_unique(array_filter(
                    array_map(static fn ($s): string => trim((string) $s), (array) ($conf['seller_ids'] ?? [])),
                    static fn (string $s): bool => $s !== '' && $s !== '0'
                )));
                if ($raw === []) {
                    $errors["visibility.{$cid}.seller_ids"] = 'No modo Personalizado, escolha ao menos um vendedor.';
                    continue;
                }
                // O vendedor tem de pertencer À EMPRESA — payload adulterado cai aqui.
                $valid = $this->sellersOfCompany($cid, $raw);
                $unknown = array_values(array_diff($raw, $valid));
                if ($unknown !== []) {
                    $errors["visibility.{$cid}.seller_ids"] =
                        'Vendedor fora do cadastro de ' . $validCompanies[$cid]['nome'] . ': ' . implode(', ', $unknown);
                    continue;
                }
                $sellerIds = $valid;
            }

            $visibility[$cid] = ['mode' => $mode, 'seller_ids' => $sellerIds];
        }

        if ($errors !== []) {
            return ['http_status' => 422, 'body' => [
                'ok' => false, 'code' => 'VALIDATION_FAILED', 'errors' => $errors,
            ]];
        }

        // Papel GLOBAL = o menor concedido. Se um vínculo de empresa sumir, o
        // usuário cai no papel MENOS privilegiado — nunca no maior. (Aqui todos
        // os papéis já passaram pela recusa de não classificados acima.)
        $globalRole = 'viewer';
        $lowest = PHP_INT_MAX;
        foreach ($normalized as $c) {
            $r = self::rank($c['role']);
            if ($r < $lowest) { $lowest = $r; $globalRole = $c['role']; }
        }

        // ── 2. TRANSAÇÃO ÚNICA ──────────────────────────────────────────────
        $this->pdo->beginTransaction();
        try {
            $userId = (new AuthRepository($this->pdo, $this->logger))->createUser(
                $username, $fullName, $email !== '' ? $email : null, $password, $globalRole, $isActive, $actorId ?: null
            );
            if ($userId <= 0) {
                throw new \RuntimeException('createUser não devolveu id.');
            }

            // Papel + sincronia RBAC, SEM abrir transação aninhada.
            $this->rbac->applySingleRole($userId, $globalRole, $actorId ?: null);

            $link = $this->pdo->prepare(
                'INSERT INTO biomie_user_companies
                    (user_id, company_id, can_varejo, can_licitacao, is_default, role_override)
                 VALUES (:u, :c, :v, :l, :d, :r)'
            );
            // Placeholders DISTINTOS para created_by/updated_by: com prepares
            // nativos o PDO não aceita o mesmo nome duas vezes na query.
            $modeIns = $this->pdo->prepare(
                'INSERT INTO biomie_user_seller_scope (company_id, user_id, visibility_mode, created_by, updated_by)
                 VALUES (:c, :u, :m, :by_c, :by_u)'
            );
            $pickIns = $this->pdo->prepare(
                'INSERT INTO biomie_user_visible_sellers (company_id, user_id, seller_id, created_by)
                 VALUES (:c, :u, :s, :by)'
            );

            foreach ($normalized as $cid => $c) {
                $link->execute([
                    ':u' => $userId, ':c' => $cid,
                    ':v' => $c['can_varejo'] ? 1 : 0,
                    ':l' => $c['can_licitacao'] ? 1 : 0,
                    ':d' => $c['is_default'] ? 1 : 0,
                    ':r' => $c['role'],
                ]);

                $vis = $visibility[$cid] ?? ['mode' => 'all', 'seller_ids' => []];
                $modeIns->execute([
                    ':c' => $cid, ':u' => $userId, ':m' => $vis['mode'],
                    ':by_c' => $actorId ?: null, ':by_u' => $actorId ?: null,
                ]);
                foreach ($vis['seller_ids'] as $sid) {
                    $pickIns->execute([':c' => $cid, ':u' => $userId, ':s' => $sid, ':by' => $actorId ?: null]);
                }
            }

            // Auditoria DENTRO da transação: se ela falhar, o provisionamento
            // inteiro volta. Conta sem trilha é pior que conta não criada.
            $this->logs->log('USER_PROVISIONED', $userId, [
                'username'   => $username,
                'full_name'  => $fullName,
                'global_role' => $globalRole,
                'is_active'  => $isActive,
                'companies'  => array_map(static fn (array $c): array => [
                    'company_id' => $c['company_id'],
                    'role'       => $c['role'],
                    'varejo'     => $c['can_varejo'],
                    'licitacao'  => $c['can_licitacao'],
                    'is_default' => $c['is_default'],
                    'visibility_mode'  => $visibility[$c['company_id']]['mode'] ?? 'all',
                    'visible_sellers'  => count($visibility[$c['company_id']]['seller_ids'] ?? []),
                ], array_values($normalized)),
                // NUNCA: senha, hash, token, nonce.
            ], $actorId ?: null);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            return ['http_status' => 500, 'body' => [
                'ok' => false,
                'code' => 'PROVISIONING_FAILED',
                'message' => 'Nenhuma alteração foi aplicada.',
            ], 'internal' => $e->getMessage()];
        }

        $defaultCid = 0;
        foreach ($normalized as $cid => $c) {
            if ($c['is_default']) { $defaultCid = $cid; }
        }
        $totalSellers = 0;
        foreach ($visibility as $v) { $totalSellers += count($v['seller_ids']); }
        $modes = array_values(array_unique(array_map(static fn (array $v): string => $v['mode'], $visibility)));

        return ['http_status' => 201, 'body' => [
            'ok' => true,
            'user' => [
                'id' => $userId,
                'name' => $fullName,
                'username' => $username,
                'status' => $isActive ? 'active' : 'inactive',
            ],
            'summary' => [
                'companies' => count($normalized),
                'default_company' => $validCompanies[$defaultCid]['nome'] ?? null,
                'seller_visibility' => count($modes) === 1 ? $modes[0] : 'mixed',
                'visible_sellers' => $totalSellers,
            ],
        ]];
    }

    // ── Consultas de apoio ──────────────────────────────────────────────────

    private function exists(string $column, string $value): bool
    {
        $st = $this->pdo->prepare("SELECT 1 FROM biomie_users WHERE {$column} = :v LIMIT 1");
        $st->execute([':v' => $value]);

        return $st->fetchColumn() !== false;
    }

    /** @return list<int> */
    private function actorCompanyIds(int $actorId): array
    {
        $st = $this->pdo->prepare('SELECT company_id FROM biomie_user_companies WHERE user_id = :u');
        $st->execute([':u' => $actorId]);

        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<int,array{nome:string}> */
    private function activeCompanies(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT id, nome_fantasia, slug FROM biomie_companies WHERE ativo = 1') as $r) {
            $out[(int) $r['id']] = ['nome' => (string) ($r['nome_fantasia'] ?: $r['slug'])];
        }

        return $out;
    }

    /**
     * @param  list<string> $codes
     * @return list<string> os que REALMENTE existem na empresa
     */
    private function sellersOfCompany(int $companyId, array $codes): array
    {
        if ($codes === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($codes), '?'));
        $st = $this->pdo->prepare(
            "SELECT codigo_omie FROM biomie_vendedores_omie WHERE company_id = ? AND codigo_omie IN ({$ph})"
        );
        $st->execute(array_merge([$companyId], $codes));

        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
}
