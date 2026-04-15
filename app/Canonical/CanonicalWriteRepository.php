<?php

declare(strict_types=1);

namespace BIomie\Canonical;

use BIomie\Canonical\Support\CanonicalHashing;
use BIomie\Canonical\Support\CanonicalJsonCodec;
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

    public function __construct(
        private readonly Closure $pdoResolver,
        private readonly Closure $beginTransaction,
        private readonly CanonicalHashing $hashing,
        private readonly CanonicalJsonCodec $codec,
    ) {
    }

    // ── Vendedores ───────────────────────────────────────────────────────────

    public function upsertVendor(string $codigoOmie, string $nome, string $email, float $comissao): void
    {
        $sql = <<<'SQL'
            INSERT INTO biomie_vendedores_omie (codigo_omie, nome, email, comissao, ativo, sincronizado_em)
            VALUES (:codigo, :nome, :email, :comissao, 1, NOW())
            ON DUPLICATE KEY UPDATE
                nome = VALUES(nome),
                email = VALUES(email),
                comissao = VALUES(comissao),
                ativo = 1,
                sincronizado_em = NOW()
        SQL;

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([
            'codigo' => $codigoOmie,
            'nome' => $nome,
            'email' => $email,
            'comissao' => $comissao,
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
        $pedidoId = (int) ($order['pedido_id_omie'] ?? 0);
        if ($pedidoId <= 0) {
            return 'skipped';
        }

        $hash = $this->hashing->hashOrder($order);

        $existing = $this->pdo()->prepare(
            'SELECT id, hash_payload FROM ' . self::TABLE_ORDERS . ' WHERE pedido_id_omie = :pid'
        );
        $existing->execute(['pid' => $pedidoId]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if ($row && ($row['hash_payload'] ?? '') === $hash) {
            return 'unchanged';
        }

        $sql = <<<'SQL'
            INSERT INTO biomie_pedidos_canonicos (
                pedido_id_omie, codigo_pedido, codigo_integracao,
                codigo_vendedor_omie, nome_vendedor,
                codigo_cliente_omie, nome_cliente, documento_cliente,
                status_comercial, etapa_omie, status_pedido_omie,
                data_emissao, data_faturamento, data_cancelamento,
                valor_total, valor_mercadorias, valor_descontos, quantidade_itens,
                hash_payload, fonte_sync, sincronizado_em
            ) VALUES (
                :pedido_id, :codigo, :integracao,
                :vendedor_id, :vendedor_nome,
                :cliente_id, :cliente_nome, :cliente_doc,
                :status, :etapa, :status_pedido,
                :dt_emissao, :dt_faturamento, :dt_cancelamento,
                :valor_total, :valor_mercadorias, :valor_descontos, :qtd_itens,
                :hash, :fonte, NOW()
            )
            ON DUPLICATE KEY UPDATE
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
                data_emissao = VALUES(data_emissao),
                data_faturamento = VALUES(data_faturamento),
                data_cancelamento = VALUES(data_cancelamento),
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
            'dt_emissao'     => $order['data_emissao'] ?: null,
            'dt_faturamento' => $order['data_faturamento'] ?: null,
            'dt_cancelamento'=> $order['data_cancelamento'] ?: null,
            'valor_total'    => (float) ($order['valor_total'] ?? 0),
            'valor_mercadorias' => (float) ($order['valor_mercadorias'] ?? 0),
            'valor_descontos'   => (float) ($order['valor_descontos'] ?? 0),
            'qtd_itens'      => (int) ($order['quantidade_itens'] ?? 0),
            'hash'           => $hash,
            'fonte'          => (string) ($order['fonte_sync'] ?? 'cron'),
        ]);

        return $row ? 'updated' : 'inserted';
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
        }

        try {
            $delete = $pdo->prepare(
                'DELETE FROM ' . self::TABLE_ORDERS . ' WHERE data_emissao BETWEEN :start AND :end'
            );
            $delete->execute(['start' => $start, 'end' => $end]);

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
        }

        try {
            $deleteItems = $pdo->prepare(
                'DELETE item FROM ' . self::TABLE_INVOICE_ITEMS . ' item
                 INNER JOIN ' . self::TABLE_INVOICES . ' nota ON nota.nota_key = item.nota_key
                 WHERE nota.data_competencia BETWEEN :start AND :end'
            );
            $deleteItems->execute(['start' => $start, 'end' => $end]);

            $deleteInvoices = $pdo->prepare(
                'DELETE FROM ' . self::TABLE_INVOICES . ' WHERE data_competencia BETWEEN :start AND :end'
            );
            $deleteInvoices->execute(['start' => $start, 'end' => $end]);

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
        $noteKey = trim((string) ($invoice['nota_key'] ?? ''));
        if ($noteKey === '') {
            return 'skipped';
        }

        $hash = $this->hashing->hashInvoice($invoice);

        $existing = $this->pdo()->prepare(
            'SELECT id, hash_payload FROM ' . self::TABLE_INVOICES . ' WHERE nota_key = :note_key'
        );
        $existing->execute(['note_key' => $noteKey]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if ($row && ($row['hash_payload'] ?? '') === $hash) {
            return 'unchanged';
        }

        $pdo = $this->pdo();
        $startedTransaction = !$pdo->inTransaction();
        if ($startedTransaction) {
            ($this->beginTransaction)();
        }

        try {
            $sql = <<<'SQL'
                INSERT INTO biomie_notas_fiscais_canonicas (
                    nota_key, nota_id_omie, chave_nfe, numero_nf, serie_nf,
                    pedido_id_omie, codigo_pedido_origem,
                    codigo_vendedor_omie, nome_vendedor,
                    codigo_cliente_omie, nome_cliente, documento_cliente,
                    status_fiscal, data_emissao, data_cancelamento, data_competencia,
                    valor_total, valor_mercadorias, valor_descontos, quantidade_itens,
                    hash_payload, fonte_sync, sincronizado_em
                ) VALUES (
                    :nota_key, :nota_id_omie, :chave_nfe, :numero_nf, :serie_nf,
                    :pedido_id_omie, :codigo_pedido_origem,
                    :codigo_vendedor_omie, :nome_vendedor,
                    :codigo_cliente_omie, :nome_cliente, :documento_cliente,
                    :status_fiscal, :data_emissao, :data_cancelamento, :data_competencia,
                    :valor_total, :valor_mercadorias, :valor_descontos, :quantidade_itens,
                    :hash_payload, :fonte_sync, NOW()
                )
                ON DUPLICATE KEY UPDATE
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
                'status_fiscal'       => (string) ($invoice['status_fiscal'] ?? 'autorizada'),
                'data_emissao'        => ($invoice['data_emissao'] ?? null) ?: null,
                'data_cancelamento'   => ($invoice['data_cancelamento'] ?? null) ?: null,
                'data_competencia'    => ($invoice['data_competencia'] ?? null) ?: null,
                'valor_total'         => (float) ($invoice['valor_total'] ?? 0),
                'valor_mercadorias'   => (float) ($invoice['valor_mercadorias'] ?? 0),
                'valor_descontos'     => (float) ($invoice['valor_descontos'] ?? 0),
                'quantidade_itens'    => (int) ($invoice['quantidade_itens'] ?? 0),
                'hash_payload'        => $hash,
                'fonte_sync'          => (string) ($invoice['fonte_sync'] ?? 'cron'),
            ]);

            $deleteItems = $pdo->prepare(
                'DELETE FROM ' . self::TABLE_INVOICE_ITEMS . ' WHERE nota_key = :nota_key'
            );
            $deleteItems->execute(['nota_key' => $noteKey]);

            $items = is_array($invoice['items'] ?? null) ? $invoice['items'] : [];
            if ($items !== []) {
                $insertItem = $pdo->prepare(<<<'SQL'
                    INSERT INTO biomie_notas_fiscais_itens_canonicos (
                        nota_key, sequencia_item, codigo_produto, descricao_produto,
                        quantidade, valor_total, valor_desconto
                    ) VALUES (
                        :nota_key, :sequencia_item, :codigo_produto, :descricao_produto,
                        :quantidade, :valor_total, :valor_desconto
                    )
                SQL);

                foreach (array_values($items) as $index => $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $insertItem->execute([
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

    // ── Títulos Financeiros ──────────────────────────────────────────────────

    /**
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
        }

        try {
            $delete = $pdo->prepare(
                'DELETE FROM ' . self::TABLE_FINANCIAL_TITLES . ' WHERE data_competencia BETWEEN :start AND :end'
            );
            $delete->execute(['start' => $start, 'end' => $end]);

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
        $titleKey = trim((string) ($title['titulo_key'] ?? ''));
        if ($titleKey === '') {
            return 'skipped';
        }

        $hash = $this->hashing->hashFinancialTitle($title);
        $existing = $this->pdo()->prepare(
            'SELECT id, hash_payload FROM ' . self::TABLE_FINANCIAL_TITLES . ' WHERE titulo_key = :titulo_key'
        );
        $existing->execute(['titulo_key' => $titleKey]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if ($row && ($row['hash_payload'] ?? '') === $hash) {
            return 'unchanged';
        }

        $stmt = $this->pdo()->prepare(<<<'SQL'
            INSERT INTO biomie_titulos_financeiros_canonicos (
                titulo_key, titulo_id_omie, codigo_integracao, numero_documento,
                status_titulo, especie_documento, forma_pagamento,
                documento_origem_tipo, documento_origem_ref, nota_key, chave_nfe,
                pedido_id_omie, codigo_pedido_origem,
                codigo_cliente_omie, nome_cliente, documento_cliente,
                codigo_vendedor_omie, nome_vendedor, codigo_conta_corrente_omie,
                data_emissao, data_vencimento, data_liquidacao, data_competencia,
                valor_pago, valor_liquido, valor_aberto, hash_payload, fonte_sync, sincronizado_em
            ) VALUES (
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
                titulo_id_omie = VALUES(titulo_id_omie),
                codigo_integracao = VALUES(codigo_integracao),
                numero_documento = VALUES(numero_documento),
                status_titulo = VALUES(status_titulo),
                especie_documento = VALUES(especie_documento),
                forma_pagamento = VALUES(forma_pagamento),
                documento_origem_tipo = VALUES(documento_origem_tipo),
                documento_origem_ref = VALUES(documento_origem_ref),
                nota_key = VALUES(nota_key),
                chave_nfe = VALUES(chave_nfe),
                pedido_id_omie = VALUES(pedido_id_omie),
                codigo_pedido_origem = VALUES(codigo_pedido_origem),
                codigo_cliente_omie = VALUES(codigo_cliente_omie),
                nome_cliente = VALUES(nome_cliente),
                documento_cliente = VALUES(documento_cliente),
                codigo_vendedor_omie = VALUES(codigo_vendedor_omie),
                nome_vendedor = VALUES(nome_vendedor),
                codigo_conta_corrente_omie = VALUES(codigo_conta_corrente_omie),
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
            'fonte_sync'               => (string) ($title['fonte_sync'] ?? 'cron'),
        ]);

        return $row ? 'updated' : 'inserted';
    }

    // ── Documentos XML ───────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $document
     */
    public function upsertXmlDocument(array $document): string
    {
        $xmlKey = trim((string) ($document['xml_key'] ?? ''));
        if ($xmlKey === '') {
            return 'skipped';
        }

        $hash = $this->hashing->hashXmlDocument($document);
        $existing = $this->pdo()->prepare(
            'SELECT id, hash_payload FROM ' . self::TABLE_XML_DOCUMENTS . ' WHERE xml_key = :xml_key'
        );
        $existing->execute(['xml_key' => $xmlKey]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if ($row && ($row['hash_payload'] ?? '') === $hash) {
            return 'unchanged';
        }

        $stmt = $this->pdo()->prepare(<<<'SQL'
            INSERT INTO biomie_documentos_xml_canonicos (
                xml_key, nota_key, nota_id_omie, chave_nfe, numero_nf, serie_nf,
                pedido_id_omie, codigo_pedido_origem,
                documento_tipo, status_documento,
                data_emissao, data_cancelamento,
                xml_payload, metadata_json, hash_payload, fonte_sync, sincronizado_em
            ) VALUES (
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
            'fonte_sync'           => (string) ($document['fonte_sync'] ?? 'cron'),
        ]);

        return $row ? 'updated' : 'inserted';
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
        $idempotencyHash   = $this->hashing->hashCanonicalEvent($event, $sourcePayloadHash);

        $sql = <<<'SQL'
            INSERT IGNORE INTO biomie_eventos_canonicos (
                entity_name, omie_entity_id, entity_code, integration_code,
                event_type, event_at_omie, event_order, stage_code, event_user,
                document_key, document_number, document_series,
                source_payload_hash, idempotency_hash, source_payload_json,
                source, ingested_at
            ) VALUES (
                :entity_name, :omie_entity_id, :entity_code, :integration_code,
                :event_type, :event_at_omie, :event_order, :stage_code, :event_user,
                :document_key, :document_number, :document_series,
                :source_payload_hash, :idempotency_hash, :source_payload_json,
                :source, NOW()
            )
        SQL;

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([
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
     * @param array<string,mixed> $invoice
     * @return array<string,mixed>
     */
    private function buildXmlDocumentFromInvoice(array $invoice, string $invoiceHash): array
    {
        $statusFiscal = (string) ($invoice['status_fiscal'] ?? 'autorizada');
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
            'status_documento'     => $statusFiscal === 'cancelada' ? 'cancelado' : 'pendente',
            'data_emissao'         => $invoice['data_emissao'] ?? null,
            'data_cancelamento'    => $invoice['data_cancelamento'] ?? null,
            'xml_payload'          => $invoice['xml_payload'] ?? null,
            'metadata'             => [
                'nota_key'       => $invoice['nota_key'] ?? null,
                'hash_invoice'   => $invoiceHash,
                'xml_disponivel' => !empty($invoice['xml_payload']),
            ],
            'fonte_sync' => $invoice['fonte_sync'] ?? 'cron',
        ];
    }
}
