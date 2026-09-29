<?php

declare(strict_types=1);

namespace BIomie\Canonical;

use BIomie\Canonical\Support\CanonicalHashing;
use BIomie\Canonical\Support\CanonicalSyncSource;
use BIomie\Canonical\Support\CanonicalJsonCodec;
use BIomie\Support\BiVisibilityScope;
use BIomie\Support\ChannelClassifierService;
use BIomie\Support\HardDataExclusionPolicy;
use BIomie\Support\LicitacaoSeller;
use Closure;
use PDO;

/**
 * Responsável exclusivamente por operações de escrita na camada canônica.
 * Cobre: upsert de vendedores, pedidos, notas fiscais, títulos financeiros,
 * documentos XML e eventos canônicos.
 *
 * Recebe o PDO via closure para sempre operar no objeto atual após reconexões
 * gerenciadas pelo CanonicalRepository.
 */
final class CanonicalWriteRepository
{
    private const TABLE_ORDERS = 'biomie_pedidos_canonicos';
    private const TABLE_INVOICES = 'biomie_notas_fiscais_canonicas';
    private const TABLE_INVOICE_ITEMS = 'biomie_notas_fiscais_itens_canonicos';
    private const TABLE_XML_DOCUMENTS = 'biomie_documentos_xml_canonicos';
    private const TABLE_FINANCIAL_TITLES = 'biomie_titulos_financeiros_canonicos';
    private const TABLE_EVENTS = 'biomie_eventos_canonicos';

    private ?bool $nfScopeColumnExists       = null;
    private ?bool $ordScopeColumnExists      = null;
    private ?bool $nfFiscalBasisColumnExists = null;
    private ?bool $nfProjectColumnExists     = null;
    private ?bool $nfOrderOperationColumnExists = null;
    private ?ChannelClassifierService $channelClassifier = null;
    /** @var array<string,array{channel:string,visible:int,source:string}>|null */
    private ?array $vendorScopeRules = null;

    public function __construct(
        private readonly Closure $pdoResolver,
        private readonly Closure $beginTransaction,
        private readonly CanonicalHashing $hashing,
        private readonly CanonicalJsonCodec $codec,
        private readonly int $companyId,
        // Canal do contexto que rodou o sync. NÃO decide mais canal de nada: era
        // o fallback que marcou os 50 pedidos da Uni Design como licitação.
        // Mantido na assinatura por compatibilidade com os chamadores.
        string $businessChannel = 'varejo',
    ) {
        unset($businessChannel);
    }

    // ── Vendedores ───────────────────────────────────────────────────────────

    public function upsertVendor(string $codigoOmie, string $nome, string $email, float $comissao, bool $ativo = true): void
    {
        if ($this->isHardExcluded([
            'vendor_code' => $codigoOmie,
            'nome_vendedor' => $nome,
        ])) {
            return;
        }

        $sql = <<<'SQL'
            INSERT INTO biomie_vendedores_omie
                (company_id, business_channel, codigo_omie, nome, email, comissao, ativo, sincronizado_em)
            VALUES (:company_id, :business_channel, :codigo, :nome, :email, :comissao, :ativo, NOW())
            ON DUPLICATE KEY UPDATE
                business_channel = VALUES(business_channel),
                nome = VALUES(nome),
                email = VALUES(email),
                comissao = VALUES(comissao),
                ativo = VALUES(ativo),
                sincronizado_em = NOW()
        SQL;

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([
            'company_id' => $this->companyId,
            'business_channel' => $this->resolveBusinessChannel(['nome_vendedor' => $nome]),
            'codigo' => $codigoOmie,
            'nome' => $nome,
            'email' => $email,
            'comissao' => $comissao,
            'ativo' => $ativo ? 1 : 0,
        ]);
    }

    // ── Pedidos ──────────────────────────────────────────────────────────────

