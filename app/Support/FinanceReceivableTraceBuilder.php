<?php

declare(strict_types=1);

namespace BIomie\Support;

use PDO;

/**
 * Read-only builder: rastreabilidade financeira NF × boleto × vendedor.
 * Não altera dados, não cria bridges, não materializa.
 */
final class FinanceReceivableTraceBuilder
{
    private const PAID_STATUSES = ['liquidado', 'recebido', 'pago'];

    private const BOLETO_KEYWORDS = [
        'boleto', 'bol ', 'cobrança', 'cobranca', 'bancária', 'bancaria',
        'duplicata', 'borderô', 'bordero',
    ];

    /** Espécies de documento Omie que indicam boleto/duplicata. */
    private const BOLETO_ESPECIES = ['bol', 'dup', 'dmi', 'dme'];

    private const METHOD_MAP = [
        'boleto'         => ['boleto', 'bol ', 'cobrança', 'cobranca', 'duplicata', 'borderô', 'bordero'],
        'pix'            => ['pix'],
        'cartao_credito' => ['cred', 'crédito', 'credito', 'mastercard', 'visa', 'elo', 'amex', 'hipercard'],
        'cartao_debito'  => ['débito', 'debito', 'debit'],
        'dinheiro'       => ['dinheiro', 'espécie', 'especie', 'cash'],
        'transferencia'  => ['transfer', 'ted', 'doc ', 'tev', 'wire'],
        'cheque'         => ['cheque', 'chq'],
        'deposito'       => ['depós', 'depos', 'deposit'],
    ];

