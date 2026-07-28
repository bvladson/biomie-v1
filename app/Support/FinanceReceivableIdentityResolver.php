<?php

declare(strict_types=1);

namespace BIomie\Support;

use PDO;

/**
 * Read-only resolver: resolve identidade operacional completa de cada título financeiro.
 * Caminho: Título → NF → Pedido → Cliente → Vendedor → Método → Status → Ação.
 *
 * Implementa batch-loading para evitar N+1 queries em listas grandes.
 * Nenhuma escrita, nenhuma mutação de dados.
 */
final class FinanceReceivableIdentityResolver
{
    // Batch-loaded lookups (lazy init via ensureLookups())
    /** @var array<string,array<string,mixed>> nota_key → NF row */
    private array $nfByNotaKey = [];
    /** @var array<string,array<string,mixed>> chave_nfe → NF row */
    private array $nfByChaveNfe = [];
    /** @var array<string,array<string,mixed>> numero_nf → NF row (fallback) */
    private array $nfByNumeroNf = [];
    /** @var array<string,array<string,mixed>> titulo_key → bridge row (highest confidence) */
    private array $bridgeByTituloKey = [];
    /** @var array<string,array<string,mixed>> pedido_id → pedido row */
    private array $pedidoById = [];
    /** @var array<string,array<string,mixed>> codigo_omie → cliente row */
    private array $clienteByCode = [];
    /** @var array<string,array<string,mixed>> documento → cliente row */
    private array $clienteByDoc = [];

    private bool $lookupLoaded = false;

    private bool $hasNf       = false;
    private bool $hasBridge   = false;
    private bool $hasPedido   = false;
    private bool $hasCliente  = false;
    private bool $schemaLoaded = false;
    /** @var array<string,array<string,bool>> */
    private array $schemaColumns = [];

    private const UNKNOWN_VENDORS  = ['sem vendedor', 'sem vendedor definido', 'nao informado', 'não informado', 'nao identificado', ''];
    private const UNKNOWN_CLIENTS  = ['cliente nao informado', 'cliente não informado', 'nao informado', 'não informado', ''];

    public function __construct(private readonly PDO $pdo) {}

    // ─── Public API ─────────────────────────────────────────────────────────

    /** @param array<string,mixed> $title @return array<string,mixed> */
    public function resolveTitle(array $title): array
    {
        $this->ensureLookups();
        return $this->doResolve($title);
    }

    /**
     * @param list<array<string,mixed>> $titles
     * @return list<array<string,mixed>>
     */
    public function resolveMany(array $titles): array
    {
        $this->ensureLookups($titles);
        $out = [];
        foreach ($titles as $t) {
            $out[] = $this->doResolve($t);
        }
        return $out;
    }

    /** @param array<string,mixed> $resolved resolveTitle() output */
    public function explainGap(array $resolved): string
    {
        $missing = $resolved['trace_quality']['missing'] ?? [];
        if ($missing === []) {
            return 'Identidade completa: NF, pedido, cliente e vendedor resolvidos.';
        }
        $parts = [];
        if (in_array('nf', $missing, true))      $parts[] = 'NF não encontrada';
        if (in_array('pedido', $missing, true))   $parts[] = 'Pedido não vinculado';
        if (in_array('vendedor', $missing, true)) $parts[] = 'Vendedor não identificado';
        if (in_array('cliente', $missing, true))  $parts[] = 'Cliente não resolvido';
        return implode('; ', $parts) . '.';
    }

    /** @param array<string,mixed> $row */
    public function makeIdentityKey(array $row): string
    {
        $notaKey  = (string) ($row['nota_key'] ?? $row['titulo_nota_key'] ?? '');
        $pedido   = (string) ($row['pedido_id_omie'] ?? '');
        $vendor   = (string) ($row['vendor_key'] ?? $row['codigo_vendedor_omie'] ?? '');
        $client   = (string) ($row['client_key'] ?? $row['codigo_cliente_omie'] ?? '');
        return implode('|', [$notaKey, $pedido, $vendor, $client]);
    }

    // ─── Core resolver ───────────────────────────────────────────────────────

    /** @param array<string,mixed> $t @return array<string,mixed> */
    private function doResolve(array $t): array
    {
        $nfResolved      = $this->resolveNf($t);
        $pedidoResolved  = $this->resolvePedido($t, $nfResolved);
        $vendorResolved  = $this->resolveVendor($t, $nfResolved, $pedidoResolved);
        $clienteResolved = $this->resolveCliente($t, $nfResolved, $pedidoResolved);
        $paymentResolved = $this->resolvePayment($t);
        $riskResolved    = $this->resolveRisk($t, $vendorResolved);
        $traceQuality    = $this->buildTraceQuality($nfResolved, $pedidoResolved, $vendorResolved, $clienteResolved);

        return [
            'company_id'     => (int) ($t['company_id'] ?? TenantContext::companyId()),
            'identity_key'   => $this->makeIdentityKey(array_merge($t, [
                'nota_key'           => $nfResolved['nota_key'],
                'pedido_id_omie'     => $pedidoResolved['pedido_id_omie'],
                'vendor_key'         => $vendorResolved['codigo_vendedor_omie'],
                'client_key'         => $clienteResolved['codigo_cliente_omie'],
            ])),
            'titulo_key'     => (string) ($t['titulo_key'] ?? ''),
            'titulo_label'   => $this->titulLabel($t),
            'nf'             => $nfResolved,
            'pedido'         => $pedidoResolved,
            'cliente'        => $clienteResolved,
            'vendedor'       => $vendorResolved,
            'payment'        => $paymentResolved,
            'risk'           => $riskResolved,
            'trace_quality'  => $traceQuality,
        ];
    }

    // ─── NF resolution (5-path chain) ────────────────────────────────────────

