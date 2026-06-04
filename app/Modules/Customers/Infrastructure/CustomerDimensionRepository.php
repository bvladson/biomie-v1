<?php

declare(strict_types=1);

namespace BIomie\Modules\Customers\Infrastructure;

use BIomie\GeoNameNormalizer;
use BIomie\Logger;
use BIomie\Support\CnaeCodeNormalizer;
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

    private function tableExists(string $table): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
            );
            $stmt->execute([$table]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    // ── Missing-data condition ────────────────────────────────────────────────

    private function buildMissingWhere(string $target, string $alias = ''): string
    {
        $p = $alias !== '' ? $alias . '.' : '';
        $cnaeMissing = "({$p}cnae_codigo IS NULL OR {$p}cnae_codigo = '')";
        if ($this->col('biomie_clientes_dim', 'cnae_unknown_reason')) {
            $cnaeMissing = "({$cnaeMissing} AND ({$p}cnae_unknown_reason IS NULL OR {$p}cnae_unknown_reason = ''))";
        }
        return match ($target) {
            'geo'   => "({$p}cidade IS NULL OR {$p}cidade = '' OR {$p}bairro IS NULL OR {$p}bairro = '')",
            'cnae'  => $cnaeMissing,
            default => "({$p}cidade IS NULL OR {$p}cidade = '' OR {$p}bairro IS NULL OR {$p}bairro = '' OR {$cnaeMissing})",
        };
    }

    // ── Sales-buyers JOIN ─────────────────────────────────────────────────────

    private function buildSalesBuyersJoin(string $dimRef = 'biomie_clientes_dim'): string
    {
        if ($this->salesBuyersJoinProbed) {
            if ($this->salesBuyersJoinSql === null) {
                return '';
            }
            return str_replace('biomie_clientes_dim.codigo_cliente_omie', $dimRef . '.codigo_cliente_omie', $this->salesBuyersJoinSql);
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

        $baseJoin = 'INNER JOIN (' . implode(' UNION ', $selects)
            . ') _sales_src ON _sales_src.codigo_cliente_omie = %s.codigo_cliente_omie';
        $this->salesBuyersJoinSql = sprintf($baseJoin, 'biomie_clientes_dim');
        return sprintf($baseJoin, $dimRef);
    }

    private function buildSalesBuyersJoinCached(): string
    {
        return $this->buildSalesBuyersJoin('biomie_clientes_dim');
    }

    private function buildEnrichmentCandidateSql(bool $onlyMissing, string $target, string $source): string
    {
        $selects = [];

        $dimWhere = [
            'd.codigo_cliente_omie IS NOT NULL',
            'd.codigo_cliente_omie > 0',
        ];
        if ($onlyMissing) {
            $dimWhere[] = $this->buildMissingWhere($target, 'd');
        }
        $dimJoin = $source === 'sales_buyers' ? $this->buildSalesBuyersJoin('d') : '';
        $dimNameParts = [];
        if ($this->col('biomie_clientes_dim', 'cliente_nome_exibicao')) {
            $dimNameParts[] = "NULLIF(d.cliente_nome_exibicao, '')";
        }
        $dimNameParts[] = "NULLIF(d.nome_fantasia, '')";
        $dimNameParts[] = 'd.nome_cliente';
        $dimNameExpr = 'COALESCE(' . implode(', ', $dimNameParts) . ')';
        $dimDocParts = [];
        if ($this->col('biomie_clientes_dim', 'documento_normalizado')) {
            $dimDocParts[] = "NULLIF(d.documento_normalizado, '')";
        }
        $dimDocParts[] = "NULLIF(d.cpf_cnpj, '')";
        $dimDocExpr = 'COALESCE(' . implode(', ', $dimDocParts) . ')';
        $selects[] = "
            SELECT
                d.codigo_cliente_omie,
                {$dimNameExpr} AS seed_nome,
                {$dimDocExpr} AS seed_documento,
                'clientes_dim' AS source_origin
            FROM biomie_clientes_dim d
            {$dimJoin}
            WHERE " . implode(' AND ', $dimWhere);

        if ($source === 'clientes_dim') {
            return $this->dedupeCandidateSelects($selects);
        }

        if ($this->tableExists('biomie_clientes_para_enriquecer')) {
            $queueWhere = [
                'q.codigo_cliente_omie IS NOT NULL',
                'q.codigo_cliente_omie > 0',
                "q.status IN ('pendente', 'processando', 'falha', 'erro')",
            ];
            if ($onlyMissing) {
                $queueWhere[] = '(d.codigo_cliente_omie IS NULL OR ' . $this->buildMissingWhere($target, 'd') . ')';
            }
            $queueNameExpr = $this->col('biomie_clientes_para_enriquecer', 'cliente_nome_exibicao')
                ? 'MAX(q.cliente_nome_exibicao)'
                : 'NULL';
            $selects[] = "
                SELECT
                    q.codigo_cliente_omie,
                    {$queueNameExpr} AS seed_nome,
                    NULL AS seed_documento,
                    MAX(q.source_origin) AS source_origin
                FROM biomie_clientes_para_enriquecer q
                LEFT JOIN biomie_clientes_dim d ON d.codigo_cliente_omie = q.codigo_cliente_omie
                WHERE " . implode(' AND ', $queueWhere) . "
                GROUP BY q.codigo_cliente_omie";
        }

        if ($this->tableExists('mart_compradores_por_geo')) {
            $martWhere = [
                'm.codigo_cliente_omie IS NOT NULL',
                'm.codigo_cliente_omie > 0',
            ];
            if ($onlyMissing) {
                $martWhere[] = '(d.codigo_cliente_omie IS NULL OR ' . $this->buildMissingWhere($target, 'd') . ')';
            }
            $selects[] = "
                SELECT
                    m.codigo_cliente_omie,
                    MAX(m.cliente_nome) AS seed_nome,
                    MAX(m.documento) AS seed_documento,
                    'mart_compradores_por_geo' AS source_origin
                FROM mart_compradores_por_geo m
                LEFT JOIN biomie_clientes_dim d ON d.codigo_cliente_omie = m.codigo_cliente_omie
                WHERE " . implode(' AND ', $martWhere) . "
                GROUP BY m.codigo_cliente_omie";
        }

        if ($this->tableExists('mart_cliente_periodo')) {
            $periodWhere = [
                'p.codigo_cliente_omie IS NOT NULL',
                'p.codigo_cliente_omie > 0',
            ];
            if ($onlyMissing) {
                $periodWhere[] = '(d.codigo_cliente_omie IS NULL OR ' . $this->buildMissingWhere($target, 'd') . ')';
            }
            $selects[] = "
                SELECT
                    p.codigo_cliente_omie,
                    MAX(p.nome_cliente) AS seed_nome,
                    MAX(p.documento_cliente) AS seed_documento,
                    'mart_cliente_periodo' AS source_origin
                FROM mart_cliente_periodo p
                LEFT JOIN biomie_clientes_dim d ON d.codigo_cliente_omie = p.codigo_cliente_omie
                WHERE " . implode(' AND ', $periodWhere) . "
                GROUP BY p.codigo_cliente_omie";
        }

        if ($this->tableExists('biomie_notas_fiscais_canonicas')) {
            $nfWhere = [
                'n.codigo_cliente_omie IS NOT NULL',
                'n.codigo_cliente_omie > 0',
            ];
            if ($this->col('biomie_notas_fiscais_canonicas', 'status_fiscal')) {
                $nfWhere[] = "(n.status_fiscal IS NULL OR n.status_fiscal = 'autorizada')";
            }
            if ($onlyMissing) {
                $nfWhere[] = '(d.codigo_cliente_omie IS NULL OR ' . $this->buildMissingWhere($target, 'd') . ')';
            }
            $selects[] = "
                SELECT
                    n.codigo_cliente_omie,
                    MAX(n.nome_cliente) AS seed_nome,
                    MAX(n.documento_cliente) AS seed_documento,
                    'nf_canonica' AS source_origin
                FROM biomie_notas_fiscais_canonicas n
                LEFT JOIN biomie_clientes_dim d ON d.codigo_cliente_omie = n.codigo_cliente_omie
                WHERE " . implode(' AND ', $nfWhere) . "
                GROUP BY n.codigo_cliente_omie";
        }

        return $this->dedupeCandidateSelects($selects);
    }

    /** @param list<string> $selects */
    private function dedupeCandidateSelects(array $selects): string
    {
        return "
            SELECT
                CAST(u.codigo_cliente_omie AS UNSIGNED) AS codigo_cliente_omie,
                MAX(NULLIF(u.seed_nome, '')) AS seed_nome,
                MAX(NULLIF(u.seed_documento, '')) AS seed_documento,
                MAX(NULLIF(u.source_origin, '')) AS source_origin
            FROM (" . implode("\nUNION ALL\n", $selects) . ") u
            WHERE u.codigo_cliente_omie IS NOT NULL AND u.codigo_cliente_omie > 0
            GROUP BY u.codigo_cliente_omie
        ";
    }

    // ── Client queries ────────────────────────────────────────────────────────

    /** @return list<array{codigo_cliente_omie:string}> */
    public function fetchLocalClientsForEnrichment(int $limit, int $offset, bool $onlyMissing, string $target = 'all', string $source = 'clientes_dim'): array
    {
        if ($source !== 'clientes_dim') {
            $candidateSql = $this->buildEnrichmentCandidateSql($onlyMissing, $target, $source);
            $stmt = $this->pdo->prepare("
                SELECT * FROM ({$candidateSql}) candidates
                ORDER BY codigo_cliente_omie ASC
                LIMIT :lim OFFSET :off
            ");
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

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
        if ($source !== 'clientes_dim') {
            $candidateSql = $this->buildEnrichmentCandidateSql($onlyMissing, $target, $source);
            $stmt = $this->pdo->prepare("
                SELECT * FROM ({$candidateSql}) candidates
                WHERE codigo_cliente_omie > :last_code
                ORDER BY codigo_cliente_omie ASC
                LIMIT :lim
            ");
            $stmt->bindValue(':last_code', $lastCode, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

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
        if ($source !== 'clientes_dim') {
            try {
                $candidateSql = $this->buildEnrichmentCandidateSql($onlyMissing, $target, $source);
                return (int) $this->pdo->query("SELECT COUNT(*) FROM ({$candidateSql}) candidates")->fetchColumn();
            } catch (Throwable) {
                return 0;
            }
        }

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

    /** @param array<string,mixed> $seed */
    public function ensureMinimalClient(int $codigoOmie, array $seed): bool
    {
        if ($codigoOmie <= 0) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare('SELECT id FROM biomie_clientes_dim WHERE codigo_cliente_omie = :codigo LIMIT 1');
            $stmt->execute(['codigo' => $codigoOmie]);
            if ($stmt->fetchColumn()) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        $nome = trim((string) ($seed['seed_nome'] ?? $seed['cliente_nome'] ?? $seed['nome_cliente'] ?? ''));
        if ($nome === '') {
            $nome = 'Cliente pendente Omie';
        }
        $doc = trim((string) ($seed['seed_documento'] ?? $seed['documento'] ?? $seed['documento_cliente'] ?? ''));
        $digits = preg_replace('/\D+/', '', $doc) ?? '';
        $tipoDocumento = strlen($digits) === 14 ? 'CNPJ' : (strlen($digits) === 11 ? 'CPF' : null);
        $tipoPessoa = $tipoDocumento === 'CNPJ' ? 'PJ' : ($tipoDocumento === 'CPF' ? 'PF' : 'OUTRO');

        $columns = ['codigo_cliente_omie', 'nome_cliente'];
        $values = [':codigo_cliente_omie', ':nome_cliente'];
        $params = [
            'codigo_cliente_omie' => $codigoOmie,
            'nome_cliente' => mb_substr($nome, 0, 255),
        ];
        $updates = [
            "nome_cliente = IF(nome_cliente IS NULL OR nome_cliente = '' OR nome_cliente = 'Cliente sem nome' OR nome_cliente = 'Cliente pendente Omie', VALUES(nome_cliente), nome_cliente)",
        ];

        $optional = [
            'cpf_cnpj' => $doc,
            'tipo_documento' => $tipoDocumento,
            'documento_normalizado' => $digits,
            'documento_mascarado' => $this->maskDocument($digits !== '' ? $digits : $doc),
            'tipo_pessoa' => $tipoPessoa,
            'cliente_nome_exibicao' => mb_substr($nome, 0, 255),
            'geo_status' => 'pending_enrichment',
            'enrichment_status' => 'pending_omie',
            'source_priority' => 90,
        ];

        foreach ($optional as $column => $value) {
            if (!$this->col('biomie_clientes_dim', $column)) {
                continue;
            }
            $columns[] = $column;
            $values[] = ':' . $column;
            $params[$column] = $value;
            if (in_array($column, ['cliente_nome_exibicao', 'documento_normalizado', 'documento_mascarado'], true)) {
                $updates[] = "{$column} = COALESCE(NULLIF({$column}, ''), VALUES({$column}))";
            }
        }

        if ($this->col('biomie_clientes_dim', 'created_at')) {
            $columns[] = 'created_at';
            $values[] = 'NOW()';
        }
        if ($this->col('biomie_clientes_dim', 'updated_at')) {
            $columns[] = 'updated_at';
            $values[] = 'NOW()';
            $updates[] = 'updated_at = NOW()';
        }

        try {
            $sql = 'INSERT INTO biomie_clientes_dim (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', $values) . ')'
                . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            $this->logger->warning('CustomerDimensionRepository: falha ao criar cliente minimo.', [
                'codigo_cliente_omie' => $codigoOmie,
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function markEnrichmentQueue(int $codigoOmie, string $status, string $motivo = '', string $erro = ''): void
    {
        if ($codigoOmie <= 0 || !$this->tableExists('biomie_clientes_para_enriquecer')) {
            return;
        }

        $dbStatus = $status === 'erro' ? 'falha' : $status;
        if (!in_array($dbStatus, ['pendente', 'processando', 'concluido', 'falha'], true)) {
            $dbStatus = 'pendente';
        }

        $columns = ['codigo_cliente_omie', 'source_origin', 'status'];
        $values = [':codigo_cliente_omie', ':source_origin', ':status'];
        $params = [
            'codigo_cliente_omie' => $codigoOmie,
            'source_origin' => 'omie_enrichment',
            'status' => $dbStatus,
        ];
        $updates = [
            'status = VALUES(status)',
            'source_origin = VALUES(source_origin)',
        ];

        foreach (['motivo' => $motivo, 'erro' => $erro] as $column => $value) {
            if (!$this->col('biomie_clientes_para_enriquecer', $column)) {
                continue;
            }
            $columns[] = $column;
            $values[] = ':' . $column;
            $params[$column] = $value !== '' ? mb_substr($value, 0, $column === 'motivo' ? 60 : 2000) : null;
            $updates[] = "{$column} = VALUES({$column})";
        }
        if ($this->col('biomie_clientes_para_enriquecer', 'ultima_tentativa')) {
            $columns[] = 'ultima_tentativa';
            $values[] = 'CURRENT_TIMESTAMP';
            $updates[] = 'ultima_tentativa = CURRENT_TIMESTAMP';
        }
        if ($this->col('biomie_clientes_para_enriquecer', 'cliente_dim_id')) {
            $updates[] = 'cliente_dim_id = (SELECT id FROM biomie_clientes_dim WHERE codigo_cliente_omie = VALUES(codigo_cliente_omie) LIMIT 1)';
        }

        try {
            $sql = 'INSERT INTO biomie_clientes_para_enriquecer (`' . implode('`, `', $columns) . '`) VALUES ('
                . implode(', ', $values) . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
        } catch (Throwable $e) {
            $this->logger->warning('CustomerDimensionRepository: falha ao atualizar fila enriquecimento.', [
                'codigo_cliente_omie' => $codigoOmie,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function maskDocument(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) === 14) {
            return substr($digits, 0, 2) . '.***.***/****-' . substr($digits, -2);
        }
        if (strlen($digits) === 11) {
            return '***.***.' . substr($digits, 6, 3) . '-**';
        }
        if (strlen($digits) > 4) {
            return str_repeat('*', strlen($digits) - 4) . substr($digits, -4);
        }
        return $value;
    }

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
        $cnaeSource = $this->resolveCnaeSource($fields['cnae_source'] ?? '');
        $cnaeConfidence = $this->resolveCnaeConfidence($fields['cnae_source'] ?? '');
        $beforeCnae = $this->fetchCnaeContext($codigoOmie);
        if ($cidade === '' && $bairro === '' && $cnae === '') {
            $this->markUnknownCnae($codigoOmie, $beforeCnae, 'omie_sem_dados_cadastrais');
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
            $cnaeConflict = $this->hasCnaeConflict($beforeCnae['cnae_codigo'] ?? null, $cnae);
            $sets[] = "cnae_codigo = COALESCE(NULLIF(cnae_codigo, ''), :cnae_codigo)";
            $params['cnae_codigo'] = $cnae;

            $cnaeDescription = $this->findOfficialCnaeDescription($cnae);
            if ($cnaeDescription !== null && $this->col('biomie_clientes_dim', 'cnae_descricao')) {
                $sets[] = "cnae_descricao = COALESCE(NULLIF(cnae_descricao, ''), :cnae_descricao)";
                $params['cnae_descricao'] = $cnaeDescription;
            }
            if ($this->col('biomie_clientes_dim', 'cnae_source')) {
                $sets[] = "cnae_source = COALESCE(NULLIF(cnae_source, ''), :cnae_source)";
                $params['cnae_source'] = $cnaeSource;
            }
            if ($this->col('biomie_clientes_dim', 'cnae_confidence')) {
                $sets[] = 'cnae_confidence = IF(cnae_confidence = 0, :cnae_confidence, cnae_confidence)';
                $params['cnae_confidence'] = $cnaeConfidence;
            }
            if ($this->col('biomie_clientes_dim', 'cnae_captured_at')) {
                $sets[] = 'cnae_captured_at = IF(cnae_captured_at IS NULL, NOW(), cnae_captured_at)';
            }
            if ($this->col('biomie_clientes_dim', 'cnae_unknown_reason')) {
                $sets[] = 'cnae_unknown_reason = NULL';
            }
            if ($cnaeConflict && $this->col('biomie_clientes_dim', 'cnae_conflict_flag')) {
                $sets[] = 'cnae_conflict_flag = 1';
            }
        } elseif ($this->col('biomie_clientes_dim', 'cnae_unknown_reason')) {
            $sets[] = "cnae_unknown_reason = IF(cnae_codigo IS NULL OR cnae_codigo = '', 'omie_sem_cnae', cnae_unknown_reason)";
            if ($this->col('biomie_clientes_dim', 'cnae_source')) {
                $sets[] = "cnae_source = IF((cnae_codigo IS NULL OR cnae_codigo = '') AND (cnae_source IS NULL OR cnae_source = ''), 'omie_sem_cnae', cnae_source)";
            }
            if ($this->col('biomie_clientes_dim', 'cnae_captured_at')) {
                $sets[] = "cnae_captured_at = IF((cnae_codigo IS NULL OR cnae_codigo = '') AND cnae_captured_at IS NULL, NOW(), cnae_captured_at)";
            }
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
        $afterCnae = $this->fetchCnaeContext($codigoOmie);
        $fieldUpdates['cnae'] = $this->auditCnaeResult(
            $codigoOmie,
            $beforeCnae,
            $afterCnae,
            $cnae,
            $cnaeSource,
            $cnaeConfidence,
            $raw
        );

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

    /**
     * @return array<string,mixed>
     */
    private function fetchCnaeContext(int $codigoOmie): array
    {
        if ($codigoOmie <= 0 || !$this->tableExists('biomie_clientes_dim')) {
            return [];
        }

        $columns = ['codigo_cliente_omie'];
        foreach ([
            'documento_normalizado',
            'cnae_codigo',
            'cnae_descricao',
            'cnae_source',
            'cnae_confidence',
            'cnae_unknown_reason',
        ] as $column) {
            if ($this->col('biomie_clientes_dim', $column)) {
                $columns[] = $column;
            }
        }

        try {
            $stmt = $this->pdo->prepare(
                'SELECT `' . implode('`, `', $columns) . '` FROM biomie_clientes_dim WHERE codigo_cliente_omie = :codigo LIMIT 1'
            );
            $stmt->execute(['codigo' => $codigoOmie]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private function markUnknownCnae(int $codigoOmie, array $context, string $reason): void
    {
        if ($codigoOmie <= 0
            || !$this->col('biomie_clientes_dim', 'cnae_unknown_reason')
            || CnaeCodeNormalizer::digits((string) ($context['cnae_codigo'] ?? '')) !== '') {
            return;
        }

        try {
            $sets = ['cnae_unknown_reason = :reason'];
            $params = ['codigo' => $codigoOmie, 'reason' => $reason];
            if ($this->col('biomie_clientes_dim', 'cnae_source')) {
                $sets[] = "cnae_source = IF(cnae_source IS NULL OR cnae_source = '', 'omie_sem_cnae', cnae_source)";
            }
            if ($this->col('biomie_clientes_dim', 'cnae_captured_at')) {
                $sets[] = 'cnae_captured_at = IF(cnae_captured_at IS NULL, NOW(), cnae_captured_at)';
            }
            $stmt = $this->pdo->prepare(
                'UPDATE biomie_clientes_dim SET ' . implode(', ', $sets) . ' WHERE codigo_cliente_omie = :codigo'
            );
            $stmt->execute($params);

            $after = $this->fetchCnaeContext($codigoOmie);
            $this->recordCnaeAudit($codigoOmie, $context, $after, null, 'omie_sem_cnae', 0, $reason, []);
        } catch (Throwable $e) {
            $this->logger->warning('CustomerDimensionRepository: falha ao marcar CNAE desconhecido.', [
                'codigo_cliente_omie' => $codigoOmie,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @param array<string,mixed> $raw
     */
    private function auditCnaeResult(
        int $codigoOmie,
        array $before,
        array $after,
        string $omieCnae,
        string $source,
        int $confidence,
        array $raw
    ): bool {
        $old = CnaeCodeNormalizer::digits((string) ($before['cnae_codigo'] ?? ''));
        $new = CnaeCodeNormalizer::digits((string) ($after['cnae_codigo'] ?? ''));
        $omie = CnaeCodeNormalizer::digits($omieCnae);

        if ($omie !== '' && $old === '' && $new === $omie) {
            $this->recordCnaeAudit($codigoOmie, $before, $after, $omie, $source, $confidence, 'omie_cnae_preenchido', $raw);
            return true;
        }

        if ($omie !== '' && $old !== '' && $old !== $omie) {
            $this->recordCnaeAudit($codigoOmie, $before, $after, $omie, $source, $confidence, 'omie_cnae_conflito_preservado', $raw);
            return false;
        }

        $beforeReason = trim((string) ($before['cnae_unknown_reason'] ?? ''));
        $afterReason = trim((string) ($after['cnae_unknown_reason'] ?? ''));
        if ($omie === '' && $old === '' && $afterReason !== '' && $beforeReason !== $afterReason) {
            $this->recordCnaeAudit($codigoOmie, $before, $after, null, 'omie_sem_cnae', 0, $afterReason, $raw);
        }

        return false;
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @param array<string,mixed> $raw
     */
    private function recordCnaeAudit(
        int $codigoOmie,
        array $before,
        array $after,
        ?string $newCnae,
        string $source,
        int $confidence,
        string $reason,
        array $raw
    ): void {
        if (!$this->tableExists('biomie_cliente_cnae_audit')) {
            return;
        }

        try {
            $payloadJson = $raw === [] ? '' : json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $stmt = $this->pdo->prepare(
                "INSERT INTO biomie_cliente_cnae_audit
                     (codigo_cliente_omie, documento_normalizado, old_cnae, new_cnae,
                      source, confidence, reason, payload_hash, actor)
                 VALUES
                     (:codigo, :documento, :old_cnae, :new_cnae,
                      :source, :confidence, :reason, :payload_hash, 'customer_omie_enrichment')"
            );
            $stmt->execute([
                'codigo' => $codigoOmie,
                'documento' => (string) (($after['documento_normalizado'] ?? '') ?: ($before['documento_normalizado'] ?? '')),
                'old_cnae' => CnaeCodeNormalizer::digits((string) ($before['cnae_codigo'] ?? '')) ?: null,
                'new_cnae' => $newCnae !== null ? (CnaeCodeNormalizer::digits($newCnae) ?: null) : null,
                'source' => mb_substr($source, 0, 40),
                'confidence' => max(0, min(100, $confidence)),
                'reason' => mb_substr($reason, 0, 160),
                'payload_hash' => $payloadJson !== '' ? hash('sha256', $payloadJson) : null,
            ]);
        } catch (Throwable $e) {
            $this->logger->warning('CustomerDimensionRepository: falha ao auditar CNAE.', [
                'codigo_cliente_omie' => $codigoOmie,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function findOfficialCnaeDescription(string $cnae): ?string
    {
        $digits = CnaeCodeNormalizer::digits($cnae);
        if ($digits === '' || !$this->tableExists('biomie_cnae_dim')) {
            return null;
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT COALESCE(NULLIF(cnae_descricao, ''), NULLIF(descricao_oficial, ''), NULLIF(subclasse_descricao, '')) AS descricao
                 FROM biomie_cnae_dim
                 WHERE cnae_codigo_normalizado = :digits
                 LIMIT 1"
            );
            $stmt->execute(['digits' => $digits]);
            $desc = trim((string) $stmt->fetchColumn());
            return $desc !== '' ? $desc : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveCnaeSource(string $rawSource): string
    {
        $source = trim($rawSource);
        if ($source === '') {
            return 'omie_consultar_cliente';
        }

        return mb_substr('omie_' . preg_replace('/[^a-zA-Z0-9_]+/', '_', $source), 0, 40);
    }

    private function resolveCnaeConfidence(string $rawSource): int
    {
        return match (trim($rawSource)) {
            'cnae_fiscal' => 95,
            'cnae' => 90,
            default => 85,
        };
    }

    private function hasCnaeConflict(?string $current, string $incoming): bool
    {
        $old = CnaeCodeNormalizer::digits($current);
        $new = CnaeCodeNormalizer::digits($incoming);
        return $old !== '' && $new !== '' && $old !== $new;
    }

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
