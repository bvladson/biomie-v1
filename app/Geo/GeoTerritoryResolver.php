<?php

declare(strict_types=1);

namespace BIomie\Geo;

use PDO;

/**
 * GEO.R1 — Resolução e normalização de território geográfico.
 *
 * Regras rígidas:
 *  1. Para camada fortaleza_bairros: usa lógica existente (NeighborhoodNormalizer) sem alterar.
 *  2. Para grande_fortaleza_bairros: bairro de Fortaleza usa lógica atual; adjacente com
 *     has_bairro_layer=1 usa bairro oficial + alias; sem geometria → agrega no município.
 *  3. Para rmf_municipios / ceara_municipios: agrega por ibge_code / cidade_norm.
 *  4. Para ceara_regioes: agrega por regiao_planejamento_id via JOIN.
 *  5. Nunca inventa bairro. Nunca chama ViaCEP em contexto web.
 */
final class GeoTerritoryResolver
{
    private PDO $pdo;

    /** @var array<string, string> ibge_code → regiao_id cache */
    private array $municipioToRegiao = [];

    /** @var array<string, array{ibge_code: string, nome: string, is_rmf: bool, has_bairro_layer: bool}> */
    private array $municipioCache = [];

    /** @var array<string, string> bairro_norm → bairro_canonical cache por ibge */
    private array $aliasCache = [];

