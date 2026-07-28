<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Support\SecretMask;
use PDO;
use Throwable;

/**
 * Materializa a dimensão de clientes (biomie_clientes_dim) a partir das tabelas
 * canônicas existentes, sem depender de chamadas à API Omie.
 *
 * Fontes usadas (em ordem de prioridade de documento/nome):
 *   1. biomie_notas_fiscais_canonicas — documento mais confiável (NF exige CNPJ/CPF)
 *   2. biomie_pedidos_canonicos — melhor fonte para vendedor responsável
 *
 * Prioridade para vendedor responsável:
 *   pedido (100) > nota_fiscal (90) > sem_vinculo (0)
 *
 * Chave de upsert: codigo_cliente_omie (UNIQUE existente).
 * Idempotente: rodar N vezes não duplica clientes.
 */
final class CustomerDimensionMaterializationService
{
    /** @var array<string,int> */
    private const VENDOR_CONFIDENCE = [
        'pedido'      => 100,
        'nota_fiscal' => 90,
        'sem_vinculo' => 0,
    ];

    private const UPSERT_BATCH_SIZE = 100;

    /** @var array<string,mixed> */
    private array $performance = [];

    private readonly int $companyId;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Logger $logger,
        private readonly TableHealthService $tableHealth,
        ?int $companyId = null,
    ) {
        $this->companyId = $companyId ?? \BIomie\Support\TenantContext::companyId();
    }

    /**
     * @return array<string,mixed>
     */
    public function run(?int $actorUserId = null): array
    {
        $startedAt = microtime(true);
        $telemetry  = Telemetry::global();
        $span = $telemetry?->startSpan(
            'clientes.dim.materialize',
            ['domain_name' => 'clientes', 'source_name' => 'customer_dimension_builder', 'actor_user_id' => $actorUserId],
            'clientes',
            'customer_dimension_builder'
        );

        $report = $this->emptyReport();

        try {
            // ── Guard: required columns must exist (migration must be applied) ──
            if (!$this->columnExists('biomie_clientes_dim', 'documento_normalizado')) {
                $report['status'] = 'migration_pending';
                $report['errors'][] = 'Colunas de enriquecimento ausentes em biomie_clientes_dim. Execute as migrations primeiro.';
                $this->finish($report, $startedAt);
                $span?->finish('migration_pending', $this->spanResult($report));
                return $report;
            }
            if (!$this->columnExists('biomie_clientes_dim', 'materialization_hash')) {
                $report['status'] = 'migration_pending';
                $report['errors'][] = 'Hash de materializacao ausente em biomie_clientes_dim. Execute as migrations primeiro.';
                $this->finish($report, $startedAt);
                $span?->finish('migration_pending', $this->spanResult($report));
                return $report;
            }

            $telemetry?->recordEvent('info', 'clientes.dim.materialize.started', 'Materializacao da dimensao de clientes iniciada.', [
                'actor_user_id' => $actorUserId,
            ], 'clientes', 'customer_dimension_builder');

            // ── Step 1: collect candidates from source tables ─────────────────
            $pedidoMap = $this->collectFromPedidos();
            $nfMap     = $this->collectFromNotas();

            $report['source_counts'] = [
                'pedidos_clientes_unicos' => count($pedidoMap),
                'notas_clientes_unicos'   => count($nfMap),
            ];

            if (count($pedidoMap) === 0 && count($nfMap) === 0) {
                $report['status'] = 'source_empty';
                $report['warnings'][] = 'Nenhum cliente encontrado em pedidos ou notas fiscais canonicas.';
                $this->finish($report, $startedAt);
                $telemetry?->recordEvent('warning', 'clientes.dim.materialize.finished', 'Nenhum candidato encontrado.', $this->eventContext($report), 'clientes', 'customer_dimension_builder');
                $span?->finish('warning_empty', $this->spanResult($report));
                return $report;
            }

            // ── Step 2: merge candidates in PHP ───────────────────────────────
            $merged = $this->buildMergedClients($pedidoMap, $nfMap);

            $report['candidate_counts'] = [
                'total_candidatos'          => count($merged),
                'com_documento'             => count(array_filter($merged, static fn (array $r): bool => $r['documento_normalizado'] !== null)),
                'sem_documento'             => count(array_filter($merged, static fn (array $r): bool => $r['documento_normalizado'] === null)),
                'com_vendedor'              => count(array_filter($merged, static fn (array $r): bool => $r['vendedor_responsavel_confidence'] > 0)),
                'sem_vendedor'              => count(array_filter($merged, static fn (array $r): bool => $r['vendedor_responsavel_confidence'] === 0)),
                'com_bairro'                => 0, // geo enrichment is a future step
                'sem_bairro'                => count($merged),
                'com_cnae'                  => 0, // CNAE enrichment is a future step
                'sem_cnae'                  => count($merged),
            ];

            // ── Step 3: upsert into biomie_clientes_dim ───────────────────────
            [$inserted, $updated, $unchanged] = $this->batchUpsert($merged);

            $report['inserted_count'] = $inserted;
            $report['updated_count']  = $updated;
            $report['unchanged_count'] = $unchanged;

            // ── Step 4: populate biomie_clientes_geo_dim ──────────────────────
            $geoCount = 0;
            if ($this->tableHealth->tableExists('biomie_clientes_geo_dim')) {
                $geoCount = $this->upsertGeoDim($merged);
            }
            $report['geo_dim_inserted'] = $geoCount;
            $report['performance'] = $this->performance;

            // ── Step 5: count final state ─────────────────────────────────────
            $report['final_counts'] = $this->countFinalState();

            // ── Domain warnings ───────────────────────────────────────────────
            $this->appendDomainWarnings($report);

            $report['ok']     = true;
            $report['status'] = ($inserted + $updated) > 0 ? 'ok' : 'ok_unchanged';
            $this->finish($report, $startedAt);

            $telemetry?->recordEvent('info', 'clientes.dim.materialize.finished', 'Dimensao de clientes materializada.', $this->eventContext($report), 'clientes', 'customer_dimension_builder');
            $span?->finish((string) $report['status'], $this->spanResult($report));
            return $report;
        } catch (Throwable $e) {
            $report['status']   = 'failed';
            $report['errors'][] = SecretMask::sanitizeErrorMessage($e->getMessage(), 300);
            $this->finish($report, $startedAt);

            $this->logger->error('CustomerDimensionMaterializationService: falha.', [
                'message' => SecretMask::sanitizeErrorMessage($e->getMessage(), 500),
            ]);

            $telemetry?->recordEvent('error', 'clientes.dim.materialize.failed', 'Materializacao de clientes falhou.', $this->eventContext($report), 'clientes', 'customer_dimension_builder');
            $span?->finish('failed', $this->spanResult($report));
            return $report;
        }
    }

    // ── Data Collection ───────────────────────────────────────────────────────

    /**
     * Agrega um registro por codigo_cliente_omie a partir de biomie_pedidos_canonicos.
     * Vendedor é o mais recente com código não vazio.
     *
     * @return array<int,array<string,mixed>>
     */
    private function collectFromPedidos(): array
    {
        if (!$this->tableHealth->tableExists('biomie_pedidos_canonicos')) {
            return [];
        }

        $sql = <<<'SQL'
            SELECT
                codigo_cliente_omie,
                MAX(documento_cliente)                                              AS documento_raw,
                MAX(nome_cliente)                                                   AS nome_cliente,
                COUNT(*)                                                            AS total_pedidos,
                SUM(CASE WHEN status_comercial IN ('faturado','autorizado')
                         THEN valor_total ELSE 0 END)                              AS total_receita,
                MIN(COALESCE(data_faturamento, data_emissao))                      AS primeira_compra,
                MAX(COALESCE(data_faturamento, data_emissao))                      AS ultimo_pedido,
                SUBSTRING_INDEX(GROUP_CONCAT(
                    CASE WHEN codigo_vendedor_omie != '' AND codigo_vendedor_omie != 'Sem vendedor'
                         THEN codigo_vendedor_omie END
                    ORDER BY COALESCE(data_faturamento, data_emissao) DESC
                    SEPARATOR '\x01'
                ), '\x01', 1)                                                      AS vendedor_codigo,
                SUBSTRING_INDEX(GROUP_CONCAT(
                    CASE WHEN nome_vendedor != 'Sem vendedor' AND nome_vendedor != ''
                         THEN nome_vendedor END
                    ORDER BY COALESCE(data_faturamento, data_emissao) DESC
                    SEPARATOR '\x01'
                ), '\x01', 1)                                                      AS vendedor_nome
            FROM biomie_pedidos_canonicos
            WHERE company_id = :company_id
              AND codigo_cliente_omie IS NOT NULL
            GROUP BY codigo_cliente_omie
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':company_id' => $this->companyId]);
        if ($stmt === false) {
            return [];
        }

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['codigo_cliente_omie'];
            $map[$id] = $row;
        }
        return $map;
    }

    /**
     * Agrega um registro por codigo_cliente_omie a partir de biomie_notas_fiscais_canonicas.
     *
     * @return array<int,array<string,mixed>>
     */
    private function collectFromNotas(): array
    {
        if (!$this->tableHealth->tableExists('biomie_notas_fiscais_canonicas')) {
            return [];
        }

        $sql = <<<'SQL'
            SELECT
                codigo_cliente_omie,
                MAX(documento_cliente)                                                       AS documento_raw,
                MAX(nome_cliente)                                                            AS nome_cliente,
                SUM(CASE WHEN status_fiscal = 'autorizada' THEN 1 ELSE 0 END)              AS total_nf_aut,
                SUM(CASE WHEN status_fiscal = 'cancelada'  THEN 1 ELSE 0 END)              AS total_nf_can,
                SUM(CASE WHEN status_fiscal = 'autorizada' THEN valor_total ELSE 0 END)    AS receita_aut,
                SUM(CASE WHEN status_fiscal = 'cancelada'  THEN valor_total ELSE 0 END)    AS cancelado,
                MAX(CASE WHEN status_fiscal = 'autorizada'
                         THEN COALESCE(data_competencia, data_emissao) END)                AS ultima_nf,
                MAX(CASE WHEN status_fiscal = 'cancelada'
                         THEN COALESCE(data_competencia, data_emissao) END)                AS ultima_nf_can,
                SUBSTRING_INDEX(GROUP_CONCAT(
                    CASE WHEN codigo_vendedor_omie != '' AND codigo_vendedor_omie != 'Sem vendedor'
                         THEN codigo_vendedor_omie END
                    ORDER BY COALESCE(data_competencia, data_emissao) DESC
                    SEPARATOR '\x01'
                ), '\x01', 1)                                                              AS vendedor_codigo,
                SUBSTRING_INDEX(GROUP_CONCAT(
                    CASE WHEN nome_vendedor != 'Sem vendedor' AND nome_vendedor != ''
                         THEN nome_vendedor END
                    ORDER BY COALESCE(data_competencia, data_emissao) DESC
                    SEPARATOR '\x01'
                ), '\x01', 1)                                                              AS vendedor_nome
            FROM biomie_notas_fiscais_canonicas
            WHERE company_id = :company_id
              AND codigo_cliente_omie IS NOT NULL
            GROUP BY codigo_cliente_omie
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':company_id' => $this->companyId]);
        if ($stmt === false) {
            return [];
        }

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['codigo_cliente_omie'];
            $map[$id] = $row;
        }
        return $map;
    }

    // ── Merging ───────────────────────────────────────────────────────────────

    /**
     * Combina pedidos e NF em uma estrutura de cliente pronta para upsert.
     *
     * @param array<int,array<string,mixed>> $pedidoMap
     * @param array<int,array<string,mixed>> $nfMap
     * @return array<int,array<string,mixed>>
     */
    private function buildMergedClients(array $pedidoMap, array $nfMap): array
    {
        $allIds = array_unique(array_merge(array_keys($pedidoMap), array_keys($nfMap)));
        $merged = [];

        foreach ($allIds as $codigoClienteOmie) {
            $p = $pedidoMap[$codigoClienteOmie] ?? null;
            $n = $nfMap[$codigoClienteOmie] ?? null;

            // Document: prefer NF (NF requires valid CNPJ/CPF) then pedido
            $docRaw = $this->firstNonEmpty(
                $n['documento_raw'] ?? null,
                $p['documento_raw'] ?? null,
            );
            $docNorm = $this->normalizeDocument($docRaw ?? '');
            $docMask = $this->maskDocument($docNorm, $docRaw ?? '');
            $tipoDoc = match (true) {
                $docNorm !== null && strlen($docNorm) === 11 => 'cpf',
                $docNorm !== null && strlen($docNorm) === 14 => 'cnpj',
                default => null,
            };
            $tipoPessoa = match ($tipoDoc) {
                'cpf'  => 'PF',
                'cnpj' => 'PJ',
                default => null,
            };

            // Name: prefer NF then pedido; never leave blank
            $nomeNf     = $this->sanitizeName($n['nome_cliente'] ?? null);
            $nomePedido = $this->sanitizeName($p['nome_cliente'] ?? null);
            $nomeCliente = $nomeNf ?? $nomePedido ?? 'Cliente nao informado';

            // Nome de exibição
            $nomeExibicao = $nomeCliente !== 'Cliente nao informado'
                ? $nomeCliente
                : ($docMask ?? 'Cliente nao informado');

            // Segmento macro
            $segmentoMacro = match ($tipoPessoa) {
                'PF'    => 'PF',
                'PJ'    => 'PJ_SEM_CNAE',
                default => 'PJ_SEM_CNAE',
            };

            // Vendor resolution: pedido > NF > sem_vinculo
            [$vendCod, $vendNome, $vendOrigem, $vendConf] = $this->resolveVendor(
                $p['vendedor_codigo'] ?? null,
                $p['vendedor_nome'] ?? null,
                $n['vendedor_codigo'] ?? null,
                $n['vendedor_nome'] ?? null,
            );

            // Source priority (1 = best)
            $srcPriority = $p !== null ? 1 : 2;

            // Enrichment status
            $enrichStatus = $docNorm !== null ? 'pending_geo' : 'pending_doc_geo';

            $candidate = [
                'company_id'                       => $this->companyId,
                'codigo_cliente_omie'              => (int) $codigoClienteOmie,
                'cpf_cnpj'                         => $docRaw !== null ? substr($docRaw, 0, 20) : null,
                'tipo_documento'                   => $tipoDoc,
                'nome_cliente'                     => substr($nomeCliente, 0, 200),
                'nome_fantasia'                    => null,
                'segmento_macro'                   => $segmentoMacro,
                'documento_normalizado'            => $docNorm,
                'documento_mascarado'              => $docMask,
                'tipo_pessoa'                      => $tipoPessoa,
                'cliente_nome_exibicao'            => substr($nomeExibicao, 0, 255),
                'geo_status'                       => 'pending_enrichment',
                'vendedor_responsavel_codigo_omie' => $vendCod,
                'vendedor_responsavel_nome'        => substr($vendNome, 0, 120),
                'vendedor_responsavel_origem'      => $vendOrigem,
                'vendedor_responsavel_confidence'  => $vendConf,
                'primeira_compra_em'               => $p['primeira_compra'] ?? null,
                'ultima_compra_em'                 => $p['ultimo_pedido'] ?? null,
                'ultima_nf_em'                     => $n['ultima_nf'] ?? null,
                'ultima_nf_cancelada_em'           => $n['ultima_nf_can'] ?? null,
                'ultimo_pedido_em'                 => $p['ultimo_pedido'] ?? null,
                'total_pedidos'                    => (int) ($p['total_pedidos'] ?? 0),
                'total_nf_autorizadas'             => (int) ($n['total_nf_aut'] ?? 0),
                'total_nf_canceladas'              => (int) ($n['total_nf_can'] ?? 0),
                'total_receita_autorizada'         => (float) ($n['receita_aut'] ?? $p['total_receita'] ?? 0),
                'total_cancelado'                  => (float) ($n['cancelado'] ?? 0),
                'notas_12m'                        => (int) ($n['total_nf_aut'] ?? 0),
                'faturado_12m'                     => (float) ($n['receita_aut'] ?? 0),
                'enrichment_status'                => $enrichStatus,
                'source_priority'                  => $srcPriority,
            ];
            $candidate['materialization_hash'] = $this->materializationHash($candidate);
            $merged[$codigoClienteOmie] = $candidate;
        }

        return $merged;
    }

    /**
     * @param array<string,mixed> $candidate
     */
    private function materializationHash(array $candidate): string
    {
        unset($candidate['materialization_hash']);

        return hash('sha256', json_encode(
            $candidate,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @return array<int,string>
     */
    private function loadExistingMaterializationHashes(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT codigo_cliente_omie, materialization_hash FROM biomie_clientes_dim WHERE company_id = :company_id'
        );
        $stmt->execute([':company_id' => $this->companyId]);
        if ($stmt === false) {
            return [];
        }

        $hashes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $clientId = (int) ($row['codigo_cliente_omie'] ?? 0);
            if ($clientId > 0) {
                $hashes[$clientId] = (string) ($row['materialization_hash'] ?? '');
            }
        }

        return $hashes;
    }

    // ── Upsert ────────────────────────────────────────────────────────────────

    /**
     * @param array<int,array<string,mixed>> $clients
     * @return array{0:int,1:int,2:int} [inserted, updated, unchanged]
     */
    private function batchUpsert(array $clients): array
    {
        if ($clients === []) {
            return [0, 0, 0];
        }

        $existingHashes = $this->loadExistingMaterializationHashes();
        $changed = [];
        $inserted = 0;
        $updated = 0;
        $unchanged = 0;
        foreach ($clients as $row) {
            $clientId = (int) ($row['codigo_cliente_omie'] ?? 0);
            $hash = (string) ($row['materialization_hash'] ?? '');
            if (!array_key_exists($clientId, $existingHashes)) {
                $inserted++;
                $changed[] = $row;
                continue;
            }
            if ($hash !== '' && hash_equals((string) $existingHashes[$clientId], $hash)) {
                $unchanged++;
                continue;
            }
            $updated++;
            $changed[] = $row;
        }

        $chunks = array_chunk($changed, self::UPSERT_BATCH_SIZE);
        $startedTransaction = !$this->pdo->inTransaction();

        try {
            if ($startedTransaction) {
                $this->pdo->beginTransaction();
            }
            foreach ($chunks as $chunk) {
                $this->executeClientUpsertChunk($chunk);
            }
            if ($startedTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->performance = [
                'client_upsert_strategy' => 'row_fallback',
                'client_upsert_batch_size' => self::UPSERT_BATCH_SIZE,
                'client_upsert_batches' => count($chunks),
                'client_upsert_rows' => count($changed),
                'client_unchanged_rows' => $unchanged,
                'bulk_fallback_reason' => SecretMask::sanitizeErrorMessage($exception->getMessage(), 180),
            ];
            $this->logger->warning('CustomerDimensionMaterializationService: bulk upsert indisponivel; usando fallback unitario.', [
                'message' => SecretMask::sanitizeErrorMessage($exception->getMessage(), 300),
            ]);

            $this->batchUpsertRowByRow($changed);
            return [$inserted, $updated, $unchanged];
        }

        $this->performance = [
            'client_upsert_strategy' => 'bulk_transaction',
            'client_upsert_batch_size' => self::UPSERT_BATCH_SIZE,
            'client_upsert_batches' => count($chunks),
            'client_upsert_rows' => count($changed),
            'client_unchanged_rows' => $unchanged,
        ];

        return [$inserted, $updated, $unchanged];
    }

    /**
     * @param list<array<string,mixed>> $clients
     */
    private function executeClientUpsertChunk(array $clients): void
    {
        $fieldMap = [
            'company_id' => 'company_id',
            'codigo_cliente_omie' => 'codigo_cliente_omie',
            'cpf_cnpj' => 'cpf_cnpj',
            'tipo_documento' => 'tipo_documento',
            'nome_cliente' => 'nome_cliente',
            'nome_fantasia' => 'nome_fantasia',
            'segmento_macro' => 'segmento_macro',
            'documento_normalizado' => 'documento_normalizado',
            'documento_mascarado' => 'documento_mascarado',
            'tipo_pessoa' => 'tipo_pessoa',
            'cliente_nome_exibicao' => 'cliente_nome_exibicao',
            'geo_status' => 'geo_status',
            'vendedor_responsavel_codigo_omie' => 'vendedor_responsavel_codigo_omie',
            'vendedor_responsavel_nome' => 'vendedor_responsavel_nome',
            'vendedor_responsavel_origem' => 'vendedor_responsavel_origem',
            'vendedor_responsavel_confidence' => 'vendedor_responsavel_confidence',
            'primeira_compra_em' => 'primeira_compra_em',
            'ultima_compra_em' => 'ultima_compra_em',
            'ultima_nf_em' => 'ultima_nf_em',
            'ultima_nf_cancelada_em' => 'ultima_nf_cancelada_em',
            'ultimo_pedido_em' => 'ultimo_pedido_em',
            'total_pedidos' => 'total_pedidos',
            'total_nf_autorizadas' => 'total_nf_autorizadas',
            'total_nf_canceladas' => 'total_nf_canceladas',
            'total_receita_autorizada' => 'total_receita_autorizada',
            'total_cancelado' => 'total_cancelado',
            'notas_12m' => 'notas_12m',
            'faturado_12m' => 'faturado_12m',
            'enrichment_status' => 'enrichment_status',
            'source_priority' => 'source_priority',
            'materialization_hash' => 'materialization_hash',
        ];
        $columns = array_keys($fieldMap);
        array_splice($columns, 16, 0, ['vendedor_responsavel_updated_at']);
        $columns[] = 'created_at';
        $columns[] = 'updated_at';

        $groups = [];
        $params = [];
        foreach (array_values($clients) as $rowIndex => $row) {
            $placeholders = [];
            foreach ($columns as $column) {
                if (in_array($column, ['vendedor_responsavel_updated_at', 'created_at', 'updated_at'], true)) {
                    $placeholders[] = 'NOW()';
                    continue;
                }
                $param = 'r' . $rowIndex . '_' . $column;
                $placeholders[] = ':' . $param;
                $params[$param] = $row[$fieldMap[$column]] ?? null;
            }
            $groups[] = '(' . implode(', ', $placeholders) . ')';
        }

        $quotedColumns = array_map(static fn (string $column): string => '`' . $column . '`', $columns);
        $sql = 'INSERT INTO `biomie_clientes_dim` (' . implode(', ', $quotedColumns) . ') VALUES '
            . implode(', ', $groups)
            . $this->clientUpsertUpdateSql();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    private function clientUpsertUpdateSql(): string
    {
        return <<<'SQL'
            ON DUPLICATE KEY UPDATE
                `cpf_cnpj`              = COALESCE(VALUES(`cpf_cnpj`), `cpf_cnpj`),
                `tipo_documento`        = COALESCE(VALUES(`tipo_documento`), `tipo_documento`),
                `nome_cliente`          = IF(
                    VALUES(`source_priority`) <= `source_priority`
                    OR `nome_cliente` IN ('Cliente nao informado', ''),
                    VALUES(`nome_cliente`), `nome_cliente`
                ),
                `nome_fantasia`         = COALESCE(VALUES(`nome_fantasia`), `nome_fantasia`),
                `segmento_macro`        = COALESCE(VALUES(`segmento_macro`), `segmento_macro`),
                `documento_normalizado` = COALESCE(`documento_normalizado`, VALUES(`documento_normalizado`)),
                `documento_mascarado`   = COALESCE(`documento_mascarado`, VALUES(`documento_mascarado`)),
                `tipo_pessoa`           = COALESCE(VALUES(`tipo_pessoa`), `tipo_pessoa`),
                `cliente_nome_exibicao` = IF(
                    VALUES(`source_priority`) <= `source_priority` OR `cliente_nome_exibicao` IS NULL,
                    VALUES(`cliente_nome_exibicao`), `cliente_nome_exibicao`
                ),
                `vendedor_responsavel_codigo_omie` = IF(
                    VALUES(`vendedor_responsavel_confidence`) >= `vendedor_responsavel_confidence`,
                    VALUES(`vendedor_responsavel_codigo_omie`), `vendedor_responsavel_codigo_omie`
                ),
                `vendedor_responsavel_nome` = IF(
                    VALUES(`vendedor_responsavel_confidence`) >= `vendedor_responsavel_confidence`,
                    VALUES(`vendedor_responsavel_nome`), `vendedor_responsavel_nome`
                ),
                `vendedor_responsavel_origem` = IF(
                    VALUES(`vendedor_responsavel_confidence`) >= `vendedor_responsavel_confidence`,
                    VALUES(`vendedor_responsavel_origem`), `vendedor_responsavel_origem`
                ),
                `vendedor_responsavel_confidence` = GREATEST(
                    VALUES(`vendedor_responsavel_confidence`), `vendedor_responsavel_confidence`
                ),
                `vendedor_responsavel_updated_at` = IF(
                    VALUES(`vendedor_responsavel_confidence`) >= `vendedor_responsavel_confidence`,
                    NOW(), `vendedor_responsavel_updated_at`
                ),
                `primeira_compra_em` = IF(
                    VALUES(`primeira_compra_em`) IS NOT NULL
                    AND (`primeira_compra_em` IS NULL OR VALUES(`primeira_compra_em`) < `primeira_compra_em`),
                    VALUES(`primeira_compra_em`), `primeira_compra_em`
                ),
                `ultima_compra_em` = IF(
                    VALUES(`ultima_compra_em`) IS NOT NULL
                    AND (`ultima_compra_em` IS NULL OR VALUES(`ultima_compra_em`) > `ultima_compra_em`),
                    VALUES(`ultima_compra_em`), `ultima_compra_em`
                ),
                `ultima_nf_em` = IF(
                    VALUES(`ultima_nf_em`) IS NOT NULL
                    AND (`ultima_nf_em` IS NULL OR VALUES(`ultima_nf_em`) > `ultima_nf_em`),
                    VALUES(`ultima_nf_em`), `ultima_nf_em`
                ),
                `ultima_nf_cancelada_em` = IF(
                    VALUES(`ultima_nf_cancelada_em`) IS NOT NULL
                    AND (`ultima_nf_cancelada_em` IS NULL OR VALUES(`ultima_nf_cancelada_em`) > `ultima_nf_cancelada_em`),
                    VALUES(`ultima_nf_cancelada_em`), `ultima_nf_cancelada_em`
                ),
                `ultimo_pedido_em` = IF(
                    VALUES(`ultimo_pedido_em`) IS NOT NULL
                    AND (`ultimo_pedido_em` IS NULL OR VALUES(`ultimo_pedido_em`) > `ultimo_pedido_em`),
                    VALUES(`ultimo_pedido_em`), `ultimo_pedido_em`
                ),
                `total_pedidos`            = VALUES(`total_pedidos`),
                `total_nf_autorizadas`     = VALUES(`total_nf_autorizadas`),
                `total_nf_canceladas`      = VALUES(`total_nf_canceladas`),
                `total_receita_autorizada` = VALUES(`total_receita_autorizada`),
                `total_cancelado`          = VALUES(`total_cancelado`),
                `notas_12m`                = VALUES(`notas_12m`),
                `faturado_12m`             = VALUES(`faturado_12m`),
                `enrichment_status`        = VALUES(`enrichment_status`),
                `source_priority`          = LEAST(VALUES(`source_priority`), `source_priority`),
                `materialization_hash`     = VALUES(`materialization_hash`),
                `updated_at`               = NOW()
        SQL;
    }

    /**
     * Compatibility fallback when a server rejects a multi-row statement.
     *
     * @param array<int,array<string,mixed>> $clients
     */
    private function batchUpsertRowByRow(array $clients): void
    {
        if ($clients === []) {
            return;
        }

        $sql = <<<'SQL'
            INSERT INTO `biomie_clientes_dim` (
                `company_id`, `codigo_cliente_omie`, `cpf_cnpj`, `tipo_documento`, `nome_cliente`,
                `nome_fantasia`, `segmento_macro`,
                `documento_normalizado`, `documento_mascarado`, `tipo_pessoa`,
                `cliente_nome_exibicao`, `geo_status`,
                `vendedor_responsavel_codigo_omie`, `vendedor_responsavel_nome`,
                `vendedor_responsavel_origem`, `vendedor_responsavel_confidence`,
                `vendedor_responsavel_updated_at`,
                `primeira_compra_em`, `ultima_compra_em`,
                `ultima_nf_em`, `ultima_nf_cancelada_em`, `ultimo_pedido_em`,
                `total_pedidos`, `total_nf_autorizadas`, `total_nf_canceladas`,
                `total_receita_autorizada`, `total_cancelado`,
                `notas_12m`, `faturado_12m`,
                `enrichment_status`, `source_priority`, `materialization_hash`,
                `created_at`, `updated_at`
            ) VALUES (
                :company_id, :cco, :cpf, :tipo_doc, :nome, :fantasia, :segmento,
                :doc_norm, :doc_mask, :tipo_pessoa,
                :nome_exib, :geo_status,
                :vend_cod, :vend_nome, :vend_orig, :vend_conf, NOW(),
                :prim_compra, :ult_compra,
                :ult_nf, :ult_nf_can, :ult_pedido,
                :tot_ped, :tot_nf_aut, :tot_nf_can,
                :tot_receita, :tot_cancel,
                :notas_12m, :fat_12m,
                :enrich, :src_prio, :materialization_hash,
                NOW(), NOW()
            )
            ON DUPLICATE KEY UPDATE
                `cpf_cnpj`              = COALESCE(VALUES(`cpf_cnpj`), `cpf_cnpj`),
                `tipo_documento`        = COALESCE(VALUES(`tipo_documento`), `tipo_documento`),
                `nome_cliente`          = IF(
                    VALUES(`source_priority`) <= `source_priority`
                    OR `nome_cliente` IN ('Cliente nao informado', ''),
                    VALUES(`nome_cliente`), `nome_cliente`
                ),
                `nome_fantasia`         = COALESCE(VALUES(`nome_fantasia`), `nome_fantasia`),
                `segmento_macro`        = COALESCE(VALUES(`segmento_macro`), `segmento_macro`),
                `documento_normalizado` = COALESCE(`documento_normalizado`, VALUES(`documento_normalizado`)),
                `documento_mascarado`   = COALESCE(`documento_mascarado`, VALUES(`documento_mascarado`)),
                `tipo_pessoa`           = COALESCE(VALUES(`tipo_pessoa`), `tipo_pessoa`),
                `cliente_nome_exibicao` = IF(
                    VALUES(`source_priority`) <= `source_priority` OR `cliente_nome_exibicao` IS NULL,
                    VALUES(`cliente_nome_exibicao`), `cliente_nome_exibicao`
                ),
                `vendedor_responsavel_codigo_omie` = IF(
                    VALUES(`vendedor_responsavel_confidence`) >= `vendedor_responsavel_confidence`,
                    VALUES(`vendedor_responsavel_codigo_omie`), `vendedor_responsavel_codigo_omie`
                ),
                `vendedor_responsavel_nome` = IF(
                    VALUES(`vendedor_responsavel_confidence`) >= `vendedor_responsavel_confidence`,
                    VALUES(`vendedor_responsavel_nome`), `vendedor_responsavel_nome`
                ),
                `vendedor_responsavel_origem` = IF(
                    VALUES(`vendedor_responsavel_confidence`) >= `vendedor_responsavel_confidence`,
                    VALUES(`vendedor_responsavel_origem`), `vendedor_responsavel_origem`
                ),
                `vendedor_responsavel_confidence` = GREATEST(
                    VALUES(`vendedor_responsavel_confidence`), `vendedor_responsavel_confidence`
                ),
                `vendedor_responsavel_updated_at` = IF(
                    VALUES(`vendedor_responsavel_confidence`) >= `vendedor_responsavel_confidence`,
                    NOW(), `vendedor_responsavel_updated_at`
                ),
                `primeira_compra_em` = IF(
                    VALUES(`primeira_compra_em`) IS NOT NULL
                    AND (`primeira_compra_em` IS NULL OR VALUES(`primeira_compra_em`) < `primeira_compra_em`),
                    VALUES(`primeira_compra_em`), `primeira_compra_em`
                ),
                `ultima_compra_em` = IF(
                    VALUES(`ultima_compra_em`) IS NOT NULL
                    AND (`ultima_compra_em` IS NULL OR VALUES(`ultima_compra_em`) > `ultima_compra_em`),
                    VALUES(`ultima_compra_em`), `ultima_compra_em`
                ),
                `ultima_nf_em` = IF(
                    VALUES(`ultima_nf_em`) IS NOT NULL
                    AND (`ultima_nf_em` IS NULL OR VALUES(`ultima_nf_em`) > `ultima_nf_em`),
                    VALUES(`ultima_nf_em`), `ultima_nf_em`
                ),
                `ultima_nf_cancelada_em` = IF(
                    VALUES(`ultima_nf_cancelada_em`) IS NOT NULL
                    AND (`ultima_nf_cancelada_em` IS NULL OR VALUES(`ultima_nf_cancelada_em`) > `ultima_nf_cancelada_em`),
                    VALUES(`ultima_nf_cancelada_em`), `ultima_nf_cancelada_em`
                ),
                `ultimo_pedido_em` = IF(
                    VALUES(`ultimo_pedido_em`) IS NOT NULL
                    AND (`ultimo_pedido_em` IS NULL OR VALUES(`ultimo_pedido_em`) > `ultimo_pedido_em`),
                    VALUES(`ultimo_pedido_em`), `ultimo_pedido_em`
                ),
                `total_pedidos`          = VALUES(`total_pedidos`),
                `total_nf_autorizadas`   = VALUES(`total_nf_autorizadas`),
                `total_nf_canceladas`    = VALUES(`total_nf_canceladas`),
                `total_receita_autorizada` = VALUES(`total_receita_autorizada`),
                `total_cancelado`        = VALUES(`total_cancelado`),
                `notas_12m`              = VALUES(`notas_12m`),
                `faturado_12m`           = VALUES(`faturado_12m`),
                `enrichment_status`      = VALUES(`enrichment_status`),
                `source_priority`        = LEAST(VALUES(`source_priority`), `source_priority`),
                `materialization_hash`   = VALUES(`materialization_hash`),
                `updated_at`             = NOW()
        SQL;

        $stmt = $this->pdo->prepare($sql);

        foreach ($clients as $row) {
            $stmt->execute([
                'company_id' => $this->companyId,
                'cco'        => $row['codigo_cliente_omie'],
                'cpf'        => $row['cpf_cnpj'],
                'tipo_doc'   => $row['tipo_documento'],
                'nome'       => $row['nome_cliente'],
                'fantasia'   => $row['nome_fantasia'],
                'segmento'   => $row['segmento_macro'],
                'doc_norm'   => $row['documento_normalizado'],
                'doc_mask'   => $row['documento_mascarado'],
                'tipo_pessoa'=> $row['tipo_pessoa'],
                'nome_exib'  => $row['cliente_nome_exibicao'],
                'geo_status' => $row['geo_status'],
                'vend_cod'   => $row['vendedor_responsavel_codigo_omie'],
                'vend_nome'  => $row['vendedor_responsavel_nome'],
                'vend_orig'  => $row['vendedor_responsavel_origem'],
                'vend_conf'  => $row['vendedor_responsavel_confidence'],
                'prim_compra'=> $row['primeira_compra_em'],
                'ult_compra' => $row['ultima_compra_em'],
                'ult_nf'     => $row['ultima_nf_em'],
                'ult_nf_can' => $row['ultima_nf_cancelada_em'],
                'ult_pedido' => $row['ultimo_pedido_em'],
                'tot_ped'    => $row['total_pedidos'],
                'tot_nf_aut' => $row['total_nf_autorizadas'],
                'tot_nf_can' => $row['total_nf_canceladas'],
                'tot_receita'=> $row['total_receita_autorizada'],
                'tot_cancel' => $row['total_cancelado'],
                'notas_12m'  => $row['notas_12m'],
                'fat_12m'    => $row['faturado_12m'],
                'enrich'     => $row['enrichment_status'],
                'src_prio'   => $row['source_priority'],
                'materialization_hash' => $row['materialization_hash'],
            ]);
        }
    }

    /**
     * Insere entradas em biomie_clientes_geo_dim para clientes ainda sem registro.
     * Apenas cria o esqueleto com geo_status = pending_enrichment.
     *
     * @param array<int,array<string,mixed>> $clients
     */
    private function upsertGeoDim(array $clients): int
    {
        if ($clients === [] || !$this->columnExists('biomie_clientes_geo_dim', 'geo_status')) {
            return 0;
        }

        $existing = [];
        $stmt = $this->pdo->prepare(
            'SELECT codigo_cliente_omie, documento_normalizado, cliente_nome_exibicao, geo_status '
            . 'FROM biomie_clientes_geo_dim WHERE company_id = :company_id'
        );
        $stmt->execute([':company_id' => $this->companyId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $clientId = (int) ($row['codigo_cliente_omie'] ?? 0);
            if ($clientId > 0) {
                $existing[$clientId] = $row;
            }
        }

        $candidates = [];
        foreach ($clients as $row) {
            $clientId = (int) ($row['codigo_cliente_omie'] ?? 0);
            $current = $existing[$clientId] ?? null;
            $needsRepair = $current === null
                || ($this->isBlank($current['documento_normalizado'] ?? null) && !$this->isBlank($row['documento_normalizado'] ?? null))
                || ($this->isBlank($current['cliente_nome_exibicao'] ?? null) && !$this->isBlank($row['cliente_nome_exibicao'] ?? null))
                || $this->isBlank($current['geo_status'] ?? null);
            if ($needsRepair) {
                $candidates[] = $row;
            }
        }

        $count = 0;
        $chunks = array_chunk($candidates, self::UPSERT_BATCH_SIZE);
        $fallbackChunks = 0;
        foreach ($chunks as $chunk) {
            $groups = [];
            $params = [];
            foreach ($chunk as $index => $row) {
                $groups[] = sprintf('(:cid_%1$d, :cco_%1$d, :doc_%1$d, :name_%1$d, \'pending_enrichment\')', $index);
                $params['cid_' . $index] = $this->companyId;
                $params['cco_' . $index] = $row['codigo_cliente_omie'];
                $params['doc_' . $index] = $row['documento_normalizado'];
                $params['name_' . $index] = $row['cliente_nome_exibicao'];
            }
            $sql = <<<'SQL'
                INSERT INTO `biomie_clientes_geo_dim`
                    (`company_id`, `codigo_cliente_omie`, `documento_normalizado`, `cliente_nome_exibicao`, `geo_status`)
                VALUES
            SQL . implode(', ', $groups) . <<<'SQL'
                ON DUPLICATE KEY UPDATE
                    `documento_normalizado` = COALESCE(`documento_normalizado`, VALUES(`documento_normalizado`)),
                    `cliente_nome_exibicao` = COALESCE(`cliente_nome_exibicao`, VALUES(`cliente_nome_exibicao`)),
                    `geo_status`            = COALESCE(`geo_status`, 'pending_enrichment')
            SQL;
            try {
                $ps = $this->pdo->prepare($sql);
                $ps->execute($params);
                $count += count($chunk);
            } catch (Throwable) {
                $fallbackChunks++;
                $count += $this->upsertGeoDimRowsFallback($chunk);
            }
        }
        $this->performance['geo_upsert_strategy'] = $fallbackChunks > 0 ? 'bulk_with_row_fallback' : 'bulk';
        $this->performance['geo_upsert_batches'] = count($chunks);
        $this->performance['geo_upsert_rows'] = count($candidates);
        $this->performance['geo_unchanged_rows'] = count($clients) - count($candidates);
        $this->performance['geo_upsert_fallback_batches'] = $fallbackChunks;

        return $count;
    }

    /**
     * @param list<array<string,mixed>> $clients
     */
    private function upsertGeoDimRowsFallback(array $clients): int
    {
        $sql = <<<'SQL'
            INSERT INTO `biomie_clientes_geo_dim`
                (`company_id`, `codigo_cliente_omie`, `documento_normalizado`, `cliente_nome_exibicao`, `geo_status`)
            VALUES
                (:company_id, :cco, :doc_norm, :nome_exib, 'pending_enrichment')
            ON DUPLICATE KEY UPDATE
                `documento_normalizado` = COALESCE(`documento_normalizado`, VALUES(`documento_normalizado`)),
                `cliente_nome_exibicao` = COALESCE(`cliente_nome_exibicao`, VALUES(`cliente_nome_exibicao`)),
                `geo_status`            = COALESCE(`geo_status`, 'pending_enrichment')
        SQL;
        $stmt = $this->pdo->prepare($sql);
        $count = 0;
        foreach ($clients as $row) {
            try {
                $stmt->execute([
                    'company_id' => $this->companyId,
                    'cco' => $row['codigo_cliente_omie'],
                    'doc_norm' => $row['documento_normalizado'],
                    'nome_exib' => $row['cliente_nome_exibicao'],
                ]);
                $count++;
            } catch (Throwable) {
                // A single malformed geo row must not abort the materialization.
            }
        }

        return $count;
    }

    // ── Document Normalization ────────────────────────────────────────────────

    private function normalizeDocument(string $value): ?string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        if ($digits === '') {
            return null;
        }
        $len = strlen($digits);
        if ($len === 11 || $len === 14) {
            return $digits;
        }
        // Non-standard length — store null (documento inválido, não quebrar)
        return null;
    }

    private function maskDocument(?string $normalized, string $raw): ?string
    {
        if ($normalized === null) {
            return null;
        }
        if (strlen($normalized) === 11) {
            // CPF — fully masked to protect PII
            return '***.***.***-**';
        }
        // CNPJ — show company root (first 8 digits), mask branch/check
        return substr($raw, 0, 2) . '.' . substr($raw, 2, 3) . '.' . substr($raw, 5, 3) . '/****-**';
    }

    // ── Vendor Resolution ─────────────────────────────────────────────────────

    /**
     * @return array{0:?string,1:string,2:string,3:int}
     */
    private function resolveVendor(
        ?string $pedidoCod,
        ?string $pedidoNome,
        ?string $nfCod,
        ?string $nfNome,
    ): array {
        $pedidoCod = $this->sanitizeVendorCode($pedidoCod);
        $nfCod     = $this->sanitizeVendorCode($nfCod);

        if ($pedidoCod !== null) {
            return [
                $pedidoCod,
                $this->sanitizeName($pedidoNome) ?? 'Sem vendedor',
                'pedido',
                self::VENDOR_CONFIDENCE['pedido'],
            ];
        }

        if ($nfCod !== null) {
            return [
                $nfCod,
                $this->sanitizeName($nfNome) ?? 'Sem vendedor',
                'nota_fiscal',
                self::VENDOR_CONFIDENCE['nota_fiscal'],
            ];
        }

        return [null, 'Sem vendedor', 'sem_vinculo', self::VENDOR_CONFIDENCE['sem_vinculo']];
    }

    private function sanitizeVendorCode(?string $code): ?string
    {
        $code = trim((string) $code);
        if ($code === '' || $code === 'Sem vendedor' || $code === '0') {
            return null;
        }
        return $code;
    }

    private function sanitizeName(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '' || $trimmed === 'Cliente nao informado' || $trimmed === 'Sem vendedor') {
            return null;
        }
        return $trimmed;
    }

    private function firstNonEmpty(?string ...$values): ?string
    {
        foreach ($values as $v) {
            if ($v !== null && trim($v) !== '') {
                return trim($v);
            }
        }
        return null;
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }

    // ── Diagnostics ───────────────────────────────────────────────────────────

    /**
     * @return array<string,int|null>
     */
    private function countFinalState(): array
    {
        if (!$this->tableHealth->tableExists('biomie_clientes_dim')) {
            return [];
        }

        try {
            $countStmt = $this->pdo->prepare(<<<'SQL'
                SELECT
                    COUNT(*) AS total,
                    SUM(documento_normalizado IS NOT NULL AND documento_normalizado != '') AS com_documento,
                    SUM(vendedor_responsavel_confidence > 0) AS com_vendedor,
                    SUM(cidade IS NOT NULL AND cidade != '' AND bairro IS NOT NULL AND bairro != '') AS com_geo,
                    SUM(cnae_codigo IS NOT NULL AND cnae_codigo != '') AS com_cnae
                FROM `biomie_clientes_dim`
                WHERE company_id = :company_id
            SQL);
            $countStmt->execute([':company_id' => $this->companyId]);
            $row = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $total = (int) ($row['total'] ?? 0);
            $comDoc = (int) ($row['com_documento'] ?? 0);
            $comVend = (int) ($row['com_vendedor'] ?? 0);
            $comGeo = (int) ($row['com_geo'] ?? 0);
            $comCnae = (int) ($row['com_cnae'] ?? 0);
        } catch (Throwable) {
            return ['total' => null];
        }

        return [
            'total'          => $total,
            'com_documento'  => $comDoc,
            'sem_documento'  => $total - $comDoc,
            'com_vendedor'   => $comVend,
            'sem_vendedor'   => $total - $comVend,
            'com_geo'        => $comGeo,
            'sem_bairro'     => $total - $comGeo,
            'com_cnae'       => $comCnae,
            'sem_cnae'       => $total - $comCnae,
        ];
    }

    /**
     * @param array<string,mixed> $report
     */
    private function appendDomainWarnings(array &$report): void
    {
        $fc = $report['final_counts'] ?? [];
        $total = (int) ($fc['total'] ?? 0);

        if ($total === 0) {
            $report['warnings'][] = 'Nenhum cliente foi materializado. Verifique as tabelas canonicas.';
            return;
        }
        if ((int) ($fc['sem_documento'] ?? 0) > 0) {
            $report['warnings'][] = ((int) $fc['sem_documento']) . ' cliente(s) sem documento normalizado.';
        }
        if ((int) ($fc['sem_vendedor'] ?? 0) > 0) {
            $report['warnings'][] = ((int) $fc['sem_vendedor']) . ' cliente(s) sem vendedor responsavel.';
        }
        if ((int) ($fc['sem_bairro'] ?? $total) > 0) {
            $report['warnings'][] = 'Clientes sem bairro: execute enriquecimento geo depois.';
        }
        if ((int) ($fc['sem_cnae'] ?? $total) > 0) {
            $report['warnings'][] = 'Clientes sem CNAE: enriquecimento pendente.';
        }
    }

    // ── Telemetry Helpers ─────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private function eventContext(array $report): array
    {
        return [
            'source_counts'    => $report['source_counts'] ?? [],
            'candidate_counts' => $report['candidate_counts'] ?? [],
            'inserted_count'   => $report['inserted_count'] ?? 0,
            'updated_count'    => $report['updated_count'] ?? 0,
            'unchanged_count'  => $report['unchanged_count'] ?? 0,
            'performance'      => $report['performance'] ?? [],
            'final_counts'     => $report['final_counts'] ?? [],
            'warnings'         => $report['warnings'] ?? [],
            'errors'           => $report['errors'] ?? [],
            'duration_ms'      => $report['duration_ms'] ?? 0,
        ];
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private function spanResult(array $report): array
    {
        return [
            'ok'             => $report['ok'] ?? false,
            'status'         => $report['status'] ?? 'unknown',
            'inserted_count' => $report['inserted_count'] ?? 0,
            'updated_count'  => $report['updated_count'] ?? 0,
            'unchanged_count'=> $report['unchanged_count'] ?? 0,
            'warnings'       => $report['warnings'] ?? [],
        ];
    }

    /**
     * @param array<string,mixed> $report
     */
    private function finish(array &$report, float $startedAt): void
    {
        $report['ended_at']   = date('Y-m-d H:i:s');
        $report['duration_ms'] = max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyReport(): array
    {
        return [
            'ok'              => false,
            'status'          => 'pending',
            'started_at'      => date('Y-m-d H:i:s'),
            'ended_at'        => null,
            'duration_ms'     => 0,
            'source_counts'   => [],
            'candidate_counts'=> [],
            'inserted_count'  => 0,
            'updated_count'   => 0,
            'unchanged_count' => 0,
            'geo_dim_inserted'=> 0,
            'performance'     => [],
            'final_counts'    => [],
            'warnings'        => [],
            'errors'          => [],
        ];
    }

    private function columnExists(string $table, string $column): bool
    {
        return SchemaCapabilities::columnExists($this->pdo, $table, $column);
    }
}