    /** @param array<string,mixed> $t @return array<string,mixed> */
    private function resolveNf(array $t): array
    {
        $empty = ['found' => false, 'nota_key' => '', 'numero_nf' => '', 'chave_nfe' => '',
                  'data_emissao' => '', 'status_fiscal' => '', 'operation_scope' => 'unknown', 'source' => 'none'];

        if (!$this->hasNf && !$this->hasBridge) {
            return $empty;
        }

        $tituloKey      = (string) ($t['titulo_key'] ?? '');
        $notaKeyRaw     = trim((string) ($t['nota_key'] ?? $t['titulo_nota_key'] ?? ''));
        $chaveNfeDirect = trim((string) ($t['chave_nfe'] ?? ''));
        $docOrigemRef   = trim((string) ($t['documento_origem_ref'] ?? ''));

        // Path 1: bridge by titulo_key (highest confidence)
        $bridgeRow = $this->bridgeByTituloKey[$this->tenantKey($t, $tituloKey)] ?? null;
        if ($bridgeRow !== null) {
            $bridgeNotaKey = (string) ($bridgeRow['nota_key'] ?? '');
            if ($bridgeNotaKey !== '') {
                $nf = $this->nfByNotaKey[$this->tenantKey($bridgeRow, $bridgeNotaKey)] ?? null;
                if ($nf !== null) {
                    return $this->nfRow($nf, 'bridge_titulo_key');
                }
                return array_merge($empty, [
                    'found'    => true,
                    'nota_key' => $bridgeNotaKey,
                    'source'   => 'bridge_partial',
                ]);
            }
        }

        // Path 2: titulo.nota_key stored as chave_nfe (44-char NF access key)
        // Omie sync stores chave_nfe in nota_key field — try nfByChaveNfe first.
        if (strlen($notaKeyRaw) === 44) {
            $nf = $this->nfByChaveNfe[$this->tenantKey($t, $notaKeyRaw)] ?? null;
            if ($nf !== null) {
                return $this->nfRow($nf, 'title_nota_key_as_chave_nfe');
            }
        }

        // Path 3: titulo.nota_key direct (omie:XXXXX format)
        if ($notaKeyRaw !== '') {
            $nf = $this->nfByNotaKey[$this->tenantKey($t, $notaKeyRaw)] ?? null;
            if ($nf !== null) {
                return $this->nfRow($nf, 'title_nota_key');
            }
        }

        // Path 4: titulo.chave_nfe direct
        if ($chaveNfeDirect !== '') {
            $nf = $this->nfByChaveNfe[$this->tenantKey($t, $chaveNfeDirect)] ?? null;
            if ($nf !== null) {
                return $this->nfRow($nf, 'title_chave_nfe');
            }
        }

        // Path 5: titulo.documento_origem_ref as chave_nfe (44-char)
        if (strlen($docOrigemRef) === 44) {
            $nf = $this->nfByChaveNfe[$this->tenantKey($t, $docOrigemRef)] ?? null;
            if ($nf !== null) {
                return $this->nfRow($nf, 'documento_origem_ref');
            }
        }

        // Path 6: titulo.numero_documento = nf.numero_nf (low confidence fallback)
        $numDoc = trim((string) ($t['numero_documento'] ?? ''));
        if ($numDoc !== '' && $this->hasNf) {
            $nf = $this->nfByNumeroNf[$this->tenantKey($t, $numDoc)] ?? null;
            if ($nf !== null) {
                return $this->nfRow($nf, 'numero_documento_nf');
            }
        }

        // Partial matches (key present but NF not in canonical table for this period)
        if ($chaveNfeDirect !== '') {
            return array_merge($empty, [
                'found'    => true,
                'nota_key' => $chaveNfeDirect,
                'chave_nfe'=> $chaveNfeDirect,
                'source'   => 'title_chave_nfe_partial',
            ]);
        }
        if ($notaKeyRaw !== '') {
            return array_merge($empty, [
                'found'    => true,
                'nota_key' => $notaKeyRaw,
                'source'   => 'titulo_nota_key_partial',
            ]);
        }

        return $empty;
    }

    /** @param array<string,mixed> $nf @return array<string,mixed> */
    private function nfRow(array $nf, string $source): array
    {
        return [
            'found'          => true,
            'company_id'     => (int) ($nf['company_id'] ?? TenantContext::companyId()),
            'nota_key'       => (string) ($nf['nota_key'] ?? ''),
            'numero_nf'      => (string) ($nf['numero_nf'] ?? ''),
            'chave_nfe'      => (string) ($nf['chave_nfe'] ?? ''),
            'data_emissao'   => (string) ($nf['data_emissao'] ?? ''),
            'status_fiscal'  => (string) ($nf['status_fiscal'] ?? $nf['status_nf'] ?? ''),
            'operation_scope'=> (string) ($nf['operation_scope'] ?? 'commercial'),
            'codigo_cliente_omie' => (string) ($nf['codigo_cliente_omie'] ?? ''),
            'nome_cliente' => (string) ($nf['nome_cliente'] ?? ''),
            'documento_cliente' => (string) ($nf['documento_cliente'] ?? ''),
            'codigo_vendedor_omie' => (string) ($nf['codigo_vendedor_omie'] ?? ''),
            'nome_vendedor' => (string) ($nf['nome_vendedor'] ?? ''),
            'pedido_id_omie' => (string) ($nf['pedido_id_omie'] ?? ''),
            'source'         => $source,
        ];
    }

    // ─── Pedido resolution (4-path chain) ────────────────────────────────────

