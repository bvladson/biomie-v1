<?php

declare(strict_types=1);

namespace BIomie\DataGovernance;

use BIomie\Logger;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Enriquece biomie_clientes_dim com classificações de governança.
 *
 * Para cada cliente canonical encontrado nas NF-e ou pedidos:
 *   1. Agrega estatísticas de compra dos últimos 12 meses.
 *   2. Aplica DocumentNormalizer, ClientClassifier, SegmentDictionary, RecurrencePolicy.
 *   3. Faz UPSERT em biomie_clientes_dim.
 *
 * Deve ser executado ANTES de materialize_marts.php para que os marts
 * recebam tipo_pessoa e segmento_cnae corretos ao fazer JOIN.
 *
 * Ordem de execução diária recomendada:
 *   1. sync (OmieSyncService / CommercialSyncCoordinator)
 *   2. enrich_clients_governance.php  ← este serviço
 *   3. materialize_marts.php
 */
final class ClientGovernanceService
{
    private const DIM_TABLE = 'biomie_clientes_dim';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Enriquece todos os clientes com compras no banco, em lotes.
     *
     * @return int Total de clientes processados
     */
    public function enrichAll(int $batchSize = 300): int
    {
        $total  = 0;
        $offset = 0;

        do {
            $batch = $this->loadClientStats($offset, $batchSize);
            foreach ($batch as $row) {
                try {
                    $this->upsertClient($row);
                    $total++;
                } catch (Throwable $e) {
                    $this->logger->warning('ClientGovernanceService: falha ao enriquecer cliente.', [
                        'codigo_cliente_omie' => $row['codigo_cliente_omie'] ?? null,
                        'message'             => $e->getMessage(),
                    ]);
                }
            }
            $offset += $batchSize;
        } while (count($batch) === $batchSize);

        $this->logger->info('ClientGovernanceService: enriquecimento concluido.', ['total' => $total]);

        return $total;
    }

    // ── Privado ──────────────────────────────────────────────────────────────

