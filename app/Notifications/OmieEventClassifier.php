<?php

declare(strict_types=1);

namespace BIomie\Notifications;

/**
 * Traduz um evento cru da Omie numa notificação legível, priorizada e clicável.
 *
 * Antes toda notificação era "Evento Omie recebido — Domínio: commercial ·
 * ClienteFornecedor.Incluido": genérica, sem valor e sem ação. Aqui o tópico +
 * payload viram título, corpo, severidade, prioridade e deep-link.
 *
 * Prioridade (o que a diretoria quer ver primeiro):
 *   high   → PEDIDOS, CLIENTES e FATURAMENTO (o núcleo comercial)
 *   normal → alterações de pedido, contas a receber
 *   low    → contas a pagar, produtos/estoque (ruído operacional)
 *
 * Classificação acontece na ESCRITA (webhook), então a listagem não paga nada.
 */
final class OmieEventClassifier
{
    public const PRIORITY_HIGH   = 'high';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_LOW    = 'low';

    /**
     * @param array<string,mixed> $payload payload cru do webhook (com 'event')
     * @return array{category:string,severity:string,priority:string,icon:string,title:string,body:string,link:?string,dedupe_key:string,dedupe_window_seconds:int}
     */
    public static function classify(string $topic, array $payload, string $domain = 'commercial'): array
    {
        $event = is_array($payload['event'] ?? null) ? $payload['event'] : $payload;
        $topic = trim($topic);

        // ── PEDIDOS ──────────────────────────────────────────────────────────
        if (str_starts_with($topic, 'VendaProduto.')) {
            $numero = self::str($event, ['numeroPedido', 'codIntPedido']);
            $valor  = self::money($event, ['valorPedido']);
            $etapa  = self::str($event, ['etapaDescr']);
            $ref    = $numero !== '' ? ('#' . $numero) : '';
            $link   = $numero !== '' ? ('pedidos.php?open=order&pedido_codigo=' . rawurlencode($numero)) : null;

            return match ($topic) {
                'VendaProduto.Incluida' => self::make(
                    'business', 'success', self::PRIORITY_HIGH, 'shopping_cart',
                    trim('Novo pedido ' . $ref),
                    self::join([$valor, $etapa]),
                    $link, 'pedido_novo|' . $numero, 300
                ),
                'VendaProduto.Faturada' => self::make(
                    'business', 'success', self::PRIORITY_HIGH, 'paid',
                    trim('Pedido ' . $ref . ' faturado'),
                    self::join([$valor]),
                    $link, 'pedido_faturado|' . $numero, 300
                ),
                'VendaProduto.Cancelada' => self::make(
                    'business', 'critical', self::PRIORITY_HIGH, 'cancel',
                    trim('Pedido ' . $ref . ' cancelado'),
                    self::join([$valor]),
                    $link, 'pedido_cancelado|' . $numero, 300
                ),
                'VendaProduto.EtapaAlterada' => self::make(
                    'business', 'info', self::PRIORITY_NORMAL, 'moving',
                    trim('Pedido ' . $ref . ' mudou de etapa'),
                    self::join([$etapa, $valor]),
                    $link, 'pedido_etapa|' . $numero, 900
                ),
                default => self::make(
                    'business', 'info', self::PRIORITY_NORMAL, 'edit_note',
                    trim('Pedido ' . $ref . ' alterado'),
                    self::join([$etapa, $valor]),
                    $link, 'pedido_alterado|' . $numero, 1800
                ),
            };
        }

        // ── FATURAMENTO (NF-e) ───────────────────────────────────────────────
        if (str_starts_with($topic, 'NFe.')) {
            $nf     = self::str($event, ['numero_nf', 'nNF']);
            $chave  = self::str($event, ['nfe_chave', 'chave']);
            $ref    = $nf !== '' ? (' ' . $nf) : '';
            $link   = $nf !== ''
                ? ('pedidos.php?open=invoice&nf=' . rawurlencode($nf) . ($chave !== '' ? '&chave=' . rawurlencode($chave) : ''))
                : null;

            $emissao = self::date($event, ['data_emis']);

            if ($topic === 'NFe.NotaCancelada') {
                return self::make(
                    'business', 'critical', self::PRIORITY_HIGH, 'receipt_long',
                    'NF' . $ref . ' cancelada',
                    self::join([$emissao !== '' ? ('Emitida em ' . $emissao) : '']),
                    $link, 'nf_cancelada|' . $nf, 300
                );
            }

            return self::make(
                'business', 'success', self::PRIORITY_HIGH, 'receipt_long',
                'NF' . $ref . ' autorizada',
                self::join([$emissao !== '' ? ('Emitida em ' . $emissao) : '']),
                $link, 'nf_autorizada|' . $nf, 300
            );
        }

        // ── CLIENTES ─────────────────────────────────────────────────────────
        if (str_starts_with($topic, 'ClienteFornecedor.')) {
            $nome = self::str($event, ['razao_social', 'nome_fantasia']);
            $doc  = self::str($event, ['cnpj_cpf']);
            $cid  = self::str($event, ['codigo_cliente_omie']);
            $cidade = self::str($event, ['cidade']);
            $link = $cid !== ''
                ? ('pedidos.php?open=customer&codigo_cliente_omie=' . rawurlencode($cid))
                : null;

            $isNew = $topic === 'ClienteFornecedor.Incluido';

            return self::make(
                'business',
                $isNew ? 'success' : 'info',
                $isNew ? self::PRIORITY_HIGH : self::PRIORITY_NORMAL,
                $isNew ? 'person_add' : 'manage_accounts',
                ($isNew ? 'Novo cliente' : 'Cliente atualizado') . ($nome !== '' ? ': ' . $nome : ''),
                self::join([$doc, $cidade]),
                $link,
                ($isNew ? 'cliente_novo|' : 'cliente_alt|') . ($cid !== '' ? $cid : $nome),
                600
            );
        }

        // ── RECEBÍVEIS (médio) ───────────────────────────────────────────────
        if (str_starts_with($topic, 'Financas.ContaReceber.')) {
            $baixa = str_contains($topic, 'BaixaRealizada');
            return self::make(
                'business', $baixa ? 'success' : 'info', self::PRIORITY_NORMAL,
                $baixa ? 'price_check' : 'request_quote',
                $baixa ? 'Recebimento confirmado' : 'Conta a receber atualizada',
                self::join([self::money($event, ['valor_documento', 'valorDocumento'])]),
                'financeiro.php',
                'receber|' . ($baixa ? 'baixa' : 'alt'),
                1800
            );
        }

        // ── CONTAS A PAGAR / PRODUTOS / ESTOQUE (ruído: agrupado) ────────────
        if (str_starts_with($topic, 'Financas.ContaPagar.')) {
            return self::make(
                'system', 'info', self::PRIORITY_LOW, 'payments',
                'Contas a pagar movimentadas',
                'Lançamentos de contas a pagar foram criados ou alterados na Omie.',
                null, 'pagar|' . $domain, 3600
            );
        }

        if (str_starts_with($topic, 'Produto.')) {
            $isEstoque = str_contains($topic, 'MovimentacaoEstoque');
            return self::make(
                'system', 'info', self::PRIORITY_LOW,
                $isEstoque ? 'inventory' : 'category',
                $isEstoque ? 'Movimentação de estoque' : 'Catálogo de produtos atualizado',
                $isEstoque ? 'Houve entradas/saídas de estoque na Omie.' : 'Produtos foram incluídos ou alterados na Omie.',
                'mercadorias.php', 'produto|' . ($isEstoque ? 'estoque' : 'catalogo'), 3600
            );
        }

        // ── Fallback: mantém o evento visível, mas sem inflar a lista ────────
        return self::make(
            'webhook', 'info', self::PRIORITY_LOW, 'sync_alt',
            'Evento Omie recebido',
            $topic !== '' ? $topic : ('Domínio: ' . $domain),
            null, 'webhook_received|' . $domain, 3600
        );
    }