    /** Lookup nCodCC → {bol_sn, pix_sn, descricao} carregado sob demanda do bronze. */
    private ?array $accountLookup = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function build(string $start, string $end, array $filters = []): array
    {
        $today  = date('Y-m-d');
        $titles = $this->fetchTitles($start, $end);
        $rows   = [];

        foreach ($titles as $t) {
            $rows[] = $this->normalizeRow($t, $today);
        }

        $status = trim($filters['status'] ?? 'all');
        if ($status === 'open') {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => (bool) $r['is_open']));
        } elseif ($status === 'overdue') {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => (bool) $r['is_overdue']));
        } elseif ($status === 'due') {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => (bool) $r['is_open'] && !(bool) $r['is_overdue']));
        } elseif ($status === 'received') {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => (bool) $r['is_paid']));
        }

        $method = trim($filters['payment_method'] ?? 'all');
        if ($method !== '' && $method !== 'all') {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => ($r['normalized_payment_method'] ?? '') === $method));
        }

        if (!empty($filters['only_without_nf'])) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => ($r['nota_key'] ?? '') === ''));
        }
        if (!empty($filters['only_without_vendor'])) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => (bool) ($r['vendor_missing'] ?? false)));
        }

        return [
            'ok'                    => true,
            'period'                => ['start' => $start, 'end' => $end, 'reference_date' => $today],
            'summary'               => $this->buildSummary($rows),
            'open_invoice_summary'  => $this->buildOpenInvoiceSummary($rows),
            'payment_method_ranking'=> $this->buildPaymentMethodRanking($rows),
            'vendor_ranking'        => $this->buildVendorRanking($rows),
            'boleto_open_titles'    => $this->buildBoletoOpenTitles($rows),
            'nf_rows'               => array_slice($rows, 0, 200),
            'title_rows'            => array_slice($rows, 0, 200),
            'diagnostics'           => [
                'source'        => 'finance_receivable_trace',
                'titles_loaded' => count($titles),
                'generated_at'  => date(DATE_ATOM),
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function fetchTitles(string $start, string $end): array
    {
        $hasBridge    = $this->tableExists('biomie_bridge_nf_titulo_items');
        $hasNf        = $this->tableExists('biomie_notas_fiscais_canonicas');
        $hasMartBridge = $this->tableExists('mart_bridge_pedido_nfe_titulo');

        $nfCols = $hasNf
            ? 'nf.numero_nf, nf.data_emissao AS nf_data_emissao,
               nf.nome_cliente AS nf_nome_cliente, nf.documento_cliente AS nf_documento_cliente,
               nf.codigo_cliente_omie AS nf_codigo_cliente,
               nf.nome_vendedor AS nf_nome_vendedor, nf.codigo_vendedor_omie AS nf_codigo_vendedor'
            : "NULL AS numero_nf, NULL AS nf_data_emissao,
               NULL AS nf_nome_cliente, NULL AS nf_documento_cliente,
               NULL AS nf_codigo_cliente, NULL AS nf_nome_vendedor, NULL AS nf_codigo_vendedor";

        $bridgeCols = $hasBridge
            ? 'bi.nota_key AS bridge_nota_key, bi.confidence_score, bi.match_method, bi.is_manual_override'
            : 'NULL AS bridge_nota_key, NULL AS confidence_score, NULL AS match_method, NULL AS is_manual_override';

        $martBridgeCols = $hasMartBridge
            ? 'mb.nota_key AS mart_nota_key, mb.pedido_id_omie AS mart_pedido_id'
            : 'NULL AS mart_nota_key, NULL AS mart_pedido_id';

        $bridgeJoin = $hasBridge
            ? 'LEFT JOIN biomie_bridge_nf_titulo_items bi ON bi.titulo_key = t.titulo_key'
            : '';

        $nfJoin = ($hasBridge && $hasNf)
            ? 'LEFT JOIN biomie_notas_fiscais_canonicas nf ON nf.nota_key = bi.nota_key'
            : ($hasNf
                ? 'LEFT JOIN biomie_notas_fiscais_canonicas nf ON nf.nota_key = t.nota_key'
                : '');

        $martJoin = $hasMartBridge
            ? 'LEFT JOIN mart_bridge_pedido_nfe_titulo mb ON mb.titulo_key = t.titulo_key'
            : '';

        $sql = "SELECT
                    t.titulo_key,
                    t.titulo_id_omie,
                    t.numero_documento,
                    t.status_titulo,
                    t.especie_documento,
                    t.forma_pagamento,
                    t.codigo_conta_corrente_omie,
                    t.documento_origem_tipo,
                    t.documento_origem_ref,
                    t.nota_key AS titulo_nota_key,
                    t.chave_nfe,
                    t.pedido_id_omie,
                    t.codigo_pedido_origem,
                    t.codigo_cliente_omie AS titulo_codigo_cliente,
                    t.nome_cliente AS titulo_nome_cliente,
                    t.documento_cliente,
                    t.codigo_vendedor_omie AS titulo_codigo_vendedor,
                    t.nome_vendedor AS titulo_nome_vendedor,
                    t.data_emissao,
                    t.data_vencimento,
                    t.data_liquidacao,
                    t.data_competencia,
                    t.valor_liquido,
                    t.valor_aberto,
                    {$bridgeCols},
                    {$nfCols},
                    {$martBridgeCols}
                FROM biomie_titulos_financeiros_canonicos t
                {$bridgeJoin}
                {$nfJoin}
                {$martJoin}
                WHERE t.data_competencia BETWEEN :start AND :end
                ORDER BY t.data_competencia ASC, t.data_vencimento ASC, t.titulo_key ASC
                LIMIT 5000";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['start' => $start, 'end' => $end]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /** @param array<string,mixed> $t @return array<string,mixed> */
    private function normalizeRow(array $t, string $today): array
    {
        $status   = strtolower(trim((string) ($t['status_titulo'] ?? '')));
        $isPaid   = in_array($status, self::PAID_STATUSES, true);
        $amtOpen  = (float) ($t['valor_aberto'] ?? 0);
        $dueDate  = (string) ($t['data_vencimento'] ?? '');
        $daysLate = (!$isPaid && $dueDate !== '' && $dueDate < $today)
            ? (int) floor((strtotime($today) - strtotime($dueDate)) / 86400) : 0;
        $isOpen   = !$isPaid && $amtOpen > 0.0001;
        $isOverdue = $isOpen && $daysLate > 0;

        $notaKey     = $this->resolveNfKey($t);
        $vendor      = $this->resolveVendorInfo($t);
        $client      = $this->resolveClientInfo($t);
        $payMethod   = $this->normalizePaymentMethod($t);
        $isBoleto    = $payMethod === 'boleto';

        return [
            'titulo_key'               => (string) ($t['titulo_key'] ?? ''),
            'numero_documento'         => (string) ($t['numero_documento'] ?? ''),
            'document_label'           => $this->documentLabel($t),
            'nota_key'                 => $notaKey,
            'numero_nf'                => (string) ($t['numero_nf'] ?? ''),
            'nf_data_emissao'          => (string) ($t['nf_data_emissao'] ?? $t['data_emissao'] ?? ''),
            'pedido_id_omie'           => (string) ($t['pedido_id_omie'] ?? $t['mart_pedido_id'] ?? ''),
            'client_key'               => $client['key'],
            'cliente_nome'             => $client['name'],
            'cliente_documento'        => $this->maskDocument((string) ($t['documento_cliente'] ?? $t['nf_documento_cliente'] ?? '')),
            'vendor_key'               => $vendor['key'],
            'vendedor_nome'            => $vendor['name'],
            'vendor_missing'           => $vendor['missing'],
            'data_vencimento'          => $dueDate,
            'data_liquidacao'          => (string) ($t['data_liquidacao'] ?? ''),
            'data_competencia'         => (string) ($t['data_competencia'] ?? ''),
            'status_titulo'            => (string) ($t['status_titulo'] ?? ''),
            'is_paid'                  => $isPaid,
            'is_open'                  => $isOpen,
            'is_overdue'               => $isOverdue,
            'dias_atraso'              => $daysLate,
            'aging_bucket'             => $this->agingBucket($dueDate, $today),
            'valor_liquido'            => round((float) ($t['valor_liquido'] ?? 0), 2),
            'valor_aberto'             => round($amtOpen, 2),
            'valor_vencido'            => $isOverdue ? round($amtOpen, 2) : 0.0,
            'valor_a_vencer'           => ($isOpen && !$isOverdue) ? round($amtOpen, 2) : 0.0,
            'normalized_payment_method'=> $payMethod,
            'raw_payment_method'       => (string) ($t['forma_pagamento'] ?? ''),
            'raw_document_species'     => (string) ($t['especie_documento'] ?? ''),
            'raw_account'              => (string) ($t['codigo_conta_corrente_omie'] ?? ''),
            'is_boleto'                => $isBoleto,
            'bridge_nota_key'          => (string) ($t['bridge_nota_key'] ?? ''),
            'bridge_confidence'        => $t['confidence_score'] !== null ? (int) $t['confidence_score'] : null,
            'bridge_match_method'      => (string) ($t['match_method'] ?? ''),
            'bridge_status'            => $notaKey !== '' ? 'vinculado' : 'sem_nf_vinculada',
            'recommended_action'       => $this->recommendedAction($vendor['missing'], $isOverdue, $daysLate, $notaKey),
        ];
    }

    public function normalizePaymentMethod(array $title): string
    {
        $forma   = strtolower(trim((string) ($title['forma_pagamento'] ?? '')));
        $especie = strtolower(trim((string) ($title['especie_documento'] ?? '')));

        // 1. Espécie de documento Omie (código oficial: BOL, DUP, DMI, DME)
        if (in_array($especie, self::BOLETO_ESPECIES, true)) {
            return 'boleto';
        }

        // 2. Keyword search em forma_pagamento + especie_documento
        $combined = $forma . ' ' . $especie;
        foreach (self::METHOD_MAP as $method => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($combined, $kw)) {
                    return $method;
                }
            }
        }

        // 3. Fallback via configuração da conta corrente (bol_sn / pix_sn do bronze Omie)
        $contaCode = trim((string) ($title['codigo_conta_corrente_omie'] ?? $title['raw_account'] ?? ''));
        if ($contaCode !== '') {
            $accountMethod = $this->detectMethodFromAccount($contaCode);
            if ($accountMethod !== null) {
                return $accountMethod;
            }
        }

        if ($forma === '' && $especie === '') {
            return 'nao_informado';
        }

        return 'outro';
    }

    /**
     * Detecta método de pagamento a partir da configuração da conta corrente no bronze Omie.
     * Usa bol_sn (conta configurada para boleto) e pix_sn (conta configurada para PIX).
     * Fonte oficial Omie — não é heurística de nome de conta.
     */
    private function detectMethodFromAccount(string $contaCode): ?string
    {
        $lookup = $this->loadAccountLookup();
        $account = $lookup[$contaCode] ?? null;
        if ($account === null) {
            return null;
        }

        $bolSn = strtolower(trim((string) ($account['bol_sn'] ?? '')));
        $pixSn = strtolower(trim((string) ($account['pix_sn'] ?? '')));

        // bol_sn='S' → conta configurada para boleto bancário (campo oficial Omie)
        if ($bolSn === 's') {
            return 'boleto';
        }
        // pix_sn='S' → conta configurada para PIX (campo oficial Omie)
        if ($pixSn === 's') {
            return 'pix';
        }

        return null;
    }

    /**
     * Carrega lookup nCodCC (string) → {bol_sn, pix_sn} do bronze Omie (ListarContasCorrentes).
     * Lazy-loaded uma vez por instância do builder.
     *
     * @return array<string,array<string,mixed>>
     */
    private function loadAccountLookup(): array
    {
        if ($this->accountLookup !== null) {
            return $this->accountLookup;
        }

        $this->accountLookup = [];

        try {
            $bronzeExists = (bool) $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biomie_omie_bronze'"
            )->fetchColumn();

            if (!$bronzeExists) {
                return $this->accountLookup;
            }

            $stmt = $this->pdo->query(
                "SELECT payload_json FROM biomie_omie_bronze
                 WHERE omie_method = 'ListarContasCorrentes'
                 ORDER BY ingested_at DESC
                 LIMIT 500"
            );
            if ($stmt === false) {
                return $this->accountLookup;
            }

            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $payloadJson) {
                $payload = json_decode((string) $payloadJson, true);
                if (!is_array($payload)) {
                    continue;
                }
                $nCodCC = trim((string) ($payload['nCodCC'] ?? ''));
                if ($nCodCC === '') {
                    continue;
                }
                $this->accountLookup[$nCodCC] = [
                    'bol_sn'  => (string) ($payload['bol_sn'] ?? ''),
                    'pix_sn'  => (string) ($payload['pix_sn'] ?? ''),
                    'descricao' => (string) ($payload['descricao'] ?? ''),
                    'cobr_sn' => (string) ($payload['cobr_sn'] ?? ''),
                ];
            }
        } catch (\Throwable) {
            // Bronze não disponível — continua sem enriquecimento de conta
        }

        return $this->accountLookup;
    }

    private function resolveNfKey(array $t): string
    {
        if ((string) ($t['bridge_nota_key'] ?? '') !== '') {
            return (string) $t['bridge_nota_key'];
        }
        if ((string) ($t['titulo_nota_key'] ?? '') !== '') {
            return (string) $t['titulo_nota_key'];
        }
        if ((string) ($t['mart_nota_key'] ?? '') !== '') {
            return (string) $t['mart_nota_key'];
        }
        return '';
    }

    /** @return array{key:string,name:string,missing:bool} */
    private function resolveVendorInfo(array $t): array
    {
        $nfCode = trim((string) ($t['nf_codigo_vendedor'] ?? ''));
        $nfName = trim((string) ($t['nf_nome_vendedor'] ?? ''));
        if ($nfCode !== '' || ($nfName !== '' && !$this->isUnknownVendor($nfName))) {
            return ['key' => $nfCode ?: $nfName, 'name' => $nfName ?: 'Vendedor ' . $nfCode, 'missing' => false];
        }

        $tCode = trim((string) ($t['titulo_codigo_vendedor'] ?? ''));
        $tName = trim((string) ($t['titulo_nome_vendedor'] ?? ''));
        if ($tCode !== '' || ($tName !== '' && !$this->isUnknownVendor($tName))) {
            return ['key' => $tCode ?: $tName, 'name' => $tName ?: 'Vendedor ' . $tCode, 'missing' => false];
        }

        return ['key' => '__sem_vendedor__', 'name' => 'Sem vendedor definido', 'missing' => true];
    }

    private function isUnknownVendor(string $name): bool
    {
        return in_array(strtolower($name), ['sem vendedor', 'sem vendedor definido', 'nao informado', 'não informado', ''], true);
    }

    /** @return array{key:string,name:string} */
    private function resolveClientInfo(array $t): array
    {
        $nfClient = trim((string) ($t['nf_nome_cliente'] ?? ''));
        $nfCode   = (string) ($t['nf_codigo_cliente'] ?? '');
        if ($nfClient !== '' && !$this->isUnknownClient($nfClient)) {
            return ['key' => $nfCode ?: $nfClient, 'name' => $nfClient];
        }

        $tClient = trim((string) ($t['titulo_nome_cliente'] ?? ''));
        $tCode   = (string) ($t['titulo_codigo_cliente'] ?? '');
        if ($tClient !== '' && !$this->isUnknownClient($tClient)) {
            return ['key' => $tCode ?: $tClient, 'name' => $tClient];
        }

        return ['key' => '__sem_cliente__', 'name' => 'Cliente não informado'];
    }

    private function isUnknownClient(string $name): bool
    {
        return in_array(strtolower($name), ['cliente nao informado', 'cliente não informado', 'nao informado', 'não informado', ''], true);
    }

    private function documentLabel(array $t): string
    {
        $ref = trim((string) ($t['documento_origem_ref'] ?? ''));
        if ($ref !== '') return $ref;
        $num = trim((string) ($t['numero_documento'] ?? ''));
        return $num !== '' ? $num : (string) ($t['titulo_key'] ?? '');
    }

    private function maskDocument(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) === 14) {
            return substr($digits, 0, 2) . '.' . substr($digits, 2, 3) . '.' . substr($digits, 5, 3) . '/****-**';
        }
        if (strlen($digits) === 11) {
            return '***.***.***-**';
        }
        return '';
    }

    private function agingBucket(string $dueDate, string $today): string
    {
        if ($dueDate === '') return 'sem_data';
        if ($dueDate > $today) return 'a_vencer';
        if ($dueDate === $today) return 'vence_hoje';
        $days = (int) floor((strtotime($today) - strtotime($dueDate)) / 86400);
        return match (true) {
            $days <= 7  => '1_7',
            $days <= 15 => '8_15',
            $days <= 30 => '16_30',
            $days <= 60 => '31_60',
            default     => '60_plus',
        };
    }

    private function recommendedAction(bool $vendorMissing, bool $isOverdue, int $daysLate, string $notaKey): string
    {
        if ($vendorMissing && $notaKey === '') return 'Revisar vínculo financeiro';
        if ($vendorMissing) return 'Associar vendedor à NF';
        if ($isOverdue && $daysLate > 60) return 'Acionar jurídico / Cobrar urgente';
        if ($isOverdue) return 'Cobrar hoje';
        return 'Acompanhar vencimento';
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private function buildSummary(array $rows): array
    {
        $openTotal = $overdueTotal = $dueTotal = 0.0;
        $openTitles = $overdueTitles = $boletoOpen = $boletoOverdue = 0;
        $nfsWithOpenBoleto = $titlesWithNf = $titlesWithoutNf = $titlesWithoutVendor = 0;
        $nfsOpenSet = [];
        $methodsSet = [];
        $maxDelay = 0;
        $topMethod = '';
        $topMethodVal = 0.0;

        foreach ($rows as $row) {
            if ($row['is_open']) {
                $openTotal += (float) $row['valor_aberto'];
                $openTitles++;
                if ($row['is_overdue']) {
                    $overdueTotal += (float) $row['valor_aberto'];
                    $overdueTitles++;
                    $maxDelay = max($maxDelay, (int) $row['dias_atraso']);
                } else {
                    $dueTotal += (float) $row['valor_aberto'];
                }
                if ($row['nota_key'] !== '') $titlesWithNf++;
                else $titlesWithoutNf++;
                if ($row['vendor_missing']) $titlesWithoutVendor++;
                if ($row['is_boleto']) {
                    $boletoOpen++;
                    if ($row['nota_key'] !== '') $nfsOpenSet[$row['nota_key']] = true;
                    if ($row['is_overdue']) $boletoOverdue++;
                }

                $m = $row['normalized_payment_method'];
                $methodsSet[$m] = ($methodsSet[$m] ?? 0.0) + (float) $row['valor_aberto'];
            }
        }

        $nfsWithOpenBoleto = count($nfsOpenSet);
        if ($methodsSet !== []) {
            $topMethod = (string) array_key_first(
                arsort($methodsSet) ? $methodsSet : $methodsSet
            );
            arsort($methodsSet);
            $topMethod = (string) array_key_first($methodsSet);
            $topMethodVal = reset($methodsSet) ?: 0.0;
        }

        return [
            'open_total'               => round($openTotal, 2),
            'overdue_total'            => round($overdueTotal, 2),
            'due_total'                => round($dueTotal, 2),
            'boleto_open_total'        => $boletoOpen,
            'boleto_overdue_total'     => $boletoOverdue,
            'nfs_with_open_boleto'     => $nfsWithOpenBoleto,
            'titles_with_nf'           => $titlesWithNf,
            'titles_without_nf'        => $titlesWithoutNf,
            'titles_without_vendor'    => $titlesWithoutVendor,
            'payment_methods_count'    => count($methodsSet),
            'top_payment_method'       => $topMethod,
            'top_payment_method_value' => round($topMethodVal, 2),
            'max_delay_days'           => $maxDelay,
            'open_titles'              => $openTitles,
            'overdue_titles'           => $overdueTitles,
        ];
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function buildOpenInvoiceSummary(array $rows): array
    {
        $nfMap = [];

        foreach ($rows as $row) {
            if (!$row['is_open'] || $row['nota_key'] === '') continue;
            $key = $row['nota_key'];
            if (!isset($nfMap[$key])) {
                $nfMap[$key] = [
                    'nota_key'                => $key,
                    'numero_nf'               => $row['numero_nf'],
                    'nf_data_emissao'         => $row['nf_data_emissao'],
                    'cliente_nome'            => $row['cliente_nome'],
                    'cliente_documento'       => $row['cliente_documento'],
                    'vendedor_nome'           => $row['vendedor_nome'],
                    'vendor_key'              => $row['vendor_key'],
                    'qtd_titulos'             => 0,
                    'qtd_boletos'             => 0,
                    'valor_aberto'            => 0.0,
                    'valor_vencido'           => 0.0,
                    'valor_a_vencer'          => 0.0,
                    'menor_vencimento'        => null,
                    'maior_vencimento'        => null,
                    'maior_atraso'            => 0,
                    'metodo_pagamento_principal' => $row['normalized_payment_method'],
                    'bridge_confidence'       => $row['bridge_confidence'],
                    'bridge_status'           => $row['bridge_status'],
                    'match_method'            => $row['bridge_match_method'],
                    'recommended_action'      => $row['recommended_action'],
                ];
            }

            $entry = &$nfMap[$key];
            $entry['qtd_titulos']++;
            if ($row['is_boleto']) $entry['qtd_boletos']++;
            $entry['valor_aberto']  += (float) $row['valor_aberto'];
            $entry['valor_vencido'] += (float) $row['valor_vencido'];
            $entry['valor_a_vencer'] += (float) $row['valor_a_vencer'];
            $entry['maior_atraso']  = max((int) $entry['maior_atraso'], (int) $row['dias_atraso']);

            $due = $row['data_vencimento'];
            if ($due !== '') {
                if ($entry['menor_vencimento'] === null || $due < $entry['menor_vencimento']) $entry['menor_vencimento'] = $due;
                if ($entry['maior_vencimento'] === null || $due > $entry['maior_vencimento']) $entry['maior_vencimento'] = $due;
            }
            unset($entry);
        }

        $out = array_values($nfMap);
        foreach ($out as &$entry) {
            $entry['valor_aberto']   = round((float) $entry['valor_aberto'], 2);
            $entry['valor_vencido']  = round((float) $entry['valor_vencido'], 2);
            $entry['valor_a_vencer'] = round((float) $entry['valor_a_vencer'], 2);
        }
        unset($entry);

        usort($out, static fn (array $a, array $b): int =>
            ((float) $b['valor_aberto'] <=> (float) $a['valor_aberto'])
        );

        return array_slice($out, 0, 50);
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function buildPaymentMethodRanking(array $rows): array
    {
        $methodLabels = [
            'boleto'         => 'Boleto',
            'pix'            => 'Pix',
            'cartao_credito' => 'Cartão Crédito',
            'cartao_debito'  => 'Cartão Débito',
            'dinheiro'       => 'Dinheiro',
            'transferencia'  => 'Transferência',
            'cheque'         => 'Cheque',
            'deposito'       => 'Depósito',
            'nao_informado'  => 'Não informado',
            'outro'          => 'Outro',
        ];

        $map = [];
        $grandTotal = 0.0;

        foreach ($rows as $row) {
            $m = $row['normalized_payment_method'];
            if (!isset($map[$m])) {
                $map[$m] = [
                    'method_key'     => $m,
                    'method_label'   => $methodLabels[$m] ?? ucfirst($m),
                    'titles_count'   => 0,
                    'nfs_count_set'  => [],
                    'clients_count_set' => [],
                    'total_value'    => 0.0,
                    'open_value'     => 0.0,
                    'overdue_value'  => 0.0,
                    'due_value'      => 0.0,
                    'received_value' => 0.0,
                ];
            }
            $entry = &$map[$m];
            $entry['titles_count']++;
            if ($row['nota_key'] !== '') $entry['nfs_count_set'][$row['nota_key']] = true;
            if ($row['client_key'] !== '' && $row['client_key'] !== '__sem_cliente__') {
                $entry['clients_count_set'][$row['client_key']] = true;
            }
            $entry['total_value'] += (float) $row['valor_liquido'];
            if ($row['is_open']) {
                $entry['open_value'] += (float) $row['valor_aberto'];
                $grandTotal += (float) $row['valor_aberto'];
                if ($row['is_overdue']) $entry['overdue_value'] += (float) $row['valor_aberto'];
                else $entry['due_value'] += (float) $row['valor_aberto'];
            }
            if ($row['is_paid']) {
                $entry['received_value'] += (float) $row['valor_liquido'];
            }
            unset($entry);
        }

        $out = [];
        foreach ($map as $entry) {
            $out[] = [
                'method_key'      => $entry['method_key'],
                'method_label'    => $entry['method_label'],
                'titles_count'    => $entry['titles_count'],
                'nfs_count'       => count($entry['nfs_count_set']),
                'clients_count'   => count($entry['clients_count_set']),
                'total_value'     => round((float) $entry['total_value'], 2),
                'open_value'      => round((float) $entry['open_value'], 2),
                'overdue_value'   => round((float) $entry['overdue_value'], 2),
                'due_value'       => round((float) $entry['due_value'], 2),
                'received_value'  => round((float) $entry['received_value'], 2),
                'share_pct'       => $grandTotal > 0
                    ? round(((float) $entry['open_value'] / $grandTotal) * 100, 1) : 0.0,
            ];
        }

        usort($out, static fn (array $a, array $b): int =>
            ((float) $b['total_value'] <=> (float) $a['total_value'])
        );

        return array_slice($out, 0, 10);
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function buildVendorRanking(array $rows): array
    {
        $map = [];

        foreach ($rows as $row) {
            if (!$row['is_open'] || !$row['is_boleto']) continue;
            $key = $row['vendor_key'];
            if (!isset($map[$key])) {
                $map[$key] = [
                    'vendor_key'   => $key,
                    'vendor_name'  => $row['vendedor_nome'],
                    'open_value'   => 0.0,
                    'overdue_value'=> 0.0,
                    'boleto_count' => 0,
                    'nf_set'       => [],
                    'max_delay'    => 0,
                ];
            }
            $map[$key]['open_value']    += (float) $row['valor_aberto'];
            $map[$key]['overdue_value'] += (float) $row['valor_vencido'];
            $map[$key]['boleto_count']++;
            if ($row['nota_key'] !== '') $map[$key]['nf_set'][$row['nota_key']] = true;
            $map[$key]['max_delay'] = max((int) $map[$key]['max_delay'], (int) $row['dias_atraso']);
        }

        $out = [];
        foreach ($map as $entry) {
            $out[] = [
                'vendor_key'   => $entry['vendor_key'],
                'vendor_name'  => $entry['vendor_name'],
                'open_value'   => round((float) $entry['open_value'], 2),
                'overdue_value'=> round((float) $entry['overdue_value'], 2),
                'boleto_count' => $entry['boleto_count'],
                'nf_count'     => count($entry['nf_set']),
                'max_delay'    => $entry['max_delay'],
            ];
        }

        usort($out, static fn (array $a, array $b): int =>
            ((float) $b['open_value'] <=> (float) $a['open_value'])
        );

        return array_slice($out, 0, 20);
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function buildBoletoOpenTitles(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            if (!$row['is_open'] || !$row['is_boleto']) continue;
            $out[] = [
                'titulo_key'               => $row['titulo_key'],
                'numero_documento'         => $row['numero_documento'],
                'nota_key'                 => $row['nota_key'],
                'numero_nf'                => $row['numero_nf'],
                'pedido_id_omie'           => $row['pedido_id_omie'],
                'cliente_nome'             => $row['cliente_nome'],
                'vendedor_nome'            => $row['vendedor_nome'],
                'data_vencimento'          => $row['data_vencimento'],
                'dias_atraso'              => $row['dias_atraso'],
                'valor_liquido'            => $row['valor_liquido'],
                'valor_aberto'             => $row['valor_aberto'],
                'status_titulo'            => $row['status_titulo'],
                'normalized_payment_method'=> $row['normalized_payment_method'],
                'raw_payment_method'       => $row['raw_payment_method'],
                'bridge_status'            => $row['bridge_status'],
                'recommended_action'       => $row['recommended_action'],
            ];
        }

        usort($out, static fn (array $a, array $b): int =>
            ((int) $b['dias_atraso'] <=> (int) $a['dias_atraso'])
        );

        return array_slice($out, 0, 100);
    }

    private function tableExists(string $table): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1"
            );
            $stmt->execute(['t' => $table]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