    /**
     * Upsert de pedido canônico. Se o hash não mudou, pula a escrita.
     * Retorna 'inserted', 'updated', 'unchanged' ou 'skipped'.
     *
     * @param array<string,mixed> $order
     */
    public function upsertOrder(array $order): string
    {
        if ($this->isHardExcluded($order)) {
            return 'skipped';
        }

        $pedidoId = (int) ($order['pedido_id_omie'] ?? 0);
        if ($pedidoId <= 0) {
            return 'skipped';
        }

        $hash = $this->hashing->hashOrder($order);

        $existing = $this->pdo()->prepare(
            'SELECT id, hash_payload, business_channel, channel_confidence,
                    is_biomie_commercial_visible
               FROM ' . self::TABLE_ORDERS .
            ' WHERE company_id = :company_id AND pedido_id_omie = :pid'
        );
        $existing->execute(['company_id' => $this->companyId, 'pid' => $pedidoId]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        // O pedido passa pelo MESMO classificador da NF (override manual >
        // regra de vendedor > regras de canal). Antes herdava o canal do
        // contexto que rodou o sync: na Uni Design os 50 pedidos ficaram
        // 'licitacao' enquanto as NFs deles eram varejo.
        $visibility = $this->existingManualInvoiceVisibility($row)
            ?? $this->resolveInvoiceVisibility($order);

        if ($row && ($row['hash_payload'] ?? '') === $hash) {
            $this->updateOrderVisibility($pedidoId, $visibility);
            return 'unchanged';
        }

        $sql = <<<'SQL'
            INSERT INTO biomie_pedidos_canonicos (
                company_id, business_channel,
                pedido_id_omie, codigo_pedido, codigo_integracao,
                codigo_vendedor_omie, nome_vendedor,
                codigo_cliente_omie, nome_cliente, documento_cliente,
                status_comercial, etapa_omie, status_pedido_omie, observacoes,
                data_emissao, data_faturamento, data_cancelamento,
                data_inclusao_omie, data_alteracao_omie, data_previsao_faturamento,
                valor_total, valor_mercadorias, valor_descontos, quantidade_itens,
                hash_payload, fonte_sync, sincronizado_em
            ) VALUES (
                :company_id, :business_channel,
                :pedido_id, :codigo, :integracao,
                :vendedor_id, :vendedor_nome,
                :cliente_id, :cliente_nome, :cliente_doc,
                :status, :etapa, :status_pedido, :observacoes,
                :dt_emissao, :dt_faturamento, :dt_cancelamento,
                :dt_inclusao, :dt_alteracao, :dt_previsao_fat,
                :valor_total, :valor_mercadorias, :valor_descontos, :qtd_itens,
                :hash, :fonte, NOW()
            )
            ON DUPLICATE KEY UPDATE
                business_channel = VALUES(business_channel),
                codigo_pedido = VALUES(codigo_pedido),
                codigo_integracao = VALUES(codigo_integracao),
                codigo_vendedor_omie = VALUES(codigo_vendedor_omie),
                nome_vendedor = VALUES(nome_vendedor),
                codigo_cliente_omie = VALUES(codigo_cliente_omie),
                nome_cliente = VALUES(nome_cliente),
                documento_cliente = VALUES(documento_cliente),
                status_comercial = VALUES(status_comercial),
                etapa_omie = VALUES(etapa_omie),
                status_pedido_omie = VALUES(status_pedido_omie),
                observacoes = VALUES(observacoes),
                data_emissao = VALUES(data_emissao),
                data_faturamento = VALUES(data_faturamento),
                data_cancelamento = VALUES(data_cancelamento),
                data_inclusao_omie = VALUES(data_inclusao_omie),
                data_alteracao_omie = VALUES(data_alteracao_omie),
                data_previsao_faturamento = VALUES(data_previsao_faturamento),
                valor_total = VALUES(valor_total),
                valor_mercadorias = VALUES(valor_mercadorias),
                valor_descontos = VALUES(valor_descontos),
                quantidade_itens = VALUES(quantidade_itens),
                hash_payload = VALUES(hash_payload),
                fonte_sync = VALUES(fonte_sync),
                sincronizado_em = NOW()
        SQL;

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([
            'company_id'     => $this->companyId,
            'business_channel' => $visibility['channel'],
            'pedido_id'      => $pedidoId,
            'codigo'         => (string) ($order['codigo_pedido'] ?? ''),
            'integracao'     => $order['codigo_integracao'] ?? null,
            'vendedor_id'    => (string) ($order['codigo_vendedor_omie'] ?? ''),
            'vendedor_nome'  => (string) ($order['nome_vendedor'] ?? 'Sem vendedor'),
            'cliente_id'     => ($order['codigo_cliente_omie'] ?? null) ?: null,
            'cliente_nome'   => (string) ($order['nome_cliente'] ?? 'Cliente não informado'),
            'cliente_doc'    => $order['documento_cliente'] ?? null,
            'status'         => (string) ($order['status_comercial'] ?? 'outro'),
            'etapa'          => $order['etapa_omie'] ?? null,
            'status_pedido'  => $order['status_pedido_omie'] ?? null,
            'observacoes'    => $order['observacoes'] ?? null,
            'dt_emissao'     => $order['data_emissao'] ?: null,
            'dt_faturamento' => $order['data_faturamento'] ?: null,
            'dt_cancelamento'=> $order['data_cancelamento'] ?: null,
            'dt_inclusao'    => ($order['data_inclusao_omie'] ?? null) ?: null,
            'dt_alteracao'   => ($order['data_alteracao_omie'] ?? null) ?: null,
            'dt_previsao_fat'=> ($order['data_previsao_faturamento'] ?? null) ?: null,
            'valor_total'    => (float) ($order['valor_total'] ?? 0),
            'valor_mercadorias' => (float) ($order['valor_mercadorias'] ?? 0),
            'valor_descontos'   => (float) ($order['valor_descontos'] ?? 0),
            'qtd_itens'      => (int) ($order['quantidade_itens'] ?? 0),
            'hash'           => $hash,
            'fonte'          => CanonicalSyncSource::normalize($order['fonte_sync'] ?? null),
        ]);

        $this->updateOrderVisibility($pedidoId, $visibility);
        $this->tryUpdateOrderScope($pedidoId, $order);

        return $row ? 'updated' : 'inserted';
    }

    /**
     * Grava canal + visibilidade + auditoria da classificação no pedido. Só
     * escreve quando algo muda ou quando o pedido nunca foi classificado.
     *
     * @param array{channel:string,scope:string,visible:int,source:string,confidence:string,rule_id:?int} $visibility
     */
    private function updateOrderVisibility(int $pedidoId, array $visibility): void
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE ' . self::TABLE_ORDERS . '
                SET business_channel = :channel,
                    business_unit_scope = :scope,
                    is_biomie_commercial_visible = :visible,
                    visibility_rule_source = :source,
                    visibility_checked_at = NOW(),
                    channel_confidence = :confidence,
                    channel_rule_id = :rule_id,
                    channel_classified_at = NOW()
              WHERE company_id = :company_id
                AND pedido_id_omie = :pid
                AND (business_channel <> :channel_cmp
                     OR is_biomie_commercial_visible <> :visible_cmp
                     OR NOT (visibility_rule_source <=> :source_cmp)
                     OR NOT (channel_confidence <=> :confidence_cmp)
                     OR NOT (channel_rule_id <=> :rule_id_cmp)
                     OR channel_classified_at IS NULL)'
        );
        $stmt->execute([
            'channel' => $visibility['channel'],
            'scope' => $visibility['scope'],
            'visible' => $visibility['visible'],
            'source' => $visibility['source'],
            'confidence' => $visibility['confidence'],
            'rule_id' => $visibility['rule_id'],
            'company_id' => $this->companyId,
            'pid' => $pedidoId,
            'channel_cmp' => $visibility['channel'],
            'visible_cmp' => $visibility['visible'],
            'source_cmp' => $visibility['source'],
            'confidence_cmp' => $visibility['confidence'],
            'rule_id_cmp' => $visibility['rule_id'],
        ]);
    }

    private function tryUpdateOrderScope(int $pedidoId, array $order): void
    {
        if (!isset($order['operation_scope'])) {
            return;
        }

        $this->ordScopeColumnExists ??= $this->checkColumnExists(self::TABLE_ORDERS, 'operation_scope');
        if (!$this->ordScopeColumnExists) {
            return;
        }

        try {
            $stmt = $this->pdo()->prepare(
                'UPDATE ' . self::TABLE_ORDERS . '
                 SET omie_operation_name        = :op_name,
                     operation_scope            = :scope,
                     operation_scope_source     = :src,
                     operation_scope_confidence = :conf,
                     operation_scope_rule       = :rule,
                     operation_scope_checked_at = NOW()
                 WHERE company_id = :company_id AND pedido_id_omie = :pid'
            );
            $stmt->execute([
                'company_id' => $this->companyId,
                'op_name' => ($order['omie_operation_name'] ?? null) ?: null,
                'scope'   => (string) ($order['operation_scope'] ?? 'unknown'),
                'src'     => ($order['operation_scope_source'] ?? null) ?: null,
                'conf'    => ($order['operation_scope_confidence'] ?? null) ?: null,
                'rule'    => ($order['operation_scope_rule'] ?? null) ?: null,
                'pid'     => $pedidoId,
            ]);
        } catch (\Throwable) {
            // Never interrupt sync due to scope column issues.
        }
    }

    /**
     * Substitui a carteira aberta da janela pelo retrato atual do Omie.
     *
     * @param list<array<string,mixed>> $orders
     * @return array{inserted:int,updated:int,unchanged:int,skipped:int}
     */
    public function replacePendingOrdersWindow(string $start, string $end, array $orders): array
    {
        $stats = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];

        $pdo = $this->pdo();
        $startedTransaction = !$pdo->inTransaction();
        if ($startedTransaction) {
            ($this->beginTransaction)();
            $pdo = $this->pdo();
        }

        try {
            $delete = $pdo->prepare(
                'DELETE FROM ' . self::TABLE_ORDERS .
                ' WHERE company_id = :company_id AND data_emissao BETWEEN :start AND :end'
            );
            $delete->execute(['company_id' => $this->companyId, 'start' => $start, 'end' => $end]);

            foreach ($orders as $order) {
                $result = $this->upsertOrder($order);
                if (isset($stats[$result])) {
                    $stats[$result]++;
                }
            }

            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return $stats;
    }

    // ── Notas Fiscais ────────────────────────────────────────────────────────

    /**
     * Substitui a janela fiscal pelo retrato atual do Omie.
     *
     * @param list<array<string,mixed>> $invoices
     * @return array{inserted:int,updated:int,unchanged:int,skipped:int}
     */
    public function replaceInvoicesWindow(string $start, string $end, array $invoices): array
    {
        $stats = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];

        $pdo = $this->pdo();
        $startedTransaction = !$pdo->inTransaction();
        if ($startedTransaction) {
            ($this->beginTransaction)();
            $pdo = $this->pdo();
        }

        try {
            $deleteItems = $pdo->prepare(
                'DELETE item FROM ' . self::TABLE_INVOICE_ITEMS . ' item
                 INNER JOIN ' . self::TABLE_INVOICES . ' nota ON nota.nota_key = item.nota_key
                    AND nota.company_id = item.company_id
                 WHERE nota.company_id = :company_id
                   AND nota.data_competencia BETWEEN :start AND :end'
            );
            $deleteItems->execute(['company_id' => $this->companyId, 'start' => $start, 'end' => $end]);

            $deleteInvoices = $pdo->prepare(
                'DELETE FROM ' . self::TABLE_INVOICES .
                ' WHERE company_id = :company_id AND data_competencia BETWEEN :start AND :end'
            );
            $deleteInvoices->execute(['company_id' => $this->companyId, 'start' => $start, 'end' => $end]);

            foreach ($invoices as $invoice) {
                $result = $this->upsertInvoice($invoice);
                if (isset($stats[$result])) {
                    $stats[$result]++;
                }
            }

            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return $stats;
    }

    /**
     * @param array<string,mixed> $invoice
     */
    public function upsertInvoice(array $invoice): string
    {
        if ($this->isHardExcluded($invoice)) {
            return 'skipped';
        }

        $incomingNoteKey = trim((string) ($invoice['nota_key'] ?? ''));
        if ($incomingNoteKey === '') {
            return 'skipped';
        }

        // A historic record may have first arrived with a document fallback
        // key and later be enriched with nIdNF/cChaveNFe. Resolve all provider
        // identities before the upsert and retain the already-persisted key;
        // this prevents the enrichment pass from creating a second invoice.
        $row = $this->findExistingInvoiceIdentity($invoice, $incomingNoteKey);
        $noteKey = is_array($row) && trim((string) ($row['nota_key'] ?? '')) !== ''
            ? trim((string) $row['nota_key'])
            : $incomingNoteKey;
        $invoice['nota_key'] = $noteKey;
        // Backfills must enrich fiscal data without undoing an operator's
        // explicit channel decision. Automatic classification is only used
        // when the persisted row is not a manual override.
        $visibility = $this->existingManualInvoiceVisibility($row)
            ?? $this->resolveInvoiceVisibility($invoice);
        $invoice['business_channel'] = $visibility['channel'];
        $invoice['business_unit_scope'] = $visibility['scope'];
        $invoice['is_biomie_commercial_visible'] = $visibility['visible'];
        $invoice['visibility_rule_source'] = $visibility['source'];
        $invoice['channel_confidence'] = $visibility['confidence'];
        $invoice['channel_rule_id'] = $visibility['rule_id'];
        $hash = $this->hashing->hashInvoice($invoice);

        if ($row && ($row['hash_payload'] ?? '') === $hash) {
            // Enrichments may have been deployed after this payload hash was
            // stored. Re-apply every derived contract even when the Omie body
            // itself is unchanged.
            $pdo = $this->pdo();
            $startedTransaction = !$pdo->inTransaction();
            if ($startedTransaction) {
                ($this->beginTransaction)();
                $pdo = $this->pdo();
            }
            try {
                $this->updateInvoiceVisibility($noteKey, $visibility);
                $this->tryUpdateInvoiceScope($noteKey, $invoice);
                $this->tryUpdateFiscalBasis($noteKey, $invoice);
                $this->tryUpdateInvoiceProject($noteKey, $invoice);
                $this->tryUpdateInvoiceOrderOperation($noteKey, $invoice);
                if ($startedTransaction && $pdo->inTransaction()) {
                    $pdo->commit();
                }
            } catch (\Throwable $exception) {
                if ($startedTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
            return 'unchanged';
        }

        $pdo = $this->pdo();
        $startedTransaction = !$pdo->inTransaction();
        if ($startedTransaction) {
            ($this->beginTransaction)();
            $pdo = $this->pdo();
        }

        try {
            $sql = <<<'SQL'
                INSERT INTO biomie_notas_fiscais_canonicas (
                    company_id, business_channel,
                    business_unit_scope, is_biomie_commercial_visible,
                    visibility_rule_source, visibility_checked_at,
                    channel_confidence, channel_rule_id, channel_classified_at,
                    nota_key, nota_id_omie, chave_nfe, numero_nf, serie_nf,
                    pedido_id_omie, codigo_pedido_origem,
                    codigo_vendedor_omie, nome_vendedor,
                    codigo_cliente_omie, nome_cliente, documento_cliente,
                    status_fiscal, observacoes, data_emissao, data_cancelamento, data_competencia,
                    valor_total, valor_mercadorias, valor_descontos, quantidade_itens,
                    hash_payload, fonte_sync, sincronizado_em
                ) VALUES (
                    :company_id, :business_channel,
                    :business_unit_scope, :is_biomie_commercial_visible,
                    :visibility_rule_source, NOW(),
                    :channel_confidence, :channel_rule_id, NOW(),
                    :nota_key, :nota_id_omie, :chave_nfe, :numero_nf, :serie_nf,
                    :pedido_id_omie, :codigo_pedido_origem,
                    :codigo_vendedor_omie, :nome_vendedor,
                    :codigo_cliente_omie, :nome_cliente, :documento_cliente,
                    :status_fiscal, :observacoes, :data_emissao, :data_cancelamento, :data_competencia,
                    :valor_total, :valor_mercadorias, :valor_descontos, :quantidade_itens,
                    :hash_payload, :fonte_sync, NOW()
                )
                ON DUPLICATE KEY UPDATE
                    business_channel = VALUES(business_channel),
                    business_unit_scope = VALUES(business_unit_scope),
                    is_biomie_commercial_visible = VALUES(is_biomie_commercial_visible),
                    visibility_rule_source = VALUES(visibility_rule_source),
                    visibility_checked_at = NOW(),
                    channel_confidence = VALUES(channel_confidence),
                    channel_rule_id = VALUES(channel_rule_id),
                    channel_classified_at = NOW(),
                    nota_id_omie = VALUES(nota_id_omie),
                    chave_nfe = VALUES(chave_nfe),
                    numero_nf = VALUES(numero_nf),
                    serie_nf = VALUES(serie_nf),
                    pedido_id_omie = VALUES(pedido_id_omie),
                    codigo_pedido_origem = VALUES(codigo_pedido_origem),
                    codigo_vendedor_omie = VALUES(codigo_vendedor_omie),
                    nome_vendedor = VALUES(nome_vendedor),
                    codigo_cliente_omie = VALUES(codigo_cliente_omie),
                    nome_cliente = VALUES(nome_cliente),
                    documento_cliente = VALUES(documento_cliente),
                    status_fiscal = VALUES(status_fiscal),
                    observacoes = VALUES(observacoes),
                    data_emissao = VALUES(data_emissao),
                    data_cancelamento = VALUES(data_cancelamento),
                    data_competencia = VALUES(data_competencia),
                    valor_total = VALUES(valor_total),
                    valor_mercadorias = VALUES(valor_mercadorias),
                    valor_descontos = VALUES(valor_descontos),
                    quantidade_itens = VALUES(quantidade_itens),
                    hash_payload = VALUES(hash_payload),
                    fonte_sync = VALUES(fonte_sync),
                    sincronizado_em = NOW()
            SQL;

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'company_id'         => $this->companyId,
                'business_channel'   => $visibility['channel'],
                'business_unit_scope' => $visibility['scope'],
                'is_biomie_commercial_visible' => $visibility['visible'],
                'visibility_rule_source' => $visibility['source'],
                'channel_confidence' => $visibility['confidence'],
                'channel_rule_id' => $visibility['rule_id'],
                'nota_key'            => $noteKey,
                'nota_id_omie'        => ($invoice['nota_id_omie'] ?? null) ?: null,
                'chave_nfe'           => ($invoice['chave_nfe'] ?? null) ?: null,
                'numero_nf'           => (string) ($invoice['numero_nf'] ?? ''),
                'serie_nf'            => ($invoice['serie_nf'] ?? null) ?: null,
                'pedido_id_omie'      => ($invoice['pedido_id_omie'] ?? null) ?: null,
                'codigo_pedido_origem'=> ($invoice['codigo_pedido_origem'] ?? null) ?: null,
                'codigo_vendedor_omie'=> (string) ($invoice['codigo_vendedor_omie'] ?? ''),
                'nome_vendedor'       => (string) ($invoice['nome_vendedor'] ?? 'Sem vendedor'),
                'codigo_cliente_omie' => ($invoice['codigo_cliente_omie'] ?? null) ?: null,
                'nome_cliente'        => (string) ($invoice['nome_cliente'] ?? 'Cliente nao informado'),
                'documento_cliente'   => ($invoice['documento_cliente'] ?? null) ?: null,
                'status_fiscal'       => (string) ($invoice['status_fiscal'] ?? 'nao_autorizada'),
                'observacoes'         => ($invoice['observacoes'] ?? null) ?: null,
                'data_emissao'        => ($invoice['data_emissao'] ?? null) ?: null,
                'data_cancelamento'   => ($invoice['data_cancelamento'] ?? null) ?: null,
                'data_competencia'    => ($invoice['data_competencia'] ?? null) ?: null,
                'valor_total'         => (float) ($invoice['valor_total'] ?? 0),
                'valor_mercadorias'   => (float) ($invoice['valor_mercadorias'] ?? 0),
                'valor_descontos'     => (float) ($invoice['valor_descontos'] ?? 0),
                'quantidade_itens'    => (int) ($invoice['quantidade_itens'] ?? 0),
                'hash_payload'        => $hash,
                'fonte_sync'          => CanonicalSyncSource::normalize($invoice['fonte_sync'] ?? null),
            ]);

            $deleteItems = $pdo->prepare(
                'DELETE FROM ' . self::TABLE_INVOICE_ITEMS .
                ' WHERE company_id = :company_id AND nota_key = :nota_key'
            );
            $deleteItems->execute(['company_id' => $this->companyId, 'nota_key' => $noteKey]);

            $items = is_array($invoice['items'] ?? null) ? $invoice['items'] : [];
            if ($items !== []) {
                $insertItem = $pdo->prepare(<<<'SQL'
                    INSERT INTO biomie_notas_fiscais_itens_canonicos (
                        company_id, business_channel,
                        nota_key, sequencia_item, codigo_produto, descricao_produto,
                        quantidade, valor_total, valor_desconto
                    ) VALUES (
                        :company_id, :business_channel,
                        :nota_key, :sequencia_item, :codigo_produto, :descricao_produto,
                        :quantidade, :valor_total, :valor_desconto
                    )
                SQL);

                foreach (array_values($items) as $index => $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $insertItem->execute([
                        'company_id'        => $this->companyId,
                        'business_channel'  => $visibility['channel'],
                        'nota_key'         => $noteKey,
                        'sequencia_item'   => $index + 1,
                        'codigo_produto'   => (string) ($item['codigo_produto'] ?? ''),
                        'descricao_produto'=> (string) ($item['descricao_produto'] ?? 'Produto sem descricao'),
                        'quantidade'       => (float) ($item['quantidade'] ?? 0),
                        'valor_total'      => (float) ($item['valor_total'] ?? 0),
                        'valor_desconto'   => (float) ($item['valor_desconto'] ?? 0),
                    ]);
                }
            }

            $this->upsertXmlDocument($this->buildXmlDocumentFromInvoice($invoice, $hash));
            $this->tryUpdateInvoiceScope($noteKey, $invoice);
            $this->tryUpdateFiscalBasis($noteKey, $invoice);
            $this->tryUpdateInvoiceProject($noteKey, $invoice);
            $this->tryUpdateInvoiceOrderOperation($noteKey, $invoice);

            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return $row ? 'updated' : 'inserted';
    }

    /**
     * @param array<string,mixed> $invoice
     * @return array<string,mixed>|false
     */
    private function findExistingInvoiceIdentity(array $invoice, string $noteKey): array|false
    {
        $conditions = ['nota_key = :identity_note_key'];
        $params = [
            'identity_company_id' => $this->companyId,
            'identity_note_key' => $noteKey,
        ];

        $noteId = (int) ($invoice['nota_id_omie'] ?? 0);
        if ($noteId > 0) {
            $conditions[] = 'nota_id_omie = :identity_note_id';
            $params['identity_note_id'] = $noteId;
        }

        $accessKey = trim((string) ($invoice['chave_nfe'] ?? ''));
        if ($accessKey !== '') {
            $conditions[] = 'chave_nfe = :identity_access_key';
            $params['identity_access_key'] = $accessKey;
        }

        $stmt = $this->pdo()->prepare(sprintf(
            'SELECT id, nota_key, hash_payload,
                    business_channel, business_unit_scope,
                    is_biomie_commercial_visible, visibility_rule_source,
                    channel_confidence, channel_rule_id
               FROM %s
              WHERE company_id = :identity_company_id
                AND (%s)
              ORDER BY (nota_key = :identity_preferred_key) DESC, id ASC
              LIMIT 1',
            self::TABLE_INVOICES,
            implode(' OR ', $conditions),
        ));
        $params['identity_preferred_key'] = $noteKey;
        $stmt->execute($params);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Reaplica a classificação de canal às NFs que nunca a receberam.
     *
     * POR QUE EXISTE
     * A classificação acontece na escrita (upsertInvoice), e a sincronização só
     * revisita uma janela recente. NFs gravadas enquanto o caminho de escrita não
     * classificava — no incidente de 2026, as sincronizadas entre 30/06 e 23/07 —
     * ficaram com channel_classified_at NULL e nunca mais foram tocadas. Com
     * isso passavam por fora de todas as regras de canal, inclusive as de venda
     * intra-grupo (cliente_cnpj_list), e algumas licitações ficaram visíveis no
     * escopo comercial.
     *
     * O QUE FAZ
     * Usa exatamente o mesmo caminho da escrita: resolveInvoiceVisibility() para
     * decidir (override manual > regra de vendedor > classificador) e
     * updateInvoiceVisibility() para gravar. Nenhuma regra nova, nenhuma regra
     * alterada. Override manual é preservado por construção.
     *
     * DIFERENÇA PARA bin/classify_channels.php
     * Aquele comando reclassifica TODAS as NFs não-manuais usando apenas o
     * classificador, sem a regra de escopo por vendedor que a escrita aplica.
     * Este método mexe só no que nunca foi classificado e segue a escrita.
     *
     * $onlyUnclassified = false estende o mesmo passe a TODAS as NFs não-manuais:
     * serve para alinhar ao conjunto de regras atual as NFs classificadas por
     * uma versão anterior das regras. Continua simulando por padrão.
     *
     * @return array{total:int, alteradas:int, iguais:int, aplicado:bool, mudancas:list<array<string,mixed>>}
     */
    public function reclassifyUnclassifiedInvoices(bool $apply = false, bool $onlyUnclassified = true): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT nota_key, numero_nf, data_emissao, nome_cliente,
                    codigo_vendedor_omie, nome_vendedor, observacoes,
                    documento_cliente, projeto_id_omie,
                    business_channel, is_biomie_commercial_visible,
                    valor_base_comercial_nf
               FROM ' . self::TABLE_INVOICES . '
              WHERE company_id = :company_id
                ' . ($onlyUnclassified ? 'AND channel_classified_at IS NULL' : '') . '
                AND (channel_confidence IS NULL OR channel_confidence <> \'manual\')
              ORDER BY data_emissao, numero_nf'
        );
        $stmt->execute(['company_id' => $this->companyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $resultado = [
            'total' => \count($rows),
            'alteradas' => 0,
            'iguais' => 0,
            'aplicado' => false,
            'mudancas' => [],
        ];
        if ($rows === []) {
            return $resultado;
        }

        $decisoes = [];
        foreach ($rows as $row) {
            // Mesmo formato que chega do Omie: sem canal explícito no payload.
            $visibility = $this->resolveInvoiceVisibility([
                'business_channel' => null,
                'codigo_vendedor_omie' => (string) ($row['codigo_vendedor_omie'] ?? ''),
                'nome_vendedor' => (string) ($row['nome_vendedor'] ?? ''),
                'observacoes' => (string) ($row['observacoes'] ?? ''),
                'documento_cliente' => (string) ($row['documento_cliente'] ?? ''),
                'projeto_id_omie' => (string) ($row['projeto_id_omie'] ?? ''),
            ]);
            $decisoes[] = [(string) $row['nota_key'], $visibility];

            $mudou = (string) $row['business_channel'] !== (string) $visibility['channel']
                || (int) $row['is_biomie_commercial_visible'] !== (int) $visibility['visible'];
            if (!$mudou) {
                $resultado['iguais']++;
                continue;
            }
            $resultado['alteradas']++;
            $resultado['mudancas'][] = [
                'numero_nf' => $row['numero_nf'],
                'data_emissao' => $row['data_emissao'],
                'nome_cliente' => $row['nome_cliente'],
                'nome_vendedor' => $row['nome_vendedor'],
                'valor_base_comercial_nf' => (float) $row['valor_base_comercial_nf'],
                'antes' => ['channel' => $row['business_channel'], 'visible' => (int) $row['is_biomie_commercial_visible']],
                'depois' => ['channel' => $visibility['channel'], 'visible' => (int) $visibility['visible']],
                'fonte' => $visibility['source'],
                'rule_id' => $visibility['rule_id'],
            ];
        }

        if (!$apply) {
            return $resultado;
        }

        // Grava também as NFs cujo canal não muda: o objetivo é não sobrar
        // nenhuma linha sem classificação registrada (regra, confiança, data).
        $pdo = $this->pdo();
        $iniciou = !$pdo->inTransaction();
        if ($iniciou) {
            ($this->beginTransaction)();
            $pdo = $this->pdo();
        }
        try {
            foreach ($decisoes as [$notaKey, $visibility]) {
                $this->updateInvoiceVisibility($notaKey, $visibility);
            }
            if ($iniciou && $pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($iniciou && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $resultado['aplicado'] = true;

        return $resultado;
    }

    /**
     * Reaplica aos pedidos o classificador da escrita (o mesmo da NF).
     *
     * Os pedidos eram gravados com o canal do CONTEXTO que rodou o sync, sem
     * passar pelas regras; upsertOrder() passou a classificar, mas pedidos com
     * hash inalterado só são reclassificados quando o Omie os devolve de novo.
     * Este passe alinha o estoque inteiro. Override manual é preservado.
     * Simula por padrão; só grava com $apply.
     *
     * @return array{total:int, alteradas:int, iguais:int, aplicado:bool, por_canal:array<string,array{pedidos:int,valor:float}>, mudancas:list<array<string,mixed>>}
     */
    public function reclassifyOrders(bool $apply = false): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT pedido_id_omie, codigo_pedido, data_emissao, nome_cliente,
                    codigo_vendedor_omie, nome_vendedor, observacoes,
                    documento_cliente, business_channel, channel_confidence,
                    business_unit_scope, is_biomie_commercial_visible,
                    channel_rule_id, valor_mercadorias
               FROM ' . self::TABLE_ORDERS . '
              WHERE company_id = :company_id
              ORDER BY data_emissao, pedido_id_omie'
        );
        $stmt->execute(['company_id' => $this->companyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $resultado = [
            'total' => \count($rows),
            'alteradas' => 0,
            'iguais' => 0,
            'aplicado' => false,
            'por_canal' => [],
            'mudancas' => [],
        ];
        $decisoes = [];
        foreach ($rows as $row) {
            $visibility = $this->existingManualInvoiceVisibility($row)
                ?? $this->resolveInvoiceVisibility([
                    'business_channel' => null,
                    'codigo_vendedor_omie' => (string) ($row['codigo_vendedor_omie'] ?? ''),
                    'nome_vendedor' => (string) ($row['nome_vendedor'] ?? ''),
                    'observacoes' => (string) ($row['observacoes'] ?? ''),
                    'documento_cliente' => (string) ($row['documento_cliente'] ?? ''),
                ]);
            $decisoes[] = [(int) $row['pedido_id_omie'], $visibility];

            $canalDepois = $visibility['channel'];
            $resultado['por_canal'][$canalDepois] ??= ['pedidos' => 0, 'valor' => 0.0];
            $resultado['por_canal'][$canalDepois]['pedidos']++;
            $resultado['por_canal'][$canalDepois]['valor'] += (float) $row['valor_mercadorias'];

            $mudou = (string) $row['business_channel'] !== $canalDepois
                || (int) $row['is_biomie_commercial_visible'] !== (int) $visibility['visible'];
            if (!$mudou) {
                $resultado['iguais']++;
                continue;
            }
            $resultado['alteradas']++;
            $resultado['mudancas'][] = [
                'pedido' => $row['codigo_pedido'],
                'data_emissao' => $row['data_emissao'],
                'nome_cliente' => $row['nome_cliente'],
                'nome_vendedor' => $row['nome_vendedor'],
                'valor_mercadorias' => (float) $row['valor_mercadorias'],
                'antes' => ['channel' => $row['business_channel'], 'visible' => (int) $row['is_biomie_commercial_visible']],
                'depois' => ['channel' => $canalDepois, 'visible' => (int) $visibility['visible']],
                'fonte' => $visibility['source'],
                'rule_id' => $visibility['rule_id'],
            ];
        }

        if (!$apply || $decisoes === []) {
            return $resultado;
        }

        $pdo = $this->pdo();
        $iniciou = !$pdo->inTransaction();
        if ($iniciou) {
            ($this->beginTransaction)();
            $pdo = $this->pdo();
        }
        try {
            foreach ($decisoes as [$pedidoId, $visibility]) {
                $this->updateOrderVisibility($pedidoId, $visibility);
            }
            if ($iniciou && $pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($iniciou && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $resultado['aplicado'] = true;

        return $resultado;
    }

    /**
     * @param array<string,mixed>|false $row
     * @return array{channel:string,scope:string,visible:int,source:string,confidence:string,rule_id:?int}|null
     */
    private function existingManualInvoiceVisibility(array|false $row): ?array
    {
        if (!is_array($row)
            || strtolower(trim((string) ($row['channel_confidence'] ?? ''))) !== 'manual') {
            return null;
        }

        $channel = ChannelClassifierService::normalizeChannel($row['business_channel'] ?? null);
        if ($channel === null) {
            return null;
        }

        $scope = strtolower(trim((string) ($row['business_unit_scope'] ?? '')));
        if ($scope === '') {
            $scope = match ($channel) {
                'licitacao' => 'licitacao',
                'outros' => 'outros',
                default => 'comercial',
            };
        }

        $ruleId = (int) ($row['channel_rule_id'] ?? 0);

        return [
            'channel' => $channel,
            'scope' => $scope,
            'visible' => (int) ($row['is_biomie_commercial_visible'] ?? ($channel === 'varejo' ? 1 : 0)),
            'source' => trim((string) ($row['visibility_rule_source'] ?? '')) ?: 'manual_preserved',
            'confidence' => 'manual',
            'rule_id' => $ruleId > 0 ? $ruleId : null,
        ];
    }

    /**
     * Persiste um lote fiscal de forma incremental e atômica.
     *
     * Diferente de replaceInvoicesWindow(), este método nunca remove a janela
     * antes da gravação. Ele é o caminho seguro para backfills históricos:
     * todas as páginas são validadas pelo repositório Omie e somente então o
     * lote do dia é aplicado por upsert.
     *
     * @param list<array<string,mixed>> $invoices
     * @return array{inserted:int,updated:int,unchanged:int,skipped:int}
     */
    public function upsertInvoicesBatch(array $invoices): array
    {
        $stats = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
        $pdo = $this->pdo();
        $startedTransaction = !$pdo->inTransaction();

        if ($startedTransaction) {
            ($this->beginTransaction)();
            $pdo = $this->pdo();
        }

        try {
            foreach ($invoices as $invoice) {
                if (!is_array($invoice)) {
                    $stats['skipped']++;
                    continue;
                }

                $result = $this->upsertInvoice($invoice);
                if (isset($stats[$result])) {
                    $stats[$result]++;
                }
            }

            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return $stats;
    }

    private function tryUpdateFiscalBasis(string $noteKey, array $invoice): void
    {
        if (!isset($invoice['valor_frete'])) {
            return;
        }

        $this->nfFiscalBasisColumnExists ??= $this->checkColumnExists(self::TABLE_INVOICES, 'valor_frete');
        if (!$this->nfFiscalBasisColumnExists) {
            return;
        }

        $frete  = (float) ($invoice['valor_frete'] ?? 0);
        $outras = (float) ($invoice['valor_outras_despesas'] ?? 0);
        $seguro = (float) ($invoice['valor_seguro'] ?? 0);
        $total  = (float) ($invoice['valor_total'] ?? 0);
        $base   = max(0.0, $total - $frete - $outras - $seguro);

        // Fase O: write raw components.
        // Fase O.1: also write pre-computed base + flags when columns exist.
        $hasPreComp = $this->checkColumnExists(self::TABLE_INVOICES, 'valor_base_comercial_nf');

        if ($hasPreComp) {
            $stmt = $this->pdo()->prepare(
                    'UPDATE ' . self::TABLE_INVOICES . '
                     SET valor_ipi                          = :ipi,
                         valor_frete                        = :frete,
                         valor_outras_despesas              = :outras,
                         valor_seguro                       = :seguro,
                         valor_base_comercial_nf            = :base,
                         has_frete                          = :hfrete,
                         has_outras_despesas                = :houtras,
                         has_seguro                         = :hseguro,
                         has_componentes_nao_comissionaveis = :hcomp,
                         fiscal_basis_source                = :src,
                         fiscal_basis_checked_at            = NOW()
                     WHERE company_id = :company_id AND nota_key = :nota_key'
            );
            $stmt->execute([
                    'company_id' => $this->companyId,
                    'ipi'      => (float) ($invoice['valor_ipi'] ?? 0),
                    'frete'    => $frete,
                    'outras'   => $outras,
                    'seguro'   => $seguro,
                    'base'     => $base,
                    'hfrete'   => $frete  > 0.009 ? 1 : 0,
                    'houtras'  => $outras > 0.009 ? 1 : 0,
                    'hseguro'  => $seguro > 0.009 ? 1 : 0,
                    'hcomp'    => ($frete > 0.009 || $outras > 0.009 || $seguro > 0.009) ? 1 : 0,
                    'src'      => 'sync_mapper',
                    'nota_key' => $noteKey,
            ]);
        } else {
            $stmt = $this->pdo()->prepare(
                    'UPDATE ' . self::TABLE_INVOICES . '
                     SET valor_ipi              = :ipi,
                         valor_frete            = :frete,
                         valor_outras_despesas  = :outras,
                         valor_seguro           = :seguro
                     WHERE company_id = :company_id AND nota_key = :nota_key'
            );
            $stmt->execute([
                    'company_id' => $this->companyId,
                    'ipi'      => (float) ($invoice['valor_ipi'] ?? 0),
                    'frete'    => $frete,
                    'outras'   => $outras,
                    'seguro'   => $seguro,
                    'nota_key' => $noteKey,
            ]);
        }
    }

    private function tryUpdateInvoiceScope(string $noteKey, array $invoice): void
    {
        if (!isset($invoice['operation_scope'])) {
            return;
        }

        $this->nfScopeColumnExists ??= $this->checkColumnExists(self::TABLE_INVOICES, 'operation_scope');
        if (!$this->nfScopeColumnExists) {
            return;
        }

        $stmt = $this->pdo()->prepare(
            'UPDATE ' . self::TABLE_INVOICES . '
             SET omie_operation_name        = :op_name,
                 operation_scope            = :scope,
                 operation_scope_source     = :src,
                 operation_scope_confidence = :conf,
                 operation_scope_rule       = :rule,
                 operation_scope_checked_at = NOW()
             WHERE company_id = :company_id AND nota_key = :nota_key'
        );
        $stmt->execute([
            'company_id' => $this->companyId,
            'op_name'  => ($invoice['omie_operation_name'] ?? null) ?: null,
            'scope'    => (string) ($invoice['operation_scope'] ?? 'unknown'),
            'src'      => ($invoice['operation_scope_source'] ?? null) ?: null,
            'conf'     => ($invoice['operation_scope_confidence'] ?? null) ?: null,
            'rule'     => ($invoice['operation_scope_rule'] ?? null) ?: null,
            'nota_key' => $noteKey,
        ]);
    }

    /** @param array<string,mixed> $invoice */
    private function tryUpdateInvoiceProject(string $noteKey, array $invoice): void
    {
        if (!array_key_exists('projeto_id_omie', $invoice)) {
            return;
        }

        $this->nfProjectColumnExists ??= $this->checkColumnExists(self::TABLE_INVOICES, 'projeto_id_omie');
        if (!$this->nfProjectColumnExists) {
            return;
        }

        $stmt = $this->pdo()->prepare(
            'UPDATE ' . self::TABLE_INVOICES . '
                SET projeto_id_omie = :project_id
              WHERE company_id = :company_id AND nota_key = :nota_key'
        );
        $projectId = (int) ($invoice['projeto_id_omie'] ?? 0);
        $stmt->execute([
            'company_id' => $this->companyId,
            'nota_key' => $noteKey,
            'project_id' => $projectId > 0 ? $projectId : null,
        ]);
    }

    /** @param array<string,mixed> $invoice */
    private function tryUpdateInvoiceOrderOperation(string $noteKey, array $invoice): void
    {
        if (!array_key_exists('pedido_operacao_omie', $invoice)) {
            return;
        }

        $this->nfOrderOperationColumnExists ??= $this->checkColumnExists(
            self::TABLE_INVOICES,
            'pedido_operacao_omie',
        );
        if (!$this->nfOrderOperationColumnExists) {
            return;
        }

        $operation = trim((string) ($invoice['pedido_operacao_omie'] ?? ''));
        $stmt = $this->pdo()->prepare(
            'UPDATE ' . self::TABLE_INVOICES . '
                SET pedido_operacao_omie = :order_operation
              WHERE company_id = :company_id AND nota_key = :nota_key'
        );
        $stmt->execute([
            'company_id' => $this->companyId,
            'nota_key' => $noteKey,
            'order_operation' => $operation !== '' ? $operation : null,
        ]);
    }

    private function checkColumnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    // ── Títulos Financeiros ──────────────────────────────────────────────────

    /**
     * Atualiza os títulos retornados pela janela sem apagar o histórico.
     *
     * A origem financeira combina consultas por vencimento e pagamento. Um
     * título ausente em uma dessas consultas pode apenas ter mudado de status
     * ou data de competência, portanto sua ausência não representa exclusão.
     * O upsert também preserva vínculos NF×título e enriquecimentos locais.
     *
     * @param list<array<string,mixed>> $titles
     * @return array{inserted:int,updated:int,unchanged:int,skipped:int}
     */
    public function replaceFinancialTitlesWindow(string $start, string $end, array $titles): array
    {
        $stats = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];

        $pdo = $this->pdo();
        $startedTransaction = !$pdo->inTransaction();
        if ($startedTransaction) {
            ($this->beginTransaction)();
            $pdo = $this->pdo();
        }

        try {
            foreach ($titles as $title) {
                $result = $this->upsertFinancialTitle($title);
                if (isset($stats[$result])) {
                    $stats[$result]++;
                }
            }

            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return $stats;
    }

    /**
     * @param array<string,mixed> $title
     */
    public function upsertFinancialTitle(array $title): string
    {
        if ($this->isHardExcluded($title)) {
            return 'skipped';
        }

        $titleKey = trim((string) ($title['titulo_key'] ?? ''));
        if ($titleKey === '') {
            return 'skipped';
        }

        $hash = $this->hashing->hashFinancialTitle($title);
        $existing = $this->pdo()->prepare(
            'SELECT id, hash_payload FROM ' . self::TABLE_FINANCIAL_TITLES .
            ' WHERE company_id = :company_id AND titulo_key = :titulo_key'
        );
        $existing->execute(['company_id' => $this->companyId, 'titulo_key' => $titleKey]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if ($row && ($row['hash_payload'] ?? '') === $hash) {
            return 'unchanged';
        }

        $stmt = $this->pdo()->prepare(<<<'SQL'
            INSERT INTO biomie_titulos_financeiros_canonicos (
                company_id, business_channel,
                titulo_key, titulo_id_omie, codigo_integracao, numero_documento,
                status_titulo, especie_documento, forma_pagamento,
                documento_origem_tipo, documento_origem_ref, nota_key, chave_nfe,
                pedido_id_omie, codigo_pedido_origem,
                codigo_cliente_omie, nome_cliente, documento_cliente,
                codigo_vendedor_omie, nome_vendedor, codigo_conta_corrente_omie,
                data_emissao, data_vencimento, data_liquidacao, data_competencia,
                valor_pago, valor_liquido, valor_aberto, hash_payload, fonte_sync, sincronizado_em
            ) VALUES (
                :company_id, :business_channel,
                :titulo_key, :titulo_id_omie, :codigo_integracao, :numero_documento,
                :status_titulo, :especie_documento, :forma_pagamento,
                :documento_origem_tipo, :documento_origem_ref, :nota_key, :chave_nfe,
                :pedido_id_omie, :codigo_pedido_origem,
                :codigo_cliente_omie, :nome_cliente, :documento_cliente,
                :codigo_vendedor_omie, :nome_vendedor, :codigo_conta_corrente_omie,
                :data_emissao, :data_vencimento, :data_liquidacao, :data_competencia,
                :valor_pago, :valor_liquido, :valor_aberto, :hash_payload, :fonte_sync, NOW()
            )
            ON DUPLICATE KEY UPDATE
                business_channel = VALUES(business_channel),
                titulo_id_omie = VALUES(titulo_id_omie),
                codigo_integracao = VALUES(codigo_integracao),
                numero_documento = COALESCE(NULLIF(VALUES(numero_documento), ''), numero_documento),
                status_titulo = VALUES(status_titulo),
                especie_documento = COALESCE(VALUES(especie_documento), especie_documento),
                forma_pagamento = COALESCE(VALUES(forma_pagamento), forma_pagamento),
                documento_origem_tipo = COALESCE(VALUES(documento_origem_tipo), documento_origem_tipo),
                documento_origem_ref = COALESCE(VALUES(documento_origem_ref), documento_origem_ref),
                nota_key = COALESCE(VALUES(nota_key), nota_key),
                chave_nfe = COALESCE(VALUES(chave_nfe), chave_nfe),
                pedido_id_omie = COALESCE(VALUES(pedido_id_omie), pedido_id_omie),
                codigo_pedido_origem = COALESCE(NULLIF(VALUES(codigo_pedido_origem), ''), codigo_pedido_origem),
                codigo_cliente_omie = COALESCE(VALUES(codigo_cliente_omie), codigo_cliente_omie),
                nome_cliente = CASE
                    WHEN VALUES(nome_cliente) NOT IN ('', 'Cliente nao informado', 'Cliente sem nome')
                    THEN VALUES(nome_cliente) ELSE nome_cliente END,
                documento_cliente = COALESCE(NULLIF(VALUES(documento_cliente), ''), documento_cliente),
                codigo_vendedor_omie = COALESCE(NULLIF(VALUES(codigo_vendedor_omie), ''), codigo_vendedor_omie),
                nome_vendedor = CASE
                    WHEN VALUES(nome_vendedor) NOT IN ('', 'Sem vendedor', 'Sem vendedor definido')
                    THEN VALUES(nome_vendedor) ELSE nome_vendedor END,
                codigo_conta_corrente_omie = COALESCE(NULLIF(VALUES(codigo_conta_corrente_omie), ''), codigo_conta_corrente_omie),
                data_emissao = VALUES(data_emissao),
                data_vencimento = VALUES(data_vencimento),
                data_liquidacao = VALUES(data_liquidacao),
                data_competencia = VALUES(data_competencia),
                valor_pago = VALUES(valor_pago),
                valor_liquido = VALUES(valor_liquido),
                valor_aberto = VALUES(valor_aberto),
                hash_payload = VALUES(hash_payload),
                fonte_sync = VALUES(fonte_sync),
                sincronizado_em = NOW()
        SQL);
        $stmt->execute([
            'company_id'                 => $this->companyId,
            'business_channel'           => $this->resolveBusinessChannel($title),
            'titulo_key'               => $titleKey,
            'titulo_id_omie'           => ($title['titulo_id_omie'] ?? null) ?: null,
            'codigo_integracao'        => ($title['codigo_integracao'] ?? null) ?: null,
            'numero_documento'         => (string) ($title['numero_documento'] ?? ''),
            'status_titulo'            => (string) ($title['status_titulo'] ?? 'outro'),
            'especie_documento'        => ($title['especie_documento'] ?? null) ?: null,
            'forma_pagamento'          => ($title['forma_pagamento'] ?? null) ?: null,
            'documento_origem_tipo'    => ($title['documento_origem_tipo'] ?? null) ?: null,
            'documento_origem_ref'     => ($title['documento_origem_ref'] ?? null) ?: null,
            'nota_key'                 => ($title['nota_key'] ?? null) ?: null,
            'chave_nfe'                => ($title['chave_nfe'] ?? null) ?: null,
            'pedido_id_omie'           => ($title['pedido_id_omie'] ?? null) ?: null,
            'codigo_pedido_origem'     => ($title['codigo_pedido_origem'] ?? null) ?: null,
            'codigo_cliente_omie'      => ($title['codigo_cliente_omie'] ?? null) ?: null,
            'nome_cliente'             => (string) ($title['nome_cliente'] ?? 'Cliente nao informado'),
            'documento_cliente'        => ($title['documento_cliente'] ?? null) ?: null,
            'codigo_vendedor_omie'     => (string) ($title['codigo_vendedor_omie'] ?? ''),
            'nome_vendedor'            => (string) ($title['nome_vendedor'] ?? 'Sem vendedor'),
            'codigo_conta_corrente_omie' => ($title['codigo_conta_corrente_omie'] ?? null) ?: null,
            'data_emissao'             => ($title['data_emissao'] ?? null) ?: null,
            'data_vencimento'          => ($title['data_vencimento'] ?? null) ?: null,
            'data_liquidacao'          => ($title['data_liquidacao'] ?? null) ?: null,
            'data_competencia'         => ($title['data_competencia'] ?? null) ?: null,
            'valor_pago'               => (float) ($title['valor_pago'] ?? 0),
            'valor_liquido'            => (float) ($title['valor_liquido'] ?? 0),
            'valor_aberto'             => (float) ($title['valor_aberto'] ?? 0),
            'hash_payload'             => $hash,
            'fonte_sync'               => CanonicalSyncSource::normalize($title['fonte_sync'] ?? null),
        ]);

        return $row ? 'updated' : 'inserted';
    }

    // ── Documentos XML ───────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $document
     */
    public function upsertXmlDocument(array $document): string
    {
        if ($this->isHardExcluded($document)) {
            return 'skipped';
        }

        $xmlKey = trim((string) ($document['xml_key'] ?? ''));
        if ($xmlKey === '') {
            return 'skipped';
        }

        $hash = $this->hashing->hashXmlDocument($document);
        $existing = $this->pdo()->prepare(
            'SELECT id, hash_payload FROM ' . self::TABLE_XML_DOCUMENTS .
            ' WHERE company_id = :company_id AND xml_key = :xml_key'
        );
        $existing->execute(['company_id' => $this->companyId, 'xml_key' => $xmlKey]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if ($row && ($row['hash_payload'] ?? '') === $hash) {
            return 'unchanged';
        }

        $stmt = $this->pdo()->prepare(<<<'SQL'
            INSERT INTO biomie_documentos_xml_canonicos (
                company_id,
                xml_key, nota_key, nota_id_omie, chave_nfe, numero_nf, serie_nf,
                pedido_id_omie, codigo_pedido_origem,
                documento_tipo, status_documento,
                data_emissao, data_cancelamento,
                xml_payload, metadata_json, hash_payload, fonte_sync, sincronizado_em
            ) VALUES (
                :company_id,
                :xml_key, :nota_key, :nota_id_omie, :chave_nfe, :numero_nf, :serie_nf,
                :pedido_id_omie, :codigo_pedido_origem,
                :documento_tipo, :status_documento,
                :data_emissao, :data_cancelamento,
                :xml_payload, :metadata_json, :hash_payload, :fonte_sync, NOW()
            )
            ON DUPLICATE KEY UPDATE
                nota_key = VALUES(nota_key),
                nota_id_omie = VALUES(nota_id_omie),
                chave_nfe = VALUES(chave_nfe),
                numero_nf = VALUES(numero_nf),
                serie_nf = VALUES(serie_nf),
                pedido_id_omie = VALUES(pedido_id_omie),
                codigo_pedido_origem = VALUES(codigo_pedido_origem),
                documento_tipo = VALUES(documento_tipo),
                status_documento = VALUES(status_documento),
                data_emissao = VALUES(data_emissao),
                data_cancelamento = VALUES(data_cancelamento),
                xml_payload = VALUES(xml_payload),
                metadata_json = VALUES(metadata_json),
                hash_payload = VALUES(hash_payload),
                fonte_sync = VALUES(fonte_sync),
                sincronizado_em = NOW()
        SQL);
        $stmt->execute([
            'company_id'           => $this->companyId,
            'xml_key'              => $xmlKey,
            'nota_key'             => ($document['nota_key'] ?? null) ?: null,
            'nota_id_omie'         => ($document['nota_id_omie'] ?? null) ?: null,
            'chave_nfe'            => ($document['chave_nfe'] ?? null) ?: null,
            'numero_nf'            => (string) ($document['numero_nf'] ?? ''),
            'serie_nf'             => ($document['serie_nf'] ?? null) ?: null,
            'pedido_id_omie'       => ($document['pedido_id_omie'] ?? null) ?: null,
            'codigo_pedido_origem' => ($document['codigo_pedido_origem'] ?? null) ?: null,
            'documento_tipo'       => (string) ($document['documento_tipo'] ?? 'nfe_xml'),
            'status_documento'     => (string) ($document['status_documento'] ?? 'pendente'),
            'data_emissao'         => ($document['data_emissao'] ?? null) ?: null,
            'data_cancelamento'    => ($document['data_cancelamento'] ?? null) ?: null,
            'xml_payload'          => ($document['xml_payload'] ?? null) ?: null,
            'metadata_json'        => isset($document['metadata']) && is_array($document['metadata'])
                ? $this->codec->encode($document['metadata'])
                : null,
            'hash_payload'         => $hash,
            'fonte_sync'           => CanonicalSyncSource::normalize($document['fonte_sync'] ?? null),
        ]);

        return $row ? 'updated' : 'inserted';
    }

    /**
     * @param array<string,mixed> $record
     */
    private function isHardExcluded(array $record): bool
    {
        return HardDataExclusionPolicy::matches($this->pdo(), [
            'vendor_code' => $record['codigo_vendedor_omie'] ?? $record['codigo_omie'] ?? null,
            'nota_key' => $record['nota_key'] ?? null,
            'nota_id_omie' => $record['nota_id_omie'] ?? null,
            'chave_nfe' => $record['chave_nfe'] ?? null,
            'pedido_id_omie' => $record['pedido_id_omie'] ?? null,
            'codigo_pedido' => $record['codigo_pedido'] ?? $record['codigo_pedido_origem'] ?? null,
            'titulo_key' => $record['titulo_key'] ?? null,
            'titulo_id_omie' => $record['titulo_id_omie'] ?? null,
            'xml_key' => $record['xml_key'] ?? null,
        ], $this->companyId);
    }

    // ── Eventos Canônicos ────────────────────────────────────────────────────

    /**
     * Event store canônico append-only.
     * Retorna 'inserted', 'unchanged' ou 'skipped'.
     *
     * @param array<string,mixed> $event
     */
    public function appendCanonicalEvent(array $event): string
    {
        $entityName = trim((string) ($event['entity_name'] ?? ''));
        $eventType  = trim((string) ($event['event_type'] ?? ''));
        $payload    = is_array($event['source_payload'] ?? null) ? $event['source_payload'] : [];

        if ($entityName === '' || $eventType === '' || $payload === []) {
            return 'skipped';
        }

        $sourcePayloadJson = $this->codec->encode($payload);
        $sourcePayloadHash = hash('sha256', $sourcePayloadJson);
        $idempotencyHash   = $this->hashing->hashCanonicalEvent(
            ['company_id' => $this->companyId] + $event,
            $sourcePayloadHash
        );

        $sql = <<<'SQL'
            INSERT IGNORE INTO biomie_eventos_canonicos (
                company_id,
                entity_name, omie_entity_id, entity_code, integration_code,
                event_type, event_at_omie, event_order, stage_code, event_user,
                document_key, document_number, document_series,
                source_payload_hash, idempotency_hash, source_payload_json,
                source, ingested_at
            ) VALUES (
                :company_id,
                :entity_name, :omie_entity_id, :entity_code, :integration_code,
                :event_type, :event_at_omie, :event_order, :stage_code, :event_user,
                :document_key, :document_number, :document_series,
                :source_payload_hash, :idempotency_hash, :source_payload_json,
                :source, NOW()
            )
        SQL;

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([
            'company_id'         => $this->companyId,
            'entity_name'         => $entityName,
            'omie_entity_id'      => ($event['omie_entity_id'] ?? null) ?: null,
            'entity_code'         => ($event['entity_code'] ?? null) ?: null,
            'integration_code'    => ($event['integration_code'] ?? null) ?: null,
            'event_type'          => $eventType,
            'event_at_omie'       => ($event['event_at_omie'] ?? null) ?: null,
            'event_order'         => (int) ($event['event_order'] ?? 0),
            'stage_code'          => ($event['stage_code'] ?? null) ?: null,
            'event_user'          => ($event['event_user'] ?? null) ?: null,
            'document_key'        => ($event['document_key'] ?? null) ?: null,
            'document_number'     => ($event['document_number'] ?? null) ?: null,
            'document_series'     => ($event['document_series'] ?? null) ?: null,
            'source_payload_hash' => $sourcePayloadHash,
            'idempotency_hash'    => $idempotencyHash,
            'source_payload_json' => $sourcePayloadJson,
            'source'              => (string) ($event['source'] ?? 'cron'),
        ]);

        return $stmt->rowCount() > 0 ? 'inserted' : 'unchanged';
    }

    // ── Helpers privados ─────────────────────────────────────────────────────

    private function pdo(): PDO
    {
        return ($this->pdoResolver)();
    }

    /**
     * Classifies channel and commercial visibility before the canonical upsert.
     * The result is persisted in the same transaction as the invoice, so a
     * licitation NF can never briefly appear in the varejo dashboard.
     *
     * @param array<string,mixed> $invoice
     * @return array{channel:string,scope:string,visible:int,source:string,confidence:string,rule_id:?int}
     */
    private function resolveInvoiceVisibility(array $invoice): array
    {
        $explicit = ChannelClassifierService::normalizeChannel($invoice['business_channel'] ?? null);
        if ($explicit !== null) {
            return $this->visibilityResult(
                $explicit,
                'payload_business_channel',
                'manual',
                null,
            );
        }

        $vendorCode = trim((string) ($invoice['codigo_vendedor_omie'] ?? ''));
        $vendorName = trim((string) ($invoice['nome_vendedor'] ?? ''));
        $vendorRule = $this->vendorScopeRule($vendorCode, $vendorName);
        if ($vendorRule !== null) {
            return $this->visibilityResult(
                $vendorRule['channel'],
                $vendorRule['source'],
                'manual',
                null,
                $vendorRule['visible'],
            );
        }

        $this->channelClassifier ??= new ChannelClassifierService($this->pdo(), $this->companyId);
        $classification = $this->channelClassifier->classify(
            $vendorName,
            (string) ($invoice['observacoes'] ?? ''),
            preg_replace('/\D+/', '', (string) ($invoice['documento_cliente'] ?? '')) ?? '',
            (string) ($invoice['projeto_id_omie'] ?? ''),
            $vendorCode,
        );

        $channel = ChannelClassifierService::normalizeChannel($classification['channel'] ?? null);
        if ($channel === null) {
            throw new \RuntimeException(
                'Classificador retornou canal fiscal invalido; esperado varejo, licitacao ou outros.'
            );
        }

        $confidence = strtolower(trim((string) $classification['confidence']));
        if (!in_array($confidence, ['manual', 'alta', 'media', 'baixa'], true)) {
            $confidence = 'baixa';
        }
        $ruleId = (int) ($classification['rule_id'] ?? 0);
        $source = trim((string) ($classification['source'] ?? ''));
        if ($source === '') {
            $source = $ruleId > 0 ? 'channel_classification_rule:' . $ruleId : 'channel_classifier_fallback';
        }

        return $this->visibilityResult(
            $channel,
            $source,
            $confidence,
            $ruleId > 0 ? $ruleId : null,
        );
    }

    /**
     * @return array{channel:string,scope:string,visible:int,source:string,confidence:string,rule_id:?int}
     */
    private function visibilityResult(
        string $channel,
        string $source,
        string $confidence,
        ?int $ruleId,
        ?int $visible = null,
    ): array {
        return [
            'channel' => $channel,
            'scope' => match ($channel) {
                'licitacao' => 'licitacao',
                'outros' => 'outros',
                default => 'comercial',
            },
            'visible' => $visible ?? ($channel === 'varejo' ? 1 : 0),
            'source' => $source,
            'confidence' => $confidence,
            'rule_id' => $ruleId,
        ];
    }

    /** @return array{channel:string,visible:int,source:string}|null */
    private function vendorScopeRule(string $vendorCode, string $vendorName): ?array
    {
        if ($this->vendorScopeRules === null) {
            $this->vendorScopeRules = [];
            $stmt = $this->pdo()->prepare(
                'SELECT codigo_vendedor_omie, nome_normalizado,
                        business_unit_scope, is_biomie_commercial_visible
                   FROM biomie_vendor_scope_rules
                  WHERE company_id = :company_id AND active = 1'
            );
            $stmt->execute(['company_id' => $this->companyId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $scope = strtolower(trim((string) ($row['business_unit_scope'] ?? '')));
                $channel = $scope === 'licitacao' ? 'licitacao' : 'varejo';
                $rule = [
                    'channel' => $channel,
                    'visible' => (int) ($row['is_biomie_commercial_visible'] ?? ($channel === 'varejo' ? 1 : 0)),
                    'source' => 'vendor_scope_rules',
                ];
                $code = trim((string) ($row['codigo_vendedor_omie'] ?? ''));
                $name = trim((string) ($row['nome_normalizado'] ?? ''));
                if ($code !== '') {
                    $this->vendorScopeRules['code:' . $code] = $rule;
                }
                if ($name !== '') {
                    $this->vendorScopeRules['name:' . $name] = $rule;
                }
            }
        }

        if ($vendorCode !== '' && isset($this->vendorScopeRules['code:' . $vendorCode])) {
            return $this->vendorScopeRules['code:' . $vendorCode];
        }
        $normalizedName = BiVisibilityScope::normalizeName($vendorName);
        return $normalizedName !== ''
            ? ($this->vendorScopeRules['name:' . $normalizedName] ?? null)
            : null;
    }

    /**
     * @param array{channel:string,scope:string,visible:int,source:string,confidence:string,rule_id:?int} $visibility
     */
    private function updateInvoiceVisibility(string $noteKey, array $visibility): void
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE ' . self::TABLE_INVOICES . '
                SET business_channel = :business_channel,
                    business_unit_scope = :business_unit_scope,
                    is_biomie_commercial_visible = :visible,
                    visibility_rule_source = :source,
                    visibility_checked_at = NOW(),
                    channel_confidence = :confidence,
                    channel_rule_id = :rule_id,
                    channel_classified_at = NOW()
              WHERE company_id = :company_id AND nota_key = :nota_key'
        );
        $stmt->execute([
            'company_id' => $this->companyId,
            'nota_key' => $noteKey,
            'business_channel' => $visibility['channel'],
            'business_unit_scope' => $visibility['scope'],
            'visible' => $visibility['visible'],
            'source' => $visibility['source'],
            'confidence' => $visibility['confidence'],
            'rule_id' => $visibility['rule_id'],
        ]);
    }

    /**
     * @param array<string,mixed> $record
     */
    private function resolveBusinessChannel(array $record): string
    {
        $explicit = ChannelClassifierService::normalizeChannel($record['business_channel'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }

        // Regra de negócio (LicitacaoSeller): vendedor "Licitação" => licitação;
        // qualquer outro => varejo. Títulos ligados a NF/pedido herdam o canal
        // deles depois (ChannelClassifierService::propagateRelatedChannels).
        $scope = strtolower(trim((string) ($record['business_unit_scope'] ?? '')));
        $vendorName = (string) ($record['nome_vendedor'] ?? $record['nome'] ?? '');

        return ($scope === 'licitacao' || LicitacaoSeller::isLicitacaoName($vendorName))
            ? 'licitacao'
            : 'varejo';
    }

    /**
     * @param array<string,mixed> $invoice
     * @return array<string,mixed>
     */
    private function buildXmlDocumentFromInvoice(array $invoice, string $invoiceHash): array
    {
        $statusFiscal = (string) ($invoice['status_fiscal'] ?? 'nao_autorizada');
        $documentStatus = match ($statusFiscal) {
            'cancelada' => 'cancelado',
            'nao_autorizada' => 'indisponivel',
            default => 'pendente',
        };
        $xmlKey = trim((string) ($invoice['chave_nfe'] ?? ''));
        if ($xmlKey === '') {
            $xmlKey = trim((string) ($invoice['nota_key'] ?? ''));
        }

        return [
            'xml_key'              => $xmlKey,
            'nota_key'             => $invoice['nota_key'] ?? null,
            'nota_id_omie'         => $invoice['nota_id_omie'] ?? null,
            'chave_nfe'            => $invoice['chave_nfe'] ?? null,
            'numero_nf'            => $invoice['numero_nf'] ?? '',
            'serie_nf'             => $invoice['serie_nf'] ?? null,
            'pedido_id_omie'       => $invoice['pedido_id_omie'] ?? null,
            'codigo_pedido_origem' => $invoice['codigo_pedido_origem'] ?? null,
            'documento_tipo'       => 'nfe_xml',
            'status_documento'     => $documentStatus,
            'data_emissao'         => $invoice['data_emissao'] ?? null,
            'data_cancelamento'    => $invoice['data_cancelamento'] ?? null,
            'xml_payload'          => $invoice['xml_payload'] ?? null,
            'metadata'             => [
                'nota_key'       => $invoice['nota_key'] ?? null,
                'hash_invoice'   => $invoiceHash,
                'xml_disponivel' => !empty($invoice['xml_payload']),
            ],
            'fonte_sync' => CanonicalSyncSource::normalize($invoice['fonte_sync'] ?? null),
        ];
    }
}