    /** @param array<string,mixed> $t @param array<string,mixed> $nf @return array<string,mixed> */
    private function resolvePedido(array $t, array $nf): array
    {
        $empty = ['found' => false, 'pedido_id_omie' => '', 'numero_pedido' => '', 'status_pedido' => '', 'source' => 'none'];

        // Path 1: from NF canonical
        $pedidoIdFromNf = (string) ($nf['pedido_id_omie'] ?? '');
        if ($pedidoIdFromNf !== '' && $pedidoIdFromNf !== '0') {
            $p = $this->pedidoById[$this->tenantKey($nf, $pedidoIdFromNf)] ?? null;
            if ($p !== null) {
                return $this->pedidoRow($p, 'nf_canonical');
            }
            return ['found' => true, 'pedido_id_omie' => $pedidoIdFromNf,
                    'numero_pedido' => $pedidoIdFromNf, 'status_pedido' => '', 'source' => 'nf_id_only'];
        }

        // Path 2: from titulo.pedido_id_omie direct
        $pedidoIdDirect = (string) ($t['pedido_id_omie'] ?? '');
        if ($pedidoIdDirect !== '' && $pedidoIdDirect !== '0') {
            $p = $this->pedidoById[$this->tenantKey($t, $pedidoIdDirect)] ?? null;
            if ($p !== null) {
                return $this->pedidoRow($p, 'titulo_direct');
            }
            return ['found' => true, 'pedido_id_omie' => $pedidoIdDirect,
                    'numero_pedido' => $pedidoIdDirect, 'status_pedido' => '', 'source' => 'titulo_id_only'];
        }

        // Path 3: from titulo.codigo_pedido_origem
        $codPedido = trim((string) ($t['codigo_pedido_origem'] ?? ''));
        if ($codPedido !== '') {
            return ['found' => true, 'pedido_id_omie' => $codPedido,
                    'numero_pedido' => $codPedido, 'status_pedido' => '', 'source' => 'titulo_codigo_pedido'];
        }

        // Path 4: from bridge bridge_row
        $bridgeRow = $this->bridgeByTituloKey[$this->tenantKey($t, (string)($t['titulo_key'] ?? ''))] ?? null;
        if ($bridgeRow !== null && (string)($bridgeRow['pedido_id_omie'] ?? '') !== '') {
            $pedidoIdBridge = (string) $bridgeRow['pedido_id_omie'];
            $p = $this->pedidoById[$this->tenantKey($bridgeRow, $pedidoIdBridge)] ?? null;
            if ($p !== null) {
                return $this->pedidoRow($p, 'bridge');
            }
            return ['found' => true, 'pedido_id_omie' => $pedidoIdBridge,
                    'numero_pedido' => $pedidoIdBridge, 'status_pedido' => '', 'source' => 'bridge_id_only'];
        }

        return $empty;
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    private function pedidoRow(array $p, string $source): array
    {
        return [
            'found'          => true,
            'company_id'     => (int) ($p['company_id'] ?? TenantContext::companyId()),
            'pedido_id_omie' => (string) ($p['pedido_id_omie'] ?? ''),
            'numero_pedido'  => (string) ($p['numero_pedido'] ?? $p['pedido_id_omie'] ?? ''),
            'status_pedido'  => (string) ($p['status_pedido'] ?? ''),
            'codigo_vendedor_omie' => (string) ($p['codigo_vendedor_omie'] ?? ''),
            'nome_vendedor' => (string) ($p['nome_vendedor'] ?? ''),
            'codigo_cliente_omie' => (string) ($p['codigo_cliente_omie'] ?? ''),
            'nome_cliente' => (string) ($p['nome_cliente'] ?? ''),
            'source'         => $source,
        ];
    }

    // ─── Vendor resolution (5-path chain) ────────────────────────────────────

    /** @param array<string,mixed> $t @param array<string,mixed> $nf @param array<string,mixed> $pedido @return array<string,mixed> */
    private function resolveVendor(array $t, array $nf, array $pedido): array
    {
        $empty = ['found' => false, 'codigo_vendedor_omie' => '', 'nome' => '', 'source' => 'none'];

        // Path 1: NF canonical vendor
        $nfVCode = trim((string) ($nf['codigo_vendedor_omie'] ?? ''));
        $nfVName = trim((string) ($nf['nome_vendedor'] ?? ''));
        if (!$this->isUnknownVendor($nfVCode, $nfVName)) {
            return ['found' => true, 'codigo_vendedor_omie' => $nfVCode,
                    'nome' => $nfVName ?: 'Vendedor ' . $nfVCode, 'source' => 'nf_canonical'];
        }

        // Path 2: titulo direct vendor
        $tVCode = trim((string) ($t['codigo_vendedor_omie'] ?? $t['titulo_codigo_vendedor'] ?? ''));
        $tVName = trim((string) ($t['nome_vendedor'] ?? $t['titulo_nome_vendedor'] ?? ''));
        if (!$this->isUnknownVendor($tVCode, $tVName)) {
            return ['found' => true, 'codigo_vendedor_omie' => $tVCode,
                    'nome' => $tVName ?: 'Vendedor ' . $tVCode, 'source' => 'titulo_direct'];
        }

        // Path 3: bridge row vendor
        $bridgeRow = $this->bridgeByTituloKey[$this->tenantKey($t, (string)($t['titulo_key'] ?? ''))] ?? null;
        if ($bridgeRow !== null) {
            $bVCode = trim((string) ($bridgeRow['codigo_vendedor_omie'] ?? ''));
            $bVName = trim((string) ($bridgeRow['nome_vendedor'] ?? ''));
            if (!$this->isUnknownVendor($bVCode, $bVName)) {
                return ['found' => true, 'codigo_vendedor_omie' => $bVCode,
                        'nome' => $bVName ?: 'Vendedor ' . $bVCode, 'source' => 'bridge'];
            }
        }

        // Path 4: pedido vendor
        $pVCode = trim((string) ($pedido['codigo_vendedor_omie'] ?? ''));
        $pVName = trim((string) ($pedido['nome_vendedor'] ?? ''));
        if (!$this->isUnknownVendor($pVCode, $pVName)) {
            return ['found' => true, 'codigo_vendedor_omie' => $pVCode,
                    'nome' => $pVName ?: 'Vendedor ' . $pVCode, 'source' => 'pedido'];
        }

        // Path 5: gap_reason populated
        $gapReason = $this->vendorGapReason($t, $nf);
        return array_merge($empty, ['gap_reason' => $gapReason]);
    }

    private function vendorGapReason(array $t, array $nf): string
    {
        if (!$nf['found']) return 'NF não vinculada; vendedor indisponível';
        $tVName = trim((string) ($t['nome_vendedor'] ?? ''));
        if ($tVName !== '') return 'Título tem nome "' . $tVName . '" mas é inválido';
        return 'Omie não populou vendedor no título nem na NF';
    }

    // ─── Cliente resolution (5-path chain) ───────────────────────────────────

    /** @param array<string,mixed> $t @param array<string,mixed> $nf @param array<string,mixed> $pedido @return array<string,mixed> */
    private function resolveCliente(array $t, array $nf, array $pedido): array
    {
        $empty = ['found' => false, 'codigo_cliente_omie' => '', 'nome' => '', 'documento' => '', 'source' => 'none'];

        // Path 1: titulo direct (name valid + code)
        $tCCode = trim((string) ($t['codigo_cliente_omie'] ?? $t['titulo_codigo_cliente'] ?? ''));
        $tCName = trim((string) ($t['nome_cliente'] ?? $t['titulo_nome_cliente'] ?? ''));
        if (!$this->isUnknownClient($tCName)) {
            $dim = $tCCode !== '' ? ($this->clienteByCode[$this->tenantKey($t, $tCCode)] ?? null) : null;
            return ['found' => true, 'codigo_cliente_omie' => $tCCode,
                    'nome' => $dim ? (string)($dim['nome_cliente'] ?? $tCName) : $tCName,
                    'documento' => $dim ? $this->maskDoc((string)($dim['documento'] ?? '')) : $this->maskDoc((string)($t['documento_cliente'] ?? '')),
                    'source' => $dim ? 'titulo_dim' : 'titulo_direct'];
        }

        // Path 2: NF canonical client
        $nfCCode = trim((string) ($nf['codigo_cliente_omie'] ?? ''));
        $nfCName = trim((string) ($nf['nome_cliente'] ?? ''));
        if (!$this->isUnknownClient($nfCName)) {
            $dim = $nfCCode !== '' ? ($this->clienteByCode[$this->tenantKey($nf, $nfCCode)] ?? null) : null;
            return ['found' => true, 'codigo_cliente_omie' => $nfCCode,
                    'nome' => $dim ? (string)($dim['nome_cliente'] ?? $nfCName) : $nfCName,
                    'documento' => $this->maskDoc((string)($nf['documento_cliente'] ?? $dim['documento'] ?? '')),
                    'source' => $dim ? 'nf_dim' : 'nf_direct'];
        }

        // Path 3: pedido client
        $pCCode = trim((string) ($pedido['codigo_cliente_omie'] ?? ''));
        $pCName = trim((string) ($pedido['nome_cliente'] ?? ''));
        if (!$this->isUnknownClient($pCName)) {
            return ['found' => true, 'codigo_cliente_omie' => $pCCode,
                    'nome' => $pCName, 'documento' => '', 'source' => 'pedido'];
        }

        // Path 4: dim by codigo
        if ($tCCode !== '') {
            $dim = $this->clienteByCode[$this->tenantKey($t, $tCCode)] ?? null;
            if ($dim !== null) {
                return ['found' => true, 'codigo_cliente_omie' => $tCCode,
                        'nome' => (string) ($dim['nome_cliente'] ?? ''),
                        'documento' => $this->maskDoc((string)($dim['documento'] ?? '')),
                        'source' => 'dim_by_code'];
            }
        }

        // Path 5: dim by document
        $doc = preg_replace('/\D+/', '', (string) ($t['documento_cliente'] ?? ''));
        if ($doc !== '' && strlen($doc) >= 11) {
            $dim = $this->clienteByDoc[$this->tenantKey($t, $doc)] ?? null;
            if ($dim !== null) {
                return ['found' => true, 'codigo_cliente_omie' => (string)($dim['codigo_cliente_omie'] ?? ''),
                        'nome' => (string)($dim['nome_cliente'] ?? ''),
                        'documento' => $this->maskDoc($doc),
                        'source' => 'dim_by_doc'];
            }
        }

        return $empty;
    }

    // ─── Payment resolution ───────────────────────────────────────────────────

    /** @param array<string,mixed> $t @return array<string,mixed> */
    private function resolvePayment(array $t): array
    {
        $forma   = strtolower(trim((string) ($t['forma_pagamento'] ?? '')));
        $especie = strtolower(trim((string) ($t['especie_documento'] ?? '')));

        $method = $this->detectPaymentMethod($forma, $especie);
        $labels = ['boleto' => 'Boleto', 'pix' => 'Pix', 'cartao_credito' => 'Cartão Crédito',
                   'cartao_debito' => 'Cartão Débito', 'dinheiro' => 'Dinheiro',
                   'transferencia' => 'Transferência', 'cheque' => 'Cheque',
                   'deposito' => 'Depósito', 'nao_informado' => 'Não informado', 'outro' => 'Outro'];

        $src = 'none';
        if ($especie !== '') $src = 'especie';
        elseif ($forma !== '') $src = 'forma_pagamento';

        return [
            'method'       => $method,
            'method_label' => $labels[$method] ?? ucfirst($method),
            'source'       => $src,
        ];
    }

    private function detectPaymentMethod(string $forma, string $especie): string
    {
        $boletoEspecies = ['bol', 'dup', 'dmi', 'dme'];
        if (in_array($especie, $boletoEspecies, true)) return 'boleto';

        $map = [
            'boleto'         => ['boleto', 'bol ', 'cobrança', 'cobranca', 'duplicata'],
            'pix'            => ['pix'],
            'cartao_credito' => ['cred', 'crédito', 'credito', 'mastercard', 'visa'],
            'cartao_debito'  => ['débito', 'debito', 'debit'],
            'dinheiro'       => ['dinheiro', 'espécie', 'especie'],
            'transferencia'  => ['transfer', 'ted', 'doc '],
            'cheque'         => ['cheque', 'chq'],
            'deposito'       => ['depós', 'depos', 'deposit'],
        ];

        $combined = $forma . ' ' . $especie;
        foreach ($map as $method => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($combined, $kw)) return $method;
            }
        }

