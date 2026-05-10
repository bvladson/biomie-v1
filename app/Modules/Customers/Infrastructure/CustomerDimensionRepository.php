<?php

declare(strict_types=1);

namespace BIomie\Modules\Customers\Infrastructure;

use BIomie\GeoNameNormalizer;
use BIomie\Logger;
use PDO;
use Throwable;

final class CustomerDimensionRepository
{
    private const ENDPOINT_CLIENTES = 'https://app.omie.com.br/api/v1/geral/clientes/';
    private const BRONZE_SOURCE     = 'omie_enrichment';

    /** @var array<string,bool> */
    private array $colCache = [];

    private ?string $salesBuyersJoinSql    = null;
    private bool    $salesBuyersJoinProbed = false;

    public function __construct(
        private readonly PDO    $pdo,
        private readonly Logger $logger,
    ) {}

    // ── Column cache ──────────────────────────────────────────────────────────

    private function col(string $table, string $column): bool
    {
        $key = "{$table}.{$column}";
        if (!isset($this->colCache[$key])) {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
            );
            $stmt->execute([$table, $column]);
            $this->colCache[$key] = (int) $stmt->fetchColumn() > 0;
        }
        return $this->colCache[$key];
    }

    // ── Missing-data condition ────────────────────────────────────────────────

    private function buildMissingWhere(string $target): string
    {
        return match ($target) {
            'geo'   => "(cidade IS NULL OR cidade = '' OR bairro IS NULL OR bairro = '')",
            'cnae'  => "(cnae_codigo IS NULL OR cnae_codigo = '')",
            default => "(cidade IS NULL OR cidade = '' OR bairro IS NULL OR bairro = '' OR cnae_codigo IS NULL OR cnae_codigo = '')",
        };
    }

    // ── Sales-buyers JOIN ─────────────────────────────────────────────────────

    private function buildSalesBuyersJoin(): string
    {
        if ($this->salesBuyersJoinProbed) {
            return $this->salesBuyersJoinSql ?? '';
        }
        $this->salesBuyersJoinProbed = true;

        $candidates = [
            'mart_cliente_periodo',
            'mart_fiscal_nfe',
            'biomie_vendas_geo_cliente_fact',
        ];
        $selects = [];
        foreach ($candidates as $t) {
            try {
                $this->pdo->query("SELECT 1 FROM `{$t}` LIMIT 0");
                $selects[] = "SELECT DISTINCT codigo_cliente_omie FROM `{$t}` WHERE codigo_cliente_omie IS NOT NULL AND codigo_cliente_omie > 0";
            } catch (Throwable) {}
        }

        if ($selects === []) {
            $this->salesBuyersJoinSql = null;
            return '';
        }

        $this->salesBuyersJoinSql = 'INNER JOIN (' . implode(' UNION ', $selects)
            . ') _sales_src ON _sales_src.codigo_cliente_omie = biomie_clientes_dim.codigo_cliente_omie';
        return $this->salesBuyersJoinSql;
    }

    // ── Client queries ────────────────────────────────────────────────────────

    /** @return list<array{codigo_cliente_omie:string}> */
    public function fetchLocalClientsForEnrichment(int $limit, int $offset, bool $onlyMissing, string $target = 'all', string $source = 'clientes_dim'): array
    {
        $join = $source === 'sales_buyers' ? $this->buildSalesBuyersJoin() : '';
        $sql  = "SELECT biomie_clientes_dim.codigo_cliente_omie FROM biomie_clientes_dim
                 {$join}
                 WHERE biomie_clientes_dim.codigo_cliente_omie IS NOT NULL AND biomie_clientes_dim.codigo_cliente_omie > 0";

        if ($onlyMissing) {
            $sql .= ' AND ' . $this->buildMissingWhere($target);
        }

        $sql .= ' ORDER BY biomie_clientes_dim.codigo_cliente_omie ASC LIMIT :lim OFFSET :off';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array{codigo_cliente_omie:string}> */
    public function fetchLocalClientsForEnrichmentCursor(int $limit, int $lastCode, bool $onlyMissing, string $target = 'all', string $source = 'clientes_dim'): array
    {
        $join = $source === 'sales_buyers' ? $this->buildSalesBuyersJoin() : '';
        $sql  = "SELECT biomie_clientes_dim.codigo_cliente_omie FROM biomie_clientes_dim
                 {$join}
                 WHERE biomie_clientes_dim.codigo_cliente_omie IS NOT NULL AND biomie_clientes_dim.codigo_cliente_omie > :last_code";

        if ($onlyMissing) {
            $sql .= ' AND ' . $this->buildMissingWhere($target);
        }

        $sql .= ' ORDER BY biomie_clientes_dim.codigo_cliente_omie ASC LIMIT :lim';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':last_code', $lastCode, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function countLocalClientsForEnrichment(bool $onlyMissing, string $target = 'all', string $source = 'clientes_dim'): int
    {
        $join = $source === 'sales_buyers' ? $this->buildSalesBuyersJoin() : '';
        $sql  = "SELECT COUNT(*) FROM biomie_clientes_dim
                 {$join}
                 WHERE biomie_clientes_dim.codigo_cliente_omie IS NOT NULL AND biomie_clientes_dim.codigo_cliente_omie > 0";
        if ($onlyMissing) {
            $sql .= ' AND ' . $this->buildMissingWhere($target);
        }
        try {
            return (int) $this->pdo->query($sql)->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    // ── Bronze ────────────────────────────────────────────────────────────────

    public function saveBronze(int $codigoOmie, array $payload, string $omieMethod): bool
    {
        try {
            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $idempKey    = hash('sha256', $omieMethod . '|' . $codigoOmie . '|' . substr($payloadJson, 0, 500));

            $stmt = $this->pdo->prepare(
                "INSERT INTO biomie_omie_bronze
                     (domain_name, entity_name, endpoint, omie_method, source,
                      natural_key, provider_key, request_hash, payload_hash,
                      idempotency_key, request_params_json, payload_json)
                 VALUES
                     ('clientes', 'cliente', :ep, :call, :src,
                      :nat_key, :prov_key, :req_hash, :pay_hash,
                      :idemp, :req_params, :payload)
                 ON DUPLICATE KEY UPDATE
                     payload_json = VALUES(payload_json),
                     payload_hash = VALUES(payload_hash),
                     ingested_at  = CURRENT_TIMESTAMP"
            );
            $stmt->execute([
                'ep'         => self::ENDPOINT_CLIENTES,
                'call'       => $omieMethod,
                'src'        => self::BRONZE_SOURCE,
                'nat_key'    => (string) $codigoOmie,
                'prov_key'   => (string) $codigoOmie,
                'req_hash'   => hash('sha256', $omieMethod . '|' . $codigoOmie),
                'pay_hash'   => hash('sha256', $payloadJson),
                'idemp'      => $idempKey,
                'req_params' => json_encode(['codigo_cliente_omie' => $codigoOmie], JSON_UNESCAPED_UNICODE),
                'payload'    => $payloadJson,
            ]);
            return true;
        } catch (Throwable $e) {
            $this->logger->warning('CustomerDimensionRepository: falha ao salvar bronze.', [
                'codigo_cliente_omie' => $codigoOmie,
                'message'             => $e->getMessage(),
            ]);
            return false;
        }
    }

    // ── Dimension persist ─────────────────────────────────────────────────────

    /**
     * @param array<string,mixed>  $raw
     * @param array<string,string> $fields  saída de CustomerNormalizer::normalize()
     * @return array{changed:bool,bairro_result:string,field_updates:array{cidade:bool,bairro:bool,cnae:bool},bairro_norm:string|null,oficial_bairro:array{id:int,nome:string}|null}
     */
    public function persist(int $codigoOmie, array $raw, array $fields, string $enrichmentSource): array
    {
        $fieldUpdates = ['cidade' => false, 'bairro' => false, 'cnae' => false];
        $cidade     = $fields['cidade'];
        $cidadeNorm = $fields['cidade_norm'];
        $uf         = $fields['uf'];
        $bairro     = $fields['bairro'];
        $cep        = $fields['cep'];
        $cnae       = $fields['cnae_codigo'];
        if ($cidade === '' && $bairro === '' && $cnae === '') {
            return ['changed' => false, 'bairro_result' => 'none', 'field_updates' => $fieldUpdates, 'bairro_norm' => null, 'oficial_bairro' => null];
        }
        $bairroNorm = $bairro !== '' ? GeoNameNormalizer::normalizeNeighborhood($bairro) : null;
        $sets   = [];
        $params = ['codigo' => $codigoOmie];
        if ($cidade !== '' && $this->col('biomie_clientes_dim', 'cidade')) {
            $sets[] = 'cidade = :cidade'; $params['cidade'] = $cidade; $fieldUpdates['cidade'] = true;
        }
        if ($uf !== '' && $this->col('biomie_clientes_dim', 'uf')) {
            $sets[] = 'uf = :uf'; $params['uf'] = $uf;
        }
        if ($bairro !== '' && $this->col('biomie_clientes_dim', 'bairro')) {
            $sets[] = 'bairro = :bairro'; $params['bairro'] = $bairro; $fieldUpdates['bairro'] = true;
        }
        if ($cep !== '' && $this->col('biomie_clientes_dim', 'cep')) {
            $sets[] = 'cep = :cep'; $params['cep'] = $cep;
        }
        if ($cidadeNorm !== '' && $this->col('biomie_clientes_dim', 'cidade_normalizada')) {
            $sets[] = 'cidade_normalizada = :cidade_norm'; $params['cidade_norm'] = $cidadeNorm;
        }
        if ($bairroNorm !== null && $this->col('biomie_clientes_dim', 'bairro_normalizado')) {
            $sets[] = 'bairro_normalizado = :bairro_norm'; $params['bairro_norm'] = $bairroNorm;
        }
        if ($cnae !== '' && $this->col('biomie_clientes_dim', 'cnae_codigo')) {
            $sets[] = "cnae_codigo = COALESCE(NULLIF(cnae_codigo, ''), :cnae_codigo)";
            $params['cnae_codigo'] = $cnae; $fieldUpdates['cnae'] = true;
        }
        if ($fields['endereco'] !== '' && $this->col('biomie_clientes_dim', 'logradouro')) {
            $sets[] = 'logradouro = :logradouro'; $params['logradouro'] = $fields['endereco'];
        }
        if ($fields['numero'] !== '' && $this->col('biomie_clientes_dim', 'numero')) {
            $sets[] = 'numero = :numero'; $params['numero'] = $fields['numero'];
        }
        if ($fields['complemento'] !== '' && $this->col('biomie_clientes_dim', 'complemento')) {
            $sets[] = 'complemento = :complemento'; $params['complemento'] = $fields['complemento'];
        }
        if ($this->col('biomie_clientes_dim', 'enrichment_status')) {
            $sets[] = "enrichment_status = 'omie_enriched'";
        }
        if ($this->col('biomie_clientes_dim', 'enrichment_source')) {
            $sets[] = "enrichment_source = :enrich_src"; $params['enrich_src'] = $enrichmentSource;
        }
        if ($this->col('biomie_clientes_dim', 'enriched_at')) {
            $sets[] = 'enriched_at = NOW()';
        }

        if ($sets === []) {
            return ['changed' => false, 'bairro_result' => 'none', 'field_updates' => $fieldUpdates, 'bairro_norm' => $bairroNorm, 'oficial_bairro' => null];
        }

        $stmt = $this->pdo->prepare(
            'UPDATE biomie_clientes_dim SET ' . implode(', ', $sets)
            . ' WHERE codigo_cliente_omie = :codigo'
        );
        $stmt->execute($params);
        $changed = $stmt->rowCount() > 0;

        // ── Bairro matching and queuing ───────────────────────────────────────
        $bairroResult  = 'none';
        $oficialBairro = null;

        if ($cidade === '') {
            $this->queueForEnrichment($codigoOmie, $raw, $fields, '', 'cidade_ausente');
            $bairroResult = 'cidade_ausente';
        } elseif ($cidadeNorm === 'fortaleza' && $uf === 'CE') {
            if ($bairroNorm === null) {
                $this->queueForEnrichment($codigoOmie, $raw, $fields, '', 'bairro_ausente');
                $bairroResult = 'bairro_ausente';
            } else {
                $oficialBairro = $this->findBairroOficial($bairroNorm);
                if ($oficialBairro !== null) {
                    $bairroResult = 'matched';
                } else {
                    $bairroResult = 'unmatched';
                    $this->queueForEnrichment($codigoOmie, $raw, $fields, $bairroNorm, 'bairro_nao_encontrado');
                }
            }
        }

        return ['changed' => $changed, 'bairro_result' => $bairroResult, 'field_updates' => $fieldUpdates, 'bairro_norm' => $bairroNorm, 'oficial_bairro' => $oficialBairro];
    }

    // ── Bairro matching ───────────────────────────────────────────────────────

    /** @return array{id:int,nome:string}|null */
    public function findBairroOficial(string $bairroNorm): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT id, bairro_nome FROM biomie_bairros_fortaleza_dim
                 WHERE bairro_nome_normalizado = :norm AND ativo = 1 LIMIT 1"
            );
            $stmt->execute(['norm' => $bairroNorm]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return ['id' => (int) $row['id'], 'nome' => (string) $row['bairro_nome']];
            }
        } catch (Throwable) {}
        return null;
    }

    private function queueForEnrichment(int $codigoOmie, array $raw, array $fields, string $bairroNorm, string $motivo): void
    {
        try {
            $nomeExibicao = trim((string) ($raw['nome_fantasia'] ?? '')) ?: trim((string) ($raw['razao_social'] ?? ''));

            $stmt = $this->pdo->prepare(
                "INSERT INTO biomie_clientes_para_enriquecer
                     (codigo_cliente_omie, source_origin, status, motivo,
                      cidade_atual, uf_atual, bairro_atual, sugestao_bairro_normalizado,
                      cliente_nome_exibicao)
                 VALUES
                     (:cod, 'omie_enrichment', 'pendente', :motivo,
                      :cidade, :uf, :bairro, :bairro_norm, :nome)
                 ON DUPLICATE KEY UPDATE
                     motivo                      = VALUES(motivo),
                     cidade_atual                = VALUES(cidade_atual),
                     uf_atual                    = VALUES(uf_atual),
                     bairro_atual                = VALUES(bairro_atual),
                     sugestao_bairro_normalizado = VALUES(sugestao_bairro_normalizado),
                     cliente_nome_exibicao       = VALUES(cliente_nome_exibicao),
                     status                      = IF(status = 'concluido', 'pendente', status),
                     ultima_tentativa            = CURRENT_TIMESTAMP"
            );
            $stmt->execute([
                'cod'         => $codigoOmie,
                'motivo'      => $motivo,
                'cidade'      => $fields['cidade'],
                'uf'          => $fields['uf'],
                'bairro'      => $fields['bairro'],
                'bairro_norm' => $bairroNorm,
                'nome'        => mb_substr($nomeExibicao, 0, 255),
            ]);
        } catch (Throwable $e) {
            $this->logger->warning('CustomerDimensionRepository: falha ao registrar fila enriquecimento.', [
                'codigo_cliente_omie' => $codigoOmie,
                'message'             => $e->getMessage(),
            ]);
        }
    }
}