    /**
     * Carrega estatísticas agregadas por cliente diretamente das NF-e canônicas.
     *
     * @return list<array<string,mixed>>
     */
    private function loadClientStats(int $offset, int $limit): array
    {
        $cutoff12m = (new DateTimeImmutable('-12 months'))->format('Y-m-d');

        $stmt = $this->pdo->prepare(<<<SQL
            SELECT
                n.codigo_cliente_omie,
                MIN(n.nome_cliente)                                      AS nome_cliente,
                MIN(n.documento_cliente)                                 AS documento_cliente,
                MIN(n.data_emissao)                                      AS primeira_compra_em,
                MAX(n.data_emissao)                                      AS ultima_compra_em,
                SUM(
                    n.data_emissao >= :cutoff12m_1
                    AND n.status_fiscal = 'autorizada'
                )                                                        AS notas_12m,
                SUM(
                    IF(n.data_emissao >= :cutoff12m_2
                       AND n.status_fiscal = 'autorizada',
                       n.valor_total, 0)
                )                                                        AS faturado_12m,
                COUNT(DISTINCT
                    IF(n.data_emissao >= :cutoff12m_3
                       AND n.status_fiscal = 'autorizada',
                       DATE_FORMAT(n.data_emissao, '%Y-%m'), NULL)
                )                                                        AS meses_distintos_12m,
                MIN(cd.cnae_codigo)                                      AS cnae_codigo,
                MIN(cd.cnae_descricao)                                   AS cnae_descricao
            FROM biomie_notas_fiscais_canonicas n
            LEFT JOIN biomie_clientes_dim cd
                ON cd.codigo_cliente_omie = n.codigo_cliente_omie
            WHERE n.codigo_cliente_omie IS NOT NULL
            GROUP BY n.codigo_cliente_omie
            ORDER BY n.codigo_cliente_omie
            LIMIT :limit OFFSET :offset
        SQL);

        $stmt->execute([
            'cutoff12m_1' => $cutoff12m,
            'cutoff12m_2' => $cutoff12m,
            'cutoff12m_3' => $cutoff12m,
            'limit'       => $limit,
            'offset'      => $offset,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Aplica as regras de governança e faz UPSERT em biomie_clientes_dim.
     *
     * @param array<string,mixed> $row
     */
    private function upsertClient(array $row): void
    {
        $documento = (string) ($row['documento_cliente'] ?? '');
        $cnae      = $row['cnae_codigo'] !== null ? (string) $row['cnae_codigo'] : null;

        $docNorm    = DocumentNormalizer::normalize($documento);
        $docType    = DocumentNormalizer::type($documento); // 'cpf' | 'cnpj' | 'desconhecido'
        $tipoPessoa = ClientClassifier::classify($documento, $cnae);
        $segmento   = SegmentDictionary::fromCnae($cnae);

        $mesesDistintos = (int) ($row['meses_distintos_12m'] ?? 0);
        $ultimaCompra   = $row['ultima_compra_em'] !== null
            ? (string) $row['ultima_compra_em']
            : null;

        $recorrencia   = RecurrencePolicy::classify($ultimaCompra, $mesesDistintos);
        $isRecorrente  = RecurrencePolicy::hasRepeatPattern($recorrencia) ? 1 : 0;

        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT INTO biomie_clientes_dim (
                codigo_cliente_omie,
                cpf_cnpj,
                documento_normalizado,
                tipo_documento,
                tipo_pessoa,
                nome_cliente,
                cnae_codigo,
                cnae_descricao,
                segmento_macro,
                primeira_compra_em,
                ultima_compra_em,
                notas_12m,
                faturado_12m,
                is_recorrente,
                recorrencia_faixa,
                sincronizado_em
            ) VALUES (
                :codigo_cliente_omie,
                :cpf_cnpj,
                :documento_normalizado,
                :tipo_documento,
                :tipo_pessoa,
                :nome_cliente,
                :cnae_codigo,
                :cnae_descricao,
                :segmento_macro,
                :primeira_compra_em,
                :ultima_compra_em,
                :notas_12m,
                :faturado_12m,
                :is_recorrente,
                :recorrencia_faixa,
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                cpf_cnpj              = VALUES(cpf_cnpj),
                documento_normalizado = VALUES(documento_normalizado),
                tipo_documento        = VALUES(tipo_documento),
                tipo_pessoa           = VALUES(tipo_pessoa),
                nome_cliente          = VALUES(nome_cliente),
                cnae_codigo           = IF(VALUES(cnae_codigo) IS NOT NULL, VALUES(cnae_codigo), cnae_codigo),
                cnae_descricao        = IF(VALUES(cnae_descricao) IS NOT NULL, VALUES(cnae_descricao), cnae_descricao),
                segmento_macro        = VALUES(segmento_macro),
                primeira_compra_em    = LEAST(COALESCE(primeira_compra_em, VALUES(primeira_compra_em)), VALUES(primeira_compra_em)),
                ultima_compra_em      = GREATEST(COALESCE(ultima_compra_em, VALUES(ultima_compra_em)), VALUES(ultima_compra_em)),
                notas_12m             = VALUES(notas_12m),
                faturado_12m          = VALUES(faturado_12m),
                is_recorrente         = VALUES(is_recorrente),
                recorrencia_faixa     = VALUES(recorrencia_faixa),
                sincronizado_em       = NOW()
        SQL);

        $stmt->execute([
            'codigo_cliente_omie'  => $row['codigo_cliente_omie'],
            'cpf_cnpj'             => $documento !== '' ? $documento : null,
            'documento_normalizado' => $docNorm !== '' ? $docNorm : null,
            'tipo_documento'       => strtoupper($docType), // 'CPF' | 'CNPJ' | 'DESCONHECIDO'
            'tipo_pessoa'          => $tipoPessoa,
            'nome_cliente'         => (string) ($row['nome_cliente'] ?? 'Cliente sem nome'),
            'cnae_codigo'          => $cnae,
            'cnae_descricao'       => $row['cnae_descricao'] !== null ? (string) $row['cnae_descricao'] : null,
            'segmento_macro'       => $segmento,
            'primeira_compra_em'   => $row['primeira_compra_em'] ?? null,
            'ultima_compra_em'     => $ultimaCompra,
            'notas_12m'            => (int) ($row['notas_12m'] ?? 0),
            'faturado_12m'         => (float) ($row['faturado_12m'] ?? 0),
            'is_recorrente'        => $isRecorrente,
            'recorrencia_faixa'    => $recorrencia,
        ]);
    }
}
