<?php

declare(strict_types=1);

namespace BIomie\Support;

use PDO;

/**
 * Resolução cross-empresa de clientes por CNPJ.
 *
 * Consulta biomie_clientes_grupo_membros para encontrar todas as instâncias
 * de um cliente em diferentes empresas do grupo — permitindo visão consolidada.
 */
final class ClientGroupResolver
{
    public function __construct(private readonly PDO $pdo) {}

    // ─── Lookup por CNPJ ──────────────────────────────────────────────────────

    /**
     * Retorna o grupo canônico de um CNPJ (se existir na tabela grupo).
     *
     * @return array{cnpj:string, nome_canonical:string, total_companies:int,
     *               total_receita:float, membros:list<array<string,mixed>>}|null
     */
    public function findByCnpj(string $cnpj): ?array
    {
        $cnpjClean = $this->cleanCnpj($cnpj);
        if (strlen($cnpjClean) !== 14) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT g.cnpj, g.nome_canonical, g.primary_company_id,
                    g.total_companies, g.total_pedidos, g.total_nfs,
                    g.total_receita, g.first_seen_at, g.last_seen_at
               FROM biomie_clientes_grupo g
              WHERE g.cnpj = :cnpj"
        );
        $stmt->execute(['cnpj' => $cnpjClean]);
        $grupo = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($grupo)) {
            return null;
        }

        $membros = $this->getMembros($cnpjClean);
        return array_merge($grupo, ['membros' => $membros]);
    }

    /**
     * Todos os membros (instâncias por empresa) de um CNPJ.
     *
     * @return list<array{company_id:int, slug:string, nome_fantasia:string,
     *                     codigo_cliente_omie:int|null, nome_local:string,
     *                     canal_predominante:string, total_receita:float}>
     */
    public function getMembros(string $cnpj): array
    {
        $cnpjClean = $this->cleanCnpj($cnpj);
        $stmt = $this->pdo->prepare(
            "SELECT m.company_id, c.slug, c.nome_fantasia,
                    m.codigo_cliente_omie, m.nome_local,
                    m.canal_predominante, m.total_pedidos,
                    m.total_nfs, m.total_receita,
                    m.first_seen_at, m.last_seen_at
               FROM biomie_clientes_grupo_membros m
               JOIN biomie_companies c ON c.id = m.company_id
              WHERE m.cnpj = :cnpj
              ORDER BY m.total_receita DESC"
        );
        $stmt->execute(['cnpj' => $cnpjClean]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * CNPJs que aparecem em 2+ empresas — clientes compartilhados do grupo.
     *
     * @return list<array{cnpj:string, nome_canonical:string, total_companies:int, total_receita:float}>
     */
    public function getSharedClients(int $minCompanies = 2): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT cnpj, nome_canonical, primary_company_id,
                    total_companies, total_pedidos, total_receita
               FROM biomie_clientes_grupo
              WHERE total_companies >= :min
              ORDER BY total_companies DESC, total_receita DESC"
        );
        $stmt->execute(['min' => $minCompanies]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Empresas que contêm este CNPJ como cliente (apenas IDs).
     *
     * @return list<int>
     */
    public function getCompanyIdsForCnpj(string $cnpj): array
    {
        $cnpjClean = $this->cleanCnpj($cnpj);
        $stmt = $this->pdo->prepare(
            "SELECT company_id FROM biomie_clientes_grupo_membros WHERE cnpj = :cnpj"
        );
        $stmt->execute(['cnpj' => $cnpjClean]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * Estatísticas do grupo para exibição no drawer de cliente.
     *
     * @return array{shared:bool, total_companies:int, total_receita:float, membros:list<array<string,mixed>>}
     */
    public function grupoBriefForCnpj(string $cnpj): array
    {
        $grupo = $this->findByCnpj($cnpj);
        if ($grupo === null) {
            return ['shared' => false, 'total_companies' => 1, 'total_receita' => 0.0, 'membros' => []];
        }
        return [
            'shared'          => (int) $grupo['total_companies'] >= 2,
            'total_companies' => (int) $grupo['total_companies'],
            'total_receita'   => (float) $grupo['total_receita'],
            'membros'         => $grupo['membros'],
        ];
    }

    // ─── Utilitários ──────────────────────────────────────────────────────────

    private function cleanCnpj(string $cnpj): string
    {
        return preg_replace('/\D/', '', $cnpj) ?? '';
    }
}
