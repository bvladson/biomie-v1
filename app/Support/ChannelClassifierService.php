<?php

declare(strict_types=1);

namespace BIomie\Support;

use PDO;
use Throwable;

/**
 * Engine de classificação de canal comercial (varejo / licitação / outros).
 *
 * Carrega as regras da tabela biomie_channel_classification_rules para a
 * empresa configurada e as aplica em ordem de prioridade ascendente.
 * A primeira regra que "bater" define o canal e o nível de confiança.
 *
 * Regras skip: tipo 'manual' — essas são aplicadas diretamente via UI,
 * não pelo classificador automático.
 */
final class ChannelClassifierService
{
    /** @var list<string> Domínio canônico compartilhado com as escritas fiscais. */
    public const CHANNELS = ['varejo', 'licitacao', 'outros'];

    /** @var list<array{id:int,rule_type:string,match_value:string,target_channel:string,confidence:string}> */
    private array $rules = [];
    private bool  $rulesLoaded = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $companyId
    ) {}

    // ─── Classificação de um registro individual ──────────────────────────────

    /**
     * Classifica um registro pelas regras da empresa, na prioridade cadastrada.
     * Retorna channel + confidence + rule_id + source (origem legível gravada em
     * visibility_rule_source). A regra de negócio vigente está descrita em
     * {@see LicitacaoSeller}: vendedor "Licitação" (por código, com o nome como
     * fallback) => licitação; qualquer outro vendedor => varejo.
     *
     * @return array{channel:string, confidence:string, rule_id:int|null, source:string}
     */
    public function classify(
        string $nomeVendedor = '',
        string $observacoes  = '',
        string $cnpjCliente  = '',
        string $projetoIdOmie = '',
        string $codigoVendedor = ''
    ): array {
        $this->ensureRules();

        $vendedorLow = mb_strtolower($nomeVendedor, 'UTF-8');
        $obsLow      = mb_strtolower($observacoes, 'UTF-8');
        $codigo      = trim($codigoVendedor);

        foreach ($this->rules as $rule) {
            $hit = match ($rule['rule_type']) {
                'manual'             => false,  // nunca disparado automaticamente
                'vendedor_id_list'   => $codigo !== '' && \in_array(
                    $codigo,
                    array_map('trim', explode(',', $rule['match_value'])),
                    true
                ),
                'vendedor_nome_igual' => $nomeVendedor !== ''
                    && LicitacaoSeller::normalize($nomeVendedor) === LicitacaoSeller::normalize($rule['match_value']),
                'vendedor_nome_like' => $this->likeMatch($vendedorLow, $rule['match_value']),
                'pedido_obs_like'    => $obsLow !== '' && $this->likeMatch($obsLow, $rule['match_value']),
                'cliente_cnpj_list'  => $cnpjCliente !== '' && \in_array(
                    $cnpjCliente,
                    array_map('trim', explode(',', $rule['match_value'])),
                    true
                ),
                'projeto_id_list'    => $projetoIdOmie !== '' && \in_array(
                    ltrim($projetoIdOmie, '0') ?: '0',
                    array_map(
                        static fn (string $value): string => ltrim(trim($value), '0') ?: '0',
                        explode(',', $rule['match_value'])
                    ),
                    true
                ),
                'default'            => true,
                default              => false,
            };

            if ($hit) {
                $source = self::sourceFor($rule);
                // Mesmo canal (varejo), outra explicação: "não havia vendedor" é
                // diferente de "o vendedor era o Roberto" numa auditoria.
                if ($source === LicitacaoSeller::SOURCE_REGULAR && LicitacaoSeller::isMissingSeller($nomeVendedor)) {
                    $source = LicitacaoSeller::SOURCE_MISSING;
                }
                return [
                    'channel'    => $rule['target_channel'],
                    'confidence' => $rule['confidence'],
                    'rule_id'    => (int) $rule['id'],
                    'source'     => $source,
                ];
            }
        }

        return [
            'channel' => 'varejo',
            'confidence' => 'baixa',
            'rule_id' => null,
            'source' => 'channel_classifier_fallback',
        ];
    }

    /**
     * Origem legível da decisão. Para os tipos da regra vigente usa o
     * vocabulário de negócio; tipos legados continuam identificados pelo id.
     *
     * @param array{id:int|string,rule_type:string,target_channel:string} $rule
     */
    private static function sourceFor(array $rule): string
    {
        $type = (string) $rule['rule_type'];
        $target = (string) $rule['target_channel'];

        return match (true) {
            \in_array($type, ['vendedor_id_list', 'vendedor_nome_igual'], true) && $target === 'licitacao'
                => LicitacaoSeller::SOURCE_LICITACAO,
            $type === 'cliente_cnpj_list' && $target === 'outros' => LicitacaoSeller::SOURCE_INTRA_GRUPO,
            $type === 'default' && $target === 'varejo' => LicitacaoSeller::SOURCE_REGULAR,
            default => 'channel_classification_rule:' . (int) $rule['id'],
        };
    }

    /**
     * Normaliza e valida um valor contra o domínio persistido no banco.
     * Retorna null para ausência, tipo inesperado ou membro desconhecido.
     */
    public static function normalizeChannel(mixed $channel): ?string
    {
        if (!is_string($channel)) {
            return null;
        }

        $normalized = strtolower(trim($channel));
        return in_array($normalized, self::CHANNELS, true) ? $normalized : null;
    }

    // ─── Classificação retroativa em lote ─────────────────────────────────────

    /**
     * Classifica pedidos não classificados (ou sem channel_confidence = 'manual').
     *
     * @return array{pedidos_total:int, pedidos_varejo:int, pedidos_licitacao:int, pedidos_outros:int}
     */
    public function classifyPedidos(bool $dryRun = false): array
    {
        $cid  = $this->companyId;
        $now  = date('Y-m-d H:i:s');
        $rows = $this->fetchRows(
            "SELECT id, codigo_vendedor_omie, nome_vendedor, observacoes, documento_cliente
             FROM biomie_pedidos_canonicos
             WHERE company_id = {$cid}
               AND (channel_confidence IS NULL OR channel_confidence != 'manual')"
        );

        $stats = [
            'pedidos_total' => \count($rows),
            'pedidos_varejo' => 0,
            'pedidos_licitacao' => 0,
            'pedidos_outros' => 0,
        ];
        if ($rows === []) {
            return $stats;
        }
        if ($dryRun) {
            $this->classifyRowsForStats($rows, $stats, 'pedidos');
            return $stats;
        }

        $upd = $this->pdo->prepare(
            "UPDATE biomie_pedidos_canonicos
             SET business_channel      = :ch,
                 is_biomie_commercial_visible = :vis,
                 channel_confidence    = :conf,
                 channel_rule_id       = :rid,
                 channel_classified_at = :ts
             WHERE id = :id AND company_id = {$cid}"
        );

        $this->batchUpdate($upd, $rows, $now, $stats, 'pedidos');

        return $stats;
    }

    /**
     * Classifica notas fiscais não classificadas.
     *
     * @return array{nfs_total:int, nfs_varejo:int, nfs_licitacao:int, nfs_outros:int}
     */
    public function classifyNFs(bool $dryRun = false): array
    {
        $cid  = $this->companyId;
        $now  = date('Y-m-d H:i:s');
        $rows = $this->fetchRows(
            "SELECT id, codigo_vendedor_omie, nome_vendedor, observacoes, documento_cliente, projeto_id_omie
             FROM biomie_notas_fiscais_canonicas
             WHERE company_id = {$cid}
               AND (channel_confidence IS NULL OR channel_confidence != 'manual')"
        );

        $stats = [
            'nfs_total' => \count($rows),
            'nfs_varejo' => 0,
            'nfs_licitacao' => 0,
            'nfs_outros' => 0,
        ];
        if ($rows === []) {
            return $stats;
        }
        if ($dryRun) {
            $this->classifyRowsForStats($rows, $stats, 'nfs');
            return $stats;
        }

        $upd = $this->pdo->prepare(
            "UPDATE biomie_notas_fiscais_canonicas
             SET business_channel      = :ch,
                 is_biomie_commercial_visible = :vis,
                 channel_confidence    = :conf,
                 channel_rule_id       = :rid,
                 channel_classified_at = :ts
             WHERE id = :id AND company_id = {$cid}"
        );

        $this->batchUpdate($upd, $rows, $now, $stats, 'nfs');

        return $stats;
    }

    /**
     * Propaga o canal da entidade principal para itens e titulos relacionados.
     *
     * @return array{invoice_items:int,financial_titles:int}
     */
    public function propagateRelatedChannels(bool $dryRun = false): array
    {
        if ($dryRun) {
            return ['invoice_items' => 0, 'financial_titles' => 0];
        }

        $cid = $this->companyId;
        $items = $this->pdo->exec(
            "UPDATE biomie_notas_fiscais_itens_canonicos i
             JOIN biomie_notas_fiscais_canonicas n
               ON n.company_id = i.company_id
              AND n.nota_key = i.nota_key
                SET i.business_channel = n.business_channel
              WHERE i.company_id = {$cid}
                AND i.business_channel <> n.business_channel"
        );
        $titles = $this->pdo->exec(
            "UPDATE biomie_titulos_financeiros_canonicos t
             LEFT JOIN biomie_notas_fiscais_canonicas n
               ON n.company_id = t.company_id
              AND n.nota_key = t.nota_key
             LEFT JOIN biomie_pedidos_canonicos p
               ON p.company_id = t.company_id
              AND p.pedido_id_omie = t.pedido_id_omie
                SET t.business_channel = COALESCE(n.business_channel, p.business_channel, t.business_channel)
              WHERE t.company_id = {$cid}
                AND COALESCE(n.business_channel, p.business_channel, t.business_channel)
                    <> t.business_channel"
        );

        return [
            'invoice_items' => max(0, (int) $items),
            'financial_titles' => max(0, (int) $titles),
        ];
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /** Carrega regras lazy e em ordem de prioridade. */
    private function ensureRules(): void
    {
        if ($this->rulesLoaded) {
            return;
        }

        $cid  = $this->companyId;
        $stmt = $this->pdo->query(
            "SELECT id, rule_type, match_value, target_channel, confidence
             FROM biomie_channel_classification_rules
             WHERE company_id = {$cid} AND ativo = 1
             ORDER BY priority ASC"
        );
        $this->rules       = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $this->rulesLoaded = true;
    }

    /**
     * Simula LIKE SQL: suporta % (qualquer sequência) e _ (qualquer char).
     * Todos os inputs já devem estar em lowercase antes da chamada.
     */
    private function likeMatch(string $haystack, string $sqlPattern): bool
    {
        $lower = mb_strtolower($sqlPattern, 'UTF-8');

        // Caso mais comum: %texto% → contains
        if (str_starts_with($lower, '%') && str_ends_with($lower, '%')) {
            $needle = substr($lower, 1, -1);
            return $needle !== '' && str_contains($haystack, $needle);
        }

        // Caso geral: converte pattern SQL → regex.
        // Divide por % e _ antes de quoteizar para preservar os wildcards.
        $parts  = preg_split('/([%_])/', $lower, -1, PREG_SPLIT_DELIM_CAPTURE);
        $regex  = '/^';
        foreach ($parts ?? [] as $part) {
            if ($part === '%') {
                $regex .= '.*';
            } elseif ($part === '_') {
                $regex .= '.';
            } else {
                $regex .= preg_quote($part, '/');
            }
        }
        $regex .= '$/us';

        return (bool) preg_match($regex, $haystack);
    }

    /**
     * @param list<array{id:string|int, nome_vendedor:string|null, observacoes?:string|null, documento_cliente?:string|null, projeto_id_omie?:string|int|null}> $rows
     * @param array<string,int> &$stats
     */
    private function batchUpdate(
        \PDOStatement $upd,
        array $rows,
        string $now,
        array &$stats,
        string $prefix
    ): void {
        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $i => $row) {
                $res = $this->classify(
                    (string) ($row['nome_vendedor'] ?? ''),
                    (string) ($row['observacoes'] ?? ''),
                    preg_replace('/\D+/', '', (string) ($row['documento_cliente'] ?? '')) ?? '',
                    (string) ($row['projeto_id_omie'] ?? ''),
                    (string) ($row['codigo_vendedor_omie'] ?? ''),
                );
                $upd->execute([
                    'ch'   => $res['channel'],
                    // Flag de visibilidade: 1 só para varejo. Licitação entra nos
                    // números pelo CANAL (CommercialNfUniverse), não pela flag;
                    // intra-grupo ('outros') fica fora.
                    'vis'  => $res['channel'] === 'varejo' ? 1 : 0,
                    'conf' => $res['confidence'],
                    'rid'  => $res['rule_id'],
                    'ts'   => $now,
                    'id'   => $row['id'],
                ]);
                $stats["{$prefix}_{$res['channel']}"]++;

                if (($i + 1) % 500 === 0) {
                    $this->pdo->commit();
                    $this->pdo->beginTransaction();
                }
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,int> $stats
     */
    private function classifyRowsForStats(array $rows, array &$stats, string $prefix): void
    {
        foreach ($rows as $row) {
            $res = $this->classify(
                (string) ($row['nome_vendedor'] ?? ''),
                (string) ($row['observacoes'] ?? ''),
                preg_replace('/\D+/', '', (string) ($row['documento_cliente'] ?? '')) ?? '',
                (string) ($row['projeto_id_omie'] ?? ''),
                (string) ($row['codigo_vendedor_omie'] ?? ''),
            );
            $stats["{$prefix}_{$res['channel']}"]++;
        }
    }

    /** @return list<array<string,mixed>> */
    private function fetchRows(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }
}