    /** @var array<string, bool> ibge_code → is_rmf */
    private array $rmfCache = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Normaliza o nome de uma cidade para chave de match.
     * Remove acentos, minúscula, colapsa espaços, remove sufixo de UF.
     */
    public static function normalizeCity(string $city): string
    {
        $v = mb_strtolower(trim($city));
        $v = str_replace([' - ce', ' - ceará', '-ce'], '', $v);
        $v = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $v) ?: $v;
        $v = preg_replace('/[^a-z0-9]+/', '_', $v) ?? $v;
        return trim($v, '_');
    }

    /**
     * Normaliza o nome de um bairro para chave de match.
     */
    public static function normalizeNeighborhood(string $bairro): string
    {
        $v = mb_strtolower(trim($bairro));
        $v = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $v) ?: $v;
        $v = preg_replace('/[^a-z0-9]+/', '_', $v) ?? $v;
        return trim($v, '_');
    }

    /**
     * Verifica se um código IBGE pertence à RMF.
     */
    public function isRMF(string $ibgeCode): bool
    {
        if (isset($this->rmfCache[$ibgeCode])) {
            return $this->rmfCache[$ibgeCode];
        }
        if (!$this->tableExists('biomie_geo_municipios_ce')) {
            // fallback: lista hardcoded dos 19 municípios da RMF
            $rmf = ['2304400','2303709','2307650','2307700','2304285','2301000',
                    '2306256','2309607','2309706','2306306','2304954','2303956',
                    '2303501','2311264','2310209','2310258','2312403','2312601','2313401'];
            return $this->rmfCache[$ibgeCode] = in_array($ibgeCode, $rmf, true);
        }
        try {
            $stmt = $this->pdo->prepare("SELECT is_rmf FROM biomie_geo_municipios_ce WHERE ibge_code = :c LIMIT 1");
            $stmt->execute([':c' => $ibgeCode]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $this->rmfCache[$ibgeCode] = (bool) ($row['is_rmf'] ?? false);
        } catch (\Throwable) {
            return $this->rmfCache[$ibgeCode] = false;
        }
    }

    /**
     * Verifica se um município tem camada de bairros validada.
     */
    public function hasBairroLayer(string $ibgeCode): bool
    {
        if (!$this->tableExists('biomie_geo_municipios_ce')) return false;
        try {
            $stmt = $this->pdo->prepare("SELECT has_bairro_layer FROM biomie_geo_municipios_ce WHERE ibge_code = :c LIMIT 1");
            $stmt->execute([':c' => $ibgeCode]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (bool) ($row['has_bairro_layer'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Verifica se um cidade_norm é Ceará.
     */
    public function isCeara(string $cidadeNorm): bool
    {
        if (!$this->tableExists('biomie_geo_municipios_ce')) {
            return true; // assume Ceará se não há tabela
        }
        try {
            $stmt = $this->pdo->prepare("SELECT 1 FROM biomie_geo_municipios_ce WHERE nome_norm = :c AND uf = 'CE' LIMIT 1");
            $stmt->execute([':c' => $cidadeNorm]);
            return (bool) $stmt->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Resolve o ibge_code de um cidade_norm.
     */
    public function resolveMunicipality(string $cidadeNorm): ?string
    {
        if (!$this->tableExists('biomie_geo_municipios_ce')) return null;
        try {
            $stmt = $this->pdo->prepare("SELECT ibge_code FROM biomie_geo_municipios_ce WHERE nome_norm = :c LIMIT 1");
            $stmt->execute([':c' => $cidadeNorm]);
            $row = $stmt->fetchColumn();
            return $row ? (string) $row : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve o bairro_norm de Fortaleza — usa lógica atual (NeighborhoodNormalizer).
     */
    public static function resolveFortalezaNeighborhood(string $bairroNorm): string
    {
        return $bairroNorm; // passa direto — NeighborhoodNormalizer já normalizou no mart
    }

    /**
     * Resolve o bairro de um município adjacente com has_bairro_layer=1.
     * Tenta alias table; fallback para normalização direta.
     */
    public function resolveAdjacentNeighborhood(string $bairroNorm, string $ibgeCode): ?string
    {
        $cacheKey = $ibgeCode . '::' . $bairroNorm;
        if (isset($this->aliasCache[$cacheKey])) {
            return $this->aliasCache[$cacheKey];
        }
        if (!$this->tableExists('biomie_geo_bairro_aliases')) {
            return $this->aliasCache[$cacheKey] = $bairroNorm;
        }
        try {
            $stmt = $this->pdo->prepare(
                "SELECT bairro_norm FROM biomie_geo_bairro_aliases
                 WHERE (ibge_code = :ibge OR ibge_code IS NULL)
                   AND alias_norm = :alias
                 ORDER BY ibge_code DESC LIMIT 1"
            );
            $stmt->execute([':ibge' => $ibgeCode, ':alias' => $bairroNorm]);
            $resolved = $stmt->fetchColumn();
            return $this->aliasCache[$cacheKey] = $resolved ? (string) $resolved : $bairroNorm;
        } catch (\Throwable) {
            return $this->aliasCache[$cacheKey] = $bairroNorm;
        }
    }

    /**
     * Resolve a região IPECE de um cidade_norm / ibge_code.
     */
    public function resolveCearaRegion(string $ibgeCode): ?string
    {
        if (isset($this->municipioToRegiao[$ibgeCode])) {
            return $this->municipioToRegiao[$ibgeCode];
        }
        if (!$this->tableExists('biomie_geo_municipios_ce')) return null;
        try {
            $stmt = $this->pdo->prepare(
                "SELECT regiao_planejamento_id FROM biomie_geo_municipios_ce WHERE ibge_code = :c LIMIT 1"
            );
            $stmt->execute([':c' => $ibgeCode]);
            $row = $stmt->fetchColumn();
            $val = $row ? (string) $row : null;
            $this->municipioToRegiao[$ibgeCode] = $val ?? '';
            return $val;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Lista de ibge_codes da RMF (fallback hardcoded se tabela ausente).
     *
     * @return list<string>
     */
    public function listRMFCodes(): array
    {
        if ($this->tableExists('biomie_geo_municipios_ce')) {
            try {
                $rows = $this->pdo->query(
                    "SELECT ibge_code FROM biomie_geo_municipios_ce WHERE is_rmf = 1"
                )->fetchAll(PDO::FETCH_COLUMN) ?: [];
                if (!empty($rows)) return array_map('strval', $rows);
            } catch (\Throwable) {}
        }
        return ['2304400','2303709','2307650','2307700','2304285','2301000',
                '2306256','2309607','2309706','2306306','2304954','2303956',
                '2303501','2311264','2310209','2310258','2312403','2312601','2313401'];
    }

    /**
     * Lista de ibge_codes com camada de bairros validada (adjacentes).
     *
     * @return list<string>
     */
    public function listBairroLayerCodes(): array
    {
        if (!$this->tableExists('biomie_geo_municipios_ce')) return ['2304400']; // só Fortaleza
        try {
            $rows = $this->pdo->query(
                "SELECT ibge_code FROM biomie_geo_municipios_ce WHERE has_bairro_layer = 1"
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];
            return array_map('strval', $rows) ?: ['2304400'];
        } catch (\Throwable) {
            return ['2304400'];
        }
    }

    private function tableExists(string $table): bool
    {
        static $cache = [];
        if (isset($cache[$table])) return $cache[$table];
        try {
            $stmt = $this->pdo->prepare(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1"
            );
            $stmt->execute([$table]);
            return $cache[$table] = (bool) $stmt->fetchColumn();
        } catch (\Throwable) {
            return $cache[$table] = false;
        }
    }
}