    /**
     * @return array{category:string,severity:string,priority:string,icon:string,title:string,body:string,link:?string,dedupe_key:string,dedupe_window_seconds:int}
     */
    private static function make(
        string $category,
        string $severity,
        string $priority,
        string $icon,
        string $title,
        string $body,
        ?string $link,
        string $dedupeKey,
        int $dedupeWindow
    ): array {
        return [
            'category' => $category,
            'severity' => $severity,
            'priority' => $priority,
            'icon' => $icon,
            'title' => $title !== '' ? $title : 'Evento Omie',
            'body' => $body,
            'link' => $link,
            'dedupe_key' => $dedupeKey,
            'dedupe_window_seconds' => $dedupeWindow,
        ];
    }

    /**
     * @param array<string,mixed> $event
     * @param list<string> $keys
     */
    private static function str(array $event, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $event[$key] ?? null;
            if ($value !== null && $value !== '' && !is_array($value)) {
                return trim((string) $value);
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $event
     * @param list<string> $keys
     */
    private static function money(array $event, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $event[$key] ?? null;
            if (is_numeric($value) && (float) $value > 0) {
                return 'R$ ' . number_format((float) $value, 2, ',', '.');
            }
        }

        return '';
    }

    /**
     * Datas da Omie vêm em formatos variados (ISO, dd/mm/aaaa). Normaliza para
     * dd/mm/aaaa — a notificação não pode mostrar "2026-07-10T00:00:00-03:00".
     *
     * @param array<string,mixed> $event
     * @param list<string> $keys
     */
    private static function date(array $event, array $keys): string
    {
        $raw = self::str($event, $keys);
        if ($raw === '') {
            return '';
        }

        $ts = strtotime($raw);

        return $ts !== false ? date('d/m/Y', $ts) : $raw;
    }

    /** @param list<string> $parts */
    private static function join(array $parts): string
    {
        return implode(' · ', array_filter($parts, static fn (string $p): bool => $p !== ''));
    }
}