        return ($forma === '' && $especie === '') ? 'nao_informado' : 'outro';
    }

    // ─── Risk resolution ─────────────────────────────────────────────────────

    /** @param array<string,mixed> $t @param array<string,mixed> $vendedor @return array<string,mixed> */
    private function resolveRisk(array $t, array $vendedor): array
    {
        $today     = date('Y-m-d');
        $dueDate   = (string) ($t['data_vencimento'] ?? '');
        $amtOpen   = (float) ($t['valor_aberto'] ?? 0);
        $status    = strtolower(trim((string) ($t['status_titulo'] ?? '')));
        $isPaid    = in_array($status, ['liquidado', 'recebido', 'pago'], true);
        $isOpen    = !$isPaid && $amtOpen > 0.0001;
        $daysLate  = 0;

        if ($isOpen && $dueDate !== '' && $dueDate < $today) {
            $daysLate = max(0, (int) floor((strtotime($today) - strtotime($dueDate)) / 86400));
        }

        $isOverdue = $isOpen && $daysLate > 0;
        $priority  = 'normal';
        if ($isOverdue && $daysLate > 30) $priority = 'critical';
        elseif ($isOverdue) $priority = 'warning';
        elseif (!$vendedor['found']) $priority = 'warning';

        $action = 'Acompanhar vencimento';
        if (!$vendedor['found']) $action = 'Revisar vínculo financeiro';
        elseif ($isOverdue && $daysLate > 60) $action = 'Acionar jurídico / Cobrar urgente';
        elseif ($isOverdue) $action = 'Cobrar hoje';

        $bucket = $this->agingBucket($dueDate, $today);

        return [
            'days_overdue'       => $daysLate,
            'aging_bucket'       => $bucket,
            'is_overdue'         => $isOverdue,
            'priority'           => $priority,
            'recommended_action' => $action,
        ];
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

    // ─── Trace quality ───────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function buildTraceQuality(array $nf, array $pedido, array $vendedor, array $cliente): array
    {
        $missing  = [];
        if (!$nf['found'])      $missing[] = 'nf';
        if (!$pedido['found'])  $missing[] = 'pedido';
        if (!$vendedor['found'])$missing[] = 'vendedor';
        if (!$cliente['found']) $missing[] = 'cliente';

        $score = 100 - (count($missing) * 25);

        $gapParts = [];
        if (!$nf['found'])      $gapParts[] = 'NF não encontrada';
        if (!$pedido['found'])  $gapParts[] = 'Pedido não vinculado';
        if (!$vendedor['found'])$gapParts[] = $vendedor['gap_reason'] ?? 'Vendedor não identificado';
        if (!$cliente['found']) $gapParts[] = 'Cliente não resolvido';

        return [
            'score'      => max(0, $score),
            'gap_reason' => $gapParts !== [] ? implode('; ', $gapParts) : 'ok',
            'missing'    => $missing,
        ];
    }

    // ─── Batch Lookup Loading ─────────────────────────────────────────────────

    /**
     * @param list<array<string,mixed>>|null $titles
     */
    private function ensureLookups(?array $titles = null): void
    {
        if ($this->lookupLoaded) return;
        $this->lookupLoaded = true;

        $this->loadSchemaCapabilities();
        $this->hasNf = isset($this->schemaColumns['biomie_notas_fiscais_canonicas']);
        $this->hasBridge = isset($this->schemaColumns['biomie_bridge_nf_titulo_items']);
        $this->hasPedido = isset($this->schemaColumns['biomie_pedidos_canonicos']);
        $this->hasCliente = isset($this->schemaColumns['biomie_clientes_dim']);

        if ($titles !== null) {
            $this->loadScopedLookups($titles);
            return;
        }

        if ($this->hasBridge) $this->loadBridge();
        if ($this->hasNf) $this->loadNfs();
        if ($this->hasPedido) $this->loadPedidos();
        if ($this->hasCliente) $this->loadClientes();
    }

    /** @param list<array<string,mixed>> $titles */
    private function loadScopedLookups(array $titles): void
    {
        $titleKeys = $this->uniqueValues($titles, ['titulo_key']);
        if ($this->hasBridge && $titleKeys !== []) {
            $this->loadBridgeByTitleKeys($titleKeys);
        }

        $notaKeys = $this->uniqueValues($titles, ['nota_key', 'titulo_nota_key']);
        $chaves = $this->uniqueValues($titles, ['chave_nfe']);
        $numbers = $this->uniqueValues($titles, ['numero_documento']);
        foreach ($this->bridgeByTituloKey as $bridge) {
            $notaKey = trim((string) ($bridge['nota_key'] ?? ''));
            if ($notaKey !== '') $notaKeys[] = $notaKey;
        }
        if ($this->hasNf) {
            $this->loadNfsByKeys(array_values(array_unique($notaKeys)), $chaves, $numbers);
        }

        $pedidoIds = $this->uniqueValues($titles, ['pedido_id_omie', 'codigo_pedido_origem']);
        foreach (array_merge($this->bridgeByTituloKey, $this->nfByNotaKey) as $row) {
            $pedidoId = trim((string) ($row['pedido_id_omie'] ?? ''));
            if ($pedidoId !== '' && $pedidoId !== '0') $pedidoIds[] = $pedidoId;
        }
        if ($this->hasPedido) {
            $this->loadPedidosByIds(array_values(array_unique($pedidoIds)));
        }

        $clientCodes = $this->uniqueValues($titles, ['codigo_cliente_omie']);
        $documents = $this->uniqueValues($titles, ['documento_cliente']);
        foreach (array_merge($this->nfByNotaKey, $this->pedidoById) as $row) {
            $code = trim((string) ($row['codigo_cliente_omie'] ?? ''));
            $document = trim((string) ($row['documento_cliente'] ?? ''));
            if ($code !== '' && $code !== '0') $clientCodes[] = $code;
            if ($document !== '') $documents[] = $document;
        }
        if ($this->hasCliente) {
            $this->loadClientesByKeys(array_values(array_unique($clientCodes)), $documents);
        }
    }

    /** @param list<string> $titleKeys */
    private function loadBridgeByTitleKeys(array $titleKeys): void
    {
        $pedidoExpr = $this->columnExists('biomie_bridge_nf_titulo_items', 'pedido_id_omie')
            ? "COALESCE(pedido_id_omie, '')"
            : "''";
        $vendorCodeExpr = $this->columnExists('biomie_bridge_nf_titulo_items', 'codigo_vendedor_omie')
            ? "COALESCE(codigo_vendedor_omie, '')"
            : "''";
        $vendorNameExpr = $this->columnExists('biomie_bridge_nf_titulo_items', 'nome_vendedor')
            ? "COALESCE(nome_vendedor, '')"
            : "''";
        foreach (array_chunk($titleKeys, 500) as $chunk) {
            [$placeholders, $params] = $this->inParams($chunk, 'tk');
            $tenantFilter = TenantContext::sqlFilter();
            $stmt = $this->pdo->prepare(
                "SELECT company_id, titulo_key, nota_key, confidence_score,
                        {$pedidoExpr} AS pedido_id_omie,
                        {$vendorCodeExpr} AS codigo_vendedor_omie,
                        {$vendorNameExpr} AS nome_vendedor
                 FROM biomie_bridge_nf_titulo_items
                 WHERE {$tenantFilter}
                   AND titulo_key IN ({$placeholders})
                 ORDER BY confidence_score DESC, titulo_key ASC"
            );
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $key = (string) ($row['titulo_key'] ?? '');
                $tenantKey = $this->tenantKey($row, $key);
                if ($key !== '' && !isset($this->bridgeByTituloKey[$tenantKey])) {
                    $this->bridgeByTituloKey[$tenantKey] = $row;
                }
            }
        }
    }

    /** @param list<string> $notaKeys @param list<string> $chaves @param list<string> $numbers */
    private function loadNfsByKeys(array $notaKeys, array $chaves, array $numbers): void
    {
        $conditions = [];
        $params = [];
        foreach ([
            ['column' => 'nota_key', 'values' => $notaKeys, 'prefix' => 'nk'],
            ['column' => 'chave_nfe', 'values' => $chaves, 'prefix' => 'cn'],
            ['column' => 'numero_nf', 'values' => $numbers, 'prefix' => 'nn'],
        ] as $set) {
            if ($set['values'] === []) continue;
            [$holders, $bound] = $this->inParams(array_slice(array_values(array_unique($set['values'])), 0, 1000), $set['prefix']);
            $conditions[] = $set['column'] . " IN ({$holders})";
            $params += $bound;
        }
        if ($conditions === []) return;

        $scopeExpression = $this->columnExists('biomie_notas_fiscais_canonicas', 'operation_scope')
            ? "COALESCE(operation_scope, 'commercial')"
            : "'commercial'";
        $statusExpression = $this->columnExists('biomie_notas_fiscais_canonicas', 'status_fiscal')
            ? "COALESCE(status_fiscal, '')"
            : ($this->columnExists('biomie_notas_fiscais_canonicas', 'status_nf') ? "COALESCE(status_nf, '')" : "''");
        $tenantFilter = TenantContext::sqlFilter();
        $stmt = $this->pdo->prepare(
            "SELECT company_id, nota_key, chave_nfe, numero_nf, data_emissao,
                    {$statusExpression} AS status_fiscal,
                    {$scopeExpression} AS operation_scope,
                    COALESCE(codigo_cliente_omie, '') AS codigo_cliente_omie,
                    COALESCE(nome_cliente, '') AS nome_cliente,
                    COALESCE(documento_cliente, '') AS documento_cliente,
                    COALESCE(codigo_vendedor_omie, '') AS codigo_vendedor_omie,
                    COALESCE(nome_vendedor, '') AS nome_vendedor,
                    COALESCE(pedido_id_omie, '') AS pedido_id_omie
             FROM biomie_notas_fiscais_canonicas
             WHERE {$tenantFilter}
               AND (" . implode(' OR ', $conditions) . ')'
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $notaKey = (string) ($row['nota_key'] ?? '');
            $chave = (string) ($row['chave_nfe'] ?? '');
            $numero = (string) ($row['numero_nf'] ?? '');
            if ($notaKey !== '') $this->nfByNotaKey[$this->tenantKey($row, $notaKey)] = $row;
            if ($chave !== '') $this->nfByChaveNfe[$this->tenantKey($row, $chave)] = $row;
            if ($numero !== '') $this->nfByNumeroNf[$this->tenantKey($row, $numero)] = $row;
        }
    }

    /** @param list<string> $pedidoIds */
    private function loadPedidosByIds(array $pedidoIds): void
    {
        $numberExpression = $this->columnExists('biomie_pedidos_canonicos', 'numero_pedido')
            ? 'COALESCE(numero_pedido, CAST(pedido_id_omie AS CHAR))'
            : ($this->columnExists('biomie_pedidos_canonicos', 'codigo_pedido')
                ? 'COALESCE(codigo_pedido, CAST(pedido_id_omie AS CHAR))'
                : 'CAST(pedido_id_omie AS CHAR)');
        $statusExpression = $this->columnExists('biomie_pedidos_canonicos', 'status_pedido')
            ? "COALESCE(status_pedido, '')"
            : ($this->columnExists('biomie_pedidos_canonicos', 'status_comercial')
                ? "COALESCE(status_comercial, '')"
                : "COALESCE(status_pedido_omie, '')");
        foreach (array_chunk($pedidoIds, 500) as $chunk) {
            [$placeholders, $params] = $this->inParams($chunk, 'pid');
            $tenantFilter = TenantContext::sqlFilter();
            $stmt = $this->pdo->prepare(
                "SELECT company_id, pedido_id_omie,
                        {$numberExpression} AS numero_pedido,
                        {$statusExpression} AS status_pedido,
                        COALESCE(codigo_vendedor_omie, '') AS codigo_vendedor_omie,
                        COALESCE(nome_vendedor, '') AS nome_vendedor,
                        COALESCE(codigo_cliente_omie, '') AS codigo_cliente_omie,
                        COALESCE(nome_cliente, '') AS nome_cliente
                 FROM biomie_pedidos_canonicos
                 WHERE {$tenantFilter}
                   AND CAST(pedido_id_omie AS CHAR) IN ({$placeholders})"
            );
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $id = (string) ($row['pedido_id_omie'] ?? '');
                if ($id !== '') $this->pedidoById[$this->tenantKey($row, $id)] = $row;
            }
        }
    }

    /** @param list<string> $clientCodes @param list<string> $documents */
    private function loadClientesByKeys(array $clientCodes, array $documents): void
    {
        $documentColumn = $this->columnExists('biomie_clientes_dim', 'documento')
            ? 'documento'
            : ($this->columnExists('biomie_clientes_dim', 'cpf_cnpj') ? 'cpf_cnpj' : null);
        $conditions = [];
        $params = [];
        if ($clientCodes !== []) {
            [$holders, $bound] = $this->inParams(array_slice($clientCodes, 0, 1000), 'cc');
            $conditions[] = "CAST(codigo_cliente_omie AS CHAR) IN ({$holders})";
            $params += $bound;
        }
        $digits = array_values(array_unique(array_filter(array_map(
            static fn (string $value): string => preg_replace('/\D+/', '', $value) ?? '',
            $documents
        ))));
        if ($digits !== [] && $documentColumn !== null) {
            [$holders, $bound] = $this->inParams(array_slice($digits, 0, 1000), 'cd');
            $conditions[] = "REGEXP_REPLACE(COALESCE({$documentColumn}, ''), '[^0-9]', '') IN ({$holders})";
            $params += $bound;
        }
        if ($conditions === []) return;
        $documentExpression = $documentColumn !== null ? "COALESCE({$documentColumn}, '')" : "''";
        $tenantFilter = TenantContext::sqlFilter();
        $stmt = $this->pdo->prepare(
            "SELECT company_id, codigo_cliente_omie, nome_cliente, {$documentExpression} AS documento
             FROM biomie_clientes_dim
             WHERE {$tenantFilter}
               AND (" . implode(' OR ', $conditions) . ')'
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $code = (string) ($row['codigo_cliente_omie'] ?? '');
            $doc = preg_replace('/\D+/', '', (string) ($row['documento'] ?? '')) ?? '';
            if ($code !== '') $this->clienteByCode[$this->tenantKey($row, $code)] = $row;
            if (strlen($doc) >= 11) $this->clienteByDoc[$this->tenantKey($row, $doc)] = $row;
        }
    }

    /** @param list<array<string,mixed>> $rows @param list<string> $fields @return list<string> */
    private function uniqueValues(array $rows, array $fields): array
    {
        $values = [];
        foreach ($rows as $row) {
            foreach ($fields as $field) {
                $value = trim((string) ($row[$field] ?? ''));
                if ($value !== '' && $value !== '0') $values[$value] = true;
            }
        }
        return array_keys($values);
    }

    /** @param list<string> $values @return array{0:string,1:array<string,string>} */
    private function inParams(array $values, string $prefix): array
    {
        $holders = [];
        $params = [];
        foreach (array_values($values) as $index => $value) {
            $key = ':' . $prefix . $index;
            $holders[] = $key;
            $params[$key] = $value;
        }
        return [implode(',', $holders), $params];
    }

    private function loadBridge(): void
    {
        try {
            $pedidoExpr = $this->columnExists('biomie_bridge_nf_titulo_items', 'pedido_id_omie')
                ? "COALESCE(pedido_id_omie, '')"
                : "''";
            $vendorCodeExpr = $this->columnExists('biomie_bridge_nf_titulo_items', 'codigo_vendedor_omie')
                ? "COALESCE(codigo_vendedor_omie, '')"
                : "''";
            $vendorNameExpr = $this->columnExists('biomie_bridge_nf_titulo_items', 'nome_vendedor')
                ? "COALESCE(nome_vendedor, '')"
                : "''";
            $tenantFilter = TenantContext::sqlFilter();
            $stmt = $this->pdo->query(
                "SELECT company_id, titulo_key, nota_key, confidence_score,
                        {$pedidoExpr} AS pedido_id_omie,
                        {$vendorCodeExpr} AS codigo_vendedor_omie,
                        {$vendorNameExpr} AS nome_vendedor
                 FROM biomie_bridge_nf_titulo_items
                 WHERE {$tenantFilter}
                 ORDER BY confidence_score DESC, titulo_key ASC
                 LIMIT 100000"
            );
            if ($stmt === false) return;

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = (string) $row['titulo_key'];
                // Keep row with highest confidence (already ordered DESC)
                $tenantKey = $this->tenantKey($row, $key);
                if (!isset($this->bridgeByTituloKey[$tenantKey])) {
                    $this->bridgeByTituloKey[$tenantKey] = $row;
                }
            }
        } catch (\Throwable) {}
    }

    private function loadNfs(): void
    {
        try {
            $statusExpression = $this->columnExists('biomie_notas_fiscais_canonicas', 'status_fiscal')
                ? "COALESCE(status_fiscal, '')"
                : ($this->columnExists('biomie_notas_fiscais_canonicas', 'status_nf') ? "COALESCE(status_nf, '')" : "''");
            $cols = "company_id, nota_key, chave_nfe, numero_nf, data_emissao,
                     {$statusExpression} AS status_fiscal,
                     COALESCE(operation_scope, 'commercial') AS operation_scope,
                     COALESCE(codigo_cliente_omie, '') AS codigo_cliente_omie,
                     COALESCE(nome_cliente, '') AS nome_cliente,
                     COALESCE(documento_cliente, '') AS documento_cliente,
                     COALESCE(codigo_vendedor_omie, '') AS codigo_vendedor_omie,
                     COALESCE(nome_vendedor, '') AS nome_vendedor,
                     COALESCE(pedido_id_omie, '') AS pedido_id_omie";

            // Check if operation_scope column exists (may be missing in older schemas)
            try {
                $this->pdo->query("SELECT operation_scope FROM biomie_notas_fiscais_canonicas LIMIT 1");
            } catch (\Throwable) {
                $cols = str_replace("COALESCE(operation_scope, 'commercial') AS operation_scope", "'commercial' AS operation_scope", $cols);
            }

            $tenantFilter = TenantContext::sqlFilter();
            $stmt = $this->pdo->query(
                "SELECT {$cols}
                 FROM biomie_notas_fiscais_canonicas
                 WHERE {$tenantFilter}
                 LIMIT 200000"
            );
            if ($stmt === false) return;

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $notaKey  = (string) ($row['nota_key']  ?? '');
                $chaveNfe = (string) ($row['chave_nfe'] ?? '');
                $numero   = (string) ($row['numero_nf']  ?? '');
                if ($notaKey  !== '') $this->nfByNotaKey[$this->tenantKey($row, $notaKey)] = $row;
                if ($chaveNfe !== '') $this->nfByChaveNfe[$this->tenantKey($row, $chaveNfe)] = $row;
                if ($numero   !== '') $this->nfByNumeroNf[$this->tenantKey($row, $numero)] = $row;
            }
        } catch (\Throwable) {}
    }

    private function loadPedidos(): void
    {
        try {
            $numberExpression = $this->columnExists('biomie_pedidos_canonicos', 'numero_pedido')
                ? 'COALESCE(numero_pedido, CAST(pedido_id_omie AS CHAR))'
                : ($this->columnExists('biomie_pedidos_canonicos', 'codigo_pedido')
                    ? 'COALESCE(codigo_pedido, CAST(pedido_id_omie AS CHAR))'
                    : 'CAST(pedido_id_omie AS CHAR)');
            $statusExpression = $this->columnExists('biomie_pedidos_canonicos', 'status_pedido')
                ? "COALESCE(status_pedido, '')"
                : ($this->columnExists('biomie_pedidos_canonicos', 'status_comercial')
                    ? "COALESCE(status_comercial, '')"
                    : "COALESCE(status_pedido_omie, '')");
            $tenantFilter = TenantContext::sqlFilter();
            $stmt = $this->pdo->query(
                "SELECT company_id, pedido_id_omie,
                        {$numberExpression} AS numero_pedido,
                        {$statusExpression} AS status_pedido,
                        COALESCE(codigo_vendedor_omie, '') AS codigo_vendedor_omie,
                        COALESCE(nome_vendedor, '') AS nome_vendedor,
                        COALESCE(codigo_cliente_omie, '') AS codigo_cliente_omie,
                        COALESCE(nome_cliente, '') AS nome_cliente
                 FROM biomie_pedidos_canonicos
                 WHERE {$tenantFilter}
                 LIMIT 100000"
            );
            if ($stmt === false) return;

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (string) ($row['pedido_id_omie'] ?? '');
                if ($id !== '') $this->pedidoById[$this->tenantKey($row, $id)] = $row;
            }
        } catch (\Throwable) {}
    }

    private function loadClientes(): void
    {
        try {
            $documentColumn = $this->columnExists('biomie_clientes_dim', 'documento')
                ? 'documento'
                : ($this->columnExists('biomie_clientes_dim', 'cpf_cnpj') ? 'cpf_cnpj' : null);
            $documentExpression = $documentColumn !== null ? "COALESCE({$documentColumn}, '')" : "''";
            $tenantFilter = TenantContext::sqlFilter();
            $stmt = $this->pdo->query(
                "SELECT company_id, codigo_cliente_omie, nome_cliente,
                        {$documentExpression} AS documento
                 FROM biomie_clientes_dim
                 WHERE {$tenantFilter}
                 LIMIT 50000"
            );
            if ($stmt === false) return;

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $code = (string) ($row['codigo_cliente_omie'] ?? '');
                $doc  = preg_replace('/\D+/', '', (string)($row['documento'] ?? '')) ?? '';
                if ($code !== '') $this->clienteByCode[$this->tenantKey($row, $code)] = $row;
                if (strlen($doc) >= 11) $this->clienteByDoc[$this->tenantKey($row, $doc)] = $row;
            }
        } catch (\Throwable) {}
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $row */
    private function tenantKey(array $row, string $businessKey): string
    {
        $companyId = (int) ($row['company_id'] ?? TenantContext::companyId());
        return $companyId . '|' . $businessKey;
    }

    private function isUnknownVendor(string $code, string $name): bool
    {
        if ($code === '' && $name === '') return true;
        return in_array(strtolower($name), self::UNKNOWN_VENDORS, true);
    }

    private function isUnknownClient(string $name): bool
    {
        return in_array(strtolower($name), self::UNKNOWN_CLIENTS, true);
    }

    private function titulLabel(array $t): string
    {
        $ref = trim((string) ($t['documento_origem_ref'] ?? ''));
        if ($ref !== '') return $ref;
        $num = trim((string) ($t['numero_documento'] ?? ''));
        if ($num !== '') return $num;
        $key = (string) ($t['titulo_key'] ?? '');
        return strlen($key) > 12 ? '…' . substr($key, -8) : $key;
    }

    private function maskDoc(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) === 14) return substr($digits, 0, 2) . '.' . substr($digits, 2, 3) . '.' . substr($digits, 5, 3) . '/****-**';
        if (strlen($digits) === 11) return '***.***.***-**';
        return '';
    }

    private function tableExists(string $table): bool
    {
        $this->loadSchemaCapabilities();
        if ($this->schemaLoaded) {
            return isset($this->schemaColumns[$table]);
        }
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

    private function columnExists(string $table, string $column): bool
    {
        $this->loadSchemaCapabilities();
        if ($this->schemaLoaded) {
            return isset($this->schemaColumns[$table][$column]);
        }
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
            );
            $stmt->execute(['t' => $table, 'c' => $column]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function loadSchemaCapabilities(): void
    {
        if ($this->schemaLoaded) return;
        try {
            $tables = [
                'biomie_notas_fiscais_canonicas',
                'biomie_bridge_nf_titulo_items',
                'biomie_pedidos_canonicos',
                'biomie_clientes_dim',
            ];
            [$placeholders, $params] = $this->inParams($tables, 'schema_table');
            $stmt = $this->pdo->prepare(
                "SELECT TABLE_NAME, COLUMN_NAME
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})"
            );
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $table = (string) ($row['TABLE_NAME'] ?? '');
                $column = (string) ($row['COLUMN_NAME'] ?? '');
                if ($table !== '' && $column !== '') {
                    $this->schemaColumns[$table][$column] = true;
                }
            }
            $this->schemaLoaded = true;
        } catch (\Throwable) {
            $this->schemaLoaded = false;
        }
    }
}
