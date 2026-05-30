<?php

declare(strict_types=1);

namespace BIomie\Stock;

use PDO;
use Throwable;

/**
 * EST.R1.1 — Resolve qual fonte de estoque está disponível e classifica.
 *
 * Hierarquia de fontes (ordem de prioridade):
 *   1. official_stock_api     — geral/estoque/ responde com saldo real por local
 *   2. product_snapshot       — ListarProdutos retorna quantidade_estoque > 0
 *   3. omie_document_ledger   — canônico NF+itens disponível para análise operacional
 *   4. unavailable            — nenhuma fonte disponível
 *
 * Não faz chamadas live à Omie — analisa o estado local das tabelas canônicas
 * e dos registros em biomie_produtos_estoque_saldos.
 *
 * Para probe ao vivo da API Omie use omie_stock_capability_probe.php.
 */
final class OmieStockSourceResolver
{
    public const SOURCE_OFFICIAL   = 'official_stock_api';
    public const SOURCE_SNAPSHOT   = 'product_snapshot';
    public const SOURCE_DOCUMENT   = 'omie_document_ledger';
    public const SOURCE_UNAVAILABLE = 'unavailable';

    private const SOURCE_LABELS = [
        self::SOURCE_OFFICIAL   => 'Estoque oficial Omie (por produto e local)',
        self::SOURCE_SNAPSHOT   => 'Snapshot de produto Omie (cadastro)',
        self::SOURCE_DOCUMENT   => 'Documentos fiscais/comerciais Omie',
        self::SOURCE_UNAVAILABLE => 'Sem fonte disponível',
    ];

    private const SOURCE_CONFIDENCE = [
        self::SOURCE_OFFICIAL   => 'high',
        self::SOURCE_SNAPSHOT   => 'medium',
        self::SOURCE_DOCUMENT   => 'operational',
        self::SOURCE_UNAVAILABLE => 'none',
    ];

    private const SOURCE_NOTES = [
        self::SOURCE_OFFICIAL   => 'Saldo físico real por local de estoque. Fonte mais confiável.',
        self::SOURCE_SNAPSHOT   => 'Saldo agregado do cadastro de produto Omie. Sem breakdown por local.',
        self::SOURCE_DOCUMENT   => 'Visão operacional calculada por NFs, pedidos, remessas e devoluções. Não é saldo físico real.',
        self::SOURCE_UNAVAILABLE => 'Nenhuma fonte de estoque disponível no momento.',
    ];

    public function __construct(private readonly PDO $pdo) {}

    /**
     * Resolve a melhor fonte disponível.
     *
     * @return array{source:string,label:string,confidence:string,note:string,available:bool,official_unavailable_reason:string|null,has_document_ledger:bool}
     */
    public function resolve(): array
    {
        $officialUnavailableReason = null;

        // C1 — Official: biomie_produtos_estoque_saldos com saldo real
        if ($this->tableExists('biomie_produtos_estoque_saldos')) {
            $count = $this->countNonZeroSaldos();
            if ($count > 0) {
                return $this->buildResult(self::SOURCE_OFFICIAL, null, true);
            }
            $officialUnavailableReason = $count === 0
                ? 'omie_stock_module_unavailable'
                : 'stock_api_not_enabled_for_app';
        } else {
            $officialUnavailableReason = 'stock_table_not_created';
        }

        // C2 — Snapshot: produto com quantidade_estoque preenchida
        // (detectado quando sync rodou e trouxe valores > 0)
        if ($this->tableExists('biomie_produtos_estoque_saldos')) {
            $snapshotCount = $this->countSnapshotWithValues();
            if ($snapshotCount > 0) {
                return $this->buildResult(self::SOURCE_SNAPSHOT, $officialUnavailableReason, true);
            }
        }

        // C3 — Document ledger: tabela de itens canônicos disponível com dados
        $hasDocLedger = $this->hasCanonicalItems();
        if ($hasDocLedger) {
            return $this->buildResult(self::SOURCE_DOCUMENT, $officialUnavailableReason, true);
        }

        return $this->buildResult(self::SOURCE_UNAVAILABLE, $officialUnavailableReason, false);
    }

    /**
     * Resolve para um produto específico (pode ter estoque oficial mesmo sem geral).
     *
     * @return array{source:string,label:string,confidence:string,note:string}
     */
    public function resolveForProduct(string $productCode): array
    {
        if ($productCode !== '' && $this->tableExists('biomie_produtos_estoque_saldos')) {
            try {
                $st = $this->pdo->prepare('SELECT COUNT(*) FROM biomie_produtos_estoque_saldos WHERE codigo_produto = :c AND estoque_atual > 0');
                $st->execute([':c' => $productCode]);
                if ((int)$st->fetchColumn() > 0) {
                    return $this->buildResult(self::SOURCE_OFFICIAL, null, true);
                }
            } catch (Throwable) {}
        }

        return $this->resolve();
    }

    /**
     * Diagnóstico estático sem chamada à API.
     *
     * @return array<string,mixed>
     */
    public function diagnostics(): array
    {
        $saldosTableExists   = $this->tableExists('biomie_produtos_estoque_saldos');
        $movimentosExists    = $this->tableExists('biomie_produtos_estoque_movimentos');
        $locaisExists        = $this->tableExists('biomie_estoque_locais_dim');
        $itensExists         = $this->tableExists('biomie_notas_fiscais_itens_canonicos');

        $saldosCount  = $saldosTableExists ? $this->tableCount('biomie_produtos_estoque_saldos') : 0;
        $nonZeroCount = $saldosTableExists ? $this->countNonZeroSaldos() : 0;
        $itensCount   = $itensExists       ? $this->tableCount('biomie_notas_fiscais_itens_canonicos') : 0;
        $locaisCount  = $locaisExists      ? $this->tableCount('biomie_estoque_locais_dim') : 0;

        $resolved = $this->resolve();

        return [
            'resolved_source'    => $resolved['source'],
            'source_label'       => $resolved['label'],
            'source_confidence'  => $resolved['confidence'],
            'official_reason'    => $resolved['official_unavailable_reason'],
            'tables' => [
                'biomie_estoque_locais_dim'              => ['exists' => $locaisExists,     'rows' => $locaisCount],
                'biomie_produtos_estoque_saldos'         => ['exists' => $saldosTableExists, 'rows' => $saldosCount, 'non_zero' => $nonZeroCount],
                'biomie_produtos_estoque_movimentos'     => ['exists' => $movimentosExists,  'rows' => 0],
                'biomie_notas_fiscais_itens_canonicos'   => ['exists' => $itensExists,       'rows' => $itensCount],
            ],
            'recommendations' => $this->buildRecommendations($resolved['source'], $nonZeroCount, $itensCount),
        ];
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /** @return array{source:string,label:string,confidence:string,note:string,available:bool,official_unavailable_reason:string|null,has_document_ledger:bool} */
    private function buildResult(string $source, ?string $officialReason, bool $available): array
    {
        return [
            'source'                     => $source,
            'label'                      => self::SOURCE_LABELS[$source],
            'confidence'                 => self::SOURCE_CONFIDENCE[$source],
            'note'                       => self::SOURCE_NOTES[$source],
            'available'                  => $available,
            'official_unavailable_reason' => $officialReason,
            'has_document_ledger'        => $this->hasCanonicalItems(),
        ];
    }

    private function countNonZeroSaldos(): int
    {
        try {
            $st = $this->pdo->query('SELECT COUNT(*) FROM biomie_produtos_estoque_saldos WHERE estoque_atual > 0');
            return $st ? (int)$st->fetchColumn() : 0;
        } catch (Throwable) { return 0; }
    }

    private function countSnapshotWithValues(): int
    {
        try {
            $st = $this->pdo->query('SELECT COUNT(*) FROM biomie_produtos_estoque_saldos WHERE raw_source = \'omie_produto_snapshot\' AND estoque_atual > 0');
            return $st ? (int)$st->fetchColumn() : 0;
        } catch (Throwable) { return 0; }
    }

    private function hasCanonicalItems(): bool
    {
        if (!$this->tableExists('biomie_notas_fiscais_itens_canonicos')) return false;
        try {
            $st = $this->pdo->query('SELECT COUNT(*) FROM biomie_notas_fiscais_itens_canonicos LIMIT 1');
            return $st ? (int)$st->fetchColumn() > 0 : false;
        } catch (Throwable) { return false; }
    }

    private function tableCount(string $table): int
    {
        try {
            $st = $this->pdo->query("SELECT COUNT(*) FROM `{$table}`");
            return $st ? (int)$st->fetchColumn() : 0;
        } catch (Throwable) { return 0; }
    }

    private function tableExists(string $table): bool
    {
        try {
            $st = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t LIMIT 1');
            $st->execute([':t' => $table]);
            return (int)$st->fetchColumn() > 0;
        } catch (Throwable) { return false; }
    }

    /** @return list<string> */
    private function buildRecommendations(string $source, int $nonZeroSaldos, int $itensCount): array
    {
        $recs = [];
        if ($source === self::SOURCE_OFFICIAL) {
            $recs[] = 'Estoque oficial ativo — agendar sync periódico via sync_stock_omie.php --apply --balances';
        } elseif ($source === self::SOURCE_DOCUMENT) {
            $recs[] = 'Módulo de estoque Omie indisponível (omie_stock_module_unavailable)';
            $recs[] = "Inteligência operacional ativa: {$itensCount} itens canônicos para análise";
            $recs[] = 'Para estoque real: habilitar módulo de estoque na conta Omie';
        } elseif ($source === self::SOURCE_UNAVAILABLE) {
            $recs[] = 'Sem fonte de estoque disponível — verificar sync de NFs canônicas';
        }
        return $recs;
    }
}
